<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\QuickBooksException;

/**
 * SyncEngine's wallet blocks: read every QuickBooks account a block of wallets needs in a few
 * queries before the wallets sync. Split from SyncsWallets to keep each file readable.
 */
trait BatchesWallets
{
    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function syncWalletBlock(
        SyncLedger $ledger,
        array &$connection,
        array $rows,
        array $settings,
        array &$batch,
        int $now,
        string $trigger,
    ): bool {
        if ($this->entityEnabled($settings, 'wallet') === false) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, true);
        }
        try {
            $this->prefetchWalletAccounts($connection, $ledger, $rows, $settings);

            return $this->syncBufferedBlock(
                $ledger,
                $connection,
                $rows,
                $settings,
                $batch,
                $now,
                $trigger,
                true,
                'wallet'
            );
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true) {
                $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit() === true) {
                $this->failure($ledger, $connection, $rows[0], $exception, $settings, $now);
                $error = $this->exceptionError($exception);
                $this->recordRow($ledger, $connection, $rows[0], 'failed', $batch, $trigger, $error);

                return true;
            }

            throw $exception;
        } finally {
            $this->accountById        = null;
            $this->accountByAcctNum   = null;
            $this->accountsByName     = null;
            $this->accountReadFailed  = [];
            $this->accountFieldFailed = [];
        }
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     */
    private function prefetchWalletAccounts(array $connection, SyncLedger $ledger, array $rows, array $settings): void
    {
        $this->accountById      = [];
        $this->accountByAcctNum = [];
        $this->accountsByName   = [];

        $ids   = [];
        $plans = $this->walletAccountPlans($connection, $ledger, $rows, $settings, $ids);
        $this->prefetchLinkedAccounts($connection, $ids);
        $this->prefetchAccountsByAcctNum($connection, $plans);
        $this->prefetchAccountsByName($connection, $plans);
    }

    /**
     * What to look up for each wallet in the block: its linked account, its account number and its names.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<int, string>               $ids        linked account ids, filled in
     *
     * @return array<string, array{acct: string, name: string, unique: string, linked: string}>
     */
    private function walletAccountPlans(
        array $connection,
        SyncLedger $ledger,
        array $rows,
        array $settings,
        array &$ids,
    ): array {
        $reference   = (string) ($settings['wallet_reference'] ?? 'fleetbase');
        $companyUuid = (string) $connection['company_uuid'];
        $realm       = (string) $connection['realm_id'];
        $plans       = [];
        foreach ($rows as $row) {
            $uuid   = (string) ($row['local_uuid'] ?? '');
            $wallet = $ledger->wallets[$uuid] ?? null;
            if ($uuid === '' || is_array($wallet) === false) {
                continue;
            }
            $payload  = $this->wallets->toQuickBooks($wallet, $reference);
            $link     = $ledger->link($companyUuid, $realm, 'wallet', $uuid);
            $linkedId = '';
            if (is_array($link) === true && (string) ($link['realm_id'] ?? '') === $realm) {
                $linkedId = trim((string) ($link['qbo_id'] ?? ''));
            }
            if ($linkedId !== '') {
                $ids[] = $linkedId;
            }
            // A deleted wallet without a link is never matched by number or name.
            if ($linkedId === '' && empty($wallet['deleted_at']) === false) {
                continue;
            }
            $name         = trim((string) ($payload['Name'] ?? ''));
            $plans[$uuid] = [
                'acct'   => trim((string) ($payload['AcctNum'] ?? '')),
                'name'   => $name,
                'unique' => $name === '' ? '' : $this->wallets->uniqueName($wallet, $name),
                'linked' => $linkedId,
            ];
        }

        return $plans;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $ids
     */
    private function prefetchLinkedAccounts(array $connection, array $ids): void
    {
        $this->accountReadFailed = [];
        if ($ids === []) {
            return;
        }
        $loaded = $this->queryWhereIn(
            $connection,
            'Account',
            'Id',
            array_values(array_unique($ids)),
            fn (string $accountId): ?array => $this->client->getAccount($connection, $accountId)
        );
        foreach ($loaded['rows'] as $account) {
            $this->rememberAccount($account);
        }
        $this->accountReadFailed = $loaded['failed'];
    }

    /**
     * @param array<string, mixed>                                                             $connection
     * @param array<string, array{acct: string, name: string, unique: string, linked: string}> $plans
     */
    private function prefetchAccountsByAcctNum(array $connection, array $plans): void
    {
        $acctNums = [];
        foreach ($plans as $plan) {
            if ($plan['linked'] === '' && $plan['acct'] !== '') {
                $acctNums[] = $plan['acct'];
            }
        }
        $acctNums = array_values(array_unique($acctNums));
        $loaded   = $this->readAccountsByField($connection, 'AcctNum', $acctNums);
        foreach ($loaded as $account) {
            $this->rememberAccount($account);
        }
        if (count($acctNums) === 1 && $loaded !== [] && isset($this->accountByAcctNum[$acctNums[0]]) === false) {
            $this->accountByAcctNum[$acctNums[0]] = $loaded[0];
        }
    }

    /**
     * Read by name the wallets that neither their link nor their account number found.
     *
     * @param array<string, mixed>                                                             $connection
     * @param array<string, array{acct: string, name: string, unique: string, linked: string}> $plans
     */
    private function prefetchAccountsByName(array $connection, array $plans): void
    {
        $names   = [];
        $uniques = [];
        foreach ($plans as $plan) {
            if ($this->walletAccountFound($plan) === true || $plan['name'] === '') {
                continue;
            }
            $names[] = $plan['name'];
            if ($plan['unique'] !== '') {
                $uniques[] = $plan['unique'];
            }
        }
        $names   = array_values(array_unique($names));
        $uniques = array_values(array_diff(array_unique($uniques), $names));
        $found   = $this->readAccountsByField($connection, 'Name', array_merge($names, $uniques));
        foreach ($found as $account) {
            $name = trim((string) ($account['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $this->accountsByName[$name][] = $account;
        }
        $uniqueFound = $uniques !== [] && isset($this->accountsByName[$uniques[0]]) === true;
        $nameFound   = $names !== [] && isset($this->accountsByName[$names[0]]) === true;
        if (count($names) === 1 && $found !== [] && $nameFound === false && $uniqueFound === false) {
            $this->accountsByName[$names[0]] = $found;
        }
    }

    /**
     * True when the wallet's link or account number already settled its account, or its read failed.
     *
     * @param array{acct: string, name: string, unique: string, linked: string} $plan
     */
    private function walletAccountFound(array $plan): bool
    {
        if ($plan['linked'] !== '') {
            return isset($this->accountById[$plan['linked']]) === true
                || isset($this->accountReadFailed[$plan['linked']]) === true;
        }

        return $plan['acct'] !== '' && isset($this->accountByAcctNum[$plan['acct']]) === true;
    }

    /**
     * @param array<string, mixed> $account
     */
    private function rememberAccount(array $account): void
    {
        if ($this->accountByAcctNum === null || $this->accountById === null) {
            return;
        }

        $acct        = trim((string) ($account['AcctNum'] ?? ''));
        $accountId   = trim((string) ($account['Id'] ?? ''));
        if ($acct !== '' && isset($this->accountByAcctNum[$acct]) === false) {
            $this->accountByAcctNum[$acct] = $account;
        }
        if ($accountId !== '' && isset($this->accountById[$accountId]) === false) {
            $this->accountById[$accountId] = $account;
        }
    }

    /**
     * One query when the set has one value. Larger sets go through the batch helper in chunks of 30.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $values
     *
     * @return array<int, array<string, mixed>>
     */
    private function readAccountsByField(array $connection, string $field, array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $values
        ), static fn (string $value): bool => $value !== '')));
        if ($values === [] || ($field !== 'AcctNum' && $field !== 'Name')) {
            return [];
        }
        if (count($values) === 1) {
            if ($field === 'AcctNum') {
                $one = $this->client->findAccountByAcctNum($connection, $values[0]);

                return is_array($one) === true ? [$one] : [];
            }

            return array_values(array_filter($this->client->findAccountsByName($connection, $values[0]), 'is_array'));
        }

        $loaded = $this->queryWhereIn(
            $connection,
            'Account',
            $field,
            $values,
            static fn (string $value): ?array => null
        );
        foreach ($loaded['failed'] as $value => $error) {
            $this->accountFieldFailed[$field . ':' . $value] = $error;
        }

        return $loaded['rows'];
    }
}
