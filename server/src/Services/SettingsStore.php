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
     * @return array<string, string>
     */
    public function envAuth(): array
    {
        return [
            'client_id'        => (string) config('quickbooks.client_id', ''),
            'client_secret'    => (string) config('quickbooks.client_secret', ''),
            'redirect_uri'     => (string) config('quickbooks.redirect_uri', ''),
            'environment'      => $this->configuredEnvironment(),
            'webhook_verifier' => $this->envWebhookVerifier(),
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
     * The Intuit webhook verifier. Config wins when it is set; otherwise the
     * process environment QUICKBOOKS_WEBHOOK_VERIFIER.
     */
    public function envWebhookVerifier(): string
    {
        $configured = config('quickbooks.webhook_verifier');
        if (is_string($configured) === true && $configured !== '') {
            return $configured;
        }

        foreach ([
            getenv('QUICKBOOKS_WEBHOOK_VERIFIER'),
            $_ENV['QUICKBOOKS_WEBHOOK_VERIFIER'] ?? null,
            $_SERVER['QUICKBOOKS_WEBHOOK_VERIFIER'] ?? null,
        ] as $value) {
            if (is_string($value) === true && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function companySync(string $companyUuid): array
    {
        return $this->get(SettingsKeys::companySync($companyUuid));
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
