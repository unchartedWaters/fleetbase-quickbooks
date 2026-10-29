<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Models\Setting;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;

class SettingsStore
{
    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        if (class_exists(Setting::class) === false) {
            return [];
        }

        $value = Setting::lookup($key, []);

        return is_array($value) === true ? $value : [];
    }

    /**
     * @param array<string, mixed> $value
     */
    public function put(string $key, array $value): void
    {
        if (str_ends_with($key, '.quickbooks.auth') === true) {
            $value = $this->normalizeAuth($value);
        }

        Setting::configure($key, $value);
    }

    /**
     * Auth holds app credentials and the saved public webhook and OAuth URLs.
     * A blank client secret, webhook verifier, or public URL is omitted.
     * Sync fields such as interval_minutes are removed. A non-empty secret is kept unchanged.
     *
     * @param array<string, mixed> $auth
     *
     * @return array<string, mixed>
     */
    public function normalizeAuth(array $auth): array
    {
        $normalized = [];
        foreach (['client_id', 'client_secret', 'redirect_uri', 'environment', 'webhook_verifier', 'public_webhook_receiver_url', 'public_oauth_redirect_url'] as $field) {
            if (array_key_exists($field, $auth) === false) {
                continue;
            }
            $value = $auth[$field];
            if (in_array($field, ['client_secret', 'webhook_verifier', 'public_webhook_receiver_url', 'public_oauth_redirect_url'], true) === true && (is_string($value) === false || trim($value) === '')) {
                continue;
            }
            $normalized[$field] = in_array($field, ['public_webhook_receiver_url', 'public_oauth_redirect_url'], true) === true ? trim((string) $value) : $value;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function companyAuth(string $companyUuid): array
    {
        return $this->upgradeStoredSecrets(SettingsKeys::companyAuth($companyUuid));
    }

    /**
     * @return array<string, mixed>
     */
    public function adminAuth(): array
    {
        return $this->upgradeStoredSecrets(SettingsKeys::adminAuth());
    }

    /**
     * Legacy AES-256-CBC secrets still open. On a successful read they are
     * re-encrypted with Laravel Crypt and written back so CBC is not stored.
     *
     * @return array<string, mixed>
     */
    private function upgradeStoredSecrets(string $key): array
    {
        $stored  = $this->get($key);
        $cipher  = new SecretCipher();
        $changed = false;
        foreach (['client_secret', 'webhook_verifier'] as $field) {
            $value = $stored[$field] ?? null;
            if (is_string($value) === false || $value === '') {
                continue;
            }
            $opened = $cipher->read($value);
            if ($opened === null || $opened['stored'] === $value) {
                continue;
            }
            $stored[$field] = $opened['stored'];
            $changed        = true;
        }
        if ($changed === true && class_exists(Setting::class) === true) {
            try {
                Setting::configure($key, $stored);
            } catch (\Throwable) {
                // The value in memory is already Crypt. A later read retries the write.
            }
        }

        return $this->normalizeAuth($stored);
    }

    /**
     * @return array<string, string>
     */
    public function envAuth(): array
    {
        return [
            'environment' => $this->configuredEnvironment(),
        ];
    }

    /**
     * New setups use production when no environment is stored or configured.
     * An explicit sandbox value in config is kept.
     */
    private function configuredEnvironment(): string
    {
        $environment = config('quickbooks.environment', 'production');
        if (is_string($environment) === false || trim($environment) === '') {
            return 'production';
        }

        return $environment;
    }

    /**
     * This organization's own sync settings, or [] while it has none (it then reads the template).
     *
     * @return array<string, mixed>
     */
    public function companySync(string $companyUuid): array
    {
        return self::syncFromRow($this->get(SettingsKeys::companySync($companyUuid)));
    }

    /**
     * What a stored organization row means. A complete copy is the organization's own settings.
     * A legacy row (override=true) keeps its fields and inherits the rest; any other legacy row reads as none.
     * Both predate the fields in SettingsKeys::LATER_SYNC_FIELDS, so a missing one reads as it was before.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function syncFromRow(array $row): array
    {
        if (($row[SettingsKeys::ORGANIZATION_MARKER] ?? null) !== SettingsKeys::ORGANIZATION_MARKER_VALUE) {
            return [];
        }
        $complete = self::isCompleteCopy($row);
        if ($complete === false && ($row['override'] ?? false) !== true) {
            return [];
        }
        foreach (SettingsKeys::LATER_SYNC_FIELDS as $field => $before) {
            $row[$field] = isset($row[$field]) === true && $row[$field] !== '' ? $row[$field] : $before;
        }

        return self::onlySyncFields($row);
    }

    /**
     * A complete copy carries the organization marker and every sync field with a real value, but
     * those added later (SettingsKeys::LATER_SYNC_FIELDS), which syncFromRow fills in.
     *
     * @param array<string, mixed> $row
     */
    public static function isCompleteCopy(array $row): bool
    {
        if (($row[SettingsKeys::ORGANIZATION_MARKER] ?? null) !== SettingsKeys::ORGANIZATION_MARKER_VALUE) {
            return false;
        }
        foreach (SettingsKeys::SYNC_FIELDS as $field) {
            if (array_key_exists($field, SettingsKeys::LATER_SYNC_FIELDS) === true) {
                continue;
            }
            if (isset($row[$field]) === false || $row[$field] === '') {
                return false;
            }
        }

        return true;
    }

    public function hasCompanyCopy(string $companyUuid): bool
    {
        return self::isCompleteCopy($this->get(SettingsKeys::companySync($companyUuid)));
    }

    /**
     * Saves this organization's complete sync settings. Only sync options are kept.
     *
     * @param array<string, mixed> $sync
     */
    public function putCompanySync(string $companyUuid, array $sync): void
    {
        $this->put(SettingsKeys::companySync($companyUuid), array_merge(self::onlySyncFields($sync), [
            SettingsKeys::ORGANIZATION_MARKER => SettingsKeys::ORGANIZATION_MARKER_VALUE,
        ]));
    }

    /**
     * @param array<string, mixed> $sync
     *
     * @return array<string, mixed>
     */
    public static function onlySyncFields(array $sync): array
    {
        return array_intersect_key($sync, array_fill_keys(SettingsKeys::SYNC_FIELDS, true));
    }

    /**
     * @return array<string, mixed>
     */
    public function adminSync(): array
    {
        return $this->get(SettingsKeys::adminSync());
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultSync(): array
    {
        $sync = config('quickbooks.sync', []);

        return is_array($sync) === true ? $sync : [];
    }
}
