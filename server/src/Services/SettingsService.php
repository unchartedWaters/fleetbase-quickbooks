<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\CredentialResolver;
use Fleetbase\Quickbooks\Support\SecretCipher;
use Fleetbase\Quickbooks\Support\SettingsKeys;
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
     * The app credentials the install connects and refreshes tokens with, for one Intuit environment.
     *
     * @return array{client_id: string, client_secret: string, redirect_uri: string, environment: string}
     */
    public function credentialsFor(SettingsStore $store, string $environment): array
    {
        $environment = SettingsKeys::environment($environment);
        $resolved    = $this->credentials->resolve($this->decryptAuth($store->adminAuth()), $environment);

        return [
            'client_id'     => $resolved['client_id'],
            'client_secret' => $resolved['client_secret'],
            'redirect_uri'  => $resolved['redirect_uri'],
            'environment'   => $resolved['environment'],
        ];
    }

    /**
     * The saved keys of every environment with their secrets opened, for the settings page to show what is set.
     * A secret that does not open is missing.
     *
     * @return array<string, mixed>
     */
    public function plainAuth(SettingsStore $store): array
    {
        return $this->decryptAuth($store->adminAuth());
    }

    /**
     * The environments whose Client ID and Client secret are both saved, in the order they are offered.
     *
     * @return array<int, string>
     */
    public function configuredEnvironments(SettingsStore $store): array
    {
        $configured = [];
        foreach (SettingsKeys::ENVIRONMENTS as $environment) {
            $credentials = $this->credentialsFor($store, $environment);
            if (trim($credentials['client_id']) !== '' && trim($credentials['client_secret']) !== '') {
                $configured[] = $environment;
            }
        }

        return $configured;
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
     * Seals what the form sent. A blank secret or verifier keeps the stored one, re-sealed.
     *
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    public function storeAuth(array $incoming, array $existing): array
    {
        foreach (SettingsKeys::ENVIRONMENTS as $environment) {
            $secret = SettingsKeys::credentialField($environment, 'client_secret');
            $typed  = $incoming[$secret] ?? '';
            if (is_string($typed) === false || $typed === '') {
                $kept = $existing[$secret] ?? '';
                // A blank form field keeps the stored secret. Plaintext and legacy AES are re-encrypted.
                if (is_string($kept) === true && $kept !== '') {
                    $incoming[$secret] = $this->cipher->seal($kept);
                } else {
                    unset($incoming[$secret]);
                }
            } else {
                $incoming[$secret] = $this->cipher->encrypt($typed);
            }
            $incoming = $this->storeWebhookVerifier($incoming, $existing, $environment);
        }

        return $incoming;
    }

    /**
     * Every webhook verifier saved for the install, one per environment that has one.
     *
     * Intuit signs a notification with the verifier of the app that sent it (Development or Production), and the
     * notification does not say which, so a signature is good when any saved verifier matches. When none is
     * saved, the list is empty and the signature check fails. An organization verifier and the
     * QUICKBOOKS_WEBHOOK_VERIFIER environment variable are not substitutes. Only verifiers are decrypted, never
     * client secrets.
     *
     * @return array<int, string>
     */
    public function webhookVerifiersFor(SettingsStore $store): array
    {
        $admin     = $store->adminAuth();
        $verifiers = [];
        foreach (SettingsKeys::ENVIRONMENTS as $environment) {
            $own = $this->plainWebhookVerifier($admin, $environment);
            if ($own !== null) {
                $verifiers[] = $own;
            }
        }

        return $verifiers;
    }

    /**
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    private function storeWebhookVerifier(array $incoming, array $existing, string $environment): array
    {
        $field  = SettingsKeys::credentialField($environment, 'webhook_verifier');
        $secret = $incoming[$field] ?? null;
        if (is_string($secret) === true && $secret !== '') {
            $incoming[$field] = $this->cipher->encrypt($secret);

            return $incoming;
        }

        $kept = $existing[$field] ?? null;
        if (is_string($kept) === false || $kept === '') {
            unset($incoming[$field]);

            return $incoming;
        }

        // A blank form field keeps the stored verifier. Plaintext and legacy AES are re-encrypted.
        $incoming[$field] = $this->cipher->seal($kept);

        return $incoming;
    }

    /**
     * Config defaults, then the global sync template, then the organization's own copy, per field.
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
     * Decrypts one environment's webhook_verifier only. A value that does not open is missing, not the stored string.
     *
     * @param array<string, mixed> $stored
     */
    private function plainWebhookVerifier(array $stored, string $environment): ?string
    {
        $secret = $stored[SettingsKeys::credentialField($environment, 'webhook_verifier')] ?? null;
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
        foreach (SettingsKeys::ENVIRONMENTS as $environment) {
            foreach (['client_secret', 'webhook_verifier'] as $name) {
                $field  = SettingsKeys::credentialField($environment, $name);
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
