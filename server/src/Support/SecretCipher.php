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
        } catch (DecryptException $exception) {
            // Values saved before we switched to Crypt used plain AES-256-CBC. Open those once
            // so existing secrets and tokens keep working; they are re-encrypted on next save.
            if ($this->isLegacyCiphertext($payload) === false) {
                throw new \RuntimeException('Unable to decrypt QuickBooks secret.');
            }

            return $this->decryptLegacy($payload);
        }
    }

    /**
     * True only for the old iv+AES-256-CBC blob when it opens with the legacy key.
     * Laravel Crypt payloads and other base64 strings are left alone.
     */
    public function isLegacyCiphertext(string $payload): bool
    {
        if ($payload === '' || $this->isCryptPayload($payload) === true) {
            return false;
        }

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            return false;
        }

        $plain = @openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $this->legacyKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));

        return is_string($plain);
    }

    /**
     * Re-encrypt a legacy blob with Laravel Crypt. A Crypt payload is returned unchanged.
     * A value that does not open with the legacy key is returned unchanged.
     */
    public function upgrade(string $payload): string
    {
        if ($this->isLegacyCiphertext($payload) === false) {
            return $payload;
        }

        return $this->encrypt($this->decrypt($payload));
    }

    /**
     * Store a secret with Laravel Crypt.
     * A Crypt payload is unchanged. A legacy AES blob is upgraded.
     * Any other readable value is plaintext and is encrypted.
     */
    public function seal(string $payload): string
    {
        if ($payload === '' || $this->isCryptPayload($payload) === true) {
            return $payload;
        }
        if ($this->isLegacyCiphertext($payload) === true) {
            return $this->upgrade($payload);
        }

        return $this->encrypt($payload);
    }

    /**
     * Open a stored secret.
     * A Crypt payload and a legacy AES blob decrypt to the original value.
     * After a failed decrypt, or when the value is neither of those, the result is
     * null. The raw string is not a live client secret or token.
     * seal() still encrypts that string on the next save so an older row can be upgraded.
     */
    public function reveal(string $payload): ?string
    {
        if ($payload === '') {
            return $payload;
        }
        if ($this->isCryptPayload($payload) === false && $this->isLegacyCiphertext($payload) === false) {
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

    private function decryptLegacy(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            throw new \RuntimeException('Unable to decrypt QuickBooks secret.');
        }

        $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $this->legacyKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        if ($plain === false) {
            throw new \RuntimeException('Unable to decrypt QuickBooks secret.');
        }

        return $plain;
    }

    private function legacyKey(): string
    {
        $configured = (string) config('app.key', '');
        if (str_starts_with($configured, 'base64:') === true) {
            $decoded    = base64_decode(substr($configured, 7), true);
            $configured = $decoded === false ? $configured : $decoded;
        }

        return substr(hash('sha256', $configured, true), 0, 32);
    }
}
