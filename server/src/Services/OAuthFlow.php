<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\QuickBooksException;
use Illuminate\Support\Facades\Cache;

class OAuthFlow
{
    private const OTHER_USER_MESSAGE = 'quickbooks.messages.oauth-other-user';

    private const REALM_PATTERN = '/^\d{6,20}\z/';

    private const CONNECT_FAILED_MESSAGE = 'quickbooks.messages.oauth-connect-failed';

    private const INVALID_LINK_MESSAGE = 'quickbooks.messages.oauth-invalid-link';

    public const CHANGE_COMPANY_MESSAGE = 'quickbooks.messages.oauth-change-company';

    public function __construct(private QuickBooksClient $client)
    {
    }

    /**
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     *
     * @return array{url: string, state: string}
     */
    public function begin(string $companyUuid, string $userUuid, array $credentials): array
    {
        $state    = bin2hex(random_bytes(16));
        $verifier = $this->codeVerifier();
        // Intuit requires the token request to repeat the redirect_uri sent here exactly,
        // so complete() uses this one even if the saved URL changes in between.
        Cache::put($this->key($state), [
            'company_uuid'  => $companyUuid,
            'user_uuid'     => $userUuid,
            'code_verifier' => $verifier,
            'redirect_uri'  => $credentials['redirect_uri'],
            'expires_at'    => time() + 600,
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
        if (isset($stored['code']) === true) {
            throw new QuickBooksException(400, 'quickbooks.messages.oauth-state-invalid');
        }

        // The realm comes from a browser redirect. It is a number, and it becomes part of API URLs.
        if (preg_match(self::REALM_PATTERN, $realmId) !== 1) {
            throw new QuickBooksException(400, 'quickbooks.messages.oauth-state-invalid');
        }

        $handle             = bin2hex(random_bytes(16));
        $stored['code']     = $code;
        $stored['realm_id'] = $realmId;
        Cache::put($this->key($handle), $stored, max(1, (int) $stored['expires_at'] - time()));

        return $handle;
    }

    /**
     * @return string|null the organization that started this state, if it is still known
     */
    public function forget(string $state): ?string
    {
        $stored = Cache::pull($this->key($state));

        $company = is_array($stored) === true ? ($stored['company_uuid'] ?? null) : null;

        return is_string($company) === true ? $company : null;
    }

    /**
     * $realmAllowed sees the QuickBooks company (realm) Intuit returned before the code is
     * exchanged. When it says no, the handle is dropped and no token is requested.
     *
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     * @param (callable(string): bool)|null                                                              $realmAllowed
     *
     * @return array<string, mixed>|null null when the same user already completed this handle
     */
    public function complete(
        string $handle,
        string $companyUuid,
        string $userUuid,
        array $credentials,
        ?callable $realmAllowed = null,
    ): ?array {
        $key    = $this->key($handle);
        $stored = Cache::get($key);

        if (is_array($stored) === true && empty($stored['done']) === false) {
            if ($this->startedBy($stored, $companyUuid, $userUuid) === false) {
                throw new QuickBooksException(403, self::OTHER_USER_MESSAGE);
            }

            return null;
        }

        // Check the initiator before pull. A wrong user must not burn the handle.
        $stored = $this->validState($stored);
        if ($this->startedBy($stored, $companyUuid, $userUuid) === false) {
            throw new QuickBooksException(403, self::OTHER_USER_MESSAGE);
        }

        $stored = $this->validState(Cache::pull($key));
        if (isset($stored['code']) === false) {
            throw new QuickBooksException(400, self::INVALID_LINK_MESSAGE);
        }
        if ($realmAllowed !== null && $realmAllowed((string) $stored['realm_id']) !== true) {
            throw new QuickBooksException(403, self::CHANGE_COMPANY_MESSAGE);
        }

        $connection = $this->withCompanyDetails($this->exchange($stored, $credentials));

        // A reload of the console keeps ?oauth_state in the URL and calls complete() again.
        Cache::put($key, [
            'done'         => true,
            'company_uuid' => $companyUuid,
            'user_uuid'    => $userUuid,
        ], 600);

        return $connection;
    }

    /**
     * Trade the stored code for tokens, sending the redirect_uri saved when the flow began.
     *
     * @param array<string, mixed>                                                                       $stored
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     *
     * @return array<string, mixed>
     */
    private function exchange(array $stored, array $credentials): array
    {
        $credentials = $this->withStartedRedirect($credentials, $stored);
        try {
            $tokens = $this->client->exchangeCode($credentials, (string) $stored['code'], $this->storedVerifier($stored));
        } catch (QuickBooksException $exception) {
            throw new QuickBooksException($exception->status, self::CONNECT_FAILED_MESSAGE);
        }

        return [
            'company_uuid'    => $stored['company_uuid'],
            'realm_id'        => (string) $stored['realm_id'],
            'access_token'    => $tokens['access_token'],
            'refresh_token'   => $tokens['refresh_token'],
            'token_expires_at'=> time() + $tokens['expires_in'],
            'environment'     => $credentials['environment'],
            'needs_reauth'    => false,
        ];
    }

    /**
     * The first Intuit call for the new realm answering 401, 403 or 404 means the token does not
     * belong to the realm that came back on the redirect, so nothing is stored. Any other failure
     * (network, 5xx, rate limit) is tolerated and the details are filled in on a later sync.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    private function withCompanyDetails(array $connection): array
    {
        $connection['home_currency']        = null;
        $connection['default_item_id']      = null;
        $connection['default_item_account'] = null;

        try {
            $homeCurrency = $this->client->homeCurrency($connection);
        } catch (QuickBooksException $exception) {
            if (in_array($exception->status, [401, 403, 404], true) === true) {
                throw new QuickBooksException($exception->status, self::CONNECT_FAILED_MESSAGE);
            }

            return $connection;
        }

        try {
            $defaultItemId = $this->client->ensureServiceItem($connection);
        } catch (QuickBooksException) {
            return $connection;
        }

        $connection['home_currency']   = $homeCurrency;
        $connection['default_item_id'] = ($defaultItemId !== '' && $defaultItemId !== '0') ? $defaultItemId : null;
        if ($connection['default_item_id'] !== null) {
            $connection['default_item_account'] = $this->incomeAccount($connection, $defaultItemId);
        }

        return $connection;
    }

    /**
     * The name of the income account the service item posts to, for the connection card. A failed
     * read leaves it for the next invoice sync to fill in.
     *
     * @param array<string, mixed> $connection
     */
    private function incomeAccount(array $connection, string $itemId): ?string
    {
        try {
            $item = $this->client->item($connection, $itemId);
        } catch (QuickBooksException) {
            return null;
        }
        $name = is_array($item) === true ? trim((string) ($item['IncomeAccountRef']['name'] ?? '')) : '';

        return $name !== '' ? $name : null;
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
     * A state saved before the redirect_uri was kept with it uses the current one.
     *
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     * @param array<string, mixed>                                                                       $stored
     *
     * @return array{client_id: string, client_secret: string, redirect_uri: string, environment: string}
     */
    private function withStartedRedirect(array $credentials, array $stored): array
    {
        $redirect = $stored['redirect_uri'] ?? null;
        if (is_string($redirect) === true && $redirect !== '') {
            $credentials['redirect_uri'] = $redirect;
        }

        return $credentials;
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function storedVerifier(array $stored): ?string
    {
        $verifier = $stored['code_verifier'] ?? null;

        return is_string($verifier) === true && $verifier !== '' ? $verifier : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function validState(mixed $stored): array
    {
        if (is_array($stored) === false || (int) ($stored['expires_at'] ?? 0) < time()) {
            throw new QuickBooksException(400, 'quickbooks.messages.oauth-state-invalid');
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
