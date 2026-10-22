<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Maps a Fleetbase wallet snapshot onto a QuickBooks Chart of Accounts entry.
 */
class WalletMapper
{
    public const ACCT_NUM_MAX_LENGTH = 7;

    public const NAME_MAX_LENGTH = 100;

    /**
     * @param array<string, mixed> $wallet
     * @param bool                 $pushClears include an empty description so a Fleetbase clear is sent
     *
     * @return array<string, mixed>
     */
    public function toQuickBooks(array $wallet, string $reference = 'fleetbase', bool $pushClears = false): array
    {
        $name = trim((string) ($wallet['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($wallet['public_id'] ?? 'Wallet'));
        }

        $payload = [
            'Name'        => $name,
            'AccountType' => 'Other Current Asset',
            'Active'      => $this->isActive($wallet),
        ];

        $description = trim((string) ($wallet['description'] ?? ''));
        if ($description !== '') {
            $payload['Description'] = $description;
        } elseif ($pushClears === true) {
            $payload['Description'] = '';
        }

        $currency = strtoupper(trim((string) ($wallet['currency'] ?? '')));
        if ($currency !== '') {
            $payload['CurrencyRef'] = ['value' => $currency];
        }

        if ($reference === 'fleetbase') {
            $acctNum = trim((string) ($wallet['public_id'] ?? ''));
            if ($acctNum !== '' && strlen($acctNum) <= self::ACCT_NUM_MAX_LENGTH) {
                $payload['AcctNum'] = $acctNum;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $wallet
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     */
    public function matches(array $wallet, array $payload, array $remote): bool
    {
        $payload = $this->keepUniqueName($wallet, $payload, $remote);

        return ContentHash::of($this->localCanonical($wallet, $payload)) === ContentHash::of($this->remoteCanonical($wallet, $payload, $remote));
    }

    /**
     * @param array<string, mixed> $wallet
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function localCanonical(array $wallet, array $payload): array
    {
        return [
            'acct'        => trim((string) ($payload['AcctNum'] ?? '')),
            'active'      => $this->isActive($wallet),
            'currency'    => strtoupper(trim((string) ($wallet['currency'] ?? ''))),
            'description' => (string) ($wallet['description'] ?? ''),
            'name'        => (string) ($payload['Name'] ?? ''),
        ];
    }

    /**
     * Empty currency or account number on either side is not a difference.
     *
     * @param array<string, mixed> $wallet
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private function remoteCanonical(array $wallet, array $payload, array $remote): array
    {
        $localCurrency  = strtoupper(trim((string) ($wallet['currency'] ?? '')));
        $remoteCurrency = strtoupper((string) ($remote['CurrencyRef']['value'] ?? ''));
        if ($localCurrency === '' || $remoteCurrency === '') {
            $remoteCurrency = $localCurrency;
        }

        $payloadAcct = trim((string) ($payload['AcctNum'] ?? ''));
        $remoteAcct  = trim((string) ($remote['AcctNum'] ?? ''));
        if ($payloadAcct === '' || $remoteAcct === '') {
            $remoteAcct = $payloadAcct;
        }

        return [
            'acct'        => $remoteAcct,
            'active'      => array_key_exists('Active', $remote) === false || (bool) $remote['Active'] === true,
            'currency'    => $remoteCurrency,
            'description' => (string) ($remote['Description'] ?? ''),
            'name'        => trim((string) ($remote['Name'] ?? '')),
        ];
    }

    /**
     * QuickBooks account names are unique, and every user wallet is called "Personal Wallet".
     * A wallet whose name another account already has gets its public id added.
     *
     * @param array<string, mixed> $wallet
     */
    public function uniqueName(array $wallet, string $name): string
    {
        $publicId = trim((string) ($wallet['public_id'] ?? ''));
        if ($publicId === '') {
            return $name;
        }
        $suffix = ' (' . $publicId . ')';

        return rtrim(mb_substr($name, 0, max(0, self::NAME_MAX_LENGTH - mb_strlen($suffix)))) . $suffix;
    }

    /**
     * An account named with the wallet's id added keeps the id, also after a rename, so an
     * update does not rename it back to the name another account has.
     *
     * @param array<string, mixed> $wallet
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    public function keepUniqueName(array $wallet, array $payload, array $remote): array
    {
        $name       = trim((string) ($payload['Name'] ?? ''));
        $publicId   = trim((string) ($wallet['public_id'] ?? ''));
        $remoteName = trim((string) ($remote['Name'] ?? ''));
        if ($name !== '' && $publicId !== '' && str_ends_with($remoteName, ' (' . $publicId . ')') === true) {
            $payload['Name'] = $this->uniqueName($wallet, $name);
        }

        return $payload;
    }

    /**
     * The wallet name a QuickBooks account name stands for: the added id is not copied back.
     *
     * @param array<string, mixed> $wallet
     */
    public function nameFromQuickBooks(array $wallet, string $remoteName): string
    {
        $localName = trim((string) ($wallet['name'] ?? ''));
        if ($localName !== '' && $remoteName === $this->uniqueName($wallet, $localName)) {
            return $localName;
        }
        $publicId = trim((string) ($wallet['public_id'] ?? ''));
        $suffix   = ' (' . $publicId . ')';
        if ($publicId !== '' && str_ends_with($remoteName, $suffix) === true && $remoteName !== trim($suffix)) {
            $base = rtrim(mb_substr($remoteName, 0, mb_strlen($remoteName) - mb_strlen($suffix)));

            return $base === '' ? $remoteName : $base;
        }

        return $remoteName;
    }

    /**
     * @param array<string, mixed> $wallet
     */
    public function isActive(array $wallet): bool
    {
        return (string) ($wallet['status'] ?? 'active') !== 'closed';
    }
}
