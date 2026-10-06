<?php

namespace Fleetbase\Quickbooks\Services;

/**
 * Keeps a connection's access token usable before any QuickBooks API call.
 */
class ConnectionTokens
{
    public const REAUTH_MESSAGE = 'QuickBooks needs to be reconnected. Connect from Quickbooks Setup.';

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
        $heldAlready = $companyUuid !== '' && BatchRunner::holds($companyUuid);
        $lock        = null;
        if (!$heldAlready) {
            $lock = BatchRunner::lock($companyUuid);
            if ($lock === null) {
                return $connection;
            }
            if (!$lock->get()) {
                $connection['refresh_error'] = self::ALREADY_RUNNING;

                return $connection;
            }
        }

        try {
            return $this->refreshWhileLocked($connection);
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }

    /**
     * The company lock is held for the Intuit call and the compare-and-save.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    private function refreshWhileLocked(array $connection): array
    {
        $companyUuid = (string) ($connection['company_uuid'] ?? '');
        $sent        = (string) ($connection['refresh_token'] ?? '');
        $stored      = $this->currentConnection($companyUuid);
        if (!is_array($stored) || (string) ($stored['refresh_token'] ?? '') !== $sent) {
            return is_array($stored) ? $stored : $connection;
        }

        $refreshed = $this->requestRefresh($connection);
        if (!empty($refreshed['refresh_error'])) {
            return $refreshed;
        }
        if (!$this->storeRefresh($connection, $refreshed, $sent)) {
            $current = $this->currentConnection($companyUuid);

            return is_array($current) ? $current : $connection;
        }

        return $refreshed;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    private function requestRefresh(array $connection): array
    {
        return $this->refresher->refresh($connection, $this->settings->credentialsFor($this->store, (string) ($connection['company_uuid'] ?? '')));
    }

    /**
     * Save the rotated token only when the stored refresh token is still the one that was sent.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $refreshed
     */
    private function storeRefresh(array $connection, array $refreshed, string $sentRefresh): bool
    {
        if (!empty($refreshed['refresh_error']) || $refreshed === $connection) {
            return false;
        }

        $stored = $this->currentConnection((string) ($connection['company_uuid'] ?? ''));
        if (!is_array($stored) || (string) ($stored['refresh_token'] ?? '') !== $sentRefresh) {
            return false;
        }

        $this->directory->saveConnection($refreshed);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentConnection(string $companyUuid): ?array
    {
        if ($companyUuid === '') {
            return null;
        }

        $stored = $this->directory->connection($companyUuid);

        return is_array($stored) ? $stored : null;
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
