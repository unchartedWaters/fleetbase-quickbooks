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

    public function save(Request $request): JsonResponse
    {
        // These keys are install-wide (the Intuit app credentials, the webhook verifier and the sync
        // policy for every organization), so an organization role is not enough to change them.
        $this->authorizeInstallationAdmin($request);

        $companyUuid  = $this->companyUuid($request);
        $this->rejectCompanyScope($request);
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

        $mergedAuth = $this->withComputedRedirect(array_merge($existingAuth, $incomingAuth));
        if (isset($mergedAuth['redirect_uri']) === true) {
            $incomingAuth['redirect_uri'] = $mergedAuth['redirect_uri'];
        }
        $secret     = $incomingAuth['client_secret'] ?? '';
        if (is_string($secret) === false || $secret === '') {
            $mergedAuth['client_secret'] = $existingAuth['client_secret'] ?? '';
        } else {
            $mergedAuth['client_secret'] = $secret;
        }

        $mergedSync = array_merge($this->onlySyncKeys($existingSync), $incomingSync);
        $this->stripReadOnly($mergedSync);
        $this->stripPublicUrls($mergedSync);
        $this->normalizeDirections($mergedSync);

        $errors = (new SettingsValidator())->errors($mergedAuth, $mergedSync);
        if ($errors !== []) {
            return response()->json([
                'message' => 'Some QuickBooks settings are missing or invalid.',
                'errors'  => $errors,
            ], 422);
        }

        $storedAuth = $this->settings->storeAuth($incomingAuth, $existingAuth);
        $storedAuth = $this->applyPublicUrls(
            $this->store->normalizeAuth(array_merge($existingAuth, $storedAuth)),
            $publicUrls
        );
        $this->store->put($authKey, $storedAuth);
        $this->stripReadOnly($mergedSync);
        $this->stripPublicUrls($mergedSync);
        $this->store->put($syncKey, $mergedSync);
        $this->queueTurnedOnEntities($companyUuid, $this->onlySyncKeys($existingSync), $mergedSync);
        $this->applyWebhookSubscriptions($companyUuid);

        return response()->json($this->payload($request));
    }

    public function show(Request $request): JsonResponse
    {
        $this->authorizeQuickbooks('quickbooks view settings', $request);

        $this->companyUuid($request);
        $this->rejectCompanyScope($request);

        return response()->json($this->payload($request));
    }

    /**
     * QuickBooks settings are install-wide. An organization scope is not a settings screen.
     */
    private function rejectCompanyScope(Request $request): void
    {
        if ((string) $request->input('scope', 'admin') === 'company') {
            abort(404, 'QuickBooks settings are saved for the Fleetbase install.');
        }
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

        $apiOrigins     = self::configuredOrigins(['app.url', 'fleetbase.url']);
        $consoleOrigins = [];
        foreach (self::configuredOrigins(['quickbooks.console_host', 'fleetbase.console.host']) as $origin) {
            if (self::isFleetbaseIoDefault($origin['host']) === false) {
                $consoleOrigins[] = $origin;
            }
        }

        $selected = null;
        $fromApi  = false;
        foreach ($apiOrigins as $origin) {
            if (self::hostnameIsLoopback($origin['host']) === false) {
                $selected = $origin;
                $fromApi  = true;
                break;
            }
        }
        if ($selected === null) {
            foreach ($consoleOrigins as $origin) {
                if (self::hostnameIsLoopback($origin['host']) === false) {
                    $selected = $origin;
                    break;
                }
            }
        }
        if ($selected === null) {
            if ($apiOrigins !== []) {
                $selected = $apiOrigins[0];
                $fromApi  = true;
            } else {
                $selected = $consoleOrigins[0] ?? null;
            }
        }
        if ($selected === null) {
            return '';
        }

        $port   = $fromApi === true ? $selected['port'] : self::configuredApiPort($apiOrigins);
        $scheme = $selected['scheme'];
        if ($fromApi === false && $apiOrigins !== []) {
            $scheme = $apiOrigins[0]['scheme'];
        }

        return self::formatOrigin($scheme, $selected['host'], $port);
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
     * Public URLs belong on the install-wide auth row. A sync payload must not keep them.
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
