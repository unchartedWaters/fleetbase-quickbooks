<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Support\Amounts;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\ContentHash;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\WalletMapper;

class SyncEngine
{
    private const RATE_LIMITED_MESSAGE = 'QuickBooks asked Fleetbase to wait (rate limit). Try again in a few minutes.';

    public const TOKEN_REJECTED_MESSAGE = 'QuickBooks rejected the access token. Connect again from Connection.';

    private const SYNC_FAILED_MESSAGE = 'Sync failed.';

    /**
     * QuickBooks returns at most this many rows unless the query asks for more.
     * A full page is not the end of an id lookup.
     */
    private const QUERY_PAGE_SIZE = 100;

    private ?string $lastError = null;

    /** @var array<string, bool> */
    private array $customTxnNumbers = [];

    /**
     * Payments prefetched for the current invoice block, keyed by QuickBooks invoice id.
     * Null means this invoice is alone and may use one payment query.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $blockPayments = null;

    /**
     * Linked payments prefetched for the current invoice block, keyed by QuickBooks payment id.
     * Null means this invoice is alone and may use one payment GET.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $paymentById = null;

    /**
     * Accounts prefetched for the current wallet block, keyed by QuickBooks account id.
     * Null means this wallet is alone and may use one account GET.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $accountById = null;

    /**
     * Accounts prefetched for the current wallet block, keyed by AcctNum.
     * Null means this wallet is alone and may use one account-number query.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $accountByAcctNum = null;

    /**
     * Accounts prefetched for the current wallet block, keyed by Name.
     * A name may match more than one account. Null means this wallet is alone.
     *
     * @var array<string, array<int, array<string, mixed>>>|null
     */
    private ?array $accountsByName = null;

    /**
     * Service item id already resolved for this invoice block, keyed by company uuid.
     * An empty string means ensureServiceItem ran and QuickBooks has no item.
     * Null means this invoice is alone.
     *
     * @var array<string, string>|null
     */
    private ?array $serviceItemByCompany = null;

    /**
     * Prefetched QuickBooks invoices for a block, keyed by Id. Null means load per invoice.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $invoiceById = null;

    /**
     * Prefetched QuickBooks invoices for a block, keyed by DocNumber.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $invoiceByDoc = null;

    /**
     * Doc numbers reserved for creates in the current invoice block, keyed by local uuid.
     * Null means this invoice is alone and may use one nextInvoiceDocNumber lookup.
     *
     * @var array<string, string>|null
     */
    private ?array $reservedDocNumbers = null;

    /**
     * When set, creates and updates are queued for one Batch API call instead of sent alone.
     *
     * @var array<int, array{bId: string, operation: string, entity: string, payload: array<string, mixed>}>|null
     */
    private ?array $writeBuffer = null;

    /**
     * Prefetched QuickBooks customers for a block, keyed by Fleetbase uuid.
     * Null is a successful email and display-name miss; a missing key was not looked up.
     *
     * @var array<string, array<string, mixed>|null>|null
     */
    private ?array $customerRemoteCache = null;

    /**
     * Fleetbase uuids whose block lookup failed. Failure is not "no such customer".
     *
     * @var array<string, string>
     */
    private array $customerLookupFailed = [];

    /**
     * QuickBooks invoice ids whose block read failed. Absence from invoiceById is a miss only when the id is not here.
     *
     * @var array<string, string>
     */
    private array $invoiceReadFailed = [];

    /**
     * Invoice numbers whose block read failed. A failed read is not "no invoice with this number".
     *
     * @var array<string, string>
     */
    private array $invoiceDocReadFailed = [];

    /**
     * QuickBooks account ids whose block read failed.
     *
     * @var array<string, string>
     */
    private array $accountReadFailed = [];

    /**
     * Account field lookups that failed, keyed by "AcctNum:value" or "Name:value".
     *
     * @var array<string, string>
     */
    private array $accountFieldFailed = [];

    /**
     * QuickBooks invoice ids included in the block payment prefetch.
     * Null when this invoice is not in a block.
     *
     * @var array<string, true>|null
     */
    private ?array $paymentPrefetchIds = null;

    /**
     * QuickBooks payment ids whose block read failed.
     *
     * @var array<string, string>
     */
    private array $paymentReadFailed = [];

    private bool $closeSkipped = false;

    private bool $customersPrepared = false;

    public function __construct(
        private QuickBooksClient $client,
        private CustomerMapper $customers,
        private InvoiceMapper $invoices,
        private WalletMapper $wallets,
        private BackoffPolicy $backoff,
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public function runScheduled(SyncLedger $ledger, string $companyUuid, array $settings, int $now, bool $force = false, bool $ignoreInterval = false): array
    {
        $trigger    = $force ? 'now' : 'scheduled';
        $connection = $ledger->connection($companyUuid);
        if (!is_array($connection) || !ConnectionGate::hasRealm($connection)) {
            return $this->emptyBatch($trigger, 'skipped');
        }
        if (!empty($connection['needs_reauth'])) {
            if ($force) {
                return $this->recordSkipped($ledger, $companyUuid, $trigger, 'QuickBooks needs to be connected again before sync can continue.');
            }

            return $this->emptyBatch($trigger, 'skipped');
        }
        if ($this->isRateLimited($connection, $now)) {
            if ($force) {
                return $this->recordSkipped($ledger, $companyUuid, $trigger, self::RATE_LIMITED_MESSAGE);
            }

            return $this->emptyBatch($trigger, 'skipped');
        }
        $last = $connection['last_batch_at'] ?? null;
        if (!$force && !$ignoreInterval && $last !== null && ($now - (int) $last) < ($settings['interval_minutes'] * 60)) {
            return $this->emptyBatch($trigger, 'skipped');
        }

        $pending                                            = $ledger->duePending($companyUuid, (int) $settings['batch_size'], $now);
        $result                                             = $this->process($ledger, $connection, $pending, $settings, $now, $trigger, false);
        $ledger->connections[$companyUuid]['last_batch_at'] = $now;

        return $result;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public function reconcile(SyncLedger $ledger, string $companyUuid, array $settings, int $now): array
    {
        $connection = $ledger->connection($companyUuid);
        if (!is_array($connection) || !ConnectionGate::hasRealm($connection)) {
            return $this->emptyBatch('manual', 'skipped');
        }
        if (!empty($connection['needs_reauth'])) {
            return $this->recordSkipped($ledger, $companyUuid, 'manual', 'QuickBooks needs to be connected again before sync can continue.');
        }
        if ($this->isRateLimited($connection, $now)) {
            return $this->recordSkipped($ledger, $companyUuid, 'manual', self::RATE_LIMITED_MESSAGE);
        }

        return $this->process($ledger, $connection, $this->reconcileRows($ledger, $companyUuid, $settings, $now), $settings, $now, 'manual', false);
    }

    /**
     * Webhook deliveries name the QuickBooks ids that changed. Linked Fleetbase
     * rows are updated when direction is inbound or both. Outbound and off are
     * ignored. A remote invoice with no local invoice and no link is not imported.
     *
     * @param array<int, array{entity: string, id: string, operation: string}> $entities
     * @param array<string, mixed>                                              $settings
     */
    public function acceptRemoteChanges(SyncLedger $ledger, string $companyUuid, array $entities, array $settings, int $now): void
    {
        $connection = $ledger->connection($companyUuid);
        if (!is_array($connection) || !ConnectionGate::hasRealm($connection) || !empty($connection['needs_reauth'])) {
            return;
        }

        $ledger->rebuildIndex();
        /** @var array<string, array<string, array<string, mixed>>> $wanted */
        $wanted = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $name = (string) ($entity['entity'] ?? '');
            $id   = (string) ($entity['id'] ?? '');
            if ($id === '' || strtolower((string) ($entity['operation'] ?? '')) === 'delete') {
                continue;
            }
            $kind = match ($name) {
                'Customer' => 'customer',
                'Invoice'  => 'invoice',
                'Payment'  => 'payment',
                'Account'  => 'wallet',
                default    => '',
            };
            if ($kind === '') {
                continue;
            }
            $direction = $this->direction($settings, $kind);
            if (!$this->entityEnabled($settings, $kind) || $direction === 'off' || $direction === 'outbound') {
                continue;
            }
            $link = $ledger->linkForRemote((string) $connection['realm_id'], $name, $id);
            if ($link === null) {
                continue;
            }
            $wanted[$name][$id] = $link;
        }

        foreach ($wanted as $name => $linksById) {
            $remotes = $this->readRemoteSet($connection, $name, array_keys($linksById));
            foreach ($linksById as $id => $link) {
                $remote = $remotes[$id] ?? null;
                if (!is_array($remote)) {
                    continue;
                }
                $localUuid = (string) ($link['local_uuid'] ?? '');
                if ($name === 'Customer' && isset($ledger->customers[$localUuid])) {
                    $this->applyCustomerFromRemote($ledger, $localUuid, $remote);
                } elseif ($name === 'Invoice' && isset($ledger->invoices[$localUuid])) {
                    $this->applyInvoiceFromRemote($ledger, $localUuid, $remote, (string) ($settings['invoice_reference'] ?? 'fleetbase'));
                    $this->applyPaymentStatus($ledger, $localUuid);
                } elseif ($name === 'Payment' && isset($ledger->invoices[$localUuid])) {
                    $invoiceLink = $ledger->link($companyUuid, (string) $connection['realm_id'], 'invoice', $localUuid);
                    $this->applyPaymentFromRemote($ledger, $localUuid, $remote, (string) ($invoiceLink['qbo_id'] ?? ''));
                    $this->applyPaymentStatus($ledger, $localUuid);
                } elseif ($name === 'Account' && isset($ledger->wallets[$localUuid])) {
                    $this->applyWalletFromRemote($ledger, $localUuid, $remote, (string) ($settings['wallet_reference'] ?? 'fleetbase'));
                }
            }
        }
    }

    /**
     * Sync a list of local rows. The periodic customer catalog and a webhook
     * that already resolved local ids use this. It does not scan the catalog.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     *
     * @return array<string, mixed>
     */
    public function syncEntities(SyncLedger $ledger, string $companyUuid, array $rows, array $settings, int $now): array
    {
        $connection = $ledger->connection($companyUuid);
        if (!is_array($connection) || !ConnectionGate::hasRealm($connection) || !empty($connection['needs_reauth'])) {
            return $this->emptyBatch('catalog', 'skipped');
        }

        return $this->process($ledger, $connection, $rows, $settings, $now, 'catalog', false);
    }

    /**
     * At most batch_size entities. Anything past that stays pending for a follow-up.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<int, array<string, mixed>>
     */
    private function reconcileRows(SyncLedger $ledger, string $companyUuid, array $settings, int $now): array
    {
        $limit   = max(1, (int) ($settings['batch_size'] ?? 100));
        $pending = [];
        foreach ($ledger->pending as $row) {
            if (($row['company_uuid'] ?? '') !== $companyUuid || ($row['local_type'] ?? '') !== 'invoice' || ($row['status'] ?? '') !== 'pending') {
                continue;
            }
            $next = $row['next_attempt_at'] ?? null;
            if ($next !== null && (int) $next > $now) {
                continue;
            }
            $pending[] = $row;
            if (count($pending) >= $limit) {
                break;
            }
        }
        if ($pending !== []) {
            return $pending;
        }

        $rows = [];
        $seen = [];
        foreach ($ledger->invoices as $invoice) {
            if (($invoice['company_uuid'] ?? '') !== $companyUuid) {
                continue;
            }
            if ((string) ($invoice['status'] ?? 'draft') === 'draft') {
                continue;
            }
            $uuid = (string) $invoice['uuid'];
            $seen[$uuid] = true;
            $rows[] = ['company_uuid' => $companyUuid, 'local_type' => 'invoice', 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0];
        }
        foreach ($ledger->links as $link) {
            if (($link['company_uuid'] ?? '') !== $companyUuid || ($link['local_type'] ?? '') !== 'invoice') {
                continue;
            }
            $uuid = (string) ($link['local_uuid'] ?? '');
            if ($uuid === '' || isset($seen[$uuid]) || !isset($ledger->invoices[$uuid])) {
                continue;
            }
            $seen[$uuid] = true;
            $rows[] = ['company_uuid' => $companyUuid, 'local_type' => 'invoice', 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0];
        }

        foreach (array_slice($rows, $limit) as $row) {
            $ledger->upsertPending($companyUuid, (string) $row['local_type'], (string) $row['local_uuid'], 'reconcile');
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     *
     * @return array<string, mixed>
     */
    private function process(SyncLedger $ledger, array $connection, array $rows, array $settings, int $now, string $trigger, bool $reportUnmatched): array
    {
        $this->customTxnNumbers = [];
        $batch                  = [
            'company_uuid' => $connection['company_uuid'],
            'trigger'      => $trigger,
            'direction'    => 'outbound',
            'status'       => 'running',
            'created'      => 0,
            'updated'      => 0,
            'aligned'      => 0,
            'voided'       => 0,
            'unmatched'    => 0,
            'failed'       => 0,
            'skipped'      => 0,
            'started_at'   => $now,
            'finished_at'  => null,
        ];

        $companyUuid = (string) $connection['company_uuid'];
        $ledger->rebuildIndex();
        $counts = [];
        foreach ($rows as $row) {
            $type          = (string) ($row['local_type'] ?? 'invoice');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        $handled = [];
        foreach ($rows as $row) {
            if ($this->isRateLimited($connection, $now)) {
                break;
            }
            $type = (string) ($row['local_type'] ?? 'invoice');
            $key  = $type . '|' . (string) ($row['local_uuid'] ?? '');
            if (isset($handled[$key])) {
                continue;
            }
            if (($counts[$type] ?? 0) > 1) {
                $block = [];
                foreach ($rows as $candidate) {
                    if ((string) ($candidate['local_type'] ?? 'invoice') !== $type) {
                        continue;
                    }
                    $block[] = $candidate;
                    $handled[(string) ($candidate['local_type'] ?? 'invoice') . '|' . (string) ($candidate['local_uuid'] ?? '')] = true;
                }
                $halt = match ($type) {
                    'customer' => $this->syncCustomerBlock($ledger, $connection, $block, $settings, $batch, $now, $trigger, true),
                    'wallet'   => $this->syncWalletBlock($ledger, $connection, $block, $settings, $batch, $now, $trigger),
                    default    => $this->syncInvoiceBlock($ledger, $connection, $block, $settings, $batch, $now, $trigger),
                };
                $connection = $ledger->connection($companyUuid) ?? $connection;
                if ($halt) {
                    break;
                }
                continue;
            }

            $handled[$key] = true;
            $this->runOne($ledger, $connection, $row, $settings, $batch, $now, $trigger);
            $connection = $ledger->connection($companyUuid) ?? $connection;
            if (!empty($ledger->connections[$companyUuid]['needs_reauth'])) {
                break;
            }
        }

        if (!$this->isRateLimited($connection, $now)) {
            $ledger->connections[$companyUuid]['last_rate_limit_wait'] = null;
        }

        if ($reportUnmatched) {
            $batch['unmatched'] += $this->countUnmatched($ledger, $connection);
        }

        $batch['status']      = 'finished';
        $batch['finished_at'] = $now;
        $ledger->batches[]    = $batch;

        return $batch;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     */
    private function syncCustomer(SyncLedger $ledger, array $connection, string $uuid, array $settings): string
    {
        $direction = $this->direction($settings, 'customer');
        if ($this->entityPaused($settings, 'customer')) {
            $this->closeSkipped = true;

            return 'skipped';
        }

        $customer = $ledger->customers[$uuid] ?? null;
        if ($customer === null) {
            $this->lastError = 'Customer no longer exists in Fleetbase.';

            return 'failed';
        }

        $copy      = $this->copiesRemote($direction);
        $push      = $this->pushesRemote($direction);
        $reference = (string) ($settings['customer_reference'] ?? 'fleetbase');
        $conflict  = (string) ($settings['customer_conflict'] ?? 'fleetbase');
        $payload   = $this->customers->toQuickBooks($this->customers->fromParty($customer));
        $email     = trim((string) ($customer['email'] ?? ''));
        $link      = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $uuid);
        if ($link !== null && $link['realm_id'] !== $connection['realm_id']) {
            $link = null;
        }

        $remote = null;
        if ($this->customerRemoteCache !== null && array_key_exists($uuid, $this->customerRemoteCache) && is_array($this->customerRemoteCache[$uuid])) {
            $remote = $this->customerRemoteCache[$uuid];
            $ledger->putLink($this->linkFrom($connection, 'customer', $uuid, 'Customer', $remote));
            $link = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $uuid);
        } elseif ($link === null && !($this->customerRemoteCache !== null && array_key_exists($uuid, $this->customerRemoteCache))) {
            $remote = $this->findRemoteCustomer($connection, $email, (string) ($payload['DisplayName'] ?? ''), $reference !== 'fleetbase');
            if ($remote !== null) {
                $ledger->putLink($this->linkFrom($connection, 'customer', $uuid, 'Customer', $remote));
                if ($reference === 'quickbooks' && $copy) {
                    $before = (string) ($ledger->customers[$uuid]['name'] ?? '');
                    $this->applyCustomerFromRemote($ledger, $uuid, $remote);
                    $after = (string) ($ledger->customers[$uuid]['name'] ?? '');

                    return $before !== $after ? 'updated' : 'aligned';
                }
            }
        } elseif ($link !== null) {
            $remote = $this->client->getCustomer($connection, (string) $link['qbo_id']);
            if ($remote === null) {
                $remote = $this->findRemoteCustomer($connection, $email, (string) ($payload['DisplayName'] ?? ''), $reference !== 'fleetbase');
                if ($remote !== null) {
                    $ledger->putLink($this->linkFrom($connection, 'customer', $uuid, 'Customer', $remote));
                }
            }
        }
        if ($remote === null) {
            if ($this->lastError !== null) {
                return 'failed';
            }
            if (isset($this->customerLookupFailed[$uuid])) {
                $this->lastError = $this->customerLookupFailed[$uuid];

                return 'failed';
            }
            if (!$push) {
                $this->closeSkipped = true;

                return 'skipped';
            }
            if ($this->writeBuffer !== null) {
                $this->writeBuffer[] = [
                    'bId'       => 'customer:' . $uuid,
                    'operation' => 'create',
                    'entity'    => 'Customer',
                    'payload'   => $payload,
                ];

                return 'created';
            }
            $created = $this->client->createCustomer($connection, $payload);
            $ledger->putLink($this->linkFrom($connection, 'customer', $uuid, 'Customer', $created));

            return 'created';
        }

        if ($this->customerMatches($payload, $remote)) {
            return 'aligned';
        }

        if ($reference === 'quickbooks' && $copy) {
            $name = trim((string) ($remote['DisplayName'] ?? ''));
            if ($name !== '') {
                $ledger->customers[$uuid]['name'] = $name;
            }
            $payload = $this->customers->toQuickBooks($this->customers->fromParty($ledger->customers[$uuid]));
        }

        if ($this->customerMatches($payload, $remote)) {
            return 'updated';
        }

        if (!$push && $copy) {
            $before = $ledger->customers[$uuid];
            $this->applyCustomerFromRemote($ledger, $uuid, $remote);

            return $ledger->customers[$uuid] !== $before ? 'updated' : 'aligned';
        }

        if ($conflict === 'quickbooks' && $copy) {
            $this->applyCustomerFromRemote($ledger, $uuid, $remote);

            return 'updated';
        }

        if ($reference === 'quickbooks') {
            $payload['DisplayName'] = (string) ($remote['DisplayName'] ?? $payload['DisplayName'] ?? '');
        }

        if (!$push) {
            return 'aligned';
        }

        if ($this->writeBuffer !== null) {
            $payload['Id']        = (string) $remote['Id'];
            $payload['SyncToken'] = (string) $remote['SyncToken'];
            $payload['sparse']    = true;
            $this->writeBuffer[]  = [
                'bId'       => 'customer:' . $uuid,
                'operation' => 'update',
                'entity'    => 'Customer',
                'payload'   => $payload,
            ];

            return 'updated';
        }

        $updated = $this->client->updateCustomer($connection, (string) $remote['Id'], (string) $remote['SyncToken'], $payload);
        $ledger->putLink($this->linkFrom($connection, 'customer', $uuid, 'Customer', $updated));

        return 'updated';
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     */
    private function syncWallet(SyncLedger $ledger, array $connection, string $uuid, array $settings): string
    {
        $direction = $this->direction($settings, 'wallet');
        if ($this->entityPaused($settings, 'wallet')) {
            $this->closeSkipped = true;

            return 'skipped';
        }

        $wallet = $ledger->wallets[$uuid] ?? null;
        if ($wallet === null) {
            $this->lastError = 'Wallet no longer exists in Fleetbase.';

            return 'failed';
        }

        $copy      = $this->copiesRemote($direction);
        $push      = $this->pushesRemote($direction);
        $reference = (string) ($settings['wallet_reference'] ?? 'fleetbase');
        $conflict  = (string) ($settings['wallet_conflict'] ?? 'fleetbase');
        $payload   = $this->wallets->toQuickBooks($wallet, $reference);
        $link      = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'wallet', $uuid);
        if ($link !== null && $link['realm_id'] !== $connection['realm_id']) {
            $link = null;
        }

        if ($link === null && !empty($payload['AcctNum'])) {
            $acctNum = (string) $payload['AcctNum'];
            if (isset($this->accountFieldFailed['AcctNum:' . $acctNum])) {
                $this->lastError = $this->accountFieldFailed['AcctNum:' . $acctNum];

                return 'failed';
            }
            $existing = $this->accountByAcctNum !== null
                ? ($this->accountByAcctNum[$acctNum] ?? null)
                : $this->client->findAccountByAcctNum($connection, $acctNum);
            if ($existing !== null) {
                $ledger->putLink($this->linkFrom($connection, 'wallet', $uuid, 'Account', $existing));
                $link = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'wallet', $uuid);
            }
        }

        $quickbooksPrimary = $reference === 'quickbooks' || $conflict === 'quickbooks';
        $remote            = null;
        if ($link !== null) {
            $accountId = (string) $link['qbo_id'];
            if (isset($this->accountReadFailed[$accountId])) {
                $this->lastError = $this->accountReadFailed[$accountId];

                return 'failed';
            }
            $remote = $this->accountById !== null
                ? ($this->accountById[$accountId] ?? null)
                : $this->client->getAccount($connection, $accountId);
        }
        if ($remote === null) {
            $acctNum = trim((string) ($payload['AcctNum'] ?? ''));
            $name    = trim((string) ($payload['Name'] ?? ''));
            if ($link === null && $acctNum !== '' && isset($this->accountFieldFailed['AcctNum:' . $acctNum])) {
                $this->lastError = $this->accountFieldFailed['AcctNum:' . $acctNum];

                return 'failed';
            }
            if ($name !== '' && isset($this->accountFieldFailed['Name:' . $name])) {
                $this->lastError = $this->accountFieldFailed['Name:' . $name];

                return 'failed';
            }
            $remote = $this->reattachWallet($ledger, $connection, $uuid, $wallet, $payload);
            $link   = $remote === null ? null : $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'wallet', $uuid);
        }
        if ($remote === null) {
            if (!$quickbooksPrimary) {
                $this->lastError = $this->currencyError($ledger, $connection, (string) ($wallet['currency'] ?? ''), 'Wallet');
                if ($this->lastError !== null) {
                    return 'failed';
                }
            }
            if (!$push) {
                $this->closeSkipped = true;

                return 'skipped';
            }
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
            if ($copy) {
                $this->applyWalletFromRemote($ledger, $uuid, $created, $reference);
                if ($quickbooksPrimary) {
                    $this->copyWalletCurrency($ledger, $connection, $uuid, $created);
                }
            }

            return 'created';
        }

        $before = $wallet;
        if ($quickbooksPrimary && $copy) {
            $this->applyWalletFromRemote($ledger, $uuid, $remote, $reference);
            $this->copyWalletCurrency($ledger, $connection, $uuid, $remote);
            $wallet  = $ledger->wallets[$uuid];
            $payload = $this->wallets->toQuickBooks($wallet, $reference);
        }

        if ($this->wallets->matches($wallet, $payload, $remote)) {
            return $wallet !== $before ? 'updated' : 'aligned';
        }

        if ($conflict === 'quickbooks' && $copy) {
            $before = $ledger->wallets[$uuid];
            $this->applyWalletFromRemote($ledger, $uuid, $remote, $reference);
            $this->copyWalletCurrency($ledger, $connection, $uuid, $remote);

            return $ledger->wallets[$uuid] !== $before ? 'updated' : 'aligned';
        }

        if (!$push) {
            if ($copy) {
                $before = $ledger->wallets[$uuid];
                $this->applyWalletFromRemote($ledger, $uuid, $remote, $reference);

                return $ledger->wallets[$uuid] !== $before ? 'updated' : 'aligned';
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
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     */
    private function syncInvoice(SyncLedger $ledger, array $connection, string $uuid, array $settings): string
    {
        $direction = $this->direction($settings, 'invoice');
        if ($this->entityPaused($settings, 'invoice')) {
            $this->closeSkipped = true;

            return 'skipped';
        }

        $invoice = $ledger->invoices[$uuid] ?? null;
        if ($invoice === null) {
            $this->lastError = 'Invoice no longer exists in Fleetbase.';

            return 'failed';
        }

        $this->lastError = $this->currencyError($ledger, $connection, (string) ($invoice['currency'] ?? ''), 'Invoice');
        if ($this->lastError !== null) {
            return 'failed';
        }

        $copy = $this->copiesRemote($direction);
        $push = $this->pushesRemote($direction);
        $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
        if ($customerUuid !== '' && $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $customerUuid) === null) {
            if ($this->entityPaused($settings, 'customer')) {
                $this->lastError = 'Customer sync is off, so this invoice cannot be synced.';

                return 'failed';
            }
            if ($this->customersPrepared) {
                $this->lastError = 'Invoice customer could not be synced to QuickBooks.';

                return 'failed';
            }
            $customerOutcome = $this->syncCustomer($ledger, $connection, $customerUuid, $settings);
            if ($customerOutcome === 'failed') {
                $this->lastError ??= 'Invoice customer could not be synced to QuickBooks.';

                return 'failed';
            }
        }

        $customerLink = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $customerUuid);
        if ($customerLink === null) {
            $this->lastError ??= 'Invoice customer is not a Fleetbase customer.';

            return 'failed';
        }

        $status = (string) ($invoice['status'] ?? '');
        $voided = in_array($status, ['void', 'voided', 'cancelled', 'canceled'], true) || !empty($invoice['deleted_at']);
        $itemId = $voided ? '' : $this->serviceItemId($ledger, $connection);
        if (!$voided && $itemId === '') {
            $this->lastError = 'QuickBooks has no item for invoice lines. Fleetbase could not create its "Fleetbase service" item; add an Income account in QuickBooks and try again.';

            return 'failed';
        }

        $payload   = $this->invoices->toQuickBooks($invoice, (string) $customerLink['qbo_id'], $itemId);
        $reference = (string) ($settings['invoice_reference'] ?? 'fleetbase');
        $conflict  = (string) ($settings['invoice_conflict'] ?? 'fleetbase');
        if ($reference === 'quickbooks') {
            unset($payload['DocNumber']);
        }
        $link = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'invoice', $uuid);

        if ($link === null && !$voided && !empty($invoice['number'])) {
            $number = (string) $invoice['number'];
            if (isset($this->invoiceDocReadFailed[$number])) {
                $this->lastError = $this->invoiceDocReadFailed[$number];

                return 'failed';
            }
            $existing = $this->invoiceByDoc !== null
                ? ($this->invoiceByDoc[$number] ?? null)
                : $this->client->findInvoiceByDocNumber($connection, $number);
            if ($existing !== null) {
                $ledger->putLink($this->linkFrom($connection, 'invoice', $uuid, 'Invoice', $existing));
                $link      = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'invoice', $uuid);
                $matchedId = trim((string) ($existing['Id'] ?? ''));
                if ($this->invoiceById !== null && $matchedId !== '') {
                    $this->invoiceById[$matchedId] = $existing;
                }
            }
        }

        if ($voided) {
            if ($link === null) {
                return 'aligned';
            }
            $remoteId = (string) $link['qbo_id'];
            if (isset($this->invoiceReadFailed[$remoteId])) {
                $this->lastError = $this->invoiceReadFailed[$remoteId];

                return 'failed';
            }
            $remote = $this->invoiceById !== null
                ? ($this->invoiceById[$remoteId] ?? null)
                : $this->client->getInvoice($connection, $remoteId);
            if ($remote === null) {
                return 'aligned';
            }
            if (!$push) {
                return 'aligned';
            }
            if ($this->writeBuffer !== null) {
                $this->writeBuffer[] = [
                    'bId'       => 'invoice:' . $uuid,
                    'operation' => 'void',
                    'entity'    => 'Invoice',
                    'payload'   => ['Id' => (string) $remote['Id'], 'SyncToken' => (string) $remote['SyncToken']],
                ];

                return 'voided';
            }
            $this->client->voidInvoice($connection, (string) $remote['Id'], (string) $remote['SyncToken']);

            return 'voided';
        }

        $remote = null;
        if ($link !== null) {
            $remoteId = (string) $link['qbo_id'];
            if (isset($this->invoiceReadFailed[$remoteId])) {
                $this->lastError = $this->invoiceReadFailed[$remoteId];

                return 'failed';
            }
            $remote = $this->invoiceById !== null
                ? ($this->invoiceById[$remoteId] ?? null)
                : $this->client->getInvoice($connection, $remoteId);
        }
        if ($remote === null) {
            if (!$push) {
                $this->closeSkipped = true;

                return 'skipped';
            }
            if ($reference === 'quickbooks' && $this->customTxnNumbers($connection)) {
                $next = $this->reservedDocNumbers !== null
                    ? ($this->reservedDocNumbers[$uuid] ?? null)
                    : $this->client->nextInvoiceDocNumber($connection);
                if (is_string($next) && $next !== '') {
                    $payload['DocNumber'] = $next;
                }
            }
            if ($this->writeBuffer !== null) {
                $this->writeBuffer[] = [
                    'bId'       => 'invoice:' . $uuid,
                    'operation' => 'create',
                    'entity'    => 'Invoice',
                    'payload'   => $payload,
                ];

                return 'created';
            }
            $created = $this->client->createInvoice($connection, $payload);
            $ledger->putLink($this->linkFrom($connection, 'invoice', $uuid, 'Invoice', $created));
            $this->copyInvoiceNumber($ledger, $uuid, $created, $reference);

            return $this->withPayment('created', $this->syncPayment($ledger, $connection, $ledger->invoices[$uuid], (string) $customerLink['qbo_id'], (string) ($created['Id'] ?? ''), $settings));
        }

        $numberChanged = $this->copyInvoiceNumber($ledger, $uuid, $remote, $reference);
        $invoice       = $ledger->invoices[$uuid];
        $remoteCents   = $this->majorUnits($remote['TotalAmt'] ?? 0);
        $localCents    = (int) ($invoice['total'] ?? 0);
        if ($this->invoiceHashesMatch($invoice, $remote, $conflict, $remoteCents, $localCents, $reference)) {
            if ($this->writeBuffer !== null) {
                return $numberChanged ? 'updated' : 'aligned';
            }

            return $this->withPayment(
                $numberChanged ? 'updated' : 'aligned',
                $this->syncPayment($ledger, $connection, $invoice, (string) $customerLink['qbo_id'], (string) $remote['Id'], $settings)
            );
        }

        if (($conflict === 'quickbooks' && $copy) || (!$push && $copy)) {
            $beforeStatus = (string) ($ledger->invoices[$uuid]['status'] ?? '');
            $changed      = $this->applyInvoiceFromRemote($ledger, $uuid, $remote, $reference) || $numberChanged;
            $this->applyPaymentStatus($ledger, $uuid);
            if ((string) ($ledger->invoices[$uuid]['status'] ?? '') !== $beforeStatus) {
                $changed = true;
            }

            $sendNumber = $push && $this->fleetbaseDocNumberDiffers($ledger->invoices[$uuid], $remote, $reference);
            if (!$sendNumber) {
                if ($this->writeBuffer !== null) {
                    return $changed ? 'updated' : 'aligned';
                }

                return $this->withPayment(
                    $changed ? 'updated' : 'aligned',
                    $this->syncPayment($ledger, $connection, $ledger->invoices[$uuid], (string) $customerLink['qbo_id'], (string) $remote['Id'], $settings)
                );
            }

            $payload = $this->invoices->toQuickBooks($ledger->invoices[$uuid], (string) $customerLink['qbo_id'], $itemId);
        }

        if (!$push) {
            return $numberChanged ? 'updated' : 'aligned';
        }

        $this->keepFleetbaseDocNumber($payload, $ledger->invoices[$uuid], $remote, $reference);

        if ($this->writeBuffer !== null) {
            $payload['Id']        = (string) $remote['Id'];
            $payload['SyncToken'] = (string) $remote['SyncToken'];
            $payload['sparse']    = true;
            $this->writeBuffer[]  = [
                'bId'       => 'invoice:' . $uuid,
                'operation' => 'update',
                'entity'    => 'Invoice',
                'payload'   => $payload,
            ];

            return 'updated';
        }

        $updated = $this->client->updateInvoice($connection, (string) $remote['Id'], (string) $remote['SyncToken'], $payload);
        $ledger->putLink($this->linkFrom($connection, 'invoice', $uuid, 'Invoice', $updated));

        return $this->withPayment('updated', $this->syncPayment($ledger, $connection, $ledger->invoices[$uuid], (string) $customerLink['qbo_id'], (string) ($updated['Id'] ?? $remote['Id']), $settings));
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $settings
     */
    private function syncPayment(SyncLedger $ledger, array $connection, array $invoice, string $customerRef, string $invoiceId, array $settings): ?string
    {
        if ($invoiceId === '') {
            return null;
        }

        $payDirection = $this->direction($settings, 'payment');
        if ($this->entityPaused($settings, 'payment')) {
            return null;
        }
        $copyPay = $this->copiesRemote($payDirection);
        $pushPay = $this->pushesRemote($payDirection);

        $reference         = (string) ($settings['payment_reference'] ?? 'fleetbase');
        $conflict          = (string) ($settings['payment_conflict'] ?? 'fleetbase');
        $quickbooksPrimary = ($reference === 'quickbooks' || $conflict === 'quickbooks') && $copyPay;
        $status            = (string) ($invoice['status'] ?? '');
        $pushesPayment     = in_array($status, ['paid', 'partial'], true);
        if (!$pushesPayment && !$quickbooksPrimary) {
            return null;
        }

        $uuid   = (string) $invoice['uuid'];
        $link   = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'payment', $uuid);
        $remote = null;
        if ($link !== null) {
            $paymentId = (string) $link['qbo_id'];
            if (isset($this->paymentReadFailed[$paymentId])) {
                throw new QuickBooksException(500, $this->paymentReadFailed[$paymentId]);
            }
            $remote    = $this->paymentById !== null
                ? ($this->paymentById[$paymentId] ?? null)
                : $this->client->getPayment($connection, $paymentId);
        }
        if ($remote === null) {
            $state = $this->invoicePaymentState($connection, $customerRef, $invoiceId);
            if (is_array($state['payment'])) {
                $remote = $state['payment'];
            } elseif ($state['settled'] && $quickbooksPrimary) {
                $before = $ledger->invoices[$uuid];
                $ledger->invoices[$uuid]['amount_paid'] = (int) ($ledger->invoices[$uuid]['total'] ?? 0);
                $ledger->invoices[$uuid]['payment_from_quickbooks'] = true;
                $this->applyPaymentStatus($ledger, $uuid);

                return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]) ? 'updated' : null;
            } elseif (!$pushesPayment || $state['settled'] || !$pushPay) {
                return null;
            } else {
                $payload = $this->invoices->payment($customerRef, $invoiceId, $this->paymentAmountCents($invoice), $this->paymentDate($invoice));
                if ($this->writeBuffer !== null) {
                    $this->writeBuffer[] = [
                        'bId'       => 'payment:' . $uuid,
                        'operation' => 'create',
                        'entity'    => 'Payment',
                        'payload'   => $payload,
                    ];

                    return null;
                }
                $created = $this->client->createPayment($connection, $payload);
                $ledger->putLink($this->linkFrom($connection, 'payment', $uuid, 'Payment', $created));

                return null;
            }
        }

        if (!$pushesPayment) {
            if (!$copyPay) {
                return null;
            }
            $ledger->putLink($this->linkFrom($connection, 'payment', $uuid, 'Payment', $remote));
            $before = $ledger->invoices[$uuid];
            $this->applyPaymentFromRemote($ledger, $uuid, $remote, $invoiceId);
            $this->applyPaymentStatus($ledger, $uuid);

            return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]) ? 'updated' : null;
        }

        $payload = $this->invoices->payment($customerRef, $invoiceId, $this->paymentAmountCents($invoice), $this->paymentDate($invoice));
        if ($link === null) {
            $ledger->putLink($this->linkFrom($connection, 'payment', $uuid, 'Payment', $remote));
        }

        if ($this->paymentMatches($invoice, $remote, $invoiceId)) {
            return null;
        }

        if ($conflict === 'quickbooks' && $copyPay) {
            $before = $ledger->invoices[$uuid];
            $this->applyPaymentFromRemote($ledger, $uuid, $remote, $invoiceId);
            $this->applyPaymentStatus($ledger, $uuid);

            return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]) ? 'updated' : null;
        }

        if (!$pushPay) {
            if ($copyPay) {
                $before = $ledger->invoices[$uuid];
                $this->applyPaymentFromRemote($ledger, $uuid, $remote, $invoiceId);
                $this->applyPaymentStatus($ledger, $uuid);

                return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]) ? 'updated' : null;
            }

            return null;
        }

        if ($this->paymentCoversOtherInvoices($remote, $invoiceId)) {
            $this->lastError = 'This QuickBooks payment also applies to other invoices, so Fleetbase left it unchanged.';

            return 'skipped';
        }

        if ($this->writeBuffer !== null) {
            $payload['Id']        = (string) $remote['Id'];
            $payload['SyncToken'] = (string) $remote['SyncToken'];
            $payload['sparse']    = true;
            $this->writeBuffer[]  = [
                'bId'       => 'payment:' . $uuid,
                'operation' => 'update',
                'entity'    => 'Payment',
                'payload'   => $payload,
            ];

            return 'updated';
        }

        $updated = $this->client->updatePayment($connection, (string) $remote['Id'], (string) $remote['SyncToken'], $payload);
        $ledger->putLink($this->linkFrom($connection, 'payment', $uuid, 'Payment', $updated));

        return 'updated';
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     */
    private function failure(SyncLedger $ledger, array $connection, array $row, \Throwable $exception, array $settings, int $now): string
    {
        $companyUuid = (string) $connection['company_uuid'];
        if ($exception instanceof QuickBooksException && $exception->isRateLimit()) {
            $previous                                                  = isset($connection['last_rate_limit_wait']) ? (int) $connection['last_rate_limit_wait'] : null;
            $wait                                                      = $this->backoff->rateLimitWait($exception->retryAfter, $previous);
            $ledger->connections[$companyUuid]['rate_limited_until']   = $now + $wait;
            $ledger->connections[$companyUuid]['last_rate_limit_wait'] = $wait;

            return 'failed';
        }

        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $wait     = $this->backoff->defaultWait((int) $settings['default_backoff_seconds'], $attempts);
        $status   = $attempts > (int) $settings['retry_limit'] ? 'failed' : 'pending';
        $ledger->updatePending($companyUuid, (string) $row['local_type'], (string) $row['local_uuid'], [
            'attempts'        => $attempts,
            'status'          => $status,
            'next_attempt_at' => $now + $wait,
        ]);

        return 'failed';
    }

    /**
     * A 401 means every remaining call will fail too, so rows keep their attempts and stay pending.
     */
    private function tokenRejected(SyncLedger $ledger, string $companyUuid): void
    {
        $ledger->connections[$companyUuid]['needs_reauth'] = true;
        $ledger->attempts[]                                = [
            'company_uuid' => $companyUuid,
            'local_type'   => 'connection',
            'local_uuid'   => $companyUuid,
            'outcome'      => 'failed',
            'error'        => self::TOKEN_REJECTED_MESSAGE,
        ];
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function isRateLimited(array $connection, int $now): bool
    {
        return !empty($connection['rate_limited_until']) && (int) $connection['rate_limited_until'] > $now;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function customTxnNumbers(array $connection): bool
    {
        $realm = (string) $connection['realm_id'];

        return $this->customTxnNumbers[$realm] ??= $this->client->customTxnNumbers($connection);
    }

    /**
     * The currency check is skipped while the QuickBooks home currency is unknown.
     *
     * @param array<string, mixed> $connection
     */
    private function currencyError(SyncLedger $ledger, array $connection, string $currency, string $label): ?string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return null;
        }

        $home = $this->homeCurrency($ledger, $connection);
        if ($home === '' || $home === $currency) {
            return null;
        }

        return $label . ' currency ' . $currency . ' does not match QuickBooks home currency ' . $home;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function homeCurrency(SyncLedger $ledger, array $connection): string
    {
        $home = strtoupper(trim((string) ($connection['home_currency'] ?? '')));
        if ($home !== '') {
            return $home;
        }

        $home = (string) $this->client->homeCurrency($connection);
        if ($home !== '') {
            $ledger->connections[(string) $connection['company_uuid']]['home_currency'] = $home;
        }

        return $home;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function serviceItemId(SyncLedger $ledger, array $connection): string
    {
        $companyUuid = (string) $connection['company_uuid'];
        $stored      = $ledger->connections[$companyUuid] ?? null;
        $itemId      = trim((string) (is_array($stored) ? ($stored['default_item_id'] ?? '') : ''));
        if ($itemId === '') {
            $itemId = trim((string) ($connection['default_item_id'] ?? ''));
        }
        if ($itemId !== '') {
            return $itemId;
        }
        if ($this->serviceItemByCompany !== null && array_key_exists($companyUuid, $this->serviceItemByCompany)) {
            return $this->serviceItemByCompany[$companyUuid];
        }

        $itemId = $this->client->ensureServiceItem($connection);
        if ($this->serviceItemByCompany !== null) {
            $this->serviceItemByCompany[$companyUuid] = $itemId;
        }
        if ($itemId !== '') {
            $ledger->connections[$companyUuid]['default_item_id'] = $itemId;
        }

        return $itemId;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function countUnmatched(SyncLedger $ledger, array $connection): int
    {
        $count = 0;
        $realm = (string) $connection['realm_id'];
        foreach ($ledger->remoteInvoices as $remote) {
            if (!$ledger->hasRemoteLink($realm, 'Invoice', (string) ($remote['Id'] ?? ''))) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function fleetbaseDocNumberDiffers(array $invoice, array $remote, string $reference): bool
    {
        if ($reference === 'quickbooks') {
            return false;
        }

        $local = trim((string) ($invoice['number'] ?? ''));
        if ($local === '') {
            return false;
        }

        return $local !== trim((string) ($remote['DocNumber'] ?? ''));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function keepFleetbaseDocNumber(array &$payload, array $invoice, array $remote, string $reference): void
    {
        if (!$this->fleetbaseDocNumberDiffers($invoice, $remote, $reference)) {
            return;
        }

        $payload['DocNumber'] = trim((string) ($invoice['number'] ?? ''));
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function invoiceHashesMatch(array $invoice, array $remote, string $conflict, int $remoteCents, int $localCents, string $reference): bool
    {
        if ($this->fleetbaseDocNumberDiffers($invoice, $remote, $reference)) {
            return false;
        }

        $local = ['total' => $localCents];
        $other = ['total' => $remoteCents];
        foreach (['date' => 'TxnDate', 'due_date' => 'DueDate'] as $localKey => $remoteKey) {
            $value = (string) ($invoice[$localKey] ?? '');
            if ($value === '') {
                continue;
            }
            $local[$localKey] = $value;
            $other[$localKey] = (string) ($remote[$remoteKey] ?? '');
        }
        if (array_key_exists('PrivateNote', $remote)) {
            $local['notes'] = (string) ($invoice['notes'] ?? '');
            $other['notes'] = (string) $remote['PrivateNote'];
        }
        if ($conflict === 'quickbooks') {
            $mapped         = $this->invoices->fromQuickBooks($remote);
            $local['items'] = $invoice['items'] ?? [];
            $local['tax']   = (int) ($invoice['tax'] ?? 0);
            $other['items'] = $mapped['items'];
            $other['tax']   = $mapped['tax'];
        }

        return ContentHash::of($local) === ContentHash::of($other);
    }

    private function majorUnits(mixed $amount): int
    {
        if (is_float($amount)) {
            throw new \InvalidArgumentException('Money must not be a float.');
        }
        if (!is_int($amount) && !is_string($amount)) {
            return 0;
        }

        return Amounts::toMinorUnits($amount);
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
    private function reattachWallet(SyncLedger $ledger, array $connection, string $uuid, array $wallet, array $payload): ?array
    {
        $name = trim((string) ($payload['Name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $currency = strtoupper(trim((string) ($wallet['currency'] ?? '')));
        $matches  = [];
        $accounts = $this->accountsByName !== null
            ? ($this->accountsByName[$name] ?? [])
            : $this->client->findAccountsByName($connection, $name);
        foreach ($accounts as $account) {
            if (!is_array($account)) {
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

        $ledger->putLink($this->linkFrom($connection, 'wallet', $uuid, 'Account', $matches[0]));

        return $matches[0];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     */
    private function customerMatches(array $payload, array $remote): bool
    {
        $local = [
            'email' => (string) ($payload['PrimaryEmailAddr']['Address'] ?? ''),
            'name'  => (string) ($payload['DisplayName'] ?? ''),
            'phone' => (string) ($payload['PrimaryPhone']['FreeFormNumber'] ?? ''),
        ];
        if (array_key_exists('Notes', $payload)) {
            $local['notes'] = (string) $payload['Notes'];
        }
        if (isset($payload['BillAddr']) && is_array($payload['BillAddr'])) {
            $local['address'] = [
                'city'        => (string) ($payload['BillAddr']['City'] ?? ''),
                'country'     => (string) ($payload['BillAddr']['Country'] ?? ''),
                'line1'       => (string) ($payload['BillAddr']['Line1'] ?? ''),
                'postal_code' => (string) ($payload['BillAddr']['PostalCode'] ?? ''),
                'state'       => (string) ($payload['BillAddr']['CountrySubDivisionCode'] ?? ''),
            ];
        }

        $other = [
            'email' => (string) ($remote['PrimaryEmailAddr']['Address'] ?? ''),
            'name'  => (string) ($remote['DisplayName'] ?? ''),
            'phone' => (string) ($remote['PrimaryPhone']['FreeFormNumber'] ?? ''),
        ];
        if (array_key_exists('notes', $local)) {
            $other['notes'] = (string) ($remote['Notes'] ?? '');
        }
        if (array_key_exists('address', $local)) {
            $bill = is_array($remote['BillAddr'] ?? null) ? $remote['BillAddr'] : [];
            $other['address'] = [
                'city'        => (string) ($bill['City'] ?? ''),
                'country'     => (string) ($bill['Country'] ?? ''),
                'line1'       => (string) ($bill['Line1'] ?? ''),
                'postal_code' => (string) ($bill['PostalCode'] ?? ''),
                'state'       => (string) ($bill['CountrySubDivisionCode'] ?? ''),
            ];
        }

        return ContentHash::of($local) === ContentHash::of($other);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private function linkFrom(array $connection, string $localType, string $localUuid, string $entity, array $remote): array
    {
        return [
            'company_uuid' => $connection['company_uuid'],
            'realm_id'     => $connection['realm_id'],
            'local_type'   => $localType,
            'local_uuid'   => $localUuid,
            'qbo_entity'   => $entity,
            'qbo_id'       => (string) ($remote['Id'] ?? ''),
            'sync_token'   => (string) ($remote['SyncToken'] ?? '0'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBatch(string $trigger, string $status): array
    {
        return [
            'trigger'     => $trigger,
            'direction'   => 'outbound',
            'status'      => $status,
            'created'     => 0,
            'updated'     => 0,
            'aligned'     => 0,
            'voided'      => 0,
            'unmatched'   => 0,
            'failed'      => 0,
        ];
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function copyInvoiceNumber(SyncLedger $ledger, string $uuid, array $remote, string $reference): bool
    {
        if ($reference !== 'quickbooks') {
            return false;
        }

        $number = trim((string) ($remote['DocNumber'] ?? ''));
        if ($number === '' || $number === (string) ($ledger->invoices[$uuid]['number'] ?? '')) {
            return false;
        }

        $ledger->invoices[$uuid]['number'] = $number;
        $ledger->rememberInvoice($uuid, $ledger->invoices[$uuid]);

        return true;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function applyInvoiceFromRemote(SyncLedger $ledger, string $uuid, array $remote, string $reference): bool
    {
        $changed = $this->copyInvoiceNumber($ledger, $uuid, $remote, $reference);
        foreach (['TxnDate' => 'date', 'DueDate' => 'due_date'] as $remoteKey => $local) {
            $value = (string) ($remote[$remoteKey] ?? '');
            if ($value !== '' && (string) ($ledger->invoices[$uuid][$local] ?? '') !== $value) {
                $ledger->invoices[$uuid][$local] = $value;
                $changed                         = true;
            }
        }
        if (array_key_exists('PrivateNote', $remote) && (string) $remote['PrivateNote'] !== (string) ($ledger->invoices[$uuid]['notes'] ?? '')) {
            $ledger->invoices[$uuid]['notes'] = (string) $remote['PrivateNote'];
            $changed                          = true;
        }

        $mapped = $this->invoices->fromQuickBooks($remote);
        if ($mapped['items'] !== [] && $mapped['items'] !== ($ledger->invoices[$uuid]['items'] ?? [])) {
            $ledger->invoices[$uuid]['items']                 = $mapped['items'];
            $ledger->invoices[$uuid]['items_from_quickbooks'] = true;
            $changed                                          = true;
        }
        if ((int) ($ledger->invoices[$uuid]['total'] ?? 0) !== $mapped['total']) {
            $ledger->invoices[$uuid]['total'] = $mapped['total'];
            $changed                          = true;
        }
        if ((int) ($ledger->invoices[$uuid]['tax'] ?? 0) !== $mapped['tax']) {
            $ledger->invoices[$uuid]['tax'] = $mapped['tax'];
            $changed                        = true;
        }
        if ($changed) {
            $ledger->invoices[$uuid]['replace_from_quickbooks'] = true;
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function applyCustomerFromRemote(SyncLedger $ledger, string $uuid, array $remote): void
    {
        $name = trim((string) ($remote['DisplayName'] ?? ''));
        if ($name !== '') {
            $ledger->customers[$uuid]['name'] = $name;
        }
        $email = (string) ($remote['PrimaryEmailAddr']['Address'] ?? '');
        if ($email !== '') {
            $ledger->customers[$uuid]['email'] = $email;
        }
        $phone = (string) ($remote['PrimaryPhone']['FreeFormNumber'] ?? '');
        if ($phone !== '') {
            $ledger->customers[$uuid]['phone'] = $phone;
        }
        $ledger->touchCustomer($uuid);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function applyWalletFromRemote(SyncLedger $ledger, string $uuid, array $remote, string $reference): void
    {
        $name = trim((string) ($remote['Name'] ?? ''));
        if ($name !== '') {
            $ledger->wallets[$uuid]['name'] = $name;
        }

        if (array_key_exists('Description', $remote)) {
            $ledger->wallets[$uuid]['description'] = (string) $remote['Description'];
        }

        $currency = strtoupper(trim((string) ($remote['CurrencyRef']['value'] ?? '')));
        if ($currency !== '') {
            $ledger->wallets[$uuid]['currency'] = $currency;
        }

        if (array_key_exists('Active', $remote)) {
            $ledger->wallets[$uuid]['status'] = (bool) $remote['Active'] ? 'active' : 'closed';
        }

        if ($reference !== 'quickbooks') {
            return;
        }

        $acctNum = trim((string) ($remote['AcctNum'] ?? ''));
        if ($acctNum !== '') {
            $ledger->wallets[$uuid]['acct_num'] = $acctNum;
            $meta                               = $ledger->wallets[$uuid]['meta'] ?? [];
            if (!is_array($meta)) {
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
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function findRemoteCustomer(array $connection, string $email, string $displayName, bool $allowNameWhenEmailsDiffer = false): ?array
    {
        try {
            $email = trim($email);
            if ($email !== '') {
                $found = $this->client->findCustomerByEmail($connection, $email);
                if ($found !== null) {
                    return $found;
                }
            }

            $name = trim($displayName);
            if ($name === '') {
                return null;
            }

            $byName = $this->client->findCustomerByDisplayName($connection, $name);
            if ($byName === null || $allowNameWhenEmailsDiffer || $email === '') {
                return $byName;
            }

            $remoteEmail = strtolower(trim((string) ($byName['PrimaryEmailAddr']['Address'] ?? '')));
            if ($remoteEmail === '' || $remoteEmail === strtolower($email)) {
                return $byName;
            }

            return null;
        } catch (QuickBooksException $exception) {
            if ($exception->status === 401 || $exception->status === 429) {
                throw $exception;
            }

            $this->lastError = $this->exceptionError($exception);

            return null;
        }
    }

    /**
     * Balance 0, or a payment already applied to this invoice, means QuickBooks
     * already recorded the payment. Link that payment instead of creating one.
     *
     * @param array<string, mixed> $connection
     *
     * @return array{payment: array<string, mixed>|null, settled: bool}
     */
    private function invoicePaymentState(array $connection, string $customerRef, string $invoiceId): array
    {
        $payment = null;
        $settled = false;
        if ($this->blockPayments !== null) {
            $payment = $this->blockPayments[$invoiceId] ?? null;
            $invoice = $this->invoiceById[$invoiceId] ?? null;
            if (is_array($invoice)) {
                $balance = $invoice['Balance'] ?? null;
                if ($balance !== null && $balance !== '' && $this->majorUnits($balance) === 0) {
                    $settled = true;
                }
            }
            if ($payment !== null) {
                $settled = true;
            }

            return ['payment' => $payment, 'settled' => $settled];
        }
        $invoice = $this->client->getInvoice($connection, $invoiceId);
        if (is_array($invoice)) {
            $balance = $invoice['Balance'] ?? null;
            if ($balance !== null && $balance !== '' && $this->majorUnits($balance) === 0) {
                $settled = true;
            }
            $payment = $this->paymentLinkedOnInvoice($connection, $invoice);
        }
        if ($payment === null) {
            $payment = $this->client->findPaymentForInvoice($connection, $customerRef, $invoiceId);
        }
        if ($payment !== null) {
            $settled = true;
        }

        return ['payment' => $payment, 'settled' => $settled];
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $invoice
     *
     * @return array<string, mixed>|null
     */
    private function paymentLinkedOnInvoice(array $connection, array $invoice): ?array
    {
        $txns = $invoice['LinkedTxn'] ?? [];
        if (!is_array($txns)) {
            return null;
        }
        foreach ($txns as $txn) {
            if (!is_array($txn) || (string) ($txn['TxnType'] ?? '') !== 'Payment') {
                continue;
            }
            $id = trim((string) ($txn['TxnId'] ?? ''));
            if ($id === '') {
                continue;
            }
            $payment = $this->client->getPayment($connection, $id);
            if ($payment !== null) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function paymentDate(array $invoice): string
    {
        $paid = trim((string) ($invoice['paid_at'] ?? ''));
        if ($paid !== '') {
            return substr($paid, 0, 10);
        }

        return trim((string) ($invoice['date'] ?? ''));
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function paymentMatches(array $invoice, array $remote, string $invoiceId): bool
    {
        $remoteCents = $this->amountOnInvoice($remote, $invoiceId);
        if ($remoteCents === null || $remoteCents !== $this->paymentAmountCents($invoice)) {
            return false;
        }

        $localDate  = $this->paymentDate($invoice);
        $remoteDate = substr(trim((string) ($remote['TxnDate'] ?? '')), 0, 10);
        if ($localDate !== '' && $remoteDate !== '' && $localDate !== $remoteDate) {
            return false;
        }

        return true;
    }

    /**
     * Compare the amount written onto the invoice. Invoice total is only the
     * stand-in before a payment amount has been recorded.
     *
     * @param array<string, mixed> $invoice
     */
    private function paymentAmountCents(array $invoice): int
    {
        if (!empty($invoice['payment_from_quickbooks'])) {
            return (int) ($invoice['amount_paid'] ?? 0);
        }

        if (array_key_exists('amount_paid', $invoice) && (int) $invoice['amount_paid'] > 0) {
            return (int) $invoice['amount_paid'];
        }

        return (int) ($invoice['total'] ?? 0);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function applyPaymentFromRemote(SyncLedger $ledger, string $uuid, array $remote, string $invoiceId): void
    {
        $date = substr(trim((string) ($remote['TxnDate'] ?? '')), 0, 10);
        if ($date !== '') {
            $ledger->invoices[$uuid]['paid_at'] = $date;
        }
        $amount = $this->amountOnInvoice($remote, $invoiceId);
        if ($amount !== null) {
            $ledger->invoices[$uuid]['amount_paid'] = $amount;
        }
        $ledger->invoices[$uuid]['payment_from_quickbooks'] = true;
    }

    /**
     * A payment can apply to several invoices. The line linked to this invoice
     * is the amount that belongs here. TotalAmt is only a fallback when QuickBooks
     * did not send lines.
     *
     * @param array<string, mixed> $remote
     */
    private function amountOnInvoice(array $remote, string $invoiceId): ?int
    {
        $applied = $this->linkedLineCents($remote, $invoiceId);
        if ($applied !== null) {
            return $applied;
        }
        if ($this->paymentCoversOtherInvoices($remote, $invoiceId) || !array_key_exists('TotalAmt', $remote)) {
            return null;
        }

        return $this->majorUnits($remote['TotalAmt']);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function linkedLineCents(array $remote, string $invoiceId): ?int
    {
        $lines = $remote['Line'] ?? null;
        if (!is_array($lines) || $invoiceId === '') {
            return null;
        }

        $sum   = 0;
        $found = false;
        foreach ($lines as $line) {
            if (!is_array($line) || !$this->lineLinksInvoice($line, $invoiceId)) {
                continue;
            }
            $found = true;
            $sum += $this->majorUnits($line['Amount'] ?? 0);
        }

        return $found ? $sum : null;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function paymentCoversOtherInvoices(array $remote, string $invoiceId): bool
    {
        $lines = $remote['Line'] ?? null;
        if (!is_array($lines)) {
            return false;
        }

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $txns = $line['LinkedTxn'] ?? [];
            if (!is_array($txns)) {
                continue;
            }
            foreach ($txns as $txn) {
                if (!is_array($txn)) {
                    continue;
                }
                $type = (string) ($txn['TxnType'] ?? '');
                if ($type !== '' && $type !== 'Invoice') {
                    continue;
                }
                $id = (string) ($txn['TxnId'] ?? '');
                if ($id !== '' && $id !== $invoiceId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function lineLinksInvoice(array $line, string $invoiceId): bool
    {
        $txns = $line['LinkedTxn'] ?? [];
        if (!is_array($txns)) {
            return false;
        }

        foreach ($txns as $txn) {
            if (!is_array($txn)) {
                continue;
            }
            $type = (string) ($txn['TxnType'] ?? '');
            if ($type !== '' && $type !== 'Invoice') {
                continue;
            }
            if ((string) ($txn['TxnId'] ?? '') === $invoiceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The QuickBooks payment flag only tells the directory to persist. It is not
     * stored, so setting it again is not a change the activity log should count.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function paymentRecordChanged(array $before, array $after): bool
    {
        foreach (['amount_paid', 'paid_at', 'status'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function applyPaymentStatus(SyncLedger $ledger, string $uuid): void
    {
        $paid  = (int) ($ledger->invoices[$uuid]['amount_paid'] ?? 0);
        $total = (int) ($ledger->invoices[$uuid]['total'] ?? 0);
        if ($paid >= $total) {
            $ledger->invoices[$uuid]['status'] = 'paid';

            return;
        }
        if ($paid > 0 && $paid < $total) {
            $ledger->invoices[$uuid]['status'] = 'partial';
        }
    }

    private function withPayment(string $outcome, ?string $paymentOutcome): string
    {
        if ($paymentOutcome === 'skipped' && $outcome === 'aligned') {
            return 'skipped';
        }
        if ($paymentOutcome === 'updated' && ($outcome === 'aligned' || $outcome === 'created')) {
            return 'updated';
        }

        return $outcome;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordSkipped(SyncLedger $ledger, string $companyUuid, string $trigger, string $message): array
    {
        $now                     = time();
        $batch                   = $this->emptyBatch($trigger, 'skipped');
        $batch['company_uuid']   = $companyUuid;
        $batch['started_at']     = $now;
        $batch['finished_at']    = $now;
        $ledger->batches[]       = $batch;
        $ledger->attempts[]      = [
            'company_uuid' => $companyUuid,
            'local_type'   => 'connection',
            'local_uuid'   => $companyUuid,
            'outcome'      => 'skipped',
            'error'        => $message,
        ];

        return $batch;
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<string, mixed>             $row
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function runOne(SyncLedger $ledger, array &$connection, array $row, array $settings, array &$batch, int $now, string $trigger): void
    {
        $companyUuid = (string) $connection['company_uuid'];
        $retryError  = null;
        try {
            $outcome = match ($row['local_type']) {
                'customer' => $this->syncCustomer($ledger, $connection, (string) $row['local_uuid'], $settings),
                'wallet'   => $this->syncWallet($ledger, $connection, (string) $row['local_uuid'], $settings),
                default    => $this->syncInvoice($ledger, $connection, (string) $row['local_uuid'], $settings),
            };
        } catch (\Throwable $exception) {
            $this->lastError = null;
            if ($exception instanceof QuickBooksException && $exception->isUnauthorized()) {
                $this->tokenRejected($ledger, $companyUuid);
                $batch['failed']++;

                return;
            }
            $retryError = $this->exceptionError($exception);
            $outcome    = $this->failure($ledger, $connection, $row, $exception, $settings, $now);
        }
        $connection = $ledger->connection($companyUuid) ?? $connection;
        $this->recordRow($ledger, $connection, $row, $outcome, $batch, $trigger, $retryError);
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function syncCustomerBlock(SyncLedger $ledger, array &$connection, array $rows, array $settings, array &$batch, int $now, string $trigger, bool $record): bool
    {
        if (!$this->entityEnabled($settings, 'customer')) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, $record);
        }
        try {
            $this->prefetchCustomers($connection, $ledger, $rows, $settings);

            return $this->syncBufferedBlock($ledger, $connection, $rows, $settings, $batch, $now, $trigger, $record, 'customer');
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized()) {
                $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit()) {
                $this->failure($ledger, $connection, $rows[0], $exception, $settings, $now);
                if ($record) {
                    $this->recordRow($ledger, $connection, $rows[0], 'failed', $batch, $trigger, $this->exceptionError($exception));
                }

                return true;
            }

            throw $exception;
        } finally {
            $this->customerRemoteCache  = null;
            $this->customerLookupFailed = [];
        }
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function syncWalletBlock(SyncLedger $ledger, array &$connection, array $rows, array $settings, array &$batch, int $now, string $trigger): bool
    {
        if (!$this->entityEnabled($settings, 'wallet')) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, true);
        }
        try {
            $this->prefetchWalletAccounts($connection, $ledger, $rows, $settings);

            return $this->syncBufferedBlock($ledger, $connection, $rows, $settings, $batch, $now, $trigger, true, 'wallet');
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized()) {
                $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit()) {
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
     * @param array<string, mixed>             $batch
     */
    private function syncInvoiceBlock(SyncLedger $ledger, array &$connection, array $rows, array $settings, array &$batch, int $now, string $trigger): bool
    {
        if (!$this->entityEnabled($settings, 'invoice')) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, true);
        }
        $companyUuid = (string) $connection['company_uuid'];
        $needed      = [];
        foreach ($rows as $row) {
            $invoice = $ledger->invoices[(string) ($row['local_uuid'] ?? '')] ?? null;
            if (!is_array($invoice)) {
                continue;
            }
            $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
            if ($customerUuid === '') {
                continue;
            }
            if ($ledger->link($companyUuid, (string) $connection['realm_id'], 'customer', $customerUuid) === null) {
                $needed[$customerUuid] = [
                    'company_uuid' => $companyUuid,
                    'local_type'   => 'customer',
                    'local_uuid'   => $customerUuid,
                    'status'       => 'pending',
                    'attempts'     => 0,
                ];
            }
        }
        if ($this->entityPaused($settings, 'customer')) {
            $needed = [];
        } elseif (count($needed) === 1) {
            $only = array_key_first($needed);
            try {
                $customerOutcome = $this->syncCustomer($ledger, $connection, (string) $only, $settings);
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized()) {
                    $this->tokenRejected($ledger, $companyUuid);
                    $batch['failed']++;

                    return true;
                }
                if ($exception->isRateLimit()) {
                    $this->failure($ledger, $connection, $needed[$only], $exception, $settings, $now);

                    return true;
                }
                $customerOutcome = 'failed';
                $this->lastError = $this->exceptionError($exception);
            }
            if ($customerOutcome === 'failed') {
                $this->lastError ??= 'Invoice customer could not be synced to QuickBooks.';
            }
        } elseif (count($needed) > 1) {
            if ($this->syncCustomerBlock($ledger, $connection, array_values($needed), $settings, $batch, $now, $trigger, false)) {
                return true;
            }
        }

        $this->customersPrepared    = true;
        $this->serviceItemByCompany = [];
        $this->reservedDocNumbers   = null;
        try {
            $this->prefetchInvoices($connection, $ledger, $rows);
            $this->prefetchPayments($connection, $ledger, $rows);
            $this->reserveInvoiceDocNumbers($ledger, $connection, $rows, $settings);

            return $this->syncBufferedBlock($ledger, $connection, $rows, $settings, $batch, $now, $trigger, true, 'invoice');
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized()) {
                $this->tokenRejected($ledger, $companyUuid);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit()) {
                $this->failure($ledger, $connection, $rows[0], $exception, $settings, $now);
                $this->recordRow($ledger, $connection, $rows[0], 'failed', $batch, $trigger, $this->exceptionError($exception));

                return true;
            }

            throw $exception;
        } finally {
            $this->invoiceById          = null;
            $this->invoiceByDoc         = null;
            $this->invoiceReadFailed    = [];
            $this->invoiceDocReadFailed = [];
            $this->reservedDocNumbers   = null;
            $this->blockPayments        = null;
            $this->paymentPrefetchIds   = null;
            $this->paymentById          = null;
            $this->paymentReadFailed    = [];
            $this->serviceItemByCompany = null;
            $this->customersPrepared    = false;
        }
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function syncBufferedBlock(SyncLedger $ledger, array &$connection, array $rows, array $settings, array &$batch, int $now, string $trigger, bool $record, string $kind): bool
    {
        $companyUuid       = (string) $connection['company_uuid'];
        $this->writeBuffer = [];
        /** @var array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool}> $planned */
        $planned = [];
        foreach ($rows as $row) {
            $uuid       = (string) ($row['local_uuid'] ?? '');
            $retryError = null;
            try {
                $outcome = match ($kind) {
                    'customer' => $this->syncCustomer($ledger, $connection, $uuid, $settings),
                    'wallet'   => $this->syncWallet($ledger, $connection, $uuid, $settings),
                    default    => $this->syncInvoice($ledger, $connection, $uuid, $settings),
                };
            } catch (\Throwable $exception) {
                $this->lastError = null;
                if ($exception instanceof QuickBooksException && ($exception->isUnauthorized() || $exception->isRateLimit())) {
                    $this->writeBuffer = null;
                    if ($exception->isUnauthorized()) {
                        $this->tokenRejected($ledger, $companyUuid);
                        $batch['failed']++;
                    } else {
                        $this->failure($ledger, $connection, $row, $exception, $settings, $now);
                        if ($record) {
                            $this->recordRow($ledger, $connection, $row, 'failed', $batch, $trigger, $this->exceptionError($exception));
                        }
                    }

                    return true;
                }
                $retryError = $this->exceptionError($exception);
                $outcome    = $this->failure($ledger, $connection, $row, $exception, $settings, $now);
            }
            $planned[$uuid] = [
                'row'       => $row,
                'outcome'   => $outcome,
                'error'     => $this->lastError ?? $retryError,
                'permanent' => $this->lastError !== null,
            ];
            $this->lastError = null;
        }

        $results           = $this->flushWrites($connection);
        $this->writeBuffer = $kind === 'invoice' ? [] : null;
        $connection        = $ledger->connection($companyUuid) ?? $connection;
        if ($kind === 'invoice') {
            $this->persistSuccessfulInvoiceWrites($ledger, $connection, $planned, $results, $settings);
            $this->prefetchLinkedPayments($connection, $ledger, $planned, $settings);
            $this->resolveNewlyLinkedPayments($ledger, $connection, $planned);
        }
        $halt          = false;
        $unauthorized  = false;
        /** @var array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished */
        $finished = [];
        foreach ($planned as $uuid => $plan) {
            $bId     = $kind . ':' . $uuid;
            $result  = $results[$bId] ?? null;
            $outcome = $plan['outcome'];
            $error   = $plan['error'];
            if (is_array($result)) {
                if (!empty($result['halt'])) {
                    $status = (int) ($result['status'] ?? 0);
                    if ($status === 401) {
                        if ($kind === 'invoice') {
                            $unauthorized = true;
                        } else {
                            $this->tokenRejected($ledger, $companyUuid);
                            $batch['failed']++;
                        }
                    } else {
                        $outcome = $this->failure($ledger, $connection, $plan['row'], new QuickBooksException($status, (string) ($result['error'] ?? '')), $settings, $now);
                        $error   = (string) ($result['error'] ?? '');
                        if ($kind === 'invoice') {
                            $finished[$uuid] = [
                                'row'       => $plan['row'],
                                'outcome'   => $outcome,
                                'error'     => $error,
                                'permanent' => false,
                                'planError' => $plan['error'],
                            ];
                        } elseif ($record) {
                            $this->recordRow($ledger, $connection, $plan['row'], 'failed', $batch, $trigger, $error);
                        }
                    }
                    $halt = true;
                    break;
                }
                if (empty($result['ok'])) {
                    $this->lastError = null;
                    $outcome         = $this->failure(
                        $ledger,
                        $connection,
                        $plan['row'],
                        new QuickBooksException((int) ($result['status'] ?? 400), (string) ($result['error'] ?? 'QuickBooks rejected this item.')),
                        $settings,
                        $now
                    );
                    $error = $this->batchItemAttemptError((string) ($result['error'] ?? ''), (int) ($result['status'] ?? 0));
                } elseif (is_array($result['body'] ?? null) && ($result['body']['Id'] ?? '') !== '') {
                    $localType = $kind === 'wallet' ? 'wallet' : $kind;
                    $entity    = match ($kind) {
                        'customer' => 'Customer',
                        'wallet'   => 'Account',
                        'invoice'  => 'Invoice',
                        default    => 'Invoice',
                    };
                    if ($kind !== 'payment') {
                        $ledger->putLink($this->linkFrom($connection, $localType, (string) $uuid, $entity, $result['body']));
                    }
                    if ($kind === 'invoice' && $outcome === 'created') {
                        $this->copyInvoiceNumber($ledger, (string) $uuid, $result['body'], (string) ($settings['invoice_reference'] ?? 'fleetbase'));
                    }
                }
            }
            if ($kind === 'invoice' && $outcome !== 'failed' && $outcome !== 'skipped') {
                $invoice      = $ledger->invoices[(string) $uuid] ?? null;
                $link         = $ledger->link($companyUuid, (string) $connection['realm_id'], 'invoice', (string) $uuid);
                $customerUuid = is_array($invoice) ? (string) ($invoice['customer_uuid'] ?? '') : '';
                $customerLink = $customerUuid === '' ? null : $ledger->link($companyUuid, (string) $connection['realm_id'], 'customer', $customerUuid);
                if (is_array($invoice) && is_array($link) && is_array($customerLink) && $outcome !== 'voided') {
                    try {
                        $paymentOutcome = $this->syncPayment(
                            $ledger,
                            $connection,
                            $ledger->invoices[(string) $uuid],
                            (string) $customerLink['qbo_id'],
                            (string) $link['qbo_id'],
                            $settings
                        );
                        $outcome = $this->withPayment($outcome, $paymentOutcome);
                    } catch (\Throwable $exception) {
                        $this->lastError = null;
                        if ($exception instanceof QuickBooksException && $exception->isUnauthorized()) {
                            $finished[$uuid] = [
                                'row'       => $plan['row'],
                                'outcome'   => $outcome,
                                'error'     => $error,
                                'permanent' => $plan['permanent'],
                                'planError' => $plan['error'],
                            ];
                            $this->commitFinished($ledger, $connection, $finished, $batch, $trigger, $record);
                            $this->writeBuffer = null;
                            $this->tokenRejected($ledger, $companyUuid);
                            $batch['failed']++;

                            return true;
                        }
                        $outcome = $this->failure($ledger, $connection, $plan['row'], $exception, $settings, $now);
                        $error   = $this->exceptionError($exception);
                    }
                }
            }
            if ($kind === 'invoice') {
                $finished[$uuid] = [
                    'row'       => $plan['row'],
                    'outcome'   => $outcome,
                    'error'     => $error,
                    'permanent' => $plan['permanent'],
                    'planError' => $plan['error'],
                ];
            } elseif ($record) {
                $this->lastError = ($plan['permanent'] && $outcome === 'failed') ? $plan['error'] : null;
                $this->recordRow($ledger, $connection, $plan['row'], $outcome, $batch, $trigger, $error);
            }
            if ($halt) {
                break;
            }
        }

        if ($kind !== 'invoice') {
            $this->writeBuffer = null;

            return $halt;
        }

        if ($unauthorized || !empty($ledger->connections[$companyUuid]['needs_reauth'])) {
            $queued            = $this->queuedPaymentUuids();
            $this->writeBuffer = null;
            $keep              = [];
            foreach ($finished as $uuid => $item) {
                if (!isset($queued[(string) $uuid])) {
                    $keep[$uuid] = $item;
                }
            }
            $this->commitFinished($ledger, $connection, $keep, $batch, $trigger, $record);
            if ($unauthorized) {
                $this->tokenRejected($ledger, $companyUuid);
                $batch['failed']++;
            }

            return true;
        }

        if ($this->settlePaymentBuffer($ledger, $connection, $finished, $batch, $settings, $now, $trigger, $record)) {
            return true;
        }

        return $halt;
    }

    /**
     * Invoice writes are durable before payment reads begin. A later payment failure
     * therefore cannot leave an invoice that QuickBooks created or updated unlinked.
     *
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool}> $planned
     * @param array<string, array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}> $results
     * @param array<string, mixed> $settings
     */
    private function persistSuccessfulInvoiceWrites(SyncLedger $ledger, array $connection, array $planned, array $results, array $settings): void
    {
        foreach ($planned as $uuid => $plan) {
            $result = $results['invoice:' . $uuid] ?? null;
            if (!is_array($result) || empty($result['ok']) || !is_array($result['body'] ?? null) || ($result['body']['Id'] ?? '') === '') {
                continue;
            }

            $ledger->putLink($this->linkFrom($connection, 'invoice', (string) $uuid, 'Invoice', $result['body']));
            if ($plan['outcome'] === 'created') {
                $this->copyInvoiceNumber(
                    $ledger,
                    (string) $uuid,
                    $result['body'],
                    (string) ($settings['invoice_reference'] ?? 'fleetbase')
                );
            }
        }
    }

    /**
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished
     */
    private function commitFinished(SyncLedger $ledger, array $connection, array $finished, array &$batch, string $trigger, bool $record): void
    {
        if (!$record) {
            return;
        }
        foreach ($finished as $item) {
            $this->lastError = ($item['permanent'] && $item['outcome'] === 'failed') ? $item['planError'] : null;
            $this->recordRow($ledger, $connection, $item['row'], $item['outcome'], $batch, $trigger, $item['error']);
        }
    }

    /**
     * @return array<string, true>
     */
    private function queuedPaymentUuids(): array
    {
        $uuids = [];
        foreach ($this->writeBuffer ?? [] as $op) {
            $bId = (string) ($op['bId'] ?? '');
            if (str_starts_with($bId, 'payment:')) {
                $uuids[substr($bId, strlen('payment:'))] = true;
            }
        }

        return $uuids;
    }

    /**
     * Record payment writes, then record each invoice. A failed payment is that invoice's result.
     * A 401 stops the block and leaves this invoice and every invoice after it pending.
     *
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished
     * @param array<string, mixed>                                                                                                          $batch
     * @param array<string, mixed>                                                                                                          $settings
     */
    private function settlePaymentBuffer(SyncLedger $ledger, array $connection, array $finished, array &$batch, array $settings, int $now, string $trigger, bool $record): bool
    {
        $queued = $this->queuedPaymentUuids();
        if ($queued === []) {
            $this->writeBuffer = null;
            $this->commitFinished($ledger, $connection, $finished, $batch, $trigger, $record);

            return false;
        }

        try {
            $paymentResults = $this->flushWrites($connection);
        } catch (\Throwable $exception) {
            $this->writeBuffer = null;
            if ($exception instanceof QuickBooksException && ($exception->isUnauthorized() || $exception->isRateLimit())) {
                if ($exception->isUnauthorized()) {
                    $this->commitFinished($ledger, $connection, $this->finishedBeforePayment($finished, $queued), $batch, $trigger, $record);
                    $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                    $batch['failed']++;

                    return true;
                }
                $stop = $this->firstQueuedUuid($finished, $queued);
                if ($stop !== null && isset($finished[$stop])) {
                    $finished[$stop]['outcome']   = $this->failure($ledger, $connection, $finished[$stop]['row'], $exception, $settings, $now);
                    $finished[$stop]['error']     = $this->exceptionError($exception);
                    $finished[$stop]['permanent'] = false;
                }
                $this->commitFinished($ledger, $connection, $this->finishedThrough($finished, $stop), $batch, $trigger, $record);

                return true;
            }
            $error = $this->exceptionError($exception);
            foreach (array_keys($queued) as $uuid) {
                if (!isset($finished[$uuid])) {
                    continue;
                }
                $finished[$uuid]['outcome']   = $this->failure($ledger, $connection, $finished[$uuid]['row'], $exception, $settings, $now);
                $finished[$uuid]['error']     = $error;
                $finished[$uuid]['permanent'] = false;
            }
            $this->commitFinished($ledger, $connection, $finished, $batch, $trigger, $record);

            return false;
        }

        $this->writeBuffer = null;
        foreach ($finished as $uuid => $item) {
            $result = $paymentResults['payment:' . $uuid] ?? null;
            if (!is_array($result)) {
                continue;
            }
            if (!empty($result['halt'])) {
                $status = (int) ($result['status'] ?? 0);
                if ($status === 401) {
                    $this->commitFinished($ledger, $connection, $this->finishedThrough($finished, (string) $uuid, false), $batch, $trigger, $record);
                    $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                    $batch['failed']++;

                    return true;
                }
                $message = (string) ($result['error'] ?? '');
                $finished[$uuid]['outcome']   = $this->failure($ledger, $connection, $item['row'], new QuickBooksException($status, $message), $settings, $now);
                $finished[$uuid]['error']     = $this->batchItemAttemptError($message, $status);
                $finished[$uuid]['permanent'] = false;
                $this->commitFinished($ledger, $connection, $this->finishedThrough($finished, (string) $uuid, true), $batch, $trigger, $record);

                return true;
            }
            if (empty($result['ok'])) {
                $message = (string) ($result['error'] ?? '');
                $finished[$uuid]['outcome']   = $this->failure(
                    $ledger,
                    $connection,
                    $item['row'],
                    new QuickBooksException((int) ($result['status'] ?? 400), $message !== '' ? $message : 'QuickBooks rejected this item.'),
                    $settings,
                    $now
                );
                $finished[$uuid]['error']     = $this->batchItemAttemptError($message, (int) ($result['status'] ?? 0));
                $finished[$uuid]['permanent'] = false;
                continue;
            }
            if (is_array($result['body'] ?? null) && ($result['body']['Id'] ?? '') !== '') {
                $ledger->putLink($this->linkFrom($connection, 'payment', (string) $uuid, 'Payment', $result['body']));
            }
        }
        $this->commitFinished($ledger, $connection, $finished, $batch, $trigger, $record);

        return false;
    }

    /**
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished
     * @param array<string, true>                                                                                                           $queued
     *
     * @return array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}>
     */
    private function finishedBeforePayment(array $finished, array $queued): array
    {
        $stop = $this->firstQueuedUuid($finished, $queued);

        return $stop === null ? [] : $this->finishedThrough($finished, $stop, false);
    }

    /**
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished
     * @param array<string, true>                                                                                                           $queued
     */
    private function firstQueuedUuid(array $finished, array $queued): ?string
    {
        foreach ($finished as $uuid => $item) {
            if (isset($queued[(string) $uuid])) {
                return (string) $uuid;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished
     *
     * @return array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}>
     */
    private function finishedThrough(array $finished, ?string $stopUuid, bool $includeStop = true): array
    {
        if ($stopUuid === null) {
            return [];
        }
        $prefix = [];
        foreach ($finished as $uuid => $item) {
            if ((string) $uuid === $stopUuid) {
                if ($includeStop) {
                    $prefix[$uuid] = $item;
                }
                break;
            }
            $prefix[$uuid] = $item;
        }

        return $prefix;
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     */
    private function prefetchCustomers(array $connection, SyncLedger $ledger, array $rows, array $settings): void
    {
        $this->customerRemoteCache  = [];
        $this->customerLookupFailed = [];
        $companyUuid                = (string) $connection['company_uuid'];
        $realm                      = (string) $connection['realm_id'];
        /** @var array<int, array{bId: string, query: string, uuid: string, by: string, value: string}> $queries */
        $queries = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $customer = $ledger->customers[$uuid] ?? null;
            $link     = $ledger->link($companyUuid, $realm, 'customer', $uuid);
            if (is_array($link) && (string) ($link['qbo_id'] ?? '') !== '') {
                $queries[] = [
                    'bId'   => 'customer:' . $uuid,
                    'query' => "select * from Customer where Id = '" . QuickBooksClient::escapeQuery((string) $link['qbo_id']) . "'",
                    'uuid'  => $uuid,
                    'by'    => 'id',
                    'value' => (string) $link['qbo_id'],
                ];
                continue;
            }
            if (!is_array($customer)) {
                continue;
            }
            $email = trim((string) ($customer['email'] ?? ''));
            if ($email !== '') {
                $queries[] = [
                    'bId'   => 'customer:' . $uuid,
                    'query' => "select * from Customer where PrimaryEmailAddr = '" . QuickBooksClient::escapeQuery($email) . "' maxresults 1",
                    'uuid'  => $uuid,
                    'by'    => 'email',
                    'value' => $email,
                ];
            }
        }
        if ($queries === []) {
            return;
        }
        if (count($queries) === 1) {
            $this->prefetchOneCustomer($connection, $queries[0]);
        } else {
            $items = [];
            foreach ($queries as $query) {
                $items[] = ['bId' => $query['bId'], 'query' => $query['query']];
            }
            try {
                $results = $this->client->batch($connection, $items);
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() || $exception->isRateLimit()) {
                    throw $exception;
                }
                $error = $this->exceptionError($exception);
                foreach ($queries as $query) {
                    $this->customerLookupFailed[$query['uuid']] = $error;
                }
                $results = [];
            }
            $seen = [];
            foreach ($results as $bId => $result) {
                $uuid = substr((string) $bId, strlen('customer:'));
                if ($uuid === '' || $uuid === (string) $bId) {
                    continue;
                }
                $seen[$uuid] = true;
                if (is_array($result) && !empty($result['halt'])) {
                    throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
                }
                if (!is_array($result) || empty($result['ok'])) {
                    $message = is_array($result) ? (string) ($result['error'] ?? '') : '';
                    $status  = is_array($result) ? (int) ($result['status'] ?? 0) : 0;
                    $this->customerLookupFailed[$uuid] = $this->batchItemAttemptError($message, $status);
                    continue;
                }
                $remote = $result['rows'][0] ?? null;
                $this->customerRemoteCache[$uuid] = is_array($remote) ? $remote : null;
            }
            foreach ($queries as $query) {
                if (!isset($seen[$query['uuid']]) && !isset($this->customerLookupFailed[$query['uuid']])) {
                    $this->customerLookupFailed[$query['uuid']] = self::SYNC_FAILED_MESSAGE;
                }
            }
        }

        /** @var array<int, array{bId: string, query: string, uuid: string, email: string, name: string}> $nameQueries */
        $nameQueries = [];
        foreach ($queries as $query) {
            $uuid = $query['uuid'];
            if ($query['by'] !== 'email' || isset($this->customerLookupFailed[$uuid]) || !array_key_exists($uuid, $this->customerRemoteCache) || $this->customerRemoteCache[$uuid] !== null) {
                continue;
            }
            $customer = $ledger->customers[$uuid] ?? null;
            if (!is_array($customer)) {
                continue;
            }
            $payload = $this->customers->toQuickBooks($this->customers->fromParty($customer));
            $name    = trim((string) ($payload['DisplayName'] ?? ''));
            if ($name !== '') {
                $nameQueries[] = [
                    'bId'   => 'customer-name:' . $uuid,
                    'query' => "select * from Customer where DisplayName = '" . QuickBooksClient::escapeQuery($name) . "' maxresults 1",
                    'uuid'  => $uuid,
                    'email' => trim((string) ($customer['email'] ?? '')),
                    'name'  => $name,
                ];
            }
        }
        $this->prefetchCustomerNames($connection, $nameQueries, (string) ($settings['customer_reference'] ?? 'fleetbase') !== 'fleetbase');
    }

    /**
     * @param array<string, mixed>                                                              $connection
     * @param array{bId: string, query: string, uuid: string, by: string, value: string}        $query
     */
    private function prefetchOneCustomer(array $connection, array $query): void
    {
        try {
            $remote = $query['by'] === 'id'
                ? $this->client->getCustomer($connection, $query['value'])
                : $this->client->findCustomerByEmail($connection, $query['value']);
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() || $exception->isRateLimit()) {
                throw $exception;
            }
            $this->customerLookupFailed[$query['uuid']] = $this->exceptionError($exception);

            return;
        }
        $this->customerRemoteCache[$query['uuid']] = is_array($remote) ? $remote : null;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, array{bId: string, query: string, uuid: string, email: string, name: string}> $queries
     */
    private function prefetchCustomerNames(array $connection, array $queries, bool $allowDifferentEmail): void
    {
        foreach (array_chunk($queries, QuickBooksClient::BATCH_LIMIT) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            if (count($chunk) === 1) {
                $query = $chunk[0];
                try {
                    $remote = $this->client->findCustomerByDisplayName($connection, $query['name']);
                } catch (QuickBooksException $exception) {
                    if ($exception->isUnauthorized() || $exception->isRateLimit()) {
                        throw $exception;
                    }
                    $this->customerLookupFailed[$query['uuid']] = $this->exceptionError($exception);
                    continue;
                }
                if (is_array($remote)) {
                    $remoteEmail = strtolower(trim((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')));
                    $localEmail  = strtolower($query['email']);
                    if ($allowDifferentEmail || $localEmail === '' || $remoteEmail === '' || $remoteEmail === $localEmail) {
                        $this->customerRemoteCache[$query['uuid']] = $remote;
                    }
                }
                continue;
            }
            try {
                $results = $this->client->batch($connection, array_map(
                    static fn (array $query): array => ['bId' => $query['bId'], 'query' => $query['query']],
                    $chunk
                ));
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() || $exception->isRateLimit()) {
                    throw $exception;
                }
                foreach ($chunk as $query) {
                    $this->customerLookupFailed[$query['uuid']] = $this->exceptionError($exception);
                }
                continue;
            }
            foreach ($chunk as $query) {
                $result = $results[$query['bId']] ?? null;
                if (is_array($result) && !empty($result['halt'])) {
                    throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
                }
                if (!is_array($result) || empty($result['ok'])) {
                    $message = is_array($result) ? (string) ($result['error'] ?? '') : '';
                    $status  = is_array($result) ? (int) ($result['status'] ?? 0) : 0;
                    $this->customerLookupFailed[$query['uuid']] = $this->batchItemAttemptError($message, $status);
                    continue;
                }
                $remote = $result['rows'][0] ?? null;
                if (!is_array($remote)) {
                    continue;
                }
                $remoteEmail = strtolower(trim((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')));
                $localEmail  = strtolower($query['email']);
                if ($allowDifferentEmail || $localEmail === '' || $remoteEmail === '' || $remoteEmail === $localEmail) {
                    $this->customerRemoteCache[$query['uuid']] = $remote;
                }
            }
        }
    }

    /**
     * One latest-invoice query and batched existence checks for every create in this block.
     * A single create leaves the reservation empty so syncInvoice can use one lookup.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     */
    private function reserveInvoiceDocNumbers(SyncLedger $ledger, array $connection, array $rows, array $settings): void
    {
        $this->reservedDocNumbers = null;
        if ((string) ($settings['invoice_reference'] ?? 'fleetbase') !== 'quickbooks') {
            return;
        }
        if (!$this->entityEnabled($settings, 'invoice') || !$this->pushesRemote($this->direction($settings, 'invoice'))) {
            return;
        }
        if (!$this->customTxnNumbers($connection)) {
            return;
        }

        $creates = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if ($uuid !== '' && $this->invoiceCreateNeedsDocNumber($ledger, $connection, $uuid)) {
                $creates[] = $uuid;
            }
        }
        if (count($creates) <= 1) {
            return;
        }
        if ($this->serviceItemId($ledger, $connection) === '') {
            return;
        }

        $numbers  = $this->client->nextInvoiceDocNumbers($connection, count($creates));
        $reserved = [];
        foreach ($creates as $index => $uuid) {
            $number = $numbers[$index] ?? '';
            if ($number !== '') {
                $reserved[$uuid] = $number;
            }
        }
        $this->reservedDocNumbers = $reserved;
    }

    /**
     * @param array<string, mixed> $connection
     */
    private function invoiceCreateNeedsDocNumber(SyncLedger $ledger, array $connection, string $uuid): bool
    {
        $invoice = $ledger->invoices[$uuid] ?? null;
        if (!is_array($invoice)) {
            return false;
        }

        $status = (string) ($invoice['status'] ?? '');
        if (in_array($status, ['void', 'voided', 'cancelled', 'canceled'], true) || !empty($invoice['deleted_at'])) {
            return false;
        }

        $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
        $companyUuid  = (string) $connection['company_uuid'];
        $realm        = (string) $connection['realm_id'];
        if ($customerUuid === '' || $ledger->link($companyUuid, $realm, 'customer', $customerUuid) === null) {
            return false;
        }

        $link = $ledger->link($companyUuid, $realm, 'invoice', $uuid);
        if ($link === null) {
            $number = trim((string) ($invoice['number'] ?? ''));
            if ($number !== '' && $this->invoiceByDoc !== null && isset($this->invoiceByDoc[$number])) {
                return false;
            }

            return true;
        }

        $remoteId = (string) ($link['qbo_id'] ?? '');
        if ($remoteId === '') {
            return true;
        }
        if (isset($this->invoiceReadFailed[$remoteId])) {
            return false;
        }
        if ($this->invoiceById !== null) {
            return !isset($this->invoiceById[$remoteId]);
        }

        return $this->client->getInvoice($connection, $remoteId) === null;
    }

    private function prefetchInvoices(array $connection, SyncLedger $ledger, array $rows): void
    {
        $this->invoiceById  = [];
        $this->invoiceByDoc = [];
        $ids                = [];
        $docs               = [];
        $companyUuid        = (string) $connection['company_uuid'];
        $realm              = (string) $connection['realm_id'];
        foreach ($rows as $row) {
            $uuid    = (string) ($row['local_uuid'] ?? '');
            $invoice = $ledger->invoices[$uuid] ?? null;
            $link    = $ledger->link($companyUuid, $realm, 'invoice', $uuid);
            if (is_array($link) && (string) ($link['qbo_id'] ?? '') !== '') {
                $ids[] = (string) $link['qbo_id'];
            } elseif (is_array($invoice) && trim((string) ($invoice['customer_uuid'] ?? '')) !== '' && trim((string) ($invoice['number'] ?? '')) !== '') {
                $docs[] = trim((string) $invoice['number']);
            }
        }
        $ids  = array_values(array_unique($ids));
        $docs = array_values(array_unique($docs));
        $this->invoiceReadFailed    = [];
        $this->invoiceDocReadFailed = [];
        if ($ids !== []) {
            $loaded = $this->queryWhereIn(
                $connection,
                'Invoice',
                'Id',
                $ids,
                fn (string $id): ?array => $this->client->getInvoice($connection, $id)
            );
            foreach ($loaded['rows'] as $remote) {
                $this->rememberPrefetchedInvoice($remote);
            }
            $this->invoiceReadFailed = $loaded['failed'];
        }
        if ($docs !== []) {
            $loaded = $this->queryWhereIn(
                $connection,
                'Invoice',
                'DocNumber',
                $docs,
                fn (string $doc): ?array => $this->client->findInvoiceByDocNumber($connection, $doc)
            );
            foreach ($loaded['rows'] as $remote) {
                $this->rememberPrefetchedInvoice($remote);
            }
            $this->invoiceDocReadFailed = $loaded['failed'];
        }
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function rememberPrefetchedInvoice(array $remote): void
    {
        $id = trim((string) ($remote['Id'] ?? ''));
        if ($id !== '' && $this->invoiceById !== null) {
            $this->invoiceById[$id] = $remote;
        }
        $doc = trim((string) ($remote['DocNumber'] ?? ''));
        if ($doc !== '' && $this->invoiceByDoc !== null) {
            $this->invoiceByDoc[$doc] = $remote;
        }
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     */
    private function prefetchPayments(array $connection, SyncLedger $ledger, array $rows): void
    {
        $this->blockPayments      = [];
        $this->paymentPrefetchIds = [];
        $companyUuid              = (string) $connection['company_uuid'];
        $realm               = (string) $connection['realm_id'];
        $invoiceIds          = [];
        $targets             = [];
        foreach ($rows as $row) {
            $uuid    = (string) ($row['local_uuid'] ?? '');
            $invoice = $ledger->invoices[$uuid] ?? null;
            if (!is_array($invoice)) {
                continue;
            }
            $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
            $customerLink = $customerUuid === '' ? null : $ledger->link($companyUuid, $realm, 'customer', $customerUuid);
            $link = $ledger->link($companyUuid, $realm, 'invoice', $uuid);
            $customerId = is_array($customerLink) ? trim((string) ($customerLink['qbo_id'] ?? '')) : '';
            $invoiceId  = is_array($link) ? trim((string) ($link['qbo_id'] ?? '')) : '';
            if ($customerId !== '' && $invoiceId !== '') {
                $invoiceIds[]        = $invoiceId;
                $targets[$invoiceId] = $customerId;
            }
        }
        $this->paymentPrefetchIds = array_fill_keys($invoiceIds, true);
        if ($targets === []) {
            return;
        }
        $payments = $this->client->findPaymentsForCustomers($connection, $targets, true);
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            foreach ($invoiceIds as $invoiceId) {
                if (isset($this->blockPayments[$invoiceId])) {
                    continue;
                }
                if ($this->paymentAppliesTo($payment, $invoiceId)) {
                    $this->blockPayments[$invoiceId] = $payment;
                }
            }
        }
    }

    /**
     * A DocNumber match can link an invoice after the block payment prefetch.
     * One new invoice uses one payment lookup. A larger set uses the customer payment query.
     *
     * @param array<string, mixed>                                                                                              $connection
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool}> $planned
     */
    private function resolveNewlyLinkedPayments(SyncLedger $ledger, array $connection, array $planned): void
    {
        if ($this->blockPayments === null) {
            return;
        }

        $known   = $this->paymentPrefetchIds ?? [];
        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        /** @var array<string, string> $missing invoice id => customer id */
        $missing = [];
        foreach (array_keys($planned) as $uuid) {
            $link = $ledger->link($company, $realm, 'invoice', (string) $uuid);
            if (!is_array($link)) {
                continue;
            }
            $invoiceId = trim((string) ($link['qbo_id'] ?? ''));
            if ($invoiceId === '' || isset($known[$invoiceId]) || isset($this->blockPayments[$invoiceId])) {
                continue;
            }
            $invoice      = $ledger->invoices[(string) $uuid] ?? null;
            $customerUuid = is_array($invoice) ? (string) ($invoice['customer_uuid'] ?? '') : '';
            $customerLink = $customerUuid === '' ? null : $ledger->link($company, $realm, 'customer', $customerUuid);
            $customerId   = is_array($customerLink) ? trim((string) ($customerLink['qbo_id'] ?? '')) : '';
            if ($customerId === '') {
                continue;
            }
            $missing[$invoiceId] = $customerId;
        }
        if ($missing === []) {
            return;
        }
        if (count($missing) === 1) {
            $invoiceId = (string) array_key_first($missing);
            $payment   = $this->client->findPaymentForInvoice($connection, $missing[$invoiceId], $invoiceId);
            if (is_array($payment)) {
                $this->blockPayments[$invoiceId] = $payment;
            }

            return;
        }

        foreach ($this->client->findPaymentsForCustomers($connection, $missing, true) as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            foreach (array_keys($missing) as $invoiceId) {
                if (!isset($this->blockPayments[$invoiceId]) && $this->paymentAppliesTo($payment, (string) $invoiceId)) {
                    $this->blockPayments[$invoiceId] = $payment;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function paymentAppliesTo(array $payment, string $invoiceId): bool
    {
        $lines = $payment['Line'] ?? [];
        if (!is_array($lines)) {
            return false;
        }
        foreach ($lines as $line) {
            if (is_array($line) && $this->lineLinksInvoice($line, $invoiceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>                                                                                              $connection
     *
     * @return array<string, array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}>
     */
    private function flushWrites(array $connection): array
    {
        $ops               = $this->writeBuffer ?? [];
        $this->writeBuffer = [];
        if ($ops === []) {
            return [];
        }
        if (count($ops) === 1) {
            return [$ops[0]['bId'] => $this->executeOneWrite($connection, $ops[0])];
        }

        return $this->client->batch($connection, $ops);
    }

    /**
     * @param array<string, mixed>                                                          $connection
     * @param array{bId: string, operation: string, entity: string, payload: array<string, mixed>} $op
     *
     * @return array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}
     */
    private function executeOneWrite(array $connection, array $op): array
    {
        try {
            $payload = $op['payload'];
            $body    = match ($op['entity'] . ':' . $op['operation']) {
                'Customer:create' => $this->client->createCustomer($connection, $payload),
                'Customer:update' => $this->client->updateCustomer($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
                'Invoice:create'  => $this->client->createInvoice($connection, $payload),
                'Invoice:update'  => $this->client->updateInvoice($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
                'Invoice:void'    => $this->client->voidInvoice($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0')),
                'Payment:create'  => $this->client->createPayment($connection, $payload),
                'Payment:update'  => $this->client->updatePayment($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
                'Account:create'  => $this->client->createAccount($connection, $payload),
                'Account:update'  => $this->client->updateAccount($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
                default           => throw new QuickBooksException(400, 'Unsupported batch item.'),
            };

            return ['ok' => true, 'body' => $body, 'rows' => [], 'error' => null, 'status' => 200, 'halt' => false];
        } catch (QuickBooksException $exception) {
            return [
                'ok'     => false,
                'body'   => [],
                'rows'   => [],
                'error'  => $exception->getMessage(),
                'status' => $exception->status,
                'halt'   => $exception->isUnauthorized() || $exception->isRateLimit(),
            ];
        }
    }

    /**
     * A QuickBooksException message is the status plus Intuit's detail. Any other
     * throwable is a query, decrypt, or library failure and is not shown.
     */
    private function exceptionError(\Throwable $exception): string
    {
        if ($exception instanceof QuickBooksException) {
            return $exception->getMessage();
        }

        return self::SYNC_FAILED_MESSAGE;
    }

    /**
     * Keep a composed QuickBooksException message and an Intuit item fault.
     * QuickBooksClient reports an item fault as HTTP 400. Anything else is not shown.
     */
    private function batchItemAttemptError(string $error, int $status): string
    {
        if ($error === '' || str_starts_with($error, 'QuickBooks ') || $status === 400) {
            return $error;
        }

        return self::SYNC_FAILED_MESSAGE;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function readRemoteIds(array $connection, string $entity, array $ids): array
    {
        $ids    = array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
        $mapped = [];
        foreach (array_chunk($ids, QuickBooksClient::BATCH_LIMIT) as $chunk) {
            foreach ($this->readRemoteSet($connection, $entity, $chunk) as $id => $remote) {
                $mapped[$id] = $remote;
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed>                                                                                              $connection
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool}> $planned
     * @param array<string, mixed>                                                                                              $settings
     */
    private function prefetchLinkedPayments(array $connection, SyncLedger $ledger, array $planned, array $settings): void
    {
        $this->paymentReadFailed = [];
        if ($this->entityPaused($settings, 'payment')) {
            $this->paymentById = [];

            return;
        }

        $companyUuid = (string) $connection['company_uuid'];
        $realm       = (string) $connection['realm_id'];
        $ids         = [];
        foreach (array_keys($planned) as $uuid) {
            $link = $ledger->link($companyUuid, $realm, 'payment', (string) $uuid);
            $id   = is_array($link) ? trim((string) ($link['qbo_id'] ?? '')) : '';
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $loaded = $this->queryWhereIn(
            $connection,
            'Payment',
            'Id',
            $ids,
            fn (string $id): ?array => $this->client->getPayment($connection, $id)
        );
        $this->paymentById = [];
        foreach ($loaded['rows'] as $payment) {
            $id = trim((string) ($payment['Id'] ?? ''));
            if ($id !== '') {
                $this->paymentById[$id] = $payment;
            }
        }
        $this->paymentReadFailed = $loaded['failed'];
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
            if ($uuid === '' || !is_array($wallet)) {
                continue;
            }
            $payload  = $this->wallets->toQuickBooks($wallet, $reference);
            $link     = $ledger->link($companyUuid, $realm, 'wallet', $uuid);
            $linkedId = '';
            if (is_array($link) && (string) ($link['realm_id'] ?? '') === $realm) {
                $linkedId = trim((string) ($link['qbo_id'] ?? ''));
            }
            if ($linkedId !== '') {
                $ids[] = $linkedId;
            }
            $plans[$uuid] = [
                'acct'   => trim((string) ($payload['AcctNum'] ?? '')),
                'name'   => trim((string) ($payload['Name'] ?? '')),
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
        if (count($acctNums) === 1 && $loaded !== [] && !isset($this->accountByAcctNum[$acctNums[0]])) {
            $this->accountByAcctNum[$acctNums[0]] = $loaded[0];
        }

        $names = [];
        foreach ($plans as $plan) {
            $linkedFound  = $plan['linked'] !== '' && isset($this->accountById[$plan['linked']]);
            $linkedFailed = $plan['linked'] !== '' && isset($this->accountReadFailed[$plan['linked']]);
            $acctFound    = $plan['linked'] === '' && $plan['acct'] !== '' && isset($this->accountByAcctNum[$plan['acct']]);
            if ($linkedFound || $linkedFailed || $acctFound || $plan['name'] === '') {
                continue;
            }
            $names[] = $plan['name'];
        }
        $names = array_values(array_unique($names));
        $found = $this->readAccountsByField($connection, 'Name', $names);
        foreach ($found as $account) {
            $name = trim((string) ($account['Name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $this->accountsByName[$name][] = $account;
        }
        if (count($names) === 1 && $found !== [] && !isset($this->accountsByName[$names[0]])) {
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
        if ($acct !== '' && !isset($this->accountByAcctNum[$acct])) {
            $this->accountByAcctNum[$acct] = $account;
        }
        if ($id !== '' && !isset($this->accountById[$id])) {
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

                return is_array($one) ? [$one] : [];
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

    /**
     * A successful query that omits a value is a real miss. A failed request marks those values failed
     * and does not call them missing. One value uses $readOne. Larger sets use batch() in chunks of 30,
     * and each query is paged until a short page.
     *
     * @param array<string, mixed>           $connection
     * @param array<int, string>             $values
     * @param callable(string): (?array)     $readOne
     *
     * @return array{rows: array<int, array<string, mixed>>, failed: array<string, string>}
     */
    private function queryWhereIn(array $connection, string $entity, string $field, array $values, callable $readOne): array
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $values
        ), static fn (string $value): bool => $value !== '')));
        if ($values === [] || !in_array($field, ['Id', 'DocNumber', 'AcctNum', 'Name'], true)) {
            return ['rows' => [], 'failed' => []];
        }
        if (!in_array($entity, ['Customer', 'Invoice', 'Payment', 'Account'], true)) {
            return ['rows' => [], 'failed' => []];
        }
        if (count($values) === 1) {
            try {
                $remote = $readOne($values[0]);
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() || $exception->isRateLimit()) {
                    throw $exception;
                }

                return ['rows' => [], 'failed' => [$values[0] => $this->exceptionError($exception)]];
            }

            return ['rows' => is_array($remote) ? [$remote] : [], 'failed' => []];
        }

        $rows    = [];
        $failed  = [];
        $seenIds = [];
        foreach (array_chunk($values, QuickBooksClient::BATCH_LIMIT) as $chunkIndex => $chunk) {
            $start    = 1;
            $matched  = [];
            $pages    = 0;
            while ($pages < 100) {
                $pages++;
                $query = 'select * from ' . $entity . ' where ' . $field . ' IN (' . QuickBooksClient::quotedList($chunk) . ')'
                    . ' startposition ' . $start . ' maxresults ' . self::QUERY_PAGE_SIZE;
                try {
                    $results = $this->client->batch($connection, [[
                        'bId'   => 'read-' . $chunkIndex . '-' . $start,
                        'query' => $query,
                    ]]);
                } catch (QuickBooksException $exception) {
                    if ($exception->isUnauthorized() || $exception->isRateLimit()) {
                        throw $exception;
                    }
                    $error = $this->exceptionError($exception);
                    foreach ($chunk as $value) {
                        if (!isset($matched[$value])) {
                            $failed[$value] = $error;
                        }
                    }
                    break;
                }

                $bId    = 'read-' . $chunkIndex . '-' . $start;
                $result = $results[$bId] ?? null;
                if (is_array($result) && !empty($result['halt'])) {
                    throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
                }
                if (!is_array($result) || empty($result['ok'])) {
                    $message = is_array($result) ? (string) ($result['error'] ?? '') : '';
                    $status  = is_array($result) ? (int) ($result['status'] ?? 0) : 0;
                    $error   = $this->batchItemAttemptError($message, $status);
                    foreach ($chunk as $value) {
                        if (!isset($matched[$value])) {
                            $failed[$value] = $error;
                        }
                    }
                    break;
                }

                $pageRows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
                $count    = 0;
                $added    = 0;
                foreach ($pageRows as $remote) {
                    if (!is_array($remote)) {
                        continue;
                    }
                    $count++;
                    $id = trim((string) ($remote['Id'] ?? ''));
                    if ($id !== '') {
                        if (isset($seenIds[$id])) {
                            continue;
                        }
                        $seenIds[$id] = true;
                    }
                    $added++;
                    $rows[] = $remote;
                    $value  = trim((string) ($remote[$field] ?? ''));
                    if ($value !== '') {
                        $matched[$value] = true;
                    }
                }
                if ($added === 0 || $count < self::QUERY_PAGE_SIZE) {
                    break;
                }
                $start += self::QUERY_PAGE_SIZE;
            }
        }

        return ['rows' => $rows, 'failed' => $failed];
    }

    /**
     * One id uses a direct read. Null is a real miss. A thrown error stays thrown.
     * Several ids use one batch query. A successful query that omits an id is a miss.
     * A failed batch throws. Those ids are not returned as an empty success.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $ids
     *
     * @return array<string, array<string, mixed>>
     */
    private function readRemoteSet(array $connection, string $entity, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }
        if (count($ids) === 1) {
            $id     = $ids[0];
            $remote = match ($entity) {
                'Customer' => $this->client->getCustomer($connection, $id),
                'Invoice'  => $this->client->getInvoice($connection, $id),
                'Payment'  => $this->client->getPayment($connection, $id),
                'Account'  => $this->client->getAccount($connection, $id),
                default    => null,
            };

            return is_array($remote) ? [$id => $remote] : [];
        }

        $query = 'select * from ' . $entity . ' where Id IN (' . QuickBooksClient::quotedList($ids) . ')';
        try {
            $results = $this->client->batch($connection, [['bId' => 'read', 'query' => $query]]);
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() || $exception->isRateLimit()) {
                throw $exception;
            }

            throw new QuickBooksException(
                $exception->status,
                $this->exceptionError($exception),
                $exception->retryAfter,
                $exception->faultCode,
            );
        }

        $result = $results['read'] ?? null;
        if (is_array($result) && !empty($result['halt'])) {
            throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
        }
        if (!is_array($result) || empty($result['ok'])) {
            $message = is_array($result) ? (string) ($result['error'] ?? '') : '';
            $status  = is_array($result) ? (int) ($result['status'] ?? 0) : 0;
            $error   = $this->batchItemAttemptError($message, $status);
            throw new QuickBooksException($status, $error !== '' ? $error : self::SYNC_FAILED_MESSAGE);
        }

        $read   = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        $mapped = [];
        foreach ($read as $remote) {
            if (is_array($remote) && (string) ($remote['Id'] ?? '') !== '') {
                $mapped[(string) $remote['Id']] = $remote;
            }
        }

        return $mapped;
    }

    /**
     * Missing or blank means the entity is synced. An explicit false is the per-entity Enable box.
     *
     * @param array<string, mixed> $settings
     */
    private function entityEnabled(array $settings, string $kind): bool
    {
        $key = $kind . '_enabled';
        if (!array_key_exists($key, $settings)) {
            return true;
        }
        $value = $settings[$key];
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? true;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function entityPaused(array $settings, string $kind): bool
    {
        return !$this->entityEnabled($settings, $kind) || $this->direction($settings, $kind) === 'off';
    }

    private function direction(array $settings, string $kind): string
    {
        $value = $settings[$kind . '_direction'] ?? 'both';
        if (!is_string($value) || !in_array($value, ['both', 'outbound', 'inbound', 'off'], true)) {
            return 'both';
        }

        return $value;
    }

    private function copiesRemote(string $direction): bool
    {
        return $direction === 'both' || $direction === 'inbound';
    }

    private function pushesRemote(string $direction): bool
    {
        return $direction === 'both' || $direction === 'outbound';
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $row
     * @param array<string, mixed> $batch
     */
    private function recordRow(SyncLedger $ledger, array $connection, array $row, string $outcome, array &$batch, string $trigger, ?string $retryError): void
    {
        $batch[$outcome]    = ($batch[$outcome] ?? 0) + 1;
        $ledger->attempts[] = [
            'company_uuid' => $connection['company_uuid'],
            'local_type'   => $row['local_type'],
            'local_uuid'   => $row['local_uuid'],
            'outcome'      => $outcome,
            'error'        => $this->lastError ?? $retryError,
        ];
        $tracksPending = in_array($trigger, ['scheduled', 'now', 'manual', 'catalog'], true);
        if ($this->lastError !== null && $tracksPending) {
            $ledger->updatePending((string) $connection['company_uuid'], (string) $row['local_type'], (string) $row['local_uuid'], [
                'status' => 'failed',
            ]);
        }
        $this->lastError = null;
        if ($outcome !== 'failed' && $outcome !== 'skipped' && $tracksPending) {
            $ledger->updatePending((string) $connection['company_uuid'], (string) $row['local_type'], (string) $row['local_uuid'], ['status' => 'done']);
        }
        if ($outcome === 'skipped' && $this->closeSkipped && $tracksPending) {
            $ledger->updatePending((string) $connection['company_uuid'], (string) $row['local_type'], (string) $row['local_uuid'], ['status' => 'done']);
        }
        $this->closeSkipped = false;
    }

    /**
     * A disabled entity is not read from or written to QuickBooks.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $batch
     */
    private function recordDisabledRows(SyncLedger $ledger, array $connection, array $rows, array &$batch, string $trigger, bool $record): bool
    {
        if (!$record) {
            return false;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->closeSkipped = true;
            $this->recordRow($ledger, $connection, $row, 'skipped', $batch, $trigger, null);
        }

        return false;
    }
}
