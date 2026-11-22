<?php

namespace Fleetbase\Quickbooks\Services\Concerns;

use Fleetbase\Quickbooks\Services\SyncLedger;
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

        $plan      = $this->walletPlan($ledger, $connection, $uuid, $settings, $direction);
        $payload   = $this->wallets->toQuickBooks($wallet, $plan['reference']);
        $nameTaken = false;
        $remote    = $this->walletRemote($ledger, $connection, $uuid, $wallet, $payload, $plan['baseline'], $nameTaken);
        if ($remote !== null) {
            return $this->updateWalletAccount($ledger, $connection, $uuid, $wallet, $payload, $remote, $plan);
        }
        if ($plan['baseline'] !== null) {
            return 'aligned';
        }

        return $this->createWalletAccount($ledger, $connection, $uuid, $wallet, $payload, $plan, $nameTaken);
    }

    /**
     * How this wallet syncs, and its link as it was before this sync.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     *
     * @return array{
     *     settings: array<string, mixed>, copy: bool, push: bool, reference: string, conflict: string,
     *     pushClears: bool, primary: bool, baseline: array<string, mixed>|null
     * }
     */
    private function walletPlan(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $settings,
        string $direction,
    ): array {
        $copy      = $this->copiesRemote($direction);
        $push      = $this->pushesRemote($direction);
        $reference = (string) ($settings['wallet_reference'] ?? 'fleetbase');
        $conflict  = (string) ($settings['wallet_conflict'] ?? 'fleetbase');
        $link      = $ledger->link(
            (string) $connection['company_uuid'],
            (string) $connection['realm_id'],
            'wallet',
            $uuid
        );
        if ($link !== null && $link['realm_id'] !== $connection['realm_id']) {
            $link = null;
        }

        return [
            'settings'   => $settings,
            'copy'       => $copy,
            'push'       => $push,
            'reference'  => $reference,
            'conflict'   => $conflict,
            'pushClears' => $this->sendsClears($conflict, $push, $copy),
            'primary'    => $reference === 'quickbooks' || $conflict === 'quickbooks',
            'baseline'   => $link,
        ];
    }

    /**
     * The QuickBooks account for this wallet: by its link, by account number, or by a lost link
     * that reattaches.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>      $wallet
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $link
     *
     * @return array<string, mixed>|null
     */
    private function walletRemote(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $wallet,
        array $payload,
        ?array $link,
        bool &$nameTaken,
    ): ?array {
        if ($link === null && empty($payload['AcctNum']) === false) {
            $link = $this->matchWalletByAcctNum($ledger, $connection, $uuid, (string) $payload['AcctNum']);
        }
        $remote = $link === null ? null : $this->readWalletAccount($connection, (string) $link['qbo_id']);
        if ($remote !== null) {
            return $remote;
        }

        $acctNum = trim((string) ($payload['AcctNum'] ?? ''));
        $name    = trim((string) ($payload['Name'] ?? ''));
        if ($link === null && $acctNum !== '' && isset($this->accountFieldFailed['AcctNum:' . $acctNum]) === true) {
            throw $this->accountFieldFailed['AcctNum:' . $acctNum];
        }
        if ($name !== '' && isset($this->accountFieldFailed['Name:' . $name]) === true) {
            throw $this->accountFieldFailed['Name:' . $name];
        }

        return $this->reattachWallet($ledger, $connection, $uuid, $wallet, $payload, $nameTaken);
    }

    /**
     * Link the wallet to the QuickBooks account with its account number, if there is one.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null the new link
     */
    private function matchWalletByAcctNum(SyncLedger $ledger, array $connection, string $uuid, string $acctNum): ?array
    {
        if (isset($this->accountFieldFailed['AcctNum:' . $acctNum]) === true) {
            throw $this->accountFieldFailed['AcctNum:' . $acctNum];
        }
        $existing = $this->accountByAcctNum !== null
            ? ($this->accountByAcctNum[$acctNum] ?? null)
            : $this->client->findAccountByAcctNum($connection, $acctNum);
        if ($existing === null) {
            return null;
        }
        $this->putMatchedLink($ledger, $connection, 'wallet', $uuid, 'Account', $existing);

        return $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'wallet', $uuid);
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function readWalletAccount(array $connection, string $accountId): ?array
    {
        if (isset($this->accountReadFailed[$accountId]) === true) {
            throw $this->accountReadFailed[$accountId];
        }

        return $this->accountById !== null
            ? ($this->accountById[$accountId] ?? null)
            : $this->client->getAccount($connection, $accountId);
    }

    /**
     * Same clear rule as customer notes. A Fleetbase clear is not filled back in from
     * QuickBooks, and an empty QuickBooks description is copied only when QuickBooks changed
     * the account since a known baseline.
     *
     * @param array{
     *     settings: array<string, mixed>, pushClears: bool, baseline: array<string, mixed>|null
     * } $plan
     * @param array<string, mixed> $remoteAccount
     *
     * @return array<string, mixed>
     */
    private function walletRemoteCopy(SyncLedger $ledger, string $uuid, array $plan, array $remoteAccount): array
    {
        $clears        = $this->copiesClears($plan['settings'], 'wallet', $plan['baseline'], $remoteAccount);
        $remoteAccount = $this->withoutEmptyDescription($remoteAccount, $clears);
        if ($plan['pushClears'] === true && trim((string) ($ledger->wallets[$uuid]['description'] ?? '')) === '') {
            unset($remoteAccount['Description']);
        }

        return $remoteAccount;
    }

    /**
     * No QuickBooks account has this wallet: create one.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $wallet
     * @param array<string, mixed> $payload
     * @param array{
     *     settings: array<string, mixed>, copy: bool, push: bool, reference: string, conflict: string,
     *     pushClears: bool, primary: bool, baseline: array<string, mixed>|null
     * } $plan
     */
    private function createWalletAccount(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $wallet,
        array $payload,
        array $plan,
        bool $nameTaken,
    ): string {
        if ($plan['primary'] === false) {
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
        if ($plan['push'] === false) {
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
        if ($plan['copy'] === true) {
            $copied = $this->walletRemoteCopy($ledger, $uuid, $plan, $created);
            $this->applyWalletFromRemote($ledger, $uuid, $copied, $plan['reference']);
            if ($plan['primary'] === true) {
                $this->copyWalletCurrency($ledger, $connection, $uuid, $created);
            }
        }

        return 'created';
    }

    /**
     * The wallet has a QuickBooks account: copy it to Fleetbase or send the Fleetbase wallet,
     * by the direction and conflict rule.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $wallet
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     * @param array{
     *     settings: array<string, mixed>, copy: bool, push: bool, reference: string, conflict: string,
     *     pushClears: bool, primary: bool, baseline: array<string, mixed>|null
     * } $plan
     */
    private function updateWalletAccount(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $wallet,
        array $payload,
        array $remote,
        array $plan,
    ): string {
        $before = $wallet;
        $active = $remote['Active'] ?? null;
        $remote = $this->withKeptInactive($plan['settings'], $wallet, $remote);
        // QuickBooks wins only an account it changed since the last sync; otherwise Fleetbase is sent.
        $leads = $this->fleetbaseLeads($plan['settings'], 'wallet', $plan['baseline'], $remote);
        if ($plan['primary'] === true && $plan['copy'] === true && $leads === false) {
            $differed = $this->wallets->matches($wallet, $payload, $remote) === false;
            $this->copyWalletRemote($ledger, $connection, $uuid, $remote, $plan, true);
            $wallet   = $ledger->wallets[$uuid];
            $payload  = $this->wallets->toQuickBooks($wallet, $plan['reference']);
            $replaced = $differed === true && $wallet !== $before;
            $sends    = $plan['push'] === true && $plan['conflict'] === 'quickbooks';
            $this->notePrimaryCopy('wallet', $uuid, $sends, $replaced);
        }

        if ($this->wallets->matches($wallet, $payload, $remote) === true) {
            return $this->walletSynced($ledger, $connection, $uuid, $remote, $plan, $wallet !== $before);
        }

        if ($plan['conflict'] === 'quickbooks' && $plan['copy'] === true && $leads === false) {
            $changed = $this->copyWalletRemote($ledger, $connection, $uuid, $remote, $plan, true);
            $this->notePrimaryCopy('wallet', $uuid, $plan['push'], $changed);

            return $this->walletSynced($ledger, $connection, $uuid, $remote, $plan, $changed);
        }

        if ($plan['push'] === false) {
            if ($plan['copy'] === false) {
                return 'aligned';
            }
            $changed = $this->copyWalletRemote($ledger, $connection, $uuid, $remote, $plan, false);

            return $this->walletSynced($ledger, $connection, $uuid, $remote, $plan, $changed);
        }

        $pushClears = $plan['pushClears'] === true || $leads === true;
        $payload    = $this->walletUpdatePayload(
            $ledger,
            $uuid,
            $payload,
            $remote,
            $plan['reference'],
            $pushClears,
            $active
        );

        return $this->writeWalletUpdate($ledger, $connection, $uuid, $payload, $remote);
    }

    /**
     * Copy the QuickBooks account onto the wallet. True when the wallet changed.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     * @param array{
     *     settings: array<string, mixed>, reference: string, pushClears: bool, baseline: array<string, mixed>|null
     * } $plan
     */
    private function copyWalletRemote(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $remote,
        array $plan,
        bool $withCurrency,
    ): bool {
        $before = $ledger->wallets[$uuid];
        $copied = $this->walletRemoteCopy($ledger, $uuid, $plan, $remote);
        $this->applyWalletFromRemote($ledger, $uuid, $copied, $plan['reference']);
        if ($withCurrency === true) {
            $this->copyWalletCurrency($ledger, $connection, $uuid, $remote);
        }

        return $ledger->wallets[$uuid] !== $before;
    }

    /**
     * @param array<string, mixed>                                                       $connection
     * @param array<string, mixed>                                                       $remote
     * @param array{settings: array<string, mixed>, baseline: array<string, mixed>|null} $plan
     */
    private function walletSynced(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $remote,
        array $plan,
        bool $changed,
    ): string {
        $inSync = $this->walletInSync($ledger, $uuid, $remote, $plan['settings']);

        return $this->syncedIf($ledger, $connection, 'wallet', $uuid, $remote, $changed, $inSync, $plan['baseline']);
    }

    /**
     * The update sent for a wallet: under QuickBooks reference the Fleetbase name and number,
     * and a cleared description when clears are sent.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private function walletUpdatePayload(
        SyncLedger $ledger,
        string $uuid,
        array $payload,
        array $remote,
        string $reference,
        bool $pushClears,
        mixed $active,
    ): array {
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

        return $payload;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     */
    private function writeWalletUpdate(
        SyncLedger $ledger,
        array $connection,
        string $uuid,
        array $payload,
        array $remote,
    ): string {
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

        $updated = $this->client->updateAccount(
            $connection,
            (string) $remote['Id'],
            (string) $remote['SyncToken'],
            $payload
        );
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
        if (
            $inactive === true
            && $holdsMoney === true
            && $this->wallets->isActive($wallet) === true
            && $this->tokenDecides($settings, 'wallet') === true
        ) {
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
}
