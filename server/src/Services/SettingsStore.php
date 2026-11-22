<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Models\Setting;
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
     * Auth holds the Intuit app credentials only, one set per environment (production_client_id,
     * sandbox_client_id, ...).
     * The OAuth redirect and webhook receiver URLs are computed, so a sent or older stored copy, and any sync field
     * such as interval_minutes, is removed. A blank client secret or webhook verifier is omitted. A non-empty secret
     * is kept unchanged.
     *
     * @param array<string, mixed> $auth
     *
     * @return array<string, mixed>
     */
    public function normalizeAuth(array $auth): array
    {
        $normalized = [];
        foreach (SettingsKeys::ENVIRONMENTS as $environment) {
            foreach (SettingsKeys::CREDENTIAL_FIELDS as $name) {
                $field = SettingsKeys::credentialField($environment, $name);
                if (array_key_exists($field, $auth) === false) {
                    continue;
                }
                $value = $auth[$field];
                $blank = is_string($value) === false || trim($value) === '';
                if ($name !== 'client_id' && $blank === true) {
                    continue;
                }
                $normalized[$field] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function companyAuth(string $companyUuid): array
    {
        return $this->normalizeAuth($this->get(SettingsKeys::companyAuth($companyUuid)));
    }

    /**
     * @return array<string, mixed>
     */
    public function adminAuth(): array
    {
        return $this->normalizeAuth($this->get(SettingsKeys::adminAuth()));
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
