<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Resolves Intuit app credentials without reading the session.
 * The install-wide system row is the only source. It holds one set of keys per Intuit environment
 * (production_client_id, sandbox_client_id, ...), and a blank field stays blank. The OAuth redirect is
 * not stored: it is left blank here and set by the caller that starts OAuth.
 *
 * @phpstan-type ResolvedAuth array{
 *     client_id: string,
 *     client_secret: string,
 *     redirect_uri: string,
 *     environment: string,
 *     webhook_verifier: string
 * }
 */
class CredentialResolver
{
    /**
     * @param array<string, mixed> $admin
     *
     * @return ResolvedAuth
     */
    public function resolve(array $admin, string $environment): array
    {
        return [
            'client_id'        => $this->storedValue($admin, $environment, 'client_id'),
            'client_secret'    => $this->storedValue($admin, $environment, 'client_secret'),
            'redirect_uri'     => '',
            'environment'      => $environment,
            'webhook_verifier' => $this->storedValue($admin, $environment, 'webhook_verifier'),
        ];
    }

    /**
     * What the browser may see of the stored keys: each environment's Client ID, and only whether its secret and
     * verifier are saved. The secrets themselves never leave the server.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function forBrowser(array $stored): array
    {
        $copy = [];
        foreach (SettingsKeys::ENVIRONMENTS as $environment) {
            $clientId = SettingsKeys::credentialField($environment, 'client_id');
            $secret   = SettingsKeys::credentialField($environment, 'client_secret');
            $verifier = SettingsKeys::credentialField($environment, 'webhook_verifier');

            $copy[$clientId]            = $stored[$clientId] ?? '';
            $copy[$secret . '_set']     = isset($stored[$secret]) === true && $stored[$secret] !== '';
            $copy[$verifier . '_set']   = isset($stored[$verifier]) === true && $stored[$verifier] !== '';
        }

        return $copy;
    }

    /**
     * System value only. A blank field stays blank.
     *
     * @param array<string, mixed> $admin
     */
    private function storedValue(array $admin, string $environment, string $name): string
    {
        $value = $admin[SettingsKeys::credentialField($environment, $name)] ?? null;

        return is_string($value) === true ? $value : '';
    }
}
