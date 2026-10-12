<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\SettingsKeys;
use Fleetbase\Quickbooks\Support\SettingsValidator;

/**
 * An organization's sync override over the global sync options. Global settings (credentials, verifier,
 * environment, URLs) are never read from or written to an organization here.
 */
class OrganizationSyncSettings
{
    /** @var array<int, string> */
    private const ENTITIES = ['customer', 'invoice', 'payment', 'wallet'];

    public function __construct(
        private SettingsService $settings,
        private SettingsStore $store,
    ) {
    }

    /**
     * What this organization runs with: its override over the global row over the defaults.
     *
     * @return array<string, mixed>
     */
    public function effective(string $companyUuid): array
    {
        return $this->settings->resolveSync(
            $this->store->companySync($companyUuid),
            $this->store->adminSync(),
            $this->store->defaultSync()
        );
    }

    /**
     * Saves or clears the override and returns validation errors. Nothing is written when there are errors.
     * The override is replaced by exactly the non-empty sync fields sent. Anything left out inherits the global value.
     *
     * @return array<string, string>
     */
    public function save(string $companyUuid, bool $override, mixed $sync): array
    {
        if ($override === false) {
            $this->store->clearCompanySync($companyUuid);

            return [];
        }

        $incoming = array_filter(
            SettingsStore::onlySyncFields(is_array($sync) === true ? $sync : []),
            static fn ($value): bool => $value !== null && $value !== ''
        );
        $this->normalizePresentDirections($incoming);

        // Validate the override laid over the inherited values, but report only what the override owns.
        $merged = array_merge($this->globalSync(), $incoming);
        $errors = $this->overrideErrors($incoming, (new SettingsValidator())->syncErrors($merged));
        if ($errors !== []) {
            return $errors;
        }
        $this->store->putCompanySync($companyUuid, $incoming);

        return [];
    }

    /**
     * The organization view: sync options only. No credential, secret flag, environment or URL.
     *
     * @return array<string, mixed>
     */
    public function view(string $companyUuid, bool $canEdit): array
    {
        $row      = $this->store->get(SettingsKeys::companySync($companyUuid));
        $marked   = ($row[SettingsKeys::ORGANIZATION_MARKER] ?? null) === SettingsKeys::ORGANIZATION_MARKER_VALUE;
        $auth     = $this->settings->resolveAuth([], $this->store->adminAuth(), $this->store->envAuth());
        $secret   = $auth['client_secret'] ?? null;
        $clientId = trim((string) ($auth['client_id'] ?? ''));

        return [
            'can_edit'               => $canEdit,
            'override'               => $marked && ($row['override'] ?? false) === true,
            'sync'                   => SettingsStore::onlySyncFields($this->effective($companyUuid)),
            'global_sync'            => SettingsStore::onlySyncFields($this->globalSync()),
            'company_sync'           => $this->overrideForBrowser($this->store->companySync($companyUuid)),
            'credentials_configured' => $clientId !== '' && is_string($secret) === true && trim($secret) !== '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function globalSync(): array
    {
        return $this->settings->resolveSync([], $this->store->adminSync(), $this->store->defaultSync());
    }

    /**
     * Errors for the keys the override sets, plus an entity's conflict, reference and direction errors
     * when that entity's enabled flag is in the override. Inherited problems are not the override's.
     *
     * @param array<string, mixed>  $override
     * @param array<string, string> $errors
     *
     * @return array<string, string>
     */
    private function overrideErrors(array $override, array $errors): array
    {
        foreach (self::ENTITIES as $entity) {
            $key = $entity . '_enabled';
            if (array_key_exists($key, $override) === false) {
                continue;
            }
            $value = $override[$key];
            if (is_bool($value) === false && in_array($value, [1, 0, '1', '0', 'true', 'false'], true) === false) {
                $errors[$key] = 'Choose On or Off.';
            }
        }

        $kept = [];
        foreach ($errors as $key => $message) {
            $own         = array_key_exists($key, $override) === true;
            $entityField = '/^(customer|invoice|payment|wallet)_(conflict|reference|direction)$/';
            if ($own === false && preg_match($entityField, $key, $match) === 1) {
                $own = array_key_exists($match[1] . '_enabled', $override) === true;
            }
            if ($own === true) {
                $kept[$key] = $message;
            }
        }

        return $kept;
    }

    /**
     * An override that names a direction as Off or blank means Both. A direction it leaves out is inherited.
     *
     * @param array<string, mixed> $sync
     */
    private function normalizePresentDirections(array &$sync): void
    {
        foreach (self::ENTITIES as $entity) {
            $field = $entity . '_direction';
            if (array_key_exists($field, $sync) === false) {
                continue;
            }
            if ($sync[$field] === '' || $sync[$field] === 'off' || is_string($sync[$field]) === false) {
                $sync[$field] = 'both';
            }
        }
    }

    /**
     * @param array<string, mixed> $sync
     *
     * @return array<string, mixed>
     */
    private function overrideForBrowser(array $sync): array
    {
        $sync = SettingsStore::onlySyncFields($sync);
        $this->normalizePresentDirections($sync);

        return $sync;
    }
}
