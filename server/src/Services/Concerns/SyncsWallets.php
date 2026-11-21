<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncLedger;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\WalletMapper;

/**
 * SyncEngine's wallet sync: Ledger wallets to QuickBooks accounts and back. It shares the
 * engine's state, so it lives here only to keep SyncEngine.php readable.
 */
trait SyncsWallets
{
    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     */
    private function syncWallet(SyncLedger $ledger, array $connection, string $uuid, array $settings): string
    {
        $direction = $this->direction($settings, 'wallet');
        if ($this->entityPaused($settings, 'wallet') === true) {
            $this->closeSkipped = true;

            return 'skipped';
        }

        $wallet = $ledger->wallets[$uuid] ?? null;
        if ($wallet === null) {
            $this->lastError = 'quickbooks.messages.wallet-missing';

            return 'failed';
        }
        if (empty($wallet['deleted_at']) === false) {
            return $this->deactivateRemote($ledger, $connection, 'wallet', $uuid, $this->pushesRemote($direction));
        }

        $copy       = $this->copiesRemote($direction);
        $push       = $this->pushesRemote($direction);
        $reference  = (string) ($settings['wallet_reference'] ?? 'fleetbase');
        $conflict   = (string) ($settings['wallet_conflict'] ?? 'fleetbase');
        $pushClears = $this->sendsClears($conflict, $push, $copy);
        $payload    = $this->wallets->toQuickBooks($wallet, $reference);
        $link       = $ledger->link(
            (string) $connection['company_uuid'],
            (string) $connection['realm_id'],
            'wallet',
            $uuid
        );
        if ($link !== null && $link['realm_id'] !== $connection['realm_id']) {
            $link = null;
        }
        $hadLink   = $link !== null;
        $baseline  = $link;
        $nameTaken = false;
        // Same clear rule as customer notes. A Fleetbase clear is not filled back in from
        // QuickBooks, and an empty QuickBooks description is copied only when QuickBooks changed
        // the account since a known baseline.
        $walletCopy = function (array $remoteAccount) use ($ledger, $uuid, $pushClears, $settings, $baseline): array {
            $clears        = $this->copiesClears($settings, 'wallet', $baseline, $remoteAccount);
            $remoteAccount = $this->withoutEmptyDescription($remoteAccount, $clears);
            if ($pushClears === true && trim((string) ($ledger->wallets[$uuid]['description'] ?? '')) === '') {
                unset($remoteAccount['Description']);
            }

            return $remoteAccount;
        };

        if ($link === null && empty($payload['AcctNum']) === false) {
            $acctNum = (string) $payload['AcctNum'];
            if (isset($this->accountFieldFailed['AcctNum:' . $acctNum]) === true) {
                throw $this->accountFieldFailed['AcctNum:' . $acctNum];
            }
            $existing = $this->accountByAcctNum !== null
                ? ($this->accountByAcctNum[$acctNum] ?? null)
                : $this->client->findAccountByAcctNum($connection, $acctNum);
            if ($existing !== null) {
                $this->putMatchedLink($ledger, $connection, 'wallet', $uuid, 'Account', $existing);
                $link = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'wallet', $uuid);
            }
        }

        $quickbooksPrimary = $reference === 'quickbooks' || $conflict === 'quickbooks';
        $remote            = null;
        if ($link !== null) {
            $accountId = (string) $link['qbo_id'];
            if (isset($this->accountReadFailed[$accountId]) === true) {
                throw $this->accountReadFailed[$accountId];
            }
            $remote = $this->accountById !== null
                ? ($this->accountById[$accountId] ?? null)
                : $this->client->getAccount($connection, $accountId);
        }
        if ($remote === null) {
            $acctNum = trim((string) ($payload['AcctNum'] ?? ''));
            $name    = trim((string) ($payload['Name'] ?? ''));
            if ($link === null && $acctNum !== '' && isset($this->accountFieldFailed['AcctNum:' . $acctNum]) === true) {
                throw $this->accountFieldFailed['AcctNum:' . $acctNum];
            }
            if ($name !== '' && isset($this->accountFieldFailed['Name:' . $name]) === true) {
                throw $this->accountFieldFailed['Name:' . $name];
            }
            $remote = $this->reattachWallet($ledger, $connection, $uuid, $wallet, $payload, $nameTaken);
            $link   = $remote === null ? null : $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'wallet', $uuid);
        }
        if ($remote === null && $hadLink === true) {
            return 'aligned';
        }
        if ($remote === null) {
            if ($quickbooksPrimary === false) {
                $this->lastError = $this->currencyError(
                    $ledger,
                    $connection,
                    (string) ($wallet['currency'] ?? ''),
                    'quickbooks.messages.wallet-currency-mismatch'
                );
                if ($this->lastError !== null) {
                    return 'failed';
                }
            }
            if ($push === false) {
                $this->closeSkipped = true;

                return 'skipped';
            }
            $payload = $this->newAccountName($wallet, $payload, $nameTaken);
            if ($this->writeBuffer !== null) {
                $this->writeBuffer[] = [
                    'bId'       => 'wallet:' . $uuid,
                    'operation' => 'create',
                    'entity'    => 'Account',
                    'payload'   => $payload,
                ];

                return 'created';
            }
            $created = $this->client->createAccount($connection, $payload);
            $ledger->putLink($this->linkFrom($connection, 'wallet', $uuid, 'Account', $created));
            if ($copy === true) {
                $this->applyWalletFromRemote($ledger, $uuid, $walletCopy($created), $reference);
                if ($quickbooksPrimary === true) {
                    $this->copyWalletCurrency($ledger, $connection, $uuid, $created);
                }
            }

            return 'created';
        }

        $before = $wallet;
        $active = $remote['Active'] ?? null;
        $remote = $this->withKeptInactive($settings, $wallet, $remote);
        // QuickBooks wins only an account it changed since the last sync; otherwise Fleetbase is sent.
        $leads  = $this->fleetbaseLeads($settings, 'wallet', $baseline, $remote);
        if ($leads === true) {
            $pushClears = true;
        }
        if ($quickbooksPrimary === true && $copy === true && $leads === false) {
            $differed = $this->wallets->matches($wallet, $payload, $remote) === false;
            $this->applyWalletFromRemote($ledger, $uuid, $walletCopy($remote), $reference);
            $this->copyWalletCurrency($ledger, $connection, $uuid, $remote);
            $wallet   = $ledger->wallets[$uuid];
            $payload  = $this->wallets->toQuickBooks($wallet, $reference);
            $replaced = $differed === true && $wallet !== $before;
            $this->notePrimaryCopy('wallet', $uuid, $push === true && $conflict === 'quickbooks', $replaced);
        }

        if ($this->wallets->matches($wallet, $payload, $remote) === true) {
            $inSync = $this->walletInSync($ledger, $uuid, $remote, $settings);

            $changed = $wallet !== $before;

            return $this->syncedIf($ledger, $connection, 'wallet', $uuid, $remote, $changed, $inSync, $baseline);
        }

        if ($conflict === 'quickbooks' && $copy === true && $leads === false) {
            $before = $ledger->wallets[$uuid];
            $this->applyWalletFromRemote($ledger, $uuid, $walletCopy($remote), $reference);
            $this->copyWalletCurrency($ledger, $connection, $uuid, $remote);
            $changed = $ledger->wallets[$uuid] !== $before;
            $this->notePrimaryCopy('wallet', $uuid, $push, $changed);
            $inSync = $this->walletInSync($ledger, $uuid, $remote, $settings);

            return $this->syncedIf($ledger, $connection, 'wallet', $uuid, $remote, $changed, $inSync, $baseline);
        }

        if ($push === false) {
            if ($copy === true) {
                $before = $ledger->wallets[$uuid];
                $this->applyWalletFromRemote($ledger, $uuid, $walletCopy($remote), $reference);
                $changed = $ledger->wallets[$uuid] !== $before;
                $inSync  = $this->walletInSync($ledger, $uuid, $remote, $settings);

                return $this->syncedIf($ledger, $connection, 'wallet', $uuid, $remote, $changed, $inSync, $baseline);
            }

            return 'aligned';
        }

        if ($reference === 'quickbooks') {
            $payload = $this->wallets->toQuickBooks($ledger->wallets[$uuid], 'fleetbase');
            $acctNum = trim((string) ($ledger->wallets[$uuid]['acct_num'] ?? $remote['AcctNum'] ?? ''));
            if ($acctNum !== '') {
                $payload['AcctNum'] = $acctNum;
            } else {
                unset($payload['AcctNum']);
            }
        }

        if ($pushClears === true) {
            $withClears = $this->wallets->toQuickBooks($ledger->wallets[$uuid], $reference, true);
            if (array_key_exists('Description', $withClears) === true) {
                $payload['Description'] = $withClears['Description'];
            }
        }
        $payload = $this->wallets->keepUniqueName($ledger->wallets[$uuid], $payload, $remote);
        if ($active === false && array_key_exists('Active', $remote) === false) {
            unset($payload['Active']);
        }

        if ($this->writeBuffer !== null) {
            $payload['Id']        = (string) $remote['Id'];
            $payload['SyncToken'] = (string) $remote['SyncToken'];
            $payload['sparse']    = true;
            $this->writeBuffer[]  = [
                'bId'       => 'wallet:' . $uuid,
                'operation' => 'update',
                'entity'    => 'Account',
                'payload'   => $payload,
            ];

            return 'updated';
        }

        $updated = $this->client->updateAccount($connection, (string) $remote['Id'], (string) $remote['SyncToken'], $payload);
        $ledger->putLink($this->linkFrom($connection, 'wallet', $uuid, 'Account', $updated));

        return 'updated';
    }

    /**
     * A lost link reattaches when exactly one QuickBooks account has this name and currency.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $wallet
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function reattachWallet(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $wallet,
        array $payload,
        bool &$nameTaken,
    ): ?array {
        $nameTaken = false;
        $name      = trim((string) ($payload['Name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $currency = strtoupper(trim((string) ($wallet['currency'] ?? '')));
        $matches  = [];
        $accounts = array_values(array_filter($this->accountsNamed($connection, $name), 'is_array'));
        $unique   = $this->wallets->uniqueName($wallet, $name);
        if ($unique !== $name) {
            $named    = array_filter($this->accountsNamed($connection, $unique), 'is_array');
            $accounts = array_merge($accounts, array_values($named));
        }
        $nameTaken = $accounts !== [];
        // An account another wallet is linked to is that wallet's, whatever its name.
        $ids   = array_map(static fn (array $account): string => (string) ($account['Id'] ?? ''), $accounts);
        $realm = (string) $connection['realm_id'];
        $taken = $ledger->remoteIdsLinked($realm, 'Account', array_values(array_filter($ids)), $uuid);
        foreach ($accounts as $account) {
            if (in_array((string) ($account['Id'] ?? ''), $taken, true) === true) {
                continue;
            }
            $remoteCurrency = strtoupper(trim((string) ($account['CurrencyRef']['value'] ?? '')));
            if ($currency !== '' && $remoteCurrency !== '' && $remoteCurrency !== $currency) {
                continue;
            }
            $matches[] = $account;
        }
        if (count($matches) !== 1) {
            return null;
        }

        $this->putMatchedLink($ledger, $connection, 'wallet', $uuid, 'Account', $matches[0]);

        return $matches[0];
    }

    /**
     * A new account takes the wallet's own name unless another account, or one created earlier
     * in this run, has it already.
     *
     * @param array<string, mixed> $wallet
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function newAccountName(array $wallet, array $payload, bool $nameTaken): array
    {
        $name = trim((string) ($payload['Name'] ?? ''));
        if ($name === '') {
            return $payload;
        }
        if ($nameTaken === true || isset($this->accountNamesCreated[mb_strtolower($name)]) === true) {
            $payload['Name'] = $this->wallets->uniqueName($wallet, $name);
        }
        $this->accountNamesCreated[mb_strtolower((string) $payload['Name'])] = true;

        return $payload;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, mixed>
     */
    private function accountsNamed(array $connection, string $name): array
    {
        return $this->accountsByName !== null
            ? ($this->accountsByName[$name] ?? [])
            : $this->client->findAccountsByName($connection, $name);
    }

    /**
     * @param array<string, mixed> $remote
     * @param array<string, mixed> $settings
     */
    private function walletInSync(SyncLedger $ledger, string $uuid, array $remote, array $settings): bool
    {
        $wallet = $ledger->wallets[$uuid] ?? null;
        if (is_array($wallet) === false) {
            return true;
        }
        $reference = (string) ($settings['wallet_reference'] ?? 'fleetbase');
        $remote    = $this->withKeptInactive($settings, $wallet, $remote);

        return $this->wallets->matches($wallet, $this->wallets->toQuickBooks($wallet, $reference, true), $remote);
    }

    /**
     * QuickBooks as Primary in two-way sync leaves a wallet that still holds money open when
     * QuickBooks made its account inactive (applyWalletFromRemote). That difference is kept:
     * the account is compared as if active, and a push leaves Active out.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $wallet
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private function withKeptInactive(array $settings, array $wallet, array $remote): array
    {
        $inactive   = array_key_exists('Active', $remote) === true && (bool) $remote['Active'] === false;
        $holdsMoney = (int) ($wallet['balance'] ?? 0) !== 0;
        if ($inactive === true && $holdsMoney === true && $this->wallets->isActive($wallet) === true
            && $this->tokenDecides($settings, 'wallet') === true) {
            unset($remote['Active']);
        }

        return $remote;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function applyWalletFromRemote(SyncLedger $ledger, string $uuid, array $remote, string $reference): void
    {
        $name = trim((string) ($remote['Name'] ?? ''));
        if ($name !== '') {
            $ledger->wallets[$uuid]['name'] = $this->wallets->nameFromQuickBooks($ledger->wallets[$uuid], $name);
        }

        // QuickBooks holds a longer Fleetbase description cut to its limit. That copy is not a change.
        $description = array_key_exists('Description', $remote) === true ? (string) $remote['Description'] : null;
        if ($description !== null && $description !== WalletMapper::description($ledger->wallets[$uuid])) {
            $ledger->wallets[$uuid]['description'] = $description;
        }

        $currency = strtoupper(trim((string) ($remote['CurrencyRef']['value'] ?? '')));
        if ($currency !== '') {
            $ledger->wallets[$uuid]['currency'] = $currency;
        }

        if (array_key_exists('Active', $remote) === true) {
            $active = (bool) $remote['Active'];
            // An inactive account does not close a wallet that still holds money: closing it
            // would hide the balance. The same rule applies to a delete from QuickBooks.
            $holdsMoney = (int) ($ledger->wallets[$uuid]['balance'] ?? 0) !== 0;
            if ($active === true || $holdsMoney === false) {
                $ledger->wallets[$uuid]['status'] = $active === true ? 'active' : 'closed';
            }
        }

        if ($reference !== 'quickbooks') {
            return;
        }

        $acctNum = trim((string) ($remote['AcctNum'] ?? ''));
        if ($acctNum !== '') {
            $ledger->wallets[$uuid]['acct_num'] = $acctNum;
            $meta                               = $ledger->wallets[$uuid]['meta'] ?? [];
            if (is_array($meta) === false) {
                $meta = [];
            }
            $meta['quickbooks_acct_num']        = $acctNum;
            $ledger->wallets[$uuid]['meta']     = $meta;
        }
    }

    /**
     * QuickBooks Primary: the account currency wins. CurrencyRef is used when the
     * account has one; otherwise the company home currency is used.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     */
    private function copyWalletCurrency(SyncLedger $ledger, array $connection, string $uuid, array $remote): void
    {
        $currency = strtoupper(trim((string) ($remote['CurrencyRef']['value'] ?? '')));
        if ($currency === '') {
            $currency = $this->homeCurrency($ledger, $connection);
        }
        if ($currency === '') {
            return;
        }

        $ledger->wallets[$uuid]['currency'] = $currency;
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function syncWalletBlock(SyncLedger $ledger, array &$connection, array $rows, array $settings, array &$batch, int $now, string $trigger): bool
    {
        if ($this->entityEnabled($settings, 'wallet') === false) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, true);
        }
        try {
            $this->prefetchWalletAccounts($connection, $ledger, $rows, $settings);

            return $this->syncBufferedBlock($ledger, $connection, $rows, $settings, $batch, $now, $trigger, true, 'wallet');
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true) {
                $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit() === true) {
                $this->failure($ledger, $connection, $rows[0], $exception, $settings, $now);
                $this->recordRow($ledger, $connection, $rows[0], 'failed', $batch, $trigger, $this->exceptionError($exception));

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

        $reference   = (string) ($settings['wallet_reference'] ?? 'fleetbase');
        $companyUuid = (string) $connection['company_uuid'];
        $realm       = (string) $connection['realm_id'];
        $ids         = [];
        /** @var array<string, array{acct: string, name: string, linked: string}> $plans */
        $plans = [];
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

        $this->accountReadFailed = [];
        if ($ids !== []) {
            $loaded = $this->queryWhereIn(
                $connection,
                'Account',
                'Id',
                array_values(array_unique($ids)),
                fn (string $id): ?array => $this->client->getAccount($connection, $id)
            );
            foreach ($loaded['rows'] as $account) {
                $this->rememberAccount($account);
            }
            $this->accountReadFailed = $loaded['failed'];
        }

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

        $names   = [];
        $uniques = [];
        foreach ($plans as $plan) {
            $linkedFound  = $plan['linked'] !== '' && isset($this->accountById[$plan['linked']]) === true;
            $linkedFailed = $plan['linked'] !== '' && isset($this->accountReadFailed[$plan['linked']]) === true;
            $acctFound    = $plan['linked'] === '' && $plan['acct'] !== '' && isset($this->accountByAcctNum[$plan['acct']]) === true;
            if ($linkedFound === true || $linkedFailed === true || $acctFound === true || $plan['name'] === '') {
                continue;
            }
            $names[] = $plan['name'];
            if (isset($plan['unique']) === true && $plan['unique'] !== '') {
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
     * @param array<string, mixed> $account
     */
    private function rememberAccount(array $account): void
    {
        if ($this->accountByAcctNum === null || $this->accountById === null) {
            return;
        }

        $acct = trim((string) ($account['AcctNum'] ?? ''));
        $id   = trim((string) ($account['Id'] ?? ''));
        if ($acct !== '' && isset($this->accountByAcctNum[$acct]) === false) {
            $this->accountByAcctNum[$acct] = $account;
        }
        if ($id !== '' && isset($this->accountById[$id]) === false) {
            $this->accountById[$id] = $account;
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
