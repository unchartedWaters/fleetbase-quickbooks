<?php

namespace Fleetbase\Quickbooks\Http\Controllers;

use Fleetbase\Quickbooks\Services\FleetbaseDirectory;
use Fleetbase\Quickbooks\Services\SettingsService;
use Fleetbase\Quickbooks\Services\SettingsStore;
use Fleetbase\Quickbooks\Support\Authorizer;
use Fleetbase\Quickbooks\Support\PublicHttps;
use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SettingsValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends QuickbooksController
{
    /** @var array<int, string> */
    private const READ_ONLY_URL_KEYS = [
        'public_receiver_url',
        'webhook_url',
        'internal_webhook_receiver_url',
        'internal_oauth_redirect_url',
    ];

    /** @var array<int, string> */
    private const PUBLIC_URL_KEYS = [
        'public_webhook_receiver_url',
        'public_oauth_redirect_url',
    ];

    /** @var array<int, string> */
    private const DIRECTION_FIELDS = [
        'customer_direction',
        'invoice_direction',
        'payment_direction',
        'wallet_direction',
    ];

    /** @var array<int, string> */
    private const SYNC_KEYS = [
        'enabled',
        'interval_minutes',
        'periodic_interval_hours',
        'batch_size',
        'retry_limit',
        'default_backoff_seconds',
        'customer_conflict',
        'customer_reference',
        'customer_direction',
        'customer_enabled',
        'invoice_conflict',
        'invoice_reference',
        'invoice_direction',
        'invoice_enabled',
        'payment_conflict',
        'payment_reference',
        'payment_direction',
        'payment_enabled',
        'wallet_conflict',
        'wallet_reference',
        'wallet_direction',
        'wallet_enabled',
    ];

    public function __construct(
        Authorizer $authorizer,
        private SettingsService $settings,
        private SettingsStore $store,
        private ?FleetbaseDirectory $directory = null,
    ) {
        parent::__construct($authorizer);
    }

    /**
     * scope=admin saves the global settings (installation administrator only). scope=company, the
     * default, saves this organization's sync override and nothing else.
     */
    public function save(Request $request): JsonResponse
    {
        if ($this->scope($request) === 'company') {
            return $this->saveCompany($request);
        }

        $this->authorizeInstallationAdmin($request);

        $companyUuid  = $this->companyUuid($request);
        $authKey      = SettingsKeys::adminAuth();
        $syncKey      = SettingsKeys::adminSync();
        $existingAuth = $this->store->adminAuth();
        $existingSync = $this->store->get($syncKey);
        $incomingAuth = $request->input('auth', []);
        $incomingSync = $request->input('sync', []);
        if (is_array($incomingAuth) === false) {
            $incomingAuth = [];
        }
        if (is_array($incomingSync) === false) {
            $incomingSync = [];
        }
        $incomingSync = $this->onlySyncKeys($incomingSync);
        $this->stripReadOnly($incomingAuth);
        $this->stripReadOnly($incomingSync);
        $this->stripPublicUrls($incomingSync);
        unset($incomingAuth['sources'], $incomingAuth['client_secret_set'], $incomingAuth['webhook_verifier_set']);
        $publicUrls   = $this->capturePublicUrls($incomingAuth);
        $incomingAuth = $this->store->normalizeAuth($incomingAuth);

        [$mergedAuth, $incomingAuth, $mergedSync] = $this->mergeSavedSettings($existingAuth, $incomingAuth, $existingSync, $incomingSync);

        $errors = (new SettingsValidator())->errors($mergedAuth, $mergedSync);
        if ($errors !== []) {
            return response()->json([
                'message' => 'Some QuickBooks settings are missing or invalid.',
                'errors'  => $errors,
            ], 422);
        }

        $before     = $this->effectiveSync($companyUuid, $existingSync);
        $storedAuth = $this->settings->storeAuth($incomingAuth, $existingAuth);
        $storedAuth = $this->applyPublicUrls(
            $this->store->normalizeAuth(array_merge($existingAuth, $storedAuth)),
            $publicUrls
        );
        $this->store->put($authKey, $storedAuth);
        $this->stripReadOnly($mergedSync);
        $this->stripPublicUrls($mergedSync);
        $this->store->put($syncKey, $mergedSync);
        $this->queueTurnedOnEntities($companyUuid, $before, $this->effectiveSync($companyUuid, $mergedSync));
        $this->applyWebhookSubscriptions($companyUuid);

        return response()->json($this->payload($request));
    }

    /**
     * Saves or clears this organization's sync override. Global settings are never accepted here.
     */
    private function saveCompany(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks update settings', $request);

        $companyUuid = $this->companyUuid($request);
        $extra       = array_diff(array_keys($request->all()), ['scope', 'override', 'sync', 'company_uuid']);
        if ($extra !== [] || $request->has('override') === false) {
            return response()->json([
                'message' => 'An organization can only override the sync options. Global QuickBooks settings are changed on the Admin screen.',
                'errors'  => ['settings' => 'Send only scope, override, and sync.'],
            ], 422);
        }

        $before = $this->effectiveSync($companyUuid, $this->store->adminSync());
        if ($request->boolean('override') === false) {
            $this->store->clearCompanySync($companyUuid);
        } else {
            $incoming = $request->input('sync', []);
            $incoming = is_array($incoming) === true ? $incoming : [];
            $incoming = array_filter(
                SettingsStore::onlySyncFields($incoming),
                static fn ($value): bool => $value !== null && $value !== ''
            );
            $merged = array_merge($this->store->companySync($companyUuid), $incoming);
            $this->stripReadOnly($merged);
            $this->stripPublicUrls($merged);
            $this->normalizePresentDirections($merged);

            // Fields the override leaves out are inherited, so check the override's own values over
            // what the organization would otherwise run with. The resolver alone would hide a bad value.
            $inherited = $this->settings->resolveSync([], $this->store->adminSync(), $this->store->defaultSync());
            $errors    = (new SettingsValidator())->syncErrors(array_merge($inherited, $merged));
            if ($errors !== []) {
                return response()->json([
                    'message' => 'Some QuickBooks settings are missing or invalid.',
                    'errors'  => $errors,
                ], 422);
            }
            $this->store->putCompanySync($companyUuid, $merged);
        }

        $this->queueTurnedOnEntities($companyUuid, $before, $this->effectiveSync($companyUuid, $this->store->adminSync()));

        return response()->json($this->companyPayload($request, $companyUuid));
    }

    /**
     * 'company' (the default) or 'admin'. Anything else is refused.
     */
    private function scope(Request $request): string
    {
        $scope = (string) $request->input('scope', 'company');
        if ($scope !== 'company' && $scope !== 'admin') {
            abort(422, 'Unknown QuickBooks settings scope.');
        }

        return $scope;
    }

    /**
     * What this organization actually runs with: its override over the given global row over the defaults.
     *
     * @param array<string, mixed> $adminSync
     *
     * @return array<string, mixed>
     */
    private function effectiveSync(string $companyUuid, array $adminSync): array
    {
        return $this->settings->resolveSync($this->store->companySync($companyUuid), $adminSync, $this->store->defaultSync());
    }

    /**
     * @param array<string, mixed> $existingAuth
     * @param array<string, mixed> $incomingAuth
     * @param array<string, mixed> $existingSync
     * @param array<string, mixed> $incomingSync
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function mergeSavedSettings(array $existingAuth, array $incomingAuth, array $existingSync, array $incomingSync): array
    {
        $mergedAuth = $this->withComputedRedirect(array_merge($existingAuth, $incomingAuth));
        if (isset($mergedAuth['redirect_uri']) === true) {
            $incomingAuth['redirect_uri'] = $mergedAuth['redirect_uri'];
        }
        $secret = $incomingAuth['client_secret'] ?? '';
        if (is_string($secret) === false || $secret === '') {
            $mergedAuth['client_secret'] = $existingAuth['client_secret'] ?? '';
        } else {
            $mergedAuth['client_secret'] = $secret;
        }

        $mergedSync = array_merge($this->onlySyncKeys($existingSync), $incomingSync);
        $this->stripReadOnly($mergedSync);
        $this->stripPublicUrls($mergedSync);
        $this->normalizeDirections($mergedSync);

        return [$mergedAuth, $incomingAuth, $mergedSync];
    }

    public function show(Request $request): JsonResponse
    {
        if ($this->scope($request) === 'admin') {
            // Global settings are for installation administrators only.
            $this->authorizeInstallationAdmin($request);
            $this->companyUuid($request);

            return response()->json($this->payload($request));
        }

        $this->authorizeQuickbooks('quickbooks view settings', $request);

        return response()->json($this->companyPayload($request, $this->companyUuid($request)));
    }

    /**
     * The organization view: sync options only. No credential, secret flag, environment or URL.
     *
     * @return array<string, mixed>
     */
    private function companyPayload(Request $request, string $companyUuid): array
    {
        $row       = $this->store->get(SettingsKeys::companySync($companyUuid));
        $override  = ($row[SettingsKeys::ORGANIZATION_MARKER] ?? null) === SettingsKeys::ORGANIZATION_MARKER_VALUE && ($row['override'] ?? false) === true;
        $adminSync = $this->store->adminSync();
        $auth      = $this->settings->resolveAuth([], $this->store->adminAuth(), $this->store->envAuth());

        return [
            'can_edit'               => $this->authorizer->can('quickbooks update settings', $request),
            'override'               => $override,
            'sync'                   => $this->syncForBrowser($this->effectiveSync($companyUuid, $adminSync)),
            'global_sync'            => $this->syncForBrowser($this->settings->resolveSync([], $adminSync, $this->store->defaultSync())),
            'company_sync'           => $this->companySyncForBrowser($this->store->companySync($companyUuid)),
            'credentials_configured' => trim((string) ($auth['client_id'] ?? '')) !== '' && $this->secretIsSet($auth),
        ];
    }

    /**
     * Only the sync options of a resolved bag.
     *
     * @param array<string, mixed> $sync
     *
     * @return array<string, mixed>
     */
    private function syncForBrowser(array $sync): array
    {
        return $this->onlySyncKeys($sync);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $companySync = $this->store->adminSync();
        $companyAuth = $this->store->adminAuth();
        $resolved    = $this->settings->resolveAuth(
            [],
            $companyAuth,
            $this->store->envAuth()
        );
        $browser                                  = $this->settings->forBrowser($resolved);
        $browser['client_secret_set']             = $this->secretIsSet($resolved);
        $browser['sources']                       = is_array($resolved['sources'] ?? null) === true ? $resolved['sources'] : [];
        $webhook                                  = self::internalWebhookReceiverUrl();
        $oauth                                    = self::internalOAuthRedirectUrl();
        $publicWebhook                            = self::publicOrInternal($companyAuth['public_webhook_receiver_url'] ?? null, $webhook);
        $publicOauth                              = self::publicOrInternal($companyAuth['public_oauth_redirect_url'] ?? null, $oauth);
        $browser['public_receiver_url']           = $webhook;
        $browser['webhook_url']                   = $webhook;
        $browser['internal_webhook_receiver_url'] = $webhook;
        $browser['public_webhook_receiver_url']   = $publicWebhook;
        $browser['internal_oauth_redirect_url']   = $oauth;
        $browser['public_oauth_redirect_url']     = $publicOauth;
        unset($browser['sources']['webhook_url']);

        return [
            'can_edit'                        => $this->authorizer->isInstallationAdmin($request),
            'auth'                            => $browser,
            'sync'                            => $this->settings->resolveSync(
                [],
                $companySync,
                $this->store->defaultSync()
            ),
            'company_auth'                    => $this->settings->forBrowser($companyAuth),
            'company_sync'                    => $this->companySyncForBrowser($companySync),
            'internal_webhook_receiver_url'   => $webhook,
            'public_webhook_receiver_url'     => $publicWebhook,
            'internal_oauth_redirect_url'     => $oauth,
            'public_oauth_redirect_url'       => $publicOauth,
        ];
    }

    /**
     * Fleetbase's own webhook route. Other services forward to this URL.
     * It is computed from the configured origin and is never stored.
     */
    public static function publicReceiverUrl(): string
    {
        return self::internalWebhookReceiverUrl();
    }

    public static function internalWebhookReceiverUrl(): string
    {
        return self::absoluteUrl(self::routePath('webhooks'));
    }

    /**
     * The OAuth callback registered in routes.php: {prefix}/{internal}/v1/oauth/callback.
     */
    public static function internalOAuthRedirectUrl(): string
    {
        return self::absoluteUrl(self::routePath('oauth/callback'));
    }

    private static function absoluteUrl(string $path): string
    {
        $base = self::configuredOrigin();
        if ($base === '') {
            return $path;
        }

        return rtrim($base, '/') . $path;
    }

    private static function routePath(string $suffix): string
    {
        $prefix   = 'quickbooks';
        $internal = 'int';
        if (function_exists('config') === true) {
            $configuredPrefix   = config('quickbooks.api.routing.prefix', 'quickbooks');
            $configuredInternal = config('quickbooks.api.routing.internal_prefix', 'int');
            if (is_string($configuredPrefix) === true && trim($configuredPrefix, '/') !== '') {
                $prefix = trim($configuredPrefix, '/');
            }
            if (is_string($configuredInternal) === true && trim($configuredInternal, '/') !== '') {
                $internal = trim($configuredInternal, '/');
            }
        }

        return '/' . $prefix . '/' . $internal . '/v1/' . $suffix;
    }

    /**
     * Host comes from the first configured URL that is not loopback:
     * app.url, fleetbase.url, then the console host. The port comes from the
     * API URL (app.url, then fleetbase.url). When the host is the console host,
     * the scheme still comes from the first configured application URL
     * (app.url, then fleetbase.url). With no application URL, the console host
     * keeps its own scheme. A port that exists only on the console host is the
     * console port and is not used for these receiver URLs. The fleetbase.io
     * default is not an origin, and no host is invented.
     */
    private static function configuredOrigin(): string
    {
        if (function_exists('config') === false) {
            return '';
        }

        $apiOrigins = self::configuredOrigins(['app.url', 'fleetbase.url']);
        $picked     = self::selectedConfiguredOrigin($apiOrigins);
        if ($picked === null) {
            return '';
        }
        $selected = $picked['origin'];
        $fromApi  = $picked['from_api'];

        $port   = $fromApi === true ? $selected['port'] : self::configuredApiPort($apiOrigins);
        $scheme = $selected['scheme'];
        if ($fromApi === false && $apiOrigins !== []) {
            $scheme = $apiOrigins[0]['scheme'];
        }

        return self::formatOrigin($scheme, $selected['host'], $port);
    }

    /**
     * @param array<int, array{scheme: string, host: string, port: int|null}> $apiOrigins
     *
     * @return array{origin: array{scheme: string, host: string, port: int|null}, from_api: bool}|null
     */
    private static function selectedConfiguredOrigin(array $apiOrigins): ?array
    {
        $consoleOrigins = [];
        foreach (self::configuredOrigins(['quickbooks.console_host', 'fleetbase.console.host']) as $origin) {
            if (self::isFleetbaseIoDefault($origin['host']) === false) {
                $consoleOrigins[] = $origin;
            }
        }

        foreach ($apiOrigins as $origin) {
            if (self::hostnameIsLoopback($origin['host']) === false) {
                return ['origin' => $origin, 'from_api' => true];
            }
        }
        foreach ($consoleOrigins as $origin) {
            if (self::hostnameIsLoopback($origin['host']) === false) {
                return ['origin' => $origin, 'from_api' => false];
            }
        }
        if ($apiOrigins !== []) {
            return ['origin' => $apiOrigins[0], 'from_api' => true];
        }
        if (isset($consoleOrigins[0]) === true) {
            return ['origin' => $consoleOrigins[0], 'from_api' => false];
        }

        return null;
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<int, array{scheme: string, host: string, port: int|null}>
     */
    private static function configuredOrigins(array $keys): array
    {
        $origins = [];
        foreach (self::configuredHosts($keys) as $url) {
            $origin = self::parseOrigin($url);
            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    /**
     * @return array{scheme: string, host: string, port: int|null}|null
     */
    private static function parseOrigin(string $url): ?array
    {
        $parts = parse_url(self::withScheme($url));
        if (is_array($parts) === false) {
            return null;
        }
        $host = $parts['host'] ?? null;
        if (is_string($host) === false || $host === '') {
            return null;
        }

        return [
            'scheme' => strtolower((string) ($parts['scheme'] ?? 'https')),
            'host'   => $host,
            'port'   => isset($parts['port']) === true ? (int) $parts['port'] : null,
        ];
    }

    /**
     * @param array<int, array{scheme: string, host: string, port: int|null}> $apiOrigins
     */
    private static function configuredApiPort(array $apiOrigins): ?int
    {
        foreach ($apiOrigins as $origin) {
            if ($origin['port'] !== null) {
                return $origin['port'];
            }
        }

        return null;
    }

    private static function formatOrigin(string $scheme, string $host, ?int $port): string
    {
        $host = trim($host, '[]');
        if (str_contains($host, ':') === true) {
            $host = '[' . $host . ']';
        }
        $origin = $scheme . '://' . $host;
        if ($port !== null && self::portIsDefault($scheme, $port) === false) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    private static function portIsDefault(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }

    private static function hostnameIsLoopback(string $hostname): bool
    {
        $hostname = strtolower(trim($hostname, '[]'));

        return in_array($hostname, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<int, string>
     */
    private static function configuredHosts(array $keys): array
    {
        $hosts = [];
        foreach ($keys as $key) {
            $host = config($key);
            if (is_string($host) === false) {
                continue;
            }
            $host = trim($host);
            if ($host === '') {
                continue;
            }
            $hosts[] = $host;
        }

        return $hosts;
    }

    private static function isFleetbaseIoDefault(string $url): bool
    {
        $hostname = parse_url(self::withScheme($url), PHP_URL_HOST);
        if (is_string($hostname) === false || $hostname === '') {
            $hostname = $url;
        }

        return strtolower(trim($hostname, '[]')) === 'fleetbase.io';
    }

    private static function withScheme(string $url): string
    {
        if (str_contains($url, '://') === true) {
            return $url;
        }

        return 'https://' . $url;
    }

    /**
     * The webhook endpoint is set in the Intuit developer portal.
     * apply() does not call Intuit, and saving settings does not mean Intuit accepted a subscription.
     */
    private function applyWebhookSubscriptions(string $companyUuid): void
    {
        $class = 'Fleetbase\\Quickbooks\\Services\\WebhookSubscriptions';
        if (class_exists($class) === false) {
            return;
        }

        $subscriptions = app($class);
        if (is_object($subscriptions) === false || method_exists($subscriptions, 'apply') === false) {
            return;
        }

        $subscriptions->apply('admin', $companyUuid);
    }

    /**
     * @param array<string, mixed> $auth
     */
    private function secretIsSet(array $auth): bool
    {
        $secret = $auth['client_secret'] ?? null;

        return is_string($secret) === true && trim($secret) !== '';
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function stripReadOnly(array &$bag): void
    {
        unset($bag['override'], $bag['sources']);
        foreach (self::READ_ONLY_URL_KEYS as $key) {
            unset($bag[$key]);
        }
    }

    /**
     * Public URLs belong on the global auth row. A sync payload must not keep them.
     *
     * @param array<string, mixed> $bag
     */
    private function stripPublicUrls(array &$bag): void
    {
        foreach (self::PUBLIC_URL_KEYS as $key) {
            unset($bag[$key]);
        }
    }

    /**
     * @param array<string, mixed> $auth
     *
     * @return array<string, string>
     */
    private function capturePublicUrls(array &$auth): array
    {
        $public = [];
        foreach (self::PUBLIC_URL_KEYS as $key) {
            if (array_key_exists($key, $auth) === false) {
                continue;
            }
            $public[$key] = trim((string) $auth[$key]);
            unset($auth[$key]);
        }

        return $public;
    }

    /**
     * A public https URL is stored. An empty or internal one is cleared so the
     * response and the OAuth callback stay on the computed address.
     *
     * @param array<string, mixed>  $auth
     * @param array<string, string> $public
     *
     * @return array<string, mixed>
     */
    private function applyPublicUrls(array $auth, array $public): array
    {
        foreach (self::PUBLIC_URL_KEYS as $key) {
            if (array_key_exists($key, $public) === false) {
                $current = $auth[$key] ?? null;
                if (is_string($current) === true && self::isPublicHttpsUrl($current) === true) {
                    $auth[$key] = trim($current);
                    continue;
                }
                unset($auth[$key]);
                continue;
            }
            $value = trim($public[$key]);
            if ($value !== '' && self::isPublicHttpsUrl($value) === true) {
                $auth[$key] = $value;
                continue;
            }
            unset($auth[$key]);
        }

        return $auth;
    }

    private static function publicOrInternal(mixed $saved, string $internal): string
    {
        $saved = is_string($saved) === true ? trim($saved) : '';
        if ($saved !== '' && self::isPublicHttpsUrl($saved) === true) {
            return $saved;
        }

        return $internal;
    }

    /**
     * A blank, path-only, non-https, non-public, or console-port redirect is replaced
     * with the computed API callback. A public https address is left alone.
     *
     * @param array<string, mixed> $auth
     *
     * @return array<string, mixed>
     */
    private function withComputedRedirect(array $auth): array
    {
        $current = trim((string) ($auth['redirect_uri'] ?? ''));
        if ($this->usableRedirect($current) === true) {
            return $auth;
        }

        $auth['redirect_uri'] = self::internalOAuthRedirectUrl();

        return $auth;
    }

    private function usableRedirect(string $redirect): bool
    {
        if (self::isPublicHttpsUrl($redirect) === false) {
            return false;
        }
        $parts = parse_url($redirect);

        return is_array($parts) === true && (int) ($parts['port'] ?? 0) !== 4200;
    }

    /**
     * The same public https check used when the redirect is sent to Intuit.
     */
    private static function isPublicHttpsUrl(string $url): bool
    {
        return PublicHttps::isPublicHttpsUrl($url);
    }

    /**
     * An override that names a direction as Off or blank means Both. A direction it leaves out is inherited.
     *
     * @param array<string, mixed> $sync
     */
    private function normalizePresentDirections(array &$sync): void
    {
        foreach (self::DIRECTION_FIELDS as $field) {
            if (array_key_exists($field, $sync) === true && ($sync[$field] === '' || $sync[$field] === 'off' || is_string($sync[$field]) === false)) {
                $sync[$field] = 'both';
            }
        }
    }

    /**
     * @param array<string, mixed> $sync
     */
    private function normalizeDirections(array &$sync): void
    {
        foreach (self::DIRECTION_FIELDS as $field) {
            $value = $sync[$field] ?? null;
            if (is_string($value) === false || $value === '' || $value === 'off') {
                $sync[$field] = 'both';
            }
        }
    }

    /**
     * @param array<string, mixed> $sync
     *
     * @return array<string, mixed>
     */
    private function companySyncForBrowser(array $sync): array
    {
        $sync = $this->onlySyncKeys($sync);
        $this->stripReadOnly($sync);
        $this->stripPublicUrls($sync);
        foreach (self::DIRECTION_FIELDS as $field) {
            if (array_key_exists($field, $sync) === false) {
                continue;
            }
            $value = $sync[$field];
            if (is_string($value) === false || $value === '' || $value === 'off') {
                $sync[$field] = 'both';
            }
        }

        return $sync;
    }

    /**
     * @param array<string, mixed> $sync
     *
     * @return array<string, mixed>
     */
    private function onlySyncKeys(array $sync): array
    {
        return array_intersect_key($sync, array_fill_keys(self::SYNC_KEYS, true));
    }

    /**
     * Queue the rows that just became eligible. A switch that stays on, or
     * that is turned off, does not queue.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $merged
     */
    private function queueTurnedOnEntities(string $companyUuid, array $existing, array $merged): void
    {
        $enabled = [];
        foreach ([
            'customer_enabled' => 'customer',
            'invoice_enabled'  => 'invoice',
            'wallet_enabled'   => 'wallet',
        ] as $key => $entity) {
            if ($this->entitySwitchOn($existing, $key) === false && $this->entitySwitchOn($merged, $key) === true) {
                $enabled[$entity] = true;
            }
        }
        if ($this->entitySwitchOn($existing, 'payment_enabled') === false && $this->entitySwitchOn($merged, 'payment_enabled') === true) {
            $enabled['invoice'] = true;
        }
        if ($enabled === [] || $companyUuid === '') {
            return;
        }

        $this->directory()->queueInScope($companyUuid, $enabled);
    }

    /**
     * @param array<string, mixed> $sync
     */
    private function entitySwitchOn(array $sync, string $key): bool
    {
        // A wallet switch that was never stored is off; the other entities default to on.
        if (array_key_exists($key, $sync) === false) {
            return $key !== 'wallet_enabled';
        }

        return $sync[$key] !== false;
    }

    private function directory(): FleetbaseDirectory
    {
        return $this->directory ??= app(FleetbaseDirectory::class);
    }
}
