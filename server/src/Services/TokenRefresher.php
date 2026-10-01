<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\QuickBooksException;

class TokenRefresher
{
    public const REFRESH_WINDOW_SECONDS = 300;

    public const CREDENTIALS_MESSAGE = 'QuickBooks refused the app credentials. Check Client ID and Client secret on Connection.';

    public const UNAVAILABLE_MESSAGE = 'QuickBooks could not refresh its access token. Fleetbase will try again.';

    public function __construct(private QuickBooksClient $client)
    {
    }

    /**
     * @param array<string, mixed> $connection
     */
    public function isDue(array $connection, int $now): bool
    {
        $expiresAt = $connection['token_expires_at'] ?? null;

        return $expiresAt === null || (int) $expiresAt - $now <= self::REFRESH_WINDOW_SECONDS;
    }

    /**
     * Only invalid_grant means the refresh token is dead and the user must reconnect.
     * Any other 400 or 401 (e.g. invalid_client) points at the app credentials, and
     * other failures are temporary. Both set refresh_error and keep the old tokens.
     *
     * @param array<string, mixed>                                                                       $connection
     * @param array{client_id: string, client_secret: string, redirect_uri: string, environment: string} $credentials
     *
     * @return array<string, mixed>
     */
    public function refresh(array $connection, array $credentials): array
    {
        unset($connection['refresh_error']);
        try {
            $tokens                         = $this->client->refresh($credentials, (string) ($connection['refresh_token'] ?? ''));
            $connection['access_token']     = $tokens['access_token'];
            $connection['refresh_token']    = $tokens['refresh_token'];
            $connection['token_expires_at'] = time() + $tokens['expires_in'];
            $connection['needs_reauth']     = false;
        } catch (QuickBooksException $exception) {
            if (str_contains($exception->getMessage(), 'invalid_grant')) {
                $connection['needs_reauth'] = true;
            } elseif (in_array($exception->status, [400, 401], true)) {
                $connection['refresh_error'] = self::CREDENTIALS_MESSAGE;
            } else {
                $connection['refresh_error'] = self::UNAVAILABLE_MESSAGE;
            }
        }

        return $connection;
    }
}
