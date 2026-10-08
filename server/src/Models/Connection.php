<?php

namespace Fleetbase\Quickbooks\Models;

use Fleetbase\Quickbooks\Support\SecretCipher;
use Illuminate\Support\Carbon;

/**
 * @property string      $company_uuid
 * @property string|null $realm_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property string|null $environment
 * @property string|null $default_item_id
 * @property Carbon|null $rate_limited_until
 * @property int|null    $last_rate_limit_wait
 * @property bool        $needs_reauth
 * @property string|null $home_currency
 * @property Carbon|null $last_batch_at
 */
class Connection extends QuickbooksModel
{
    protected $table = 'quickbooks_connections';

    /** @var array<string, string> */
    protected $casts = [
        'needs_reauth'       => 'boolean',
        'token_expires_at'   => 'datetime',
        'rate_limited_until' => 'datetime',
        'last_batch_at'      => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $connection): void {
            $connection->upgradeLegacyAttribute('access_token');
            $connection->upgradeLegacyAttribute('refresh_token');
        });
    }

    public function save(array $options = []): bool
    {
        $this->upgradeLegacyAttribute('access_token');
        $this->upgradeLegacyAttribute('refresh_token');

        return parent::save($options);
    }

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $this->seal($value);
    }

    public function getAccessTokenAttribute(?string $value): ?string
    {
        return $this->open($value);
    }

    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['refresh_token'] = $this->seal($value);
    }

    public function getRefreshTokenAttribute(?string $value): ?string
    {
        return $this->open($value);
    }

    private function seal(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return (new SecretCipher())->encrypt($value);
    }

    private function open(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return (new SecretCipher())->reveal($value);
    }

    /**
     * Replace a plaintext or pre-Crypt AES-256-CBC token with Laravel Crypt before the row is written.
     * A Crypt payload is left as stored.
     */
    private function upgradeLegacyAttribute(string $attribute): void
    {
        $value = $this->attributes[$attribute] ?? null;
        if (is_string($value) === false || $value === '') {
            return;
        }

        $this->attributes[$attribute] = (new SecretCipher())->seal($value);
    }
}
