<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\QuickBooksException;
use Illuminate\Support\Facades\Cache;

class OAuthFlow
{
    private const OTHER_USER_MESSAGE = 'This QuickBooks authorization was started by a different user or organization. Connect again from Connection.';

    private const CONNECT_FAILED_MESSAGE = 'QuickBooks could not finish connecting. Connect again from Connection.';

    public function __construct(private QuickBooksClient $client)
    {
    }

    /**
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     *
     * @return array{url: string, state: string}
     */
    public function begin(string $companyUuid, string $userUuid, array $credentials, bool $importCustomers): array
    {
        $state    = bin2hex(random_bytes(16));
        $verifier = $this->codeVerifier();
        Cache::put($this->key($state), [
            'company_uuid'     => $companyUuid,
            'user_uuid'        => $userUuid,
            'import_customers' => $importCustomers,
            'code_verifier'    => $verifier,
            'expires_at'       => time() + 600,
        ], 600);

        return [
            'url'   => $this->client->authorizationUrl($credentials, $state, $this->codeChallenge($verifier)),
            'state' => $state,
        ];
    }

    /**
     * Keep the code from Intuit's redirect for complete(). The state was returned to whoever
     * started the flow, so the code moves under a new handle that only this browser receives.
     * The PKCE verifier on the pulled state is copied with the rest of the payload.
     *
     * @return string the handle to pass to complete()
     */
    public function receive(string $state, string $code, string $realmId): string
    {
        $stored = $this->validState(Cache::pull($this->key($state)));
        if (isset($stored['code'])) {
            throw new QuickBooksException(400, 'QuickBooks authorization state is invalid or expired.');
        }

        $handle             = bin2hex(random_bytes(16));
        $stored['code']     = $code;
        $stored['realm_id'] = $realmId;
        Cache::put($this->key($handle), $stored, max(1, (int) $stored['expires_at'] - time()));

        return $handle;
    }

    public function forget(string $state): void
    {
        Cache::forget($this->key($state));
    }

    /**
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     *
     * @return array<string, mixed>|null null when the same user already completed this handle
     */
    public function complete(string $handle, string $companyUuid, string $userUuid, array $credentials): ?array
    {
        $key    = $this->key($handle);
        $stored = Cache::get($key);

        if (is_array($stored) && !empty($stored['done'])) {
            if (!$this->startedBy($stored, $companyUuid, $userUuid)) {
                throw new QuickBooksException(403, self::OTHER_USER_MESSAGE);
            }

            return null;
        }

        // Check the initiator before pull. A wrong user must not burn the handle.
        $stored = $this->validState($stored);
        if (!$this->startedBy($stored, $companyUuid, $userUuid)) {
            throw new QuickBooksException(403, self::OTHER_USER_MESSAGE);
        }

        $stored = $this->validState(Cache::pull($key));
        if (!isset($stored['code'])) {
            throw new QuickBooksException(400, 'This QuickBooks authorization link is not valid. Connect again from Connection.');
        }

        try {
            $tokens = $this->client->exchangeCode($credentials, (string) $stored['code'], $this->storedVerifier($stored));
        } catch (QuickBooksException $exception) {
            throw new QuickBooksException($exception->status, self::CONNECT_FAILED_MESSAGE);
        }
        // A reload of the console keeps ?oauth_state in the URL and calls complete() again.
        Cache::put($key, [
            'done'         => true,
            'company_uuid' => $companyUuid,
            'user_uuid'    => $userUuid,
        ], 600);

        $connection = [
            'company_uuid'    => $stored['company_uuid'],
            'realm_id'        => (string) $stored['realm_id'],
            'access_token'    => $tokens['access_token'],
            'refresh_token'   => $tokens['refresh_token'],
            'token_expires_at'=> time() + $tokens['expires_in'],
            'environment'     => $credentials['environment'],
            'needs_reauth'    => false,
        ];

        try {
            $connection['home_currency']   = $this->client->homeCurrency($connection);
            $connection['default_item_id'] = $this->client->ensureServiceItem($connection) ?: null;
        } catch (QuickBooksException $exception) {
            $connection['home_currency']   = null;
            $connection['default_item_id'] = null;
        }

        $connection['import_customers'] = (bool) ($stored['import_customers'] ?? false);

        return $connection;
    }

    /**
     * 32 random bytes as hex is 64 unreserved characters (RFC 7636 allows 43-128).
     */
    private function codeVerifier(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function storedVerifier(array $stored): ?string
    {
        $verifier = $stored['code_verifier'] ?? null;

        return is_string($verifier) && $verifier !== '' ? $verifier : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function validState(mixed $stored): array
    {
        if (!is_array($stored) || (int) ($stored['expires_at'] ?? 0) < time()) {
            throw new QuickBooksException(400, 'QuickBooks authorization state is invalid or expired.');
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function startedBy(array $stored, string $companyUuid, string $userUuid): bool
    {
        return $userUuid !== '' && ($stored['company_uuid'] ?? null) === $companyUuid && ($stored['user_uuid'] ?? null) === $userUuid;
    }

    private function key(string $state): string
    {
        return 'quickbooks.oauth-state.' . $state;
    }
}
