<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * Maps a Fleetbase wallet snapshot onto a QuickBooks Chart of Accounts entry.
 */
class WalletMapper
{
    public const ACCT_NUM_MAX_LENGTH = 7;

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
        } elseif ($pushClears) {
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
            'active'      => !array_key_exists('Active', $remote) || (bool) $remote['Active'],
            'currency'    => $remoteCurrency,
            'description' => (string) ($remote['Description'] ?? ''),
            'name'        => trim((string) ($remote['Name'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $wallet
     */
    public function isActive(array $wallet): bool
    {
        return (string) ($wallet['status'] ?? 'active') !== 'closed';
    }
}
