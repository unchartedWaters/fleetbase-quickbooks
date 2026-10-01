<?php

namespace Fleetbase\Quickbooks\Services;

/**
 * Keeps a connection's access token usable before any QuickBooks API call.
 */
class ConnectionTokens
{
    public const REAUTH_MESSAGE = 'QuickBooks needs to be reconnected. Connect from Connection.';

    public const ALREADY_RUNNING = 'Another QuickBooks token refresh is already running.';

    public function __construct(
        private TokenRefresher $refresher,
        private SettingsService $settings,
        private SettingsStore $store,
        private FleetbaseDirectory $directory,
    ) {
    }

    /**
     * Refresh the tokens when they are close to expiring and save the result right away.
     * The returned connection has needs_reauth set when Intuit refused the refresh token,
     * or refresh_error set when the refresh failed for another reason.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    public function refreshIfDue(array $connection, int $now): array
    {
        if (!empty($connection['needs_reauth']) || !$this->refresher->isDue($connection, $now)) {
            return $connection;
        }

        $companyUuid = (string) ($connection['company_uuid'] ?? '');
        // A batch or import already holds this company's lock. Refresh inside it.
        if ($companyUuid !== '' && BatchRunner::holds($companyUuid)) {
            return $this->refreshNow($connection);
        }

        $lock = BatchRunner::lock($companyUuid);
        if ($lock === null) {
            return $connection;
        }
        if (!$lock->get()) {
            $connection['refresh_error'] = self::ALREADY_RUNNING;

            return $connection;
        }

        try {
            return $this->refreshNow($connection);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    private function refreshNow(array $connection): array
    {
        $refreshed = $this->refresher->refresh($connection, $this->settings->credentialsFor($this->store, (string) ($connection['company_uuid'] ?? '')));
        if (empty($refreshed['refresh_error']) && $refreshed !== $connection) {
            $this->directory->saveConnection($refreshed);
        }

        return $refreshed;
    }

    /**
     * Why QuickBooks cannot be called with this connection, or null when it can.
     *
     * @param array<string, mixed> $connection
     */
    public static function blockedMessage(array $connection, int $now): ?string
    {
        if (!empty($connection['needs_reauth'])) {
            return self::REAUTH_MESSAGE;
        }

        $expiresAt = $connection['token_expires_at'] ?? null;
        if (empty($connection['refresh_error']) || ($expiresAt !== null && (int) $expiresAt > $now)) {
            return null;
        }

        return (string) $connection['refresh_error'];
    }
}
