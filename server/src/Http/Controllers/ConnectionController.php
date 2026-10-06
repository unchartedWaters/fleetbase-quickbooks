<?php

namespace Fleetbase\Quickbooks\Http\Controllers;

use Fleetbase\Quickbooks\Jobs\ImportCustomers;
use Fleetbase\Quickbooks\Jobs\SyncCompanyBatch;
use Fleetbase\Quickbooks\Models\Connection;
use Fleetbase\Quickbooks\Models\PendingSync;
use Fleetbase\Quickbooks\Models\SyncAttempt;
use Fleetbase\Quickbooks\Models\SyncBatch;
use Fleetbase\Quickbooks\Services\ConnectionProbe;
use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\OAuthFlow;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Fleetbase\Quickbooks\Services\WebhookSubscriptions;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\PublicHttps;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ConnectionController extends QuickbooksController
{
    public function __construct(
        Authorizer $authorizer,
        private OAuthFlow $oauth,
        private SettingsService $settings,
        private SettingsStore $store,
        private ConnectionProbe $probe,
    ) {
        parent::__construct($authorizer);
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks view connection');
        $connection = $this->latestConnection($this->companyUuid($request));

        return response()->json([
            'connection' => $connection instanceof Connection ? $this->forBrowser($connection) : null,
        ]);
    }

    public function batches(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks view sync');
        $perPage   = $this->pageArgument($request->input('per_page', 25), 25, 25);
        $page      = $this->pageArgument($request->input('page', 1), 1, PHP_INT_MAX);
        $paginator = SyncBatch::query()
            ->where('company_uuid', $this->companyUuid($request))
            ->orderByDesc('created_at')
            ->paginate($perPage, [
                'uuid',
                'trigger',
                'direction',
                'status',
                'created_count',
                'updated_count',
                'aligned_count',
                'voided_count',
                'unmatched_count',
                'failed_count',
                'linked_count',
                'skipped_count',
                'started_at',
                'finished_at',
                'created_at',
            ], 'page', $page);
        [$batches, $meta] = $this->batchPage($paginator);

        $batchUuids = [];
        foreach ($batches as $batch) {
            if ($batch instanceof SyncBatch) {
                $batchUuids[] = (string) $batch->uuid;
            }
        }

        $errors   = [];
        $attempts = SyncAttempt::query()
            ->whereIn('batch_uuid', $batchUuids)
            ->whereNotNull('error')
            ->orderBy('created_at')
            ->get(['batch_uuid', 'error']);
        foreach ($attempts as $attempt) {
            if (!$attempt instanceof SyncAttempt) {
                continue;
            }
            $batchUuid = (string) $attempt->batch_uuid;
            $message   = trim((string) $attempt->error);
            if ($batchUuid === '' || $message === '') {
                continue;
            }
            $errors[$batchUuid][] = $message;
        }

        $payload = [];
        foreach ($batches as $batch) {
            if (!$batch instanceof SyncBatch) {
                continue;
            }
            $messages = array_values(array_unique($errors[(string) $batch->uuid] ?? []));
            $error    = $messages === [] ? null : implode('; ', array_slice($messages, 0, 3));
            if ($error === null && (int) $batch->failed_count > 0) {
                $error = (int) $batch->failed_count . ' failed.';
            }

            $payload[] = [
                'uuid'            => $batch->uuid,
                'trigger'         => $batch->trigger,
                'direction'       => $batch->direction,
                'status'          => $batch->status,
                'created'         => (int) $batch->created_count,
                'created_count'   => (int) $batch->created_count,
                'updated'         => (int) $batch->updated_count,
                'updated_count'   => (int) $batch->updated_count,
                'aligned'         => (int) $batch->aligned_count,
                'aligned_count'   => (int) $batch->aligned_count,
                'voided'          => (int) $batch->voided_count,
                'voided_count'    => (int) $batch->voided_count,
                'unmatched'       => (int) $batch->unmatched_count,
                'unmatched_count' => (int) $batch->unmatched_count,
                'failed'          => (int) $batch->failed_count,
                'failed_count'    => (int) $batch->failed_count,
                'linked'          => (int) $batch->linked_count,
                'linked_count'    => (int) $batch->linked_count,
                'skipped'         => (int) $batch->skipped_count,
                'skipped_count'   => (int) $batch->skipped_count,
                'error'           => $error,
                'started_at'      => $batch->started_at?->toIso8601String(),
                'finished_at'     => $batch->finished_at?->toIso8601String(),
                'created_at'      => $batch->created_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'batches' => $payload,
            'meta'    => $meta,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks connect connection');
        $companyUuid = $this->companyUuid($request);
        $credentials = $this->credentials($companyUuid);
        if (trim($credentials['client_id']) === '' || trim($credentials['client_secret']) === '' || !$this->isAbsoluteHttpUrl($credentials['redirect_uri'])) {
            return response()->json([
                'message' => 'Configure QuickBooks Client ID, Client secret, and Redirect URI before connecting.',
            ], 422);
        }
        $begun       = $this->oauth->begin(
            $companyUuid,
            (string) session('user', ''),
            $credentials
        );

        return response()->json($begun);
    }

    /**
     * Intuit sends the user's browser here with only code, state and realmId, so there is
     * no Fleetbase session. Nothing is connected here: the code is kept and the console
     * finishes the connection through complete(), where the signed-in user is known.
     */
    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        $state = (string) $request->input('state');

        // Only fixed codes go back to the console; the console maps them to its own messages.
        if ($request->filled('error')) {
            $this->oauth->forget($state);

            return $this->redirectToConsole(['error' => $request->input('error') === 'access_denied' ? 'cancelled' : 'failed']);
        }

        try {
            $handle = $this->oauth->receive($state, (string) $request->input('code'), (string) $request->input('realmId'));
        } catch (QuickBooksException) {
            return $this->redirectToConsole(['error' => 'state']);
        }

        return $this->redirectToConsole(['oauth_state' => $handle]);
    }

    /**
     * Finish an authorization received by callback(). Only the user and organization that
     * started it can finish it.
     */
    public function complete(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks connect connection');
        $companyUuid = $this->companyUuid($request);

        try {
            $connection = $this->oauth->complete(
                (string) $request->input('state'),
                $companyUuid,
                (string) session('user', ''),
                $this->credentials($companyUuid)
            );
        } catch (QuickBooksException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if ($connection === null) {
            if (!$this->connectionIsStored($companyUuid)) {
                return response()->json(['message' => 'QuickBooks is not connected. Connect again from Quickbooks Setup.'], 422);
            }

            // The webhook endpoint is set in the Intuit developer portal. This does not call Intuit.
            app(WebhookSubscriptions::class)->apply('admin', $companyUuid);

            return response()->json(['connected' => true]);
        }

        $this->persist($connection);
        // The webhook endpoint is set in the Intuit developer portal. This does not call Intuit.
        app(WebhookSubscriptions::class)->apply('admin', $companyUuid);
        $this->queueExistingRecords($companyUuid);
        SyncCompanyBatch::dispatch($companyUuid, 'now');
        // Customers in Data Resolution is the control for copying QuickBooks customers.
        // The import job records a skip when a sync already holds the company lock.
        if ($this->customersEnabled()) {
            ImportCustomers::dispatch($companyUuid);
        }

        return response()->json(['connected' => true]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks disconnect connection');
        // Intuit has no webhook unsubscribe API. This deletes the install-wide connection only.
        $this->companyUuid($request);
        Connection::query()->delete();

        return response()->json(['disconnected' => true]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks import-customers connection');
        $companyUuid = $this->companyUuid($request);
        $blocked     = $this->blockedConnection($companyUuid, 'import', 'inbound');
        if ($blocked !== null) {
            return $blocked;
        }
        ImportCustomers::dispatch($companyUuid);

        return response()->json(['queued' => true]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks reconcile sync');
        $companyUuid = $this->companyUuid($request);
        $blocked     = $this->blockedConnection($companyUuid, 'manual');
        if ($blocked !== null) {
            return $blocked;
        }
        SyncCompanyBatch::dispatch($companyUuid, 'manual');

        return response()->json(['queued' => true]);
    }

    public function sync(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks reconcile sync');
        $companyUuid = $this->companyUuid($request);
        $blocked     = $this->blockedConnection($companyUuid, 'now');
        if ($blocked !== null) {
            return $blocked;
        }
        SyncCompanyBatch::dispatch($companyUuid, 'now');

        return response()->json(['queued' => true]);
    }

    public function test(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks view connection');
        $row = $this->latestConnection($this->companyUuid($request));

        return response()->json($this->probe->probe(
            $row instanceof Connection ? FleetbaseDirectory::connectionToArray($row) : null
        ));
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks view sync');
        $companyUuid = $this->companyUuid($request);
        $connection  = $this->latestConnection($companyUuid);
        // Refused imports and not-connected syncs are stored as skipped, with finished_at set.
        // Last sync is the latest batch that actually ran.
        $last        = SyncBatch::query()
            ->where('company_uuid', $companyUuid)
            ->where('status', 'finished')
            ->orderByDesc('finished_at')
            ->first();
        $credentials = $this->credentials($companyUuid);
        $configured  = trim($credentials['client_id']) !== ''
            && trim($credentials['client_secret']) !== ''
            && $this->isAbsoluteHttpUrl($credentials['redirect_uri']);

        return response()->json([
            'connection'             => $connection instanceof Connection ? $this->forBrowser($connection) : null,
            'credentials_configured' => $configured,
            'queue'                  => PendingSync::query()->where('company_uuid', $companyUuid)->where('status', 'pending')->count(),
            'last_sync'              => $last instanceof SyncBatch ? [
                'finished_at'   => $last->finished_at?->toIso8601String(),
                'status'        => $last->status,
                'trigger'       => $last->trigger,
                'created_count' => (int) $last->created_count,
                'updated_count' => (int) $last->updated_count,
                'failed_count'  => (int) $last->failed_count,
            ] : null,
        ]);
    }

    /**
     * Queue in-scope rows for entities that are turned on, then the sync job drains them.
     */
    private function queueExistingRecords(string $companyUuid): void
    {
        $resolved = $this->settings->resolveSync(
            [],
            $this->store->adminSync(),
            $this->store->defaultSync()
        );
        (new SyncFlagger())->queueEnabled(app(FleetbaseDirectory::class), $companyUuid, $resolved);
    }

    protected function connectionIsStored(string $companyUuid): bool
    {
        return Connection::query()->where('company_uuid', $companyUuid)->exists();
    }

    /**
     * Save the freshly authorized connection. The install keeps one connection row.
     *
     * @param array<string, mixed> $connection
     */
    protected function persist(array $connection): void
    {
        $companyUuid = (string) ($connection['company_uuid'] ?? '');
        if ($companyUuid !== '') {
            Connection::query()->where('company_uuid', '!=', $companyUuid)->delete();
        }
        $existing = $this->latestConnection($companyUuid);
        $model    = $existing instanceof Connection ? $existing : new Connection();
        $model->fill($connection);
        $model->save();
    }

    /**
     * @param array<string, int|string> $query
     */
    protected function redirectToConsole(array $query): RedirectResponse|JsonResponse
    {
        // fleetbase.console.host falls back to 'fleetbase.io' when CONSOLE_HOST is unset.
        $host = trim((string) config('quickbooks.console_host', ''));
        if ($host === '') {
            $fleetbaseHost = trim((string) config('fleetbase.console.host', ''));
            $host          = $fleetbaseHost === 'fleetbase.io' ? '' : $fleetbaseHost;
        }
        if ($host === '') {
            return response()->json(['message' => 'Set CONSOLE_HOST so QuickBooks can return to the console.'], 500);
        }
        if (preg_match('#^https?://#i', $host) !== 1) {
            $host = 'https://' . $host;
        }

        return new RedirectResponse(rtrim($host, '/') . '/quickbooks?' . http_build_query($query));
    }

    /**
     * Current page rows and paging summary.
     * The package test harness stubs this paginator without items(), currentPage(), or total().
     *
     * @return array{0: array<int, mixed>, 1: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    private function batchPage(object $paginator): array
    {
        if (method_exists($paginator, 'items')
            && method_exists($paginator, 'currentPage')
            && method_exists($paginator, 'lastPage')
            && method_exists($paginator, 'perPage')
            && method_exists($paginator, 'total')
        ) {
            $rows = $paginator->items();

            return [
                is_array($rows) ? $rows : [],
                [
                    'current_page' => (int) $paginator->currentPage(),
                    'last_page'    => (int) $paginator->lastPage(),
                    'per_page'     => (int) $paginator->perPage(),
                    'total'        => (int) $paginator->total(),
                ],
            ];
        }

        $summary = method_exists($paginator, 'toArray') ? $paginator->toArray() : [];
        if (!is_array($summary)) {
            $summary = [];
        }
        $rows = $summary['data'] ?? [];

        return [
            is_array($rows) ? $rows : [],
            [
                'current_page' => (int) ($summary['current_page'] ?? 1),
                'last_page'    => (int) ($summary['last_page'] ?? 1),
                'per_page'     => (int) ($summary['per_page'] ?? 25),
                'total'        => (int) ($summary['total'] ?? 0),
            ],
        ];
    }

    /**
     * Missing or non-numeric query values use $default. The result stays between 1 and $maximum.
     */
    private function pageArgument(mixed $value, int $default, int $maximum): int
    {
        $number = is_numeric($value) ? (int) $value : $default;

        return min($maximum, max(1, $number));
    }

    private function latestConnection(string $companyUuid): ?Connection
    {
        $connection = Connection::query()
            ->where('company_uuid', $companyUuid)
            ->latest('updated_at')
            ->first();
        if ($connection instanceof Connection) {
            return $connection;
        }

        $rows = Connection::query()->latest('updated_at')->limit(2)->get();
        if ($rows->count() !== 1) {
            return null;
        }
        $only = $rows->first();

        return $only instanceof Connection ? $only : null;
    }

    /**
     * Tokens stay on the server. The console only needs enough to show status.
     *
     * @return array{realm_id: string|null, environment: string|null, needs_reauth: bool, home_currency: string|null, last_batch_at: string|null}
     */
    private function forBrowser(Connection $connection): array
    {
        return [
            'realm_id'      => $connection->realm_id,
            'environment'   => $connection->environment,
            'needs_reauth'  => (bool) $connection->needs_reauth,
            'home_currency' => $connection->home_currency,
            'last_batch_at' => $connection->last_batch_at?->toIso8601String(),
        ];
    }

    /**
     * Connect copies QuickBooks customers only when Customers is on in Data Resolution.
     * A missing value uses the same default as settings: customers stay on.
     */
    private function customersEnabled(): bool
    {
        $resolved = $this->settings->resolveSync([], $this->store->adminSync(), $this->store->defaultSync());

        return !(array_key_exists('customer_enabled', $resolved) && $resolved['customer_enabled'] === false);
    }

    /**
     * Manual sync, reconcile, and customer import stay available when the schedule
     * is off, but they cannot run without a connection. A missing connection is
     * rejected without an activity row. Reconnect is still recorded.
     */
    private function blockedConnection(string $companyUuid, string $trigger, string $direction = 'outbound'): ?JsonResponse
    {
        $row = $this->latestConnection($companyUuid);
        if (ConnectionGate::usable($row)) {
            return null;
        }

        if ($trigger === 'import') {
            $message = ImportCustomers::blockedMessage(ConnectionGate::hasRealm($row) ? $row : null);
        } elseif ($row instanceof Connection && $row->needs_reauth && ConnectionGate::hasRealm($row)) {
            $message = 'QuickBooks needs to be connected again before sync can continue.';
        } else {
            $message = 'QuickBooks is not connected. Connect from Quickbooks Setup.';
        }

        if (!ConnectionGate::hasRealm($row)) {
            return response()->json(['message' => $message], 422);
        }

        $batch = new SyncBatch();
        $batch->fill([
            'company_uuid' => $companyUuid,
            'trigger'      => $trigger,
            'direction'    => $direction,
            'status'       => 'skipped',
            'started_at'   => Carbon::now(),
            'finished_at'  => Carbon::now(),
        ]);
        $batch->save();

        $attempt = new SyncAttempt();
        $attempt->fill([
            'batch_uuid'   => $batch->uuid,
            'company_uuid' => $companyUuid,
            'local_type'   => 'connection',
            'local_uuid'   => $companyUuid,
            'outcome'      => 'skipped',
            'error'        => $message,
        ]);
        $attempt->save();

        return response()->json(['message' => $message], 422);
    }

    /**
     * Intuit is sent a public https URL: the saved public OAuth URL when it passes
     * the same check used on save, otherwise the computed callback when that passes.
     *
     * @return array{client_id: string, client_secret: string, redirect_uri: string, environment: string}
     */
    private function credentials(string $companyUuid): array
    {
        $credentials = $this->settings->credentialsFor($this->store, $companyUuid);
        $public      = trim((string) ($this->store->adminAuth()['public_oauth_redirect_url'] ?? ''));
        $internal    = SettingController::internalOAuthRedirectUrl();
        if ($this->isAbsoluteHttpUrl($public)) {
            $credentials['redirect_uri'] = $public;
        } elseif ($this->isAbsoluteHttpUrl($internal)) {
            $credentials['redirect_uri'] = $internal;
        } else {
            $credentials['redirect_uri'] = '';
        }

        return $credentials;
    }

    private function isAbsoluteHttpUrl(string $url): bool
    {
        return PublicHttps::isPublicHttpsUrl($url);
    }
}
