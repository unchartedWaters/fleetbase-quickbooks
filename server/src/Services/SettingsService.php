<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SyncSettingsResolver;

class SettingsService
{
    public function __construct(
        private CredentialResolver $credentials,
        private SyncSettingsResolver $sync,
        private SecretCipher $cipher,
    ) {
    }

    /**
     * @param array<string, mixed> $company
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $env
     *
     * @return array<string, mixed>
     */
    public function resolveAuth(array $company, array $admin, array $env): array
    {
        return $this->credentials->resolve(
            $this->decryptAuth($company),
            $this->decryptAuth($admin),
            $env
        );
    }

    /**
     * The app credentials the install connects and refreshes tokens with.
     *
     * @return array{client_id: string, client_secret: string, redirect_uri: string, environment: string}
     */
    public function credentialsFor(SettingsStore $store, string $companyUuid): array
    {
        unset($companyUuid);
        $resolved = $this->resolveAuth([], $store->adminAuth(), $store->envAuth());

        return [
            'client_id'     => (string) $resolved['client_id'],
            'client_secret' => (string) $resolved['client_secret'],
            'redirect_uri'  => (string) $resolved['redirect_uri'],
            'environment'   => (string) $resolved['environment'],
        ];
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function forBrowser(array $stored): array
    {
        return $this->credentials->forBrowser($stored);
    }

    /**
     * Keeps the stored secret when the form submits a blank secret.
     *
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    public function storeAuth(array $incoming, array $existing): array
    {
        $secret = $incoming['client_secret'] ?? '';
        if (is_string($secret) === false || $secret === '') {
            $kept = $existing['client_secret'] ?? '';
            // A blank form field keeps the stored secret. Plaintext and legacy AES are re-encrypted.
            $incoming['client_secret'] = is_string($kept) === true ? $this->cipher->seal($kept) : $kept;
        } else {
            $incoming['client_secret'] = $this->cipher->encrypt($secret);
        }

        return $this->storeWebhookVerifier($incoming, $existing);
    }

    /**
     * The install-wide webhook verifier.
     *
     * The only accepted verifier is the system webhook_verifier. When that
     * row has none, the list is empty and the signature check fails. An
     * organization verifier, another organization's secret,
     * config('quickbooks.webhook_verifier'), and QUICKBOOKS_WEBHOOK_VERIFIER
     * are not substitutes. Only webhook_verifier is decrypted, never
     * client_secret, and no organization auth row is read.
     *
     * @return array<int, string>
     */
    public function webhookVerifiersFor(SettingsStore $store, string $companyUuid): array
    {
        unset($companyUuid);
        $own = $this->plainWebhookVerifier($store->adminAuth());
        if ($own === null) {
            return [];
        }

        return [$own];
    }

    /**
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    private function storeWebhookVerifier(array $incoming, array $existing): array
    {
        $secret = $incoming['webhook_verifier'] ?? null;
        if (is_string($secret) === true && $secret !== '') {
            $incoming['webhook_verifier'] = $this->cipher->encrypt($secret);

            return $incoming;
        }

        $kept = $existing['webhook_verifier'] ?? null;
        if (is_string($kept) === false || $kept === '') {
            unset($incoming['webhook_verifier']);

            return $incoming;
        }

        // A blank form field keeps the stored verifier. Plaintext and legacy AES are re-encrypted.
        $incoming['webhook_verifier'] = $this->cipher->seal($kept);

        return $incoming;
    }

    /**
     * Install-wide sync settings, then config defaults. An organization row is unused.
     *
     * @param array<string, mixed> $company
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    public function resolveSync(array $company, array $admin, array $defaults): array
    {
        return $this->sync->resolve($company, $admin, $defaults);
    }

    /**
     * Decrypts webhook_verifier only. client_secret is left untouched.
     * A value that does not open is missing, not the stored string.
     *
     * @param array<string, mixed> $stored
     */
    private function plainWebhookVerifier(array $stored): ?string
    {
        $secret = $stored['webhook_verifier'] ?? null;
        if (is_string($secret) === false || $secret === '') {
            return null;
        }

        return $this->openSecret($secret);
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    private function decryptAuth(array $stored): array
    {
        foreach (['client_secret', 'webhook_verifier'] as $field) {
            $secret = $stored[$field] ?? null;
            if (is_string($secret) === false || $secret === '') {
                continue;
            }
            $opened = $this->openSecret($secret);
            if ($opened === null) {
                unset($stored[$field]);
                continue;
            }
            $stored[$field] = $opened;
        }

        return $stored;
    }

    /**
     * Crypt payloads and legacy AES blobs open. Anything else, including a decrypt
     * that fails, is missing so the stored text is not used as the secret.
     */
    private function openSecret(string $secret): ?string
    {
        $opened = $this->cipher->reveal($secret);
        if (is_string($opened) === false || $opened === '') {
            return null;
        }

        return $opened;
    }
}
