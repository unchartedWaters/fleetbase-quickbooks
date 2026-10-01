<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Resolves Intuit app credentials without reading the session.
 * Organization values are the only source for client id, client secret,
 * redirect URI, and webhook verifier. A blank organization field stays blank.
 * A stored system row is unused. Environment may fall back to the env value.
 *
 * @phpstan-type ResolvedAuth array{client_id: string, client_secret: string, redirect_uri: string, environment: string, webhook_verifier: string, sources: array<string, string>}
 */
class CredentialResolver
{
    /**
     * @param array<string, mixed> $company
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $env
     *
     * @return ResolvedAuth
     */
    public function resolve(array $company, array $admin, array $env): array
    {
        unset($admin);
        $clientId    = $this->companyValue($company, 'client_id');
        $secret      = $this->companyValue($company, 'client_secret');
        $redirectUri = $this->companyValue($company, 'redirect_uri');
        $environment = $this->pick($company, $env, 'environment');
        $verifier    = $this->companyValue($company, 'webhook_verifier');

        return [
            'client_id'        => $clientId['value'],
            'client_secret'    => $secret['value'],
            'redirect_uri'     => $redirectUri['value'],
            'environment'      => $environment['value'],
            'webhook_verifier' => $verifier['value'],
            'sources'          => [
                'client_id'        => $clientId['source'],
                'client_secret'    => $secret['source'],
                'redirect_uri'     => $redirectUri['source'],
                'environment'      => $environment['source'],
                'webhook_verifier' => $verifier['source'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function forBrowser(array $stored): array
    {
        $copy                         = $stored;
        $copy['client_secret_set']    = isset($stored['client_secret']) && $stored['client_secret'] !== '';
        $copy['webhook_verifier_set'] = isset($stored['webhook_verifier']) && $stored['webhook_verifier'] !== '';
        unset(
            $copy['client_secret'],
            $copy['webhook_verifier'],
            $copy['public_receiver_url'],
            $copy['webhook_url'],
            $copy['internal_webhook_receiver_url'],
            $copy['public_webhook_receiver_url'],
            $copy['internal_oauth_redirect_url'],
            $copy['public_oauth_redirect_url']
        );

        return $copy;
    }

    /**
     * Organization value only. A blank field stays blank and does not read env.
     *
     * @param array<string, mixed> $company
     *
     * @return array{value: string, source: string}
     */
    private function companyValue(array $company, string $field): array
    {
        $value = $company[$field] ?? null;
        if (is_string($value) && $value !== '') {
            return ['value' => $value, 'source' => 'company'];
        }

        return ['value' => '', 'source' => 'none'];
    }

    /**
     * @param array<string, mixed> $company
     * @param array<string, mixed> $env
     *
     * @return array{value: string, source: string}
     */
    private function pick(array $company, array $env, string $field): array
    {
        foreach (['company' => $company, 'env' => $env] as $source => $bag) {
            $value = $bag[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return ['value' => $value, 'source' => $source];
            }
        }

        return ['value' => '', 'source' => 'none'];
    }
}
