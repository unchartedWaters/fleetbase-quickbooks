<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\SettingsValidator;

/**
 * An organization's own copy of the sync options. The global sync row is only a template: it is copied
 * to an organization when it connects, saves, or resets, and later global edits do not change the copy.
 * Global settings (credentials, verifier, environment, URLs) are never read from or written to an organization here.
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
     * What this organization runs with: its own copy, or the template while it has none.
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
     * Gives the organization its own copy of its current effective settings. An existing copy is kept.
     */
    public function ensureCopy(string $companyUuid): void
    {
        if ($this->store->hasCompanyCopy($companyUuid) === true) {
            return;
        }
        $this->store->putCompanySync($companyUuid, SettingsStore::onlySyncFields($this->effective($companyUuid)));
    }

    /**
     * Saves the effective settings with the submitted non-empty sync fields applied, as a complete copy.
     * Returns validation errors; nothing is written when there are errors.
     *
     * @return array<string, string>
     */
    public function save(string $companyUuid, mixed $sync): array
    {
        $incoming = array_filter(
            SettingsStore::onlySyncFields(is_array($sync) === true ? $sync : []),
            static fn ($value): bool => $value !== null && $value !== ''
        );
        $this->normalizePresentDirections($incoming);

        $full   = array_merge(SettingsStore::onlySyncFields($this->effective($companyUuid)), $incoming);
        $errors = array_merge($this->enabledErrors($incoming), (new SettingsValidator())->syncErrors($full));
        if ($errors !== []) {
            return $errors;
        }
        $this->store->putCompanySync($companyUuid, $full);

        return [];
    }

    /**
     * Replaces the organization's settings with a one-time copy of the current template.
     */
    public function reset(string $companyUuid): void
    {
        $this->store->putCompanySync($companyUuid, $this->template());
    }

    /**
     * The organization view: sync options only. No credential, secret flag, environment or URL.
     *
     * @return array<string, mixed>
     */
    public function view(string $companyUuid, bool $canEdit): array
    {
        $auth     = $this->settings->resolveAuth([], $this->store->adminAuth(), $this->store->envAuth());
        $secret   = $auth['client_secret'] ?? null;
        $clientId = trim((string) ($auth['client_id'] ?? ''));

        return [
            'can_edit'               => $canEdit,
            'sync'                   => SettingsStore::onlySyncFields($this->effective($companyUuid)),
            'has_copy'               => $this->store->hasCompanyCopy($companyUuid),
            'credentials_configured' => $clientId !== '' && is_string($secret) === true && trim($secret) !== '',
        ];
    }

    /**
     * The global sync row over the defaults: what a new copy starts from.
     *
     * @return array<string, mixed>
     */
    private function template(): array
    {
        return SettingsStore::onlySyncFields(
            $this->settings->resolveSync([], $this->store->adminSync(), $this->store->defaultSync())
        );
    }

    /**
     * An enabled flag that was sent must be boolean-like.
     *
     * @param array<string, mixed> $incoming
     *
     * @return array<string, string>
     */
    private function enabledErrors(array $incoming): array
    {
        $errors = [];
        foreach (self::ENTITIES as $entity) {
            $key = $entity . '_enabled';
            if (array_key_exists($key, $incoming) === false) {
                continue;
            }
            $value = $incoming[$key];
            if (is_bool($value) === false && in_array($value, [1, 0, '1', '0', 'true', 'false'], true) === false) {
                $errors[$key] = 'quickbooks.validation.on-off';
            }
        }

        return $errors;
    }

    /**
     * A direction the submission sets to off or blank means Both.
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
}
