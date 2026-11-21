<?php

namespace Fleetbase\Quickbooks\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts client secrets and tokens with Laravel's authenticated encryption (APP_KEY).
 */
class SecretCipher
{
    public function encrypt(string $plain): string
    {
        $this->requireAppKey();

        return Crypt::encryptString($plain);
    }

    public function decrypt(string $payload): string
    {
        $this->requireAppKey();

        try {
            return Crypt::decryptString($payload);
        } catch (DecryptException) {
            throw new \RuntimeException('Unable to decrypt QuickBooks secret.');
        }
    }

    /**
     * Store a secret with Laravel Crypt. A Crypt payload is unchanged; anything else is encrypted.
     */
    public function seal(string $payload): string
    {
        if ($payload === '' || $this->isCryptPayload($payload) === true) {
            return $payload;
        }

        return $this->encrypt($payload);
    }

    /**
     * Open a stored secret. Null when it is not a Crypt payload or does not decrypt,
     * so a raw string is never used as a live client secret or token.
     */
    public function reveal(string $payload): ?string
    {
        if ($payload === '') {
            return '';
        }
        if ($this->isCryptPayload($payload) === false) {
            return null;
        }

        try {
            return $this->decrypt($payload);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function isCryptPayload(string $payload): bool
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false || str_starts_with($decoded, '{') === false) {
            return false;
        }

        $json = json_decode($decoded, true);

        return is_array($json) === true
            && isset($json['iv'], $json['value'], $json['mac']) === true
            && is_string($json['iv']) === true
            && is_string($json['value']) === true
            && is_string($json['mac']) === true;
    }

    private function requireAppKey(): void
    {
        if ((string) config('app.key', '') === '') {
            throw new \RuntimeException('APP_KEY must be set before QuickBooks secrets can be encrypted.');
        }
    }
}
