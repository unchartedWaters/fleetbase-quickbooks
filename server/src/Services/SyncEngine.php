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

    public const TOKEN_REJECTED_MESSAGE = 'QuickBooks rejected the access token. Connect again from Quickbooks Setup.';

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
     * Each invoice keeps every linked payment. Null means this invoice is alone and may use one payment query.
     *
     * @var array<string, array<int, array<string, mixed>>>|null
     */
    private ?array $blockPayments = null;

    /**
     * Linked payments prefetched for the current invoice block, keyed by QuickBooks payment id.
     * Null means this invoice is alone and may use one payment GET. A null value is a confirmed miss.
     *
     * @var array<string, array<string, mixed>|null>|null
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

    private QuickBooksClient $client;

    /**
     * Drops the company lock for one QuickBooks HTTP call when a batch or a
     * webhook apply installed it. Null leaves calls unchanged for tests.
     *
     * @var callable|null
     */
    private $httpBoundary;

    public function __construct(
        QuickBooksClient $client,
        private CustomerMapper $customers,
        private InvoiceMapper $invoices,
        private WalletMapper $wallets,
        private BackoffPolicy $backoff,
        private ?ConnectionTokens $tokens = null,
    ) {
        $this->httpBoundary = null;
        $this->client       = new QuickBooksHttpGate($client, $this);
    }

    /**
     * Rotated tokens by company, keyed to the access token they replaced. A call that still holds
     * the replaced connection uses the rotated one instead of being rejected and refreshing again.
     *
     * @var array<string, array{from: string, tokens: array<string, mixed>}>
     */
    private array $rotated = [];

    /**
     * @param callable|null $boundary function(callable $call): mixed
     */
    public function setHttpBoundary(?callable $boundary): void
    {
        $this->httpBoundary = $boundary;
    }

    /**
     * One QuickBooks HTTP call. The batch boundary releases the company lock
     * around $call so the request is not inside that lock.
     */
    public function runHttp(callable $call): mixed
    {
        $boundary = $this->httpBoundary;
        if ($boundary === null) {
            return $call();
        }

        return $boundary($call);
    }

    /**
     * One QuickBooks HTTP call that gets one refresh-and-retry when QuickBooks answers 401.
     * The access token can expire in the middle of a long batch, while the refresh token is
     * still good, so a 401 alone does not mean the user must reconnect. The call is repeated
     * once with the rotated token. A 401 is only passed on, for tokenRejected() to flag, when
     * Intuit refused the refresh token or the freshly issued token was refused as well. A
     * refresh that failed for any other reason is temporary and is not a 401.
     *
     * @param array<string, mixed> $connection
     * @param callable             $call       function(array $connection): mixed
     */
    public function runAuthorized(array $connection, callable $call): mixed
    {
        $companyUuid = (string) ($connection['company_uuid'] ?? '');
        $rotated     = $this->rotated[$companyUuid] ?? null;
        if ($rotated !== null && (string) ($connection['access_token'] ?? '') === $rotated['from']) {
            $connection = array_merge($connection, $rotated['tokens']);
        }

        return $this->runHttp(function () use ($connection, $call, $companyUuid) {
            try {
                return $call($connection);
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() === false || $this->tokens === null || $companyUuid === '') {
                    throw $exception;
                }
                $fresh = $this->rotateTokens($connection);
                if ($fresh === null) {
                    throw $exception;
                }

                return $call($fresh);
            }
        });
    }

    /**
     * The connection to retry a rejected call with, or null when the 401 stands.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function rotateTokens(array $connection): ?array
    {
        $companyUuid = (string) ($connection['company_uuid'] ?? '');
        $sent        = (string) ($connection['access_token'] ?? '');
        $fresh       = $this->tokens?->refreshNow($connection);
        if ($fresh === null || empty($fresh['needs_reauth']) === false) {
            return null;
        }
        if (empty($fresh['refresh_error']) === false) {
            throw new QuickBooksException(503, (string) $fresh['refresh_error']);
        }
        if ((string) ($fresh['access_token'] ?? '') === $sent) {
            return null;
        }
        $tokens = [];
        foreach (FleetbaseDirectory::CONNECTION_TOKEN_FIELDS as $field) {
            if (array_key_exists($field, $fresh) === true) {
                $tokens[$field] = $fresh[$field];
            }
        }
        $this->rotated[$companyUuid] = ['from' => $sent, 'tokens' => $tokens];

        return array_merge($connection, $tokens);
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public function runScheduled(SyncLedger $ledger, string $companyUuid, array $settings, int $now, bool $force = false, bool $ignoreInterval = false): array
    {
        $trigger    = $force === true ? 'now' : 'scheduled';
        $connection = $ledger->connection($companyUuid);
        if (is_array($connection) === false || ConnectionGate::hasRealm($connection) === false) {
            return $this->emptyBatch($trigger, 'skipped');
        }
        if (empty($connection['needs_reauth']) === false) {
            if ($force === true) {
                return $this->recordSkipped($ledger, $companyUuid, $trigger, 'QuickBooks needs to be connected again before sync can continue.');
            }

            return $this->emptyBatch($trigger, 'skipped');
        }
        if ($this->isRateLimited($connection, $now) === true) {
            if ($force === true) {
                return $this->recordSkipped($ledger, $companyUuid, $trigger, self::RATE_LIMITED_MESSAGE);
            }

            return $this->emptyBatch($trigger, 'skipped');
        }
        $last = $connection['last_batch_at'] ?? null;
        if ($force === false && $ignoreInterval === false && $last !== null && ($now - (int) $last) < ($settings['interval_minutes'] * 60)) {
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
        if (is_array($connection) === false || ConnectionGate::hasRealm($connection) === false) {
            return $this->emptyBatch('manual', 'skipped');
        }
        if (empty($connection['needs_reauth']) === false) {
            return $this->recordSkipped($ledger, $companyUuid, 'manual', 'QuickBooks needs to be connected again before sync can continue.');
        }
        if ($this->isRateLimited($connection, $now) === true) {
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
     * @param array<string, mixed>                                             $settings
     */
    public function acceptRemoteChanges(SyncLedger $ledger, string $companyUuid, array $entities, array $settings, int $now): void
    {
        $connection = $ledger->connection($companyUuid);
        if (is_array($connection) === false || ConnectionGate::hasRealm($connection) === false || empty($connection['needs_reauth']) === false) {
            return;
        }

        $ledger->rebuildIndex();
        /** @var array<string, array<string, array<string, mixed>>> $wanted */
        $wanted = [];
        foreach ($entities as $entity) {
            if (is_array($entity) === false) {
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
            if ($this->entityEnabled($settings, $kind) === false || $this->quickbooksSupplies($settings, $kind) === false) {
                continue;
            }
            $link = $ledger->linkForRemote((string) $connection['realm_id'], $name, $id);
            if ($link === null) {
                continue;
            }
            $wanted[$name][$id] = $link;
        }

        foreach ($wanted as $name => $linksById) {
            $remotes = $this->readRemoteIds($connection, $name, array_keys($linksById));
            foreach ($linksById as $id => $link) {
                $remote = $remotes[$id] ?? null;
                if (is_array($remote) === false) {
                    continue;
                }
                $localUuid = (string) ($link['local_uuid'] ?? '');
                if ($name === 'Customer' && isset($ledger->customers[$localUuid]) === true) {
                    $this->applyCustomerFromRemote($ledger, $localUuid, $remote);
                } elseif ($name === 'Invoice' && isset($ledger->invoices[$localUuid]) === true) {
                    $this->applyInvoiceFromRemote($ledger, $localUuid, $remote, (string) ($settings['invoice_reference'] ?? 'fleetbase'));
                    $this->applyPaymentStatus($ledger, $localUuid);
                } elseif ($name === 'Payment') {
                    $invoiceUuid = isset($ledger->invoices[$localUuid]) === true
                        ? $localUuid
                        : $this->invoiceUuidForPayment($ledger, $connection, $remote);
                    if ($invoiceUuid === null) {
                        continue;
                    }
                    $invoiceLink = $ledger->link($companyUuid, (string) $connection['realm_id'], 'invoice', $invoiceUuid);
                    $invoiceId   = is_array($invoiceLink) === true ? trim((string) ($invoiceLink['qbo_id'] ?? '')) : '';
                    $payments    = $this->paymentsTouchingInvoice($ledger, $connection, $invoiceUuid, $invoiceId, $remote, $remotes);
                    $this->applyPaymentsFromRemote($ledger, $invoiceUuid, $payments, $invoiceId);
                    $this->applyPaymentStatus($ledger, $invoiceUuid);
                } elseif ($name === 'Account' && isset($ledger->wallets[$localUuid]) === true) {
                    $this->applyWalletFromRemote($ledger, $localUuid, $this->withoutEmptyDescription($remote), (string) ($settings['wallet_reference'] ?? 'fleetbase'));
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
        if (is_array($connection) === false || ConnectionGate::hasRealm($connection) === false || empty($connection['needs_reauth']) === false) {
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
            $uuid        = (string) $invoice['uuid'];
            $seen[$uuid] = true;
            $rows[]      = ['company_uuid' => $companyUuid, 'local_type' => 'invoice', 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0];
        }
        foreach ($ledger->links as $link) {
            if (($link['company_uuid'] ?? '') !== $companyUuid || ($link['local_type'] ?? '') !== 'invoice') {
                continue;
            }
            $uuid = (string) ($link['local_uuid'] ?? '');
            if ($uuid === '' || isset($seen[$uuid]) === true || isset($ledger->invoices[$uuid]) === false) {
                continue;
            }
            $seen[$uuid] = true;
            $rows[]      = ['company_uuid' => $companyUuid, 'local_type' => 'invoice', 'local_uuid' => $uuid, 'status' => 'pending', 'attempts' => 0];
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
            if ($this->isRateLimited($connection, $now) === true) {
                break;
            }
            $type = (string) ($row['local_type'] ?? 'invoice');
            $key  = $type . '|' . (string) ($row['local_uuid'] ?? '');
            if (isset($handled[$key]) === true) {
                continue;
            }
            if (($counts[$type] ?? 0) > 1) {
                $block = [];
                foreach ($rows as $candidate) {
                    if ((string) ($candidate['local_type'] ?? 'invoice') !== $type) {
                        continue;
                    }
                    $block[]                                                                                                     = $candidate;
                    $handled[(string) ($candidate['local_type'] ?? 'invoice') . '|' . (string) ($candidate['local_uuid'] ?? '')] = true;
                }
                $halt = match ($type) {
                    'customer' => $this->syncCustomerBlock($ledger, $connection, $block, $settings, $batch, $now, $trigger, true),
                    'wallet'   => $this->syncWalletBlock($ledger, $connection, $block, $settings, $batch, $now, $trigger),
                    default    => $this->syncInvoiceBlock($ledger, $connection, $block, $settings, $batch, $now, $trigger),
                };
                $connection = $ledger->connection($companyUuid) ?? $connection;
                if ($halt === true) {
                    break;
                }
                continue;
            }

            $handled[$key] = true;
            $this->runOne($ledger, $connection, $row, $settings, $batch, $now, $trigger);
            $connection = $ledger->connection($companyUuid) ?? $connection;
            if (empty($ledger->connections[$companyUuid]['needs_reauth']) === false) {
                break;
            }
        }

        if ($this->isRateLimited($connection, $now) === false) {
            $ledger->connections[$companyUuid]['last_rate_limit_wait'] = null;
        }

        if ($reportUnmatched === true) {
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
        if ($this->entityPaused($settings, 'customer') === true) {
            $this->closeSkipped = true;

            return 'skipped';
        }

        $customer = $ledger->customers[$uuid] ?? null;
        if ($customer === null) {
            $this->lastError = 'Customer no longer exists in Fleetbase.';

            return 'failed';
        }

        $copy       = $this->copiesRemote($direction);
        $push       = $this->pushesRemote($direction);
        $reference  = (string) ($settings['customer_reference'] ?? 'fleetbase');
        $conflict   = (string) ($settings['customer_conflict'] ?? 'fleetbase');
        $pushClears = $this->sendsClears($conflict, $push, $copy);
        $payload    = $this->customers->toQuickBooks($this->customers->fromParty($customer));
        $email      = trim((string) ($customer['email'] ?? ''));
        $link       = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $uuid);
        if ($link !== null && $link['realm_id'] !== $connection['realm_id']) {
            $link = null;
        }
        if (isset($this->customerLookupFailed[$uuid]) === true) {
            throw new QuickBooksException(500, $this->customerLookupFailed[$uuid]);
        }

        $remote    = null;
        $cacheHit  = $this->customerRemoteCache !== null && array_key_exists($uuid, $this->customerRemoteCache) === true;
        if ($cacheHit === true && is_array($this->customerRemoteCache[$uuid]) === true) {
            $remote = $this->customerRemoteCache[$uuid];
            $ledger->putLink($this->linkFrom($connection, 'customer', $uuid, 'Customer', $remote));
            $link = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $uuid);
        } elseif ($cacheHit === false && $link === null) {
            $remote = $this->findRemoteCustomer($connection, $email, (string) ($payload['DisplayName'] ?? ''), $reference !== 'fleetbase');
            if ($remote !== null) {
                $ledger->putLink($this->linkFrom($connection, 'customer', $uuid, 'Customer', $remote));
                if ($reference === 'quickbooks' && $copy === true) {
                    $before = $ledger->customers[$uuid];
                    $this->applyCustomerFromRemote($ledger, $uuid, $remote);

                    return $ledger->customers[$uuid] !== $before ? 'updated' : 'aligned';
                }
            }
        } elseif ($cacheHit === false && $link !== null) {
            $remote = $this->client->getCustomer($connection, (string) $link['qbo_id']);
        }
        if ($remote === null && $link !== null) {
            return 'aligned';
        }
        if ($remote === null) {
            if ($this->lastError !== null) {
                return 'failed';
            }
            if ($push === false) {
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

        if ($pushClears === true) {
            $payload = $this->customers->toQuickBooks($this->customers->fromParty($ledger->customers[$uuid]), true);
        }

        if ($this->customerMatches($payload, $remote) === true) {
            if ($copy === true && $pushClears === false && $this->copyCustomerNotesAndAddress($ledger, $uuid, $remote) === true) {
                return 'updated';
            }

            return 'aligned';
        }

        if ($reference === 'quickbooks' && $copy === true) {
            $name = trim((string) ($remote['DisplayName'] ?? ''));
            if ($name !== '') {
                $ledger->customers[$uuid]['name'] = $name;
            }
            $payload = $this->customers->toQuickBooks($this->customers->fromParty($ledger->customers[$uuid]), $pushClears);
        }

        if ($this->customerMatches($payload, $remote) === true) {
            return 'updated';
        }

        if ($push === false && $copy === true) {
            $before = $ledger->customers[$uuid];
            $this->applyCustomerFromRemote($ledger, $uuid, $remote);

            return $ledger->customers[$uuid] !== $before ? 'updated' : 'aligned';
        }

        if ($conflict === 'quickbooks' && $copy === true) {
            $this->applyCustomerFromRemote($ledger, $uuid, $remote);

            return 'updated';
        }

        if ($reference === 'quickbooks') {
            $payload['DisplayName'] = (string) ($remote['DisplayName'] ?? $payload['DisplayName'] ?? '');
        }

        if ($push === false) {
            return 'aligned';
        }

        $this->keepBillAddrId($payload, $remote);
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
        if ($this->entityPaused($settings, 'wallet') === true) {
            $this->closeSkipped = true;

            return 'skipped';
        }

        $wallet = $ledger->wallets[$uuid] ?? null;
        if ($wallet === null) {
            $this->lastError = 'Wallet no longer exists in Fleetbase.';

            return 'failed';
        }

        $copy       = $this->copiesRemote($direction);
        $push       = $this->pushesRemote($direction);
        $reference  = (string) ($settings['wallet_reference'] ?? 'fleetbase');
        $conflict   = (string) ($settings['wallet_conflict'] ?? 'fleetbase');
        $pushClears = $this->sendsClears($conflict, $push, $copy);
        $payload    = $this->wallets->toQuickBooks($wallet, $reference);
        // Same clear rule as customer notes. A Fleetbase clear is not filled back in
        // from QuickBooks, and an empty QuickBooks description is not copied.
        $walletCopy = function (array $remoteAccount) use ($ledger, $uuid, $pushClears): array {
            $remoteDescription = trim((string) ($remoteAccount['Description'] ?? ''));
            $localDescription  = trim((string) ($ledger->wallets[$uuid]['description'] ?? ''));
            if ($remoteDescription === '' || ($pushClears === true && $localDescription === '')) {
                unset($remoteAccount['Description']);
            }

            return $remoteAccount;
        };
        $link      = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'wallet', $uuid);
        if ($link !== null && $link['realm_id'] !== $connection['realm_id']) {
            $link = null;
        }
        $hadLink = $link !== null;

        if ($link === null && empty($payload['AcctNum']) === false) {
            $acctNum = (string) $payload['AcctNum'];
            if (isset($this->accountFieldFailed['AcctNum:' . $acctNum]) === true) {
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
            if (isset($this->accountReadFailed[$accountId]) === true) {
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
            if ($link === null && $acctNum !== '' && isset($this->accountFieldFailed['AcctNum:' . $acctNum]) === true) {
                $this->lastError = $this->accountFieldFailed['AcctNum:' . $acctNum];

                return 'failed';
            }
            if ($name !== '' && isset($this->accountFieldFailed['Name:' . $name]) === true) {
                $this->lastError = $this->accountFieldFailed['Name:' . $name];

                return 'failed';
            }
            $remote = $this->reattachWallet($ledger, $connection, $uuid, $wallet, $payload);
            $link   = $remote === null ? null : $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'wallet', $uuid);
        }
        if ($remote === null && $hadLink === true) {
            return 'aligned';
        }
        if ($remote === null) {
            if ($quickbooksPrimary === false) {
                $this->lastError = $this->currencyError($ledger, $connection, (string) ($wallet['currency'] ?? ''), 'Wallet');
                if ($this->lastError !== null) {
                    return 'failed';
                }
            }
            if ($push === false) {
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
            if ($copy === true) {
                $this->applyWalletFromRemote($ledger, $uuid, $walletCopy($created), $reference);
                if ($quickbooksPrimary === true) {
                    $this->copyWalletCurrency($ledger, $connection, $uuid, $created);
                }
            }

            return 'created';
        }

        $before = $wallet;
        if ($quickbooksPrimary === true && $copy === true) {
            $this->applyWalletFromRemote($ledger, $uuid, $walletCopy($remote), $reference);
            $this->copyWalletCurrency($ledger, $connection, $uuid, $remote);
            $wallet  = $ledger->wallets[$uuid];
            $payload = $this->wallets->toQuickBooks($wallet, $reference);
        }

        if ($this->wallets->matches($wallet, $payload, $remote) === true) {
            return $wallet !== $before ? 'updated' : 'aligned';
        }

        if ($conflict === 'quickbooks' && $copy === true) {
            $before = $ledger->wallets[$uuid];
            $this->applyWalletFromRemote($ledger, $uuid, $walletCopy($remote), $reference);
            $this->copyWalletCurrency($ledger, $connection, $uuid, $remote);

            return $ledger->wallets[$uuid] !== $before ? 'updated' : 'aligned';
        }

        if ($push === false) {
            if ($copy === true) {
                $before = $ledger->wallets[$uuid];
                $this->applyWalletFromRemote($ledger, $uuid, $walletCopy($remote), $reference);

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

        if ($pushClears === true) {
            $withClears = $this->wallets->toQuickBooks($ledger->wallets[$uuid], $reference, true);
            if (array_key_exists('Description', $withClears) === true) {
                $payload['Description'] = $withClears['Description'];
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
        if ($this->entityPaused($settings, 'invoice') === true) {
            $this->closeSkipped = true;

            return 'skipped';
        }

        $invoice = $ledger->invoices[$uuid] ?? null;
        if ($invoice === null) {
            $this->lastError = 'Invoice no longer exists in Fleetbase.';

            return 'failed';
        }

        $copy         = $this->copiesRemote($direction);
        $push         = $this->pushesRemote($direction);
        $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
        if ($customerUuid !== '' && isset($this->customerLookupFailed[$customerUuid]) === true) {
            throw new QuickBooksException(500, $this->customerLookupFailed[$customerUuid]);
        }
        if ($customerUuid !== '' && $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'customer', $customerUuid) === null) {
            if ($this->entityPaused($settings, 'customer') === true) {
                $this->lastError = 'Customer sync is off, so this invoice cannot be synced.';

                return 'failed';
            }
            if ($this->customersPrepared === true) {
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
        $voided = in_array($status, ['void', 'voided', 'cancelled', 'canceled', 'deleted'], true) === true || empty($invoice['deleted_at']) === false;
        $itemId = $voided === true ? '' : $this->serviceItemId($ledger, $connection);
        if ($voided === false && $itemId === '') {
            $this->lastError = 'QuickBooks has no item for invoice lines. Fleetbase could not create its "Fleetbase service" item; add an Income account in QuickBooks and try again.';

            return 'failed';
        }

        $payload    = $this->invoices->toQuickBooks($invoice, (string) $customerLink['qbo_id'], $itemId);
        $reference  = (string) ($settings['invoice_reference'] ?? 'fleetbase');
        $conflict   = (string) ($settings['invoice_conflict'] ?? 'fleetbase');
        $pushClears = $this->sendsClears($conflict, $push, $copy);
        if ($reference === 'quickbooks') {
            unset($payload['DocNumber']);
        }
        $link = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'invoice', $uuid);

        if ($link === null && $voided === false && empty($invoice['number']) === false) {
            $number = (string) $invoice['number'];
            if (isset($this->invoiceDocReadFailed[$number]) === true) {
                $this->lastError = $this->invoiceDocReadFailed[$number];

                return 'failed';
            }
            $existing = $this->invoiceByDoc !== null
                ? ($this->invoiceByDoc[$number] ?? null)
                : $this->client->findInvoiceByDocNumber($connection, $number);
            if (is_array($existing) === true && $this->invoiceCustomerMatches($existing, (string) ($customerLink['qbo_id'] ?? '')) === true) {
                $ledger->putLink($this->linkFrom($connection, 'invoice', $uuid, 'Invoice', $existing));
                $link      = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'invoice', $uuid);
                $matchedId = trim((string) ($existing['Id'] ?? ''));
                if ($this->invoiceById !== null && $matchedId !== '') {
                    $this->invoiceById[$matchedId] = $existing;
                }
            }
        }

        if ($voided === true) {
            if ($link === null) {
                return 'aligned';
            }
            $remoteId = (string) $link['qbo_id'];
            if (isset($this->invoiceReadFailed[$remoteId]) === true) {
                $this->lastError = $this->invoiceReadFailed[$remoteId];

                return 'failed';
            }
            $remote = $this->invoiceById !== null
                ? ($this->invoiceById[$remoteId] ?? null)
                : $this->client->getInvoice($connection, $remoteId);
            if ($remote === null) {
                return 'aligned';
            }
            if ($push === false) {
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
            if (isset($this->invoiceReadFailed[$remoteId]) === true) {
                $this->lastError = $this->invoiceReadFailed[$remoteId];

                return 'failed';
            }
            $remote = $this->invoiceById !== null
                ? ($this->invoiceById[$remoteId] ?? null)
                : $this->client->getInvoice($connection, $remoteId);
        }
        // An empty read means QuickBooks deleted this linked invoice. Do not create a replacement.
        // A failed read is stored separately and returns failed above, so it is not an empty result.
        if ($remote === null && $link !== null) {
            return 'aligned';
        }
        if ($remote === null) {
            if ($push === false) {
                $this->closeSkipped = true;

                return 'skipped';
            }
            if ($this->rejectInvoiceCurrency($payload, $ledger, $connection) === true) {
                return 'failed';
            }
            if ($reference === 'quickbooks' && $this->customTxnNumbers($connection) === true) {
                $next = $this->reservedDocNumbers !== null
                    ? ($this->reservedDocNumbers[$uuid] ?? null)
                    : $this->client->nextInvoiceDocNumber($connection);
                if (is_string($next) === false || $next === '') {
                    $this->lastError = 'QuickBooks did not confirm a free invoice number, so Fleetbase did not create this invoice.';

                    return 'failed';
                }
                $payload['DocNumber'] = $next;
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
        $partyChanged  = false;
        if ($this->quickbooksSupplies($settings, 'invoice') === true) {
            $partyChanged = $this->copyInvoiceCustomerAndCurrency($ledger, $uuid, $remote);
        }
        $invoice     = $ledger->invoices[$uuid];
        $remoteCents = $this->majorUnits($remote['TotalAmt'] ?? 0);
        $localCents  = (int) ($invoice['total'] ?? 0);
        $currencyOk  = $this->invoiceCurrencyError($ledger, $connection, (string) ($invoice['currency'] ?? '')) === null;
        // QuickBooks replaces the Fleetbase line set only when this sync copies remote lines.
        // Otherwise extra QuickBooks sales lines are not a Fleetbase mismatch.
        $remoteSuppliesLines = ($conflict === 'quickbooks' && $copy === true) || ($push === false && $copy === true);
        if ($this->invoiceHashesMatch($invoice, $remote, $conflict, $remoteCents, $localCents, $reference, $pushClears, (string) $customerLink['qbo_id'], $pushClears === true && $currencyOk === true, $remoteSuppliesLines === false) === true) {
            $outcome = ($numberChanged === true || $partyChanged === true) ? 'updated' : 'aligned';
            if ($this->writeBuffer !== null) {
                return $outcome;
            }

            return $this->withPayment(
                $outcome,
                $this->syncPayment($ledger, $connection, $invoice, (string) $customerLink['qbo_id'], (string) $remote['Id'], $settings)
            );
        }

        if (($conflict === 'quickbooks' && $copy === true) || ($push === false && $copy === true)) {
            $beforeStatus = (string) ($ledger->invoices[$uuid]['status'] ?? '');
            $changed      = $this->applyInvoiceFromRemote($ledger, $uuid, $remote, $reference) === true || $numberChanged === true;
            $this->applyPaymentStatus($ledger, $uuid);
            if ((string) ($ledger->invoices[$uuid]['status'] ?? '') !== $beforeStatus) {
                $changed = true;
            }

            $sendNumber = $push === true && $this->fleetbaseDocNumberDiffers($ledger->invoices[$uuid], $remote, $reference) === true;
            if ($sendNumber === false) {
                if ($this->writeBuffer !== null) {
                    return $changed === true ? 'updated' : 'aligned';
                }

                return $this->withPayment(
                    $changed === true ? 'updated' : 'aligned',
                    $this->syncPayment($ledger, $connection, $ledger->invoices[$uuid], (string) $customerLink['qbo_id'], (string) $remote['Id'], $settings)
                );
            }

            $payload = $this->invoices->toQuickBooks($ledger->invoices[$uuid], (string) $customerLink['qbo_id'], $itemId);
        }

        if ($push === false) {
            return ($numberChanged === true || $partyChanged === true) ? 'updated' : 'aligned';
        }

        if ($pushClears === true) {
            $payload = $this->invoices->toQuickBooks($ledger->invoices[$uuid], (string) $customerLink['qbo_id'], $itemId, true);
            if ($reference === 'quickbooks') {
                unset($payload['DocNumber']);
            }
        }

        if ($this->rejectInvoiceCurrency($payload, $ledger, $connection) === true) {
            return 'failed';
        }
        $this->keepFleetbaseDocNumber($payload, $ledger->invoices[$uuid], $remote, $reference);
        $payload = $this->invoices->withExistingLineIds($payload, $remote);
        $this->rememberPushedLineIds($ledger, $uuid, $payload);

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
        if ($this->entityPaused($settings, 'payment') === true) {
            return null;
        }
        $copyPay = $this->copiesRemote($payDirection);
        $pushPay = $this->pushesRemote($payDirection);

        $reference         = (string) ($settings['payment_reference'] ?? 'fleetbase');
        $conflict          = (string) ($settings['payment_conflict'] ?? 'fleetbase');
        $quickbooksPrimary = ($reference === 'quickbooks' || $conflict === 'quickbooks') && $copyPay === true;
        $status            = (string) ($invoice['status'] ?? '');
        $pushesPayment     = in_array($status, ['paid', 'partial'], true);
        if ($pushesPayment === false && $quickbooksPrimary === false) {
            return null;
        }

        $uuid          = (string) $invoice['uuid'];
        $applied       = $this->paymentsApplied($ledger, $connection, $uuid, $invoiceId);
        $payments      = $applied['payments'];
        $remoteInvoice = $applied['invoice'];
        if ($payments === []) {
            if ($applied['missing'] === true || $pushesPayment === false || $applied['settled'] === true || $pushPay === false) {
                return null;
            }

            $amountCents = $this->paymentAmountCents($invoice);
            if ($amountCents <= 0) {
                return null;
            }
            $payload = $this->invoices->payment($customerRef, $invoiceId, $amountCents, $this->paymentDate($invoice));
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
            $this->storePaymentLink($ledger, $connection, $uuid, $invoiceId, $created, $remoteInvoice);

            return null;
        }

        foreach ($payments as $remote) {
            $this->storePaymentLink($ledger, $connection, $uuid, $invoiceId, $remote, $remoteInvoice);
        }

        if ($pushesPayment === false) {
            $before = $ledger->invoices[$uuid];
            $this->applyPaymentsFromRemote($ledger, $uuid, $payments, $invoiceId);
            $this->applyPaymentStatus($ledger, $uuid);

            return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]) === true ? 'updated' : null;
        }

        if ($this->paymentsMatch($invoice, $payments, $invoiceId) === true) {
            return null;
        }

        if ($conflict === 'quickbooks' && $copyPay === true) {
            $before = $ledger->invoices[$uuid];
            $this->applyPaymentsFromRemote($ledger, $uuid, $payments, $invoiceId);
            $this->applyPaymentStatus($ledger, $uuid);

            return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]) === true ? 'updated' : null;
        }

        if ($pushPay === false) {
            if ($copyPay === true) {
                $before = $ledger->invoices[$uuid];
                $this->applyPaymentsFromRemote($ledger, $uuid, $payments, $invoiceId);
                $this->applyPaymentStatus($ledger, $uuid);

                return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]) === true ? 'updated' : null;
            }

            return null;
        }

        if (count($payments) !== 1) {
            $this->lastError = 'This invoice has more than one QuickBooks payment, so Fleetbase left them unchanged.';

            return 'skipped';
        }

        $remote = $payments[0];
        if ($this->paymentCoversOtherInvoices($remote, $invoiceId) === true) {
            $this->lastError = 'This QuickBooks payment also applies to other invoices, so Fleetbase left it unchanged.';

            return 'skipped';
        }

        $amountCents = $this->paymentAmountCents($invoice);
        if ($amountCents <= 0) {
            return null;
        }
        $payload = $this->invoices->payment($customerRef, $invoiceId, $amountCents, $this->paymentDate($invoice));
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
        $this->storePaymentLink($ledger, $connection, $uuid, $invoiceId, $updated, $remoteInvoice);

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
        if ($exception instanceof QuickBooksException && $exception->isRateLimit() === true) {
            $previous                                                  = isset($connection['last_rate_limit_wait']) === true ? (int) $connection['last_rate_limit_wait'] : null;
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
     * A 401 that reaches here is final: runAuthorized() already refreshed the access token and
     * repeated the call, and Intuit refused the refresh token (invalid_grant) or the new token.
     * With no token service wired in, a 401 is final at once. Every remaining call will fail
     * too, so rows keep their attempts and stay pending until the user connects again.
     */
    private function tokenRejected(SyncLedger $ledger, string $companyUuid): void
    {
        // A refresh during this run already stored newer tokens. Compare against those, or the
        // save would see a stale copy and drop the flag, and the refresh would repeat every run.
        if (isset($this->rotated[$companyUuid]) === true && isset($ledger->connections[$companyUuid]) === true) {
            $ledger->connections[$companyUuid] = array_merge($ledger->connections[$companyUuid], $this->rotated[$companyUuid]['tokens']);
        }
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
        return empty($connection['rate_limited_until']) === false && (int) $connection['rate_limited_until'] > $now;
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
     * An invoice currency that differs from the home currency is accepted only when
     * QuickBooks multi-currency is enabled.
     *
     * @param array<string, mixed> $connection
     */
    private function invoiceCurrencyError(SyncLedger $ledger, array $connection, string $currency): ?string
    {
        $error = $this->currencyError($ledger, $connection, $currency, 'Invoice');
        if ($error === null || $this->client->multiCurrencyEnabled($connection) === true) {
            return null;
        }

        return $error;
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
        $itemId      = trim((string) (is_array($stored) === true ? ($stored['default_item_id'] ?? '') : ''));
        if ($itemId === '') {
            $itemId = trim((string) ($connection['default_item_id'] ?? ''));
        }
        if ($itemId !== '') {
            return $itemId;
        }
        if ($this->serviceItemByCompany !== null && array_key_exists($companyUuid, $this->serviceItemByCompany) === true) {
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
            if ($ledger->hasRemoteLink($realm, 'Invoice', (string) ($remote['Id'] ?? '')) === false) {
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
     * The same DocNumber on another customer's invoice is a different invoice.
     *
     * @param array<string, mixed> $remote
     */
    private function invoiceCustomerMatches(array $remote, string $customerId): bool
    {
        $customerId = trim($customerId);
        if ($customerId === '') {
            return false;
        }

        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);

        return $remoteCustomer !== '' && $remoteCustomer === $customerId;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function remoteInvoiceCustomerId(array $remote): string
    {
        $ref = $remote['CustomerRef'] ?? null;
        if (is_array($ref) === true) {
            return trim((string) ($ref['value'] ?? ''));
        }

        return trim((string) $ref);
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function remoteInvoiceCurrency(array $remote): string
    {
        $ref = $remote['CurrencyRef'] ?? null;
        if (is_array($ref) === true) {
            return strtoupper(trim((string) ($ref['value'] ?? '')));
        }

        return strtoupper(trim((string) $ref));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function keepFleetbaseDocNumber(array &$payload, array $invoice, array $remote, string $reference): void
    {
        if ($this->fleetbaseDocNumberDiffers($invoice, $remote, $reference) === false) {
            return;
        }

        $payload['DocNumber'] = trim((string) ($invoice['number'] ?? ''));
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $remote
     */
    private function invoiceHashesMatch(array $invoice, array $remote, string $conflict, int $remoteCents, int $localCents, string $reference, bool $pushClears, string $customerRef, bool $compareCurrency, bool $ignoreExtraSalesLines): bool
    {
        if ($this->fleetbaseDocNumberDiffers($invoice, $remote, $reference) === true) {
            return false;
        }

        $localItems = is_array($invoice['items'] ?? null) === true ? $invoice['items'] : [];
        $compared   = $remote;
        if ($ignoreExtraSalesLines === true) {
            $limited     = $this->invoices->withoutUnmatchedSalesLines($localItems, $remote);
            $compared    = $limited['remote'];
            $remoteCents -= $limited['omitted_cents'];
        }

        $local = ['total' => $localCents];
        $other = ['total' => $remoteCents];
        foreach (['date' => 'TxnDate', 'due_date' => 'DueDate'] as $localKey => $remoteKey) {
            $value = (string) ($invoice[$localKey] ?? '');
            if ($value === '' && $pushClears === false) {
                continue;
            }
            $local[$localKey] = $value;
            $other[$localKey] = (string) ($remote[$remoteKey] ?? '');
        }
        if ($pushClears === true || array_key_exists('PrivateNote', $remote) === true) {
            $local['notes'] = (string) ($invoice['notes'] ?? '');
            $other['notes'] = (string) ($remote['PrivateNote'] ?? '');
        }
        if ($pushClears === true) {
            $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
            if ($remoteCustomer !== '') {
                $local['customer'] = trim($customerRef);
                $other['customer'] = $remoteCustomer;
            }
        }
        if ($compareCurrency === true) {
            $localCurrency  = strtoupper(trim((string) ($invoice['currency'] ?? '')));
            $remoteCurrency = $this->remoteInvoiceCurrency($remote);
            if ($localCurrency !== '' && $remoteCurrency !== '') {
                $local['currency'] = $localCurrency;
                $other['currency'] = $remoteCurrency;
            }
        }
        $mapped         = $this->invoices->fromQuickBooks($compared);
        $local['items'] = $this->hashableInvoiceItems($localItems);
        $local['tax']   = (int) ($invoice['tax'] ?? 0);
        $other['items'] = $mapped['items'];
        $other['tax']   = $mapped['tax'];

        return ContentHash::of($local) === ContentHash::of($other);
    }

    /**
     * The line id is how the next sync pairs a Fleetbase line. It is not part of the content hash.
     *
     * @param array<int, mixed> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function hashableInvoiceItems(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }
            unset($item['qbo_line_id']);
            $clean[] = $item;
        }

        return $clean;
    }

    /**
     * Remember the QuickBooks ids copied onto the Fleetbase lines so the next sync
     * pairs those lines and does not treat leftover remote sales lines as local edits.
     *
     * @param array<string, mixed> $payload
     */
    private function rememberPushedLineIds(SyncLedger $ledger, string $uuid, array $payload): void
    {
        $items = $ledger->invoices[$uuid]['items'] ?? null;
        $lines = is_array($payload['Line'] ?? null) === true ? $payload['Line'] : [];
        if (is_array($items) === false) {
            return;
        }
        $sales = [];
        foreach ($lines as $line) {
            if (is_array($line) === false || (string) ($line['DetailType'] ?? '') !== 'SalesItemLineDetail') {
                continue;
            }
            if ((string) ($line['Description'] ?? '') === InvoiceMapper::TAX_LINE_DESCRIPTION) {
                continue;
            }
            $sales[] = trim((string) ($line['Id'] ?? ''));
        }
        foreach ($items as $index => $item) {
            if (is_array($item) === false || isset($sales[$index]) === false || $sales[$index] === '') {
                continue;
            }
            $ledger->invoices[$uuid]['items'][$index]['qbo_line_id'] = $sales[$index];
        }
    }

    private function majorUnits(mixed $amount): int
    {
        if (is_float($amount) === true) {
            throw new \InvalidArgumentException('Money must not be a float.');
        }
        if (is_int($amount) === false && is_string($amount) === false) {
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
            if (is_array($account) === false) {
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
            'email' => $this->comparableEmail((string) ($payload['PrimaryEmailAddr']['Address'] ?? '')),
            'name'  => (string) ($payload['DisplayName'] ?? ''),
            'phone' => $this->comparablePhone((string) ($payload['PrimaryPhone']['FreeFormNumber'] ?? '')),
        ];
        if (array_key_exists('Notes', $payload) === true) {
            $local['notes'] = (string) $payload['Notes'];
        }
        if (isset($payload['BillAddr']) === true && is_array($payload['BillAddr']) === true) {
            $local['address'] = [
                'city'        => (string) ($payload['BillAddr']['City'] ?? ''),
                'country'     => (string) ($payload['BillAddr']['Country'] ?? ''),
                'line1'       => (string) ($payload['BillAddr']['Line1'] ?? ''),
                'line2'       => (string) ($payload['BillAddr']['Line2'] ?? ''),
                'postal_code' => (string) ($payload['BillAddr']['PostalCode'] ?? ''),
                'state'       => (string) ($payload['BillAddr']['CountrySubDivisionCode'] ?? ''),
            ];
        }

        $other = [
            'email' => $this->comparableEmail((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')),
            'name'  => (string) ($remote['DisplayName'] ?? ''),
            'phone' => $this->comparablePhone((string) ($remote['PrimaryPhone']['FreeFormNumber'] ?? '')),
        ];
        if (array_key_exists('notes', $local) === true) {
            $other['notes'] = (string) ($remote['Notes'] ?? '');
        }
        if (array_key_exists('address', $local) === true) {
            $bill             = is_array($remote['BillAddr'] ?? null) === true ? $remote['BillAddr'] : [];
            $other['address'] = [
                'city'        => (string) ($bill['City'] ?? ''),
                'country'     => (string) ($bill['Country'] ?? ''),
                'line1'       => (string) ($bill['Line1'] ?? ''),
                'line2'       => (string) ($bill['Line2'] ?? ''),
                'postal_code' => (string) ($bill['PostalCode'] ?? ''),
                'state'       => (string) ($bill['CountrySubDivisionCode'] ?? ''),
            ];
        }

        return ContentHash::of($local) === ContentHash::of($other);
    }

    /**
     * Same key import matching uses: trimmed, lower-case email.
     */
    private function comparableEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Same key import matching uses: digits only, so punctuation is not a change.
     */
    private function comparablePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
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
        $changed = $this->copyInvoiceNumber($ledger, $uuid, $remote, $reference) === true
            || $this->copyInvoiceCustomerAndCurrency($ledger, $uuid, $remote) === true;
        foreach (['TxnDate' => 'date', 'DueDate' => 'due_date'] as $remoteKey => $local) {
            $value = (string) ($remote[$remoteKey] ?? '');
            if ($value !== '' && (string) ($ledger->invoices[$uuid][$local] ?? '') !== $value) {
                $ledger->invoices[$uuid][$local] = $value;
                $changed                         = true;
            }
        }
        $note = (string) ($remote['PrivateNote'] ?? '');
        if ($note !== '' && $note !== (string) ($ledger->invoices[$uuid]['notes'] ?? '')) {
            $ledger->invoices[$uuid]['notes'] = $note;
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
        if ($changed === true) {
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
        $this->copyCustomerNotesAndAddress($ledger, $uuid, $remote);
        $ledger->touchCustomer($uuid);
    }

    /**
     * Notes and billing address are omitted from the outbound payload when Fleetbase
     * has none and QuickBooks is the source, so a match on name, email, and phone
     * still has to copy them. An empty QuickBooks note or address is not copied,
     * the same as email and phone. A Fleetbase-primary push sends the clear instead.
     *
     * @param array<string, mixed> $remote
     */
    private function copyCustomerNotesAndAddress(SyncLedger $ledger, string $uuid, array $remote): bool
    {
        $before = $ledger->customers[$uuid];
        $notes  = (string) ($remote['Notes'] ?? '');
        if ($notes !== '') {
            $ledger->customers[$uuid]['notes'] = $notes;
        }
        $bill = $remote['BillAddr'] ?? null;
        if (is_array($bill) === true) {
            $address = $this->customers->addressFromBillAddr($bill);
            if ($address !== null) {
                $ledger->customers[$uuid]['address'] = $address;
            }
        }
        if ($ledger->customers[$uuid] === $before) {
            return false;
        }

        $ledger->touchCustomer($uuid);

        return true;
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

        if (array_key_exists('Description', $remote) === true) {
            $ledger->wallets[$uuid]['description'] = (string) $remote['Description'];
        }

        $currency = strtoupper(trim((string) ($remote['CurrencyRef']['value'] ?? '')));
        if ($currency !== '') {
            $ledger->wallets[$uuid]['currency'] = $currency;
        }

        if (array_key_exists('Active', $remote) === true) {
            $ledger->wallets[$uuid]['status'] = (bool) $remote['Active'] === true ? 'active' : 'closed';
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
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function findRemoteCustomer(array $connection, string $email, string $displayName, bool $allowNameWhenEmailsDiffer = false): ?array
    {
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
        if ($byName === null || $allowNameWhenEmailsDiffer === true || $email === '') {
            return $byName;
        }

        $remoteEmail = strtolower(trim((string) ($byName['PrimaryEmailAddr']['Address'] ?? '')));
        if ($remoteEmail === '' || $remoteEmail === strtolower($email)) {
            return $byName;
        }

        return null;
    }

    /**
     * Every payment applied to this invoice. A failed block read is not treated as
     * "no payment", and it is not fetched again one id at a time.
     *
     * @param array<string, mixed> $connection
     *
     * @return array{payments: array<int, array<string, mixed>>, settled: bool, invoice: array<string, mixed>|null, missing: bool}
     */
    private function paymentsApplied(SyncLedger $ledger, array $connection, string $invoiceUuid, string $invoiceId): array
    {
        $remoteInvoice = $this->remoteInvoice($connection, $invoiceId);
        $ids           = [];
        $listed        = [];
        if (is_array($remoteInvoice) === true) {
            foreach ($this->linkedPaymentIds($remoteInvoice) as $id) {
                $ids[$id]    = true;
                $listed[$id] = true;
            }
        }
        if ($this->blockPayments !== null) {
            foreach ($this->blockPayments[$invoiceId] ?? [] as $payment) {
                if (is_array($payment) === false) {
                    continue;
                }
                $id = trim((string) ($payment['Id'] ?? ''));
                if ($id !== '') {
                    $ids[$id]    = true;
                    $listed[$id] = true;
                }
            }
        }
        $legacy = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'payment', $invoiceUuid);
        if (is_array($legacy) === true) {
            $id = trim((string) ($legacy['qbo_id'] ?? ''));
            if ($id !== '') {
                $ids[$id]    = true;
                $listed[$id] = true;
            }
        }
        // A payment stored under its QuickBooks id is absent from the invoice body after
        // that link moves. The payment-invoice row is the local record that it still applies.
        $remembered = $this->rememberedPaymentIds($ledger, $connection, [$invoiceUuid]);
        foreach (array_keys($remembered) as $id) {
            $ids[$id] = true;
        }
        foreach (array_keys($ids) as $id) {
            if (isset($this->paymentReadFailed[$id]) === true) {
                throw new QuickBooksException(500, $this->paymentReadFailed[$id]);
            }
        }

        $payments = [];
        $missing  = false;
        foreach (array_keys($ids) as $id) {
            $remote = $this->readPayment($connection, $id);
            if (is_array($remote) === false) {
                $missing = true;
                continue;
            }
            if (isset($listed[$id]) === false && $this->linkedLineCents($remote, $invoiceId) === null) {
                $missing = true;
                continue;
            }
            $payments[$id] = $remote;
        }
        foreach ($this->paymentLinkRows($ledger, $connection) as $link) {
            $id    = trim((string) ($link['qbo_id'] ?? ''));
            $local = (string) ($link['local_uuid'] ?? '');
            if ($id === '' || $local !== $id || isset($payments[$id]) === true || isset($ids[$id]) === true || isset($this->paymentReadFailed[$id]) === true) {
                continue;
            }
            $remote = $this->readPayment($connection, $id);
            if (is_array($remote) === true && $this->linkedLineCents($remote, $invoiceId) !== null) {
                $payments[$id] = $remote;
            }
        }

        $settled = false;
        if (is_array($remoteInvoice) === true) {
            $balance = $remoteInvoice['Balance'] ?? null;
            if ($balance !== null && $balance !== '' && $this->majorUnits($balance) === 0) {
                $settled = true;
            }
        }
        if ($payments !== []) {
            $settled = true;
        }

        return [
            'payments' => array_values($payments),
            'settled'  => $settled,
            'invoice'  => is_array($remoteInvoice) === true ? $remoteInvoice : null,
            'missing'  => $missing === true && $payments === [],
        ];
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     */
    private function invoiceUuidForPayment(SyncLedger $ledger, array $connection, array $remote): ?string
    {
        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        foreach ($ledger->invoices as $uuid => $invoice) {
            if (is_array($invoice) === false) {
                continue;
            }
            $link      = $ledger->link($company, $realm, 'invoice', (string) $uuid);
            $invoiceId = is_array($link) === true ? trim((string) ($link['qbo_id'] ?? '')) : '';
            if ($invoiceId !== '' && $this->linkedLineCents($remote, $invoiceId) !== null) {
                return (string) $uuid;
            }
        }

        return null;
    }

    /**
     * The changed payment plus every other payment already linked to this invoice.
     * Amounts stay in integer minor units.
     *
     * @param array<string, mixed>                $connection
     * @param array<string, mixed>                $changed
     * @param array<string, array<string, mixed>> $alreadyRead
     *
     * @return array<int, array<string, mixed>>
     */
    private function paymentsTouchingInvoice(SyncLedger $ledger, array $connection, string $invoiceUuid, string $invoiceId, array $changed, array $alreadyRead): array
    {
        $payments  = [];
        $changedId = trim((string) ($changed['Id'] ?? ''));
        if ($changedId !== '') {
            $payments[$changedId] = $changed;
        }
        foreach ($alreadyRead as $remote) {
            if (is_array($remote) === false) {
                continue;
            }
            $id = trim((string) ($remote['Id'] ?? ''));
            if ($id === '' || isset($payments[$id]) === true) {
                continue;
            }
            if ($invoiceId !== '' && $this->linkedLineCents($remote, $invoiceId) !== null) {
                $payments[$id] = $remote;
            }
        }

        $missing = [];
        foreach ($this->paymentLinkRows($ledger, $connection) as $link) {
            $id    = trim((string) ($link['qbo_id'] ?? ''));
            $local = (string) ($link['local_uuid'] ?? '');
            if ($id === '' || isset($payments[$id]) === true || ($local !== $invoiceUuid && $local !== $id)) {
                continue;
            }
            $missing[$id] = $local;
        }
        foreach ($this->readRemoteIds($connection, 'Payment', array_keys($missing)) as $id => $remote) {
            $local = $missing[$id] ?? '';
            if ($local === $invoiceUuid || ($invoiceId !== '' && $this->linkedLineCents($remote, $invoiceId) !== null)) {
                $payments[$id] = $remote;
            }
        }

        return array_values($payments);
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function remoteInvoice(array $connection, string $invoiceId): ?array
    {
        if ($this->blockPayments !== null || $this->invoiceById !== null) {
            $invoice = $this->invoiceById[$invoiceId] ?? null;

            return is_array($invoice) === true ? $invoice : null;
        }

        return $this->client->getInvoice($connection, $invoiceId);
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>|null
     */
    private function readPayment(array $connection, string $id): ?array
    {
        if (isset($this->paymentReadFailed[$id]) === true) {
            throw new QuickBooksException(500, $this->paymentReadFailed[$id]);
        }
        if ($this->paymentById !== null) {
            if (array_key_exists($id, $this->paymentById) === false) {
                return null;
            }
            $cached = $this->paymentById[$id];

            return is_array($cached) === true ? $cached : null;
        }

        return $this->client->getPayment($connection, $id);
    }

    /**
     * One stored link per QuickBooks payment id. A payment that cannot be found
     * again from the invoice or its own lines keeps the invoice uuid so the next
     * sync still sees it. A later payment never replaces an earlier id.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function storePaymentLink(SyncLedger $ledger, array $connection, string $invoiceUuid, string $invoiceId, array $remote, ?array $remoteInvoice = null): void
    {
        $paymentId = trim((string) ($remote['Id'] ?? ''));
        if ($paymentId === '') {
            return;
        }
        if ($remoteInvoice === null && $invoiceId !== '' && $this->invoiceById !== null) {
            $cached = $this->invoiceById[$invoiceId] ?? null;
            if (is_array($cached) === true) {
                $remoteInvoice = $cached;
            }
        }

        $company        = (string) $connection['company_uuid'];
        $realm          = (string) $connection['realm_id'];
        $legacy         = $ledger->link($company, $realm, 'payment', $invoiceUuid);
        $legacyId       = is_array($legacy) === true ? trim((string) ($legacy['qbo_id'] ?? '')) : '';
        $rediscoverable = $this->paymentRediscoverable($remote, $invoiceId, $remoteInvoice);
        $byPayment      = $rediscoverable === true || ($legacyId !== '' && $legacyId !== $paymentId);
        $localUuid      = $byPayment === true ? $paymentId : $invoiceUuid;
        $attributes     = $this->linkFrom($connection, 'payment', $localUuid, 'Payment', $remote);
        // The payment id is not the invoice. Keep the invoice on the link so a later
        // delete can unmark it after QuickBooks stops returning this payment.
        if ($byPayment === true && $invoiceUuid !== '' && $invoiceUuid !== $paymentId && ($rediscoverable === true || $legacyId === $paymentId)) {
            $kept    = $invoiceUuid;
            $already = $ledger->link($company, $realm, 'payment', $paymentId);
            if (is_array($already) === true) {
                $prior = trim((string) ($already['invoice_uuid'] ?? ''));
                if ($prior !== '' && $prior !== $paymentId) {
                    $kept = $prior;
                }
            }
            $attributes['invoice_uuid'] = $kept;
        }
        if ($byPayment === true && is_array($legacy) === true && $legacyId === $paymentId && $ledger->link($company, $realm, 'payment', $paymentId) === null) {
            $this->rekeyPaymentLink($ledger, $company, $realm, $invoiceUuid, $attributes);

            return;
        }

        $ledger->putLink($attributes);
        if ($byPayment === true && is_array($legacy) === true && $legacyId === $paymentId) {
            $this->forgetPaymentLink($ledger, $company, $realm, $invoiceUuid);
        }
    }

    /**
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>|null $remoteInvoice
     */
    private function paymentRediscoverable(array $remote, string $invoiceId, ?array $remoteInvoice): bool
    {
        if ($invoiceId !== '' && $this->linkedLineCents($remote, $invoiceId) !== null) {
            return true;
        }
        $paymentId = trim((string) ($remote['Id'] ?? ''));
        if ($paymentId === '' || is_array($remoteInvoice) === false) {
            return false;
        }

        return in_array($paymentId, $this->linkedPaymentIds($remoteInvoice), true);
    }

    /**
     * QuickBooks payment ids remembered for these Fleetbase invoices.
     * payment-invoice.local_uuid is the payment id and qbo_id is the invoice uuid.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $invoiceUuids
     *
     * @return array<string, true>
     */
    private function rememberedPaymentIds(SyncLedger $ledger, array $connection, array $invoiceUuids): array
    {
        $wanted = [];
        foreach ($invoiceUuids as $uuid) {
            $uuid = trim($uuid);
            if ($uuid !== '') {
                $wanted[$uuid] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        $ids     = [];
        foreach ($ledger->links as $link) {
            if (is_array($link) === false) {
                continue;
            }
            if ((string) ($link['company_uuid'] ?? '') !== $company || (string) ($link['realm_id'] ?? '') !== $realm) {
                continue;
            }
            if ((string) ($link['local_type'] ?? '') !== 'payment-invoice') {
                continue;
            }
            $invoiceUuid = trim((string) ($link['qbo_id'] ?? ''));
            $paymentId   = trim((string) ($link['local_uuid'] ?? ''));
            if ($paymentId === '' || $invoiceUuid === '' || $paymentId === $invoiceUuid || isset($wanted[$invoiceUuid]) === false) {
                continue;
            }
            $ids[$paymentId] = true;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<int, array<string, mixed>>
     */
    private function paymentLinkRows(SyncLedger $ledger, array $connection): array
    {
        $company = (string) $connection['company_uuid'];
        $realm   = (string) $connection['realm_id'];
        $rows    = [];
        foreach ($ledger->links as $link) {
            if (is_array($link) === false) {
                continue;
            }
            if ((string) ($link['company_uuid'] ?? '') !== $company || (string) ($link['realm_id'] ?? '') !== $realm) {
                continue;
            }
            if ((string) ($link['local_type'] ?? '') !== 'payment') {
                continue;
            }
            $rows[] = $link;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function rekeyPaymentLink(SyncLedger $ledger, string $company, string $realm, string $fromUuid, array $attributes): void
    {
        foreach ($ledger->links as $index => $link) {
            if (is_array($link) === false || $this->samePaymentLink($link, $company, $realm, $fromUuid) === false) {
                continue;
            }
            $ledger->links[$index] = array_merge($link, $attributes);
            $ledger->rebuildIndex();

            return;
        }
    }

    private function forgetPaymentLink(SyncLedger $ledger, string $company, string $realm, string $localUuid): void
    {
        foreach ($ledger->links as $index => $link) {
            if (is_array($link) === false || $this->samePaymentLink($link, $company, $realm, $localUuid) === false) {
                continue;
            }
            unset($ledger->links[$index]);
            $ledger->links = array_values($ledger->links);
            $ledger->rebuildIndex();

            return;
        }
    }

    /**
     * @param array<string, mixed> $link
     */
    private function samePaymentLink(array $link, string $company, string $realm, string $localUuid): bool
    {
        return (string) ($link['company_uuid'] ?? '') === $company
            && (string) ($link['realm_id'] ?? '') === $realm
            && (string) ($link['local_type'] ?? '') === 'payment'
            && (string) ($link['local_uuid'] ?? '') === $localUuid;
    }

    /**
     * @param array<string, mixed> $invoice
     *
     * @return array<int, string>
     */
    private function linkedPaymentIds(array $invoice): array
    {
        $ids  = [];
        $txns = $invoice['LinkedTxn'] ?? [];
        if (is_array($txns) === false) {
            return [];
        }
        foreach ($txns as $txn) {
            if (is_array($txn) === false || (string) ($txn['TxnType'] ?? '') !== 'Payment') {
                continue;
            }
            $id = trim((string) ($txn['TxnId'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
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
     * @param array<string, mixed>             $invoice
     * @param array<int, array<string, mixed>> $payments
     */
    private function paymentsMatch(array $invoice, array $payments, string $invoiceId): bool
    {
        if (count($payments) === 1) {
            return $this->paymentMatches($invoice, $payments[0], $invoiceId);
        }
        $remoteCents = $this->summedOnInvoice($payments, $invoiceId);

        return $remoteCents !== null && $remoteCents === $this->paymentAmountCents($invoice);
    }

    /**
     * Compare the amount written onto the invoice. Invoice total is the stand-in
     * before a payment amount has been recorded. A paid invoice stored with
     * amount paid 0 uses that total, so the payment is not sent as zero.
     *
     * @param array<string, mixed> $invoice
     */
    private function paymentAmountCents(array $invoice): int
    {
        if (empty($invoice['payment_from_quickbooks']) === false) {
            return (int) ($invoice['amount_paid'] ?? 0);
        }
        $recorded = array_key_exists('amount_paid', $invoice) === true;
        $paid     = (int) ($invoice['amount_paid'] ?? 0);
        if ($recorded === true && $paid === 0 && (string) ($invoice['status'] ?? '') === 'paid') {
            return (int) ($invoice['total'] ?? 0);
        }
        if ($recorded === true) {
            return $paid;
        }

        return (int) ($invoice['total'] ?? 0);
    }

    /**
     * @param array<int, array<string, mixed>> $payments
     */
    private function applyPaymentsFromRemote(SyncLedger $ledger, string $uuid, array $payments, string $invoiceId): void
    {
        $latest = '';
        foreach ($payments as $remote) {
            $date = substr(trim((string) ($remote['TxnDate'] ?? '')), 0, 10);
            if ($date !== '' && ($latest === '' || $date > $latest)) {
                $latest = $date;
            }
        }
        if ($latest !== '') {
            $ledger->invoices[$uuid]['paid_at'] = $latest;
        }
        $amount = $this->summedOnInvoice($payments, $invoiceId);
        if ($amount !== null) {
            $ledger->invoices[$uuid]['amount_paid'] = $amount;
        }
        $ledger->invoices[$uuid]['payment_from_quickbooks'] = true;
    }

    /**
     * Integer minor units applied to this invoice, across every payment.
     * One unknown amount makes the sum unknown so a partial total is not stored.
     *
     * @param array<int, array<string, mixed>> $payments
     */
    private function summedOnInvoice(array $payments, string $invoiceId): ?int
    {
        if ($payments === []) {
            return null;
        }

        $sum = 0;
        foreach ($payments as $remote) {
            $amount = $this->amountOnInvoice($remote, $invoiceId);
            if ($amount === null) {
                return null;
            }
            $sum += $amount;
        }

        return $sum;
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
        if ($this->paymentCoversOtherInvoices($remote, $invoiceId) === true || array_key_exists('TotalAmt', $remote) === false) {
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
        if (is_array($lines) === false || $invoiceId === '') {
            return null;
        }

        $sum   = 0;
        $found = false;
        foreach ($lines as $line) {
            if (is_array($line) === false || $this->lineLinksInvoice($line, $invoiceId) === false) {
                continue;
            }
            $found = true;
            $sum += $this->majorUnits($line['Amount'] ?? 0);
        }

        return $found === true ? $sum : null;
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function paymentCoversOtherInvoices(array $remote, string $invoiceId): bool
    {
        $lines = $remote['Line'] ?? null;
        if (is_array($lines) === false) {
            return false;
        }

        foreach ($lines as $line) {
            if (is_array($line) === false) {
                continue;
            }
            $txns = $line['LinkedTxn'] ?? [];
            if (is_array($txns) === false) {
                continue;
            }
            foreach ($txns as $txn) {
                if (is_array($txn) === false) {
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
        if (is_array($txns) === false) {
            return false;
        }

        foreach ($txns as $txn) {
            if (is_array($txn) === false) {
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
        $invoice = $ledger->invoices[$uuid];
        $status  = (string) ($invoice['status'] ?? '');
        if (in_array($status, ['void', 'voided', 'cancelled', 'canceled', 'deleted'], true) === true || empty($invoice['deleted_at']) === false) {
            return;
        }

        $hasPaid = array_key_exists('amount_paid', $invoice);
        $paid    = (int) ($invoice['amount_paid'] ?? 0);
        $total   = (int) ($invoice['total'] ?? 0);
        if ($total > 0 && $paid >= $total) {
            $ledger->invoices[$uuid]['status'] = 'paid';

            return;
        }
        if ($paid > 0 && $paid < $total) {
            $ledger->invoices[$uuid]['status'] = 'partial';

            return;
        }
        if ($hasPaid === true && $paid === 0 && $total > 0) {
            $status = (string) ($invoice['status'] ?? '');
            if ($status === '' || in_array($status, ['paid', 'partial'], true) === true) {
                $ledger->invoices[$uuid]['status'] = 'sent';
            }
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
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $batch
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
            if ($exception instanceof QuickBooksException && $exception->isUnauthorized() === true) {
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
        if ($this->entityEnabled($settings, 'customer') === false) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, $record);
        }
        try {
            $this->prefetchCustomers($connection, $ledger, $rows, $settings);

            return $this->syncBufferedBlock($ledger, $connection, $rows, $settings, $batch, $now, $trigger, $record, 'customer');
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true) {
                $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                $batch['failed']++;

                return true;
            }
            if ($exception->isRateLimit() === true) {
                $this->failure($ledger, $connection, $rows[0], $exception, $settings, $now);
                if ($record === true) {
                    $this->recordRow($ledger, $connection, $rows[0], 'failed', $batch, $trigger, $this->exceptionError($exception));
                }

                return true;
            }

            throw $exception;
        } finally {
            $this->customerRemoteCache = null;
            if ($record === true) {
                $this->customerLookupFailed = [];
            }
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
     * @param array<string, mixed>             $batch
     */
    private function syncInvoiceBlock(SyncLedger $ledger, array &$connection, array $rows, array $settings, array &$batch, int $now, string $trigger): bool
    {
        if ($this->entityEnabled($settings, 'invoice') === false) {
            return $this->recordDisabledRows($ledger, $connection, $rows, $batch, $trigger, true);
        }
        $companyUuid = (string) $connection['company_uuid'];
        $needed      = [];
        foreach ($rows as $row) {
            $invoice = $ledger->invoices[(string) ($row['local_uuid'] ?? '')] ?? null;
            if (is_array($invoice) === false) {
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
        if ($this->entityPaused($settings, 'customer') === true) {
            $needed = [];
        } elseif (count($needed) === 1) {
            $only = array_key_first($needed);
            try {
                $customerOutcome = $this->syncCustomer($ledger, $connection, (string) $only, $settings);
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() === true) {
                    $this->tokenRejected($ledger, $companyUuid);
                    $batch['failed']++;

                    return true;
                }
                if ($exception->isRateLimit() === true) {
                    $this->failure($ledger, $connection, $needed[$only], $exception, $settings, $now);

                    return true;
                }
                $this->customerLookupFailed[(string) $only]  = $this->exceptionError($exception);
                $customerOutcome                             = 'failed';
            }
            if ($customerOutcome === 'failed' && isset($this->customerLookupFailed[(string) $only]) === false) {
                $this->lastError ??= 'Invoice customer could not be synced to QuickBooks.';
            }
        } elseif (count($needed) > 1) {
            if ($this->syncCustomerBlock($ledger, $connection, array_values($needed), $settings, $batch, $now, $trigger, false) === true) {
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
            if ($exception->isUnauthorized() === true) {
                $this->tokenRejected($ledger, $companyUuid);
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
            $this->customerLookupFailed = [];
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
                if ($exception instanceof QuickBooksException && ($exception->isUnauthorized() === true || $exception->isRateLimit() === true)) {
                    $this->writeBuffer = null;
                    if ($exception->isUnauthorized() === true) {
                        $this->tokenRejected($ledger, $companyUuid);
                        $batch['failed']++;
                    } else {
                        $this->failure($ledger, $connection, $row, $exception, $settings, $now);
                        if ($record === true) {
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
            if (is_array($result) === true) {
                if (empty($result['halt']) === false) {
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
                        } elseif ($record === true) {
                            $this->recordRow($ledger, $connection, $plan['row'], 'failed', $batch, $trigger, $error);
                        }
                    }
                    $halt = true;
                    break;
                }
                if (empty($result['ok']) === true) {
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
                } elseif (is_array($result['body'] ?? null) === true && ($result['body']['Id'] ?? '') !== '') {
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
                $customerUuid = is_array($invoice) === true ? (string) ($invoice['customer_uuid'] ?? '') : '';
                $customerLink = $customerUuid === '' ? null : $ledger->link($companyUuid, (string) $connection['realm_id'], 'customer', $customerUuid);
                if (is_array($invoice) === true && is_array($link) === true && is_array($customerLink) === true && $outcome !== 'voided') {
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
                        if ($exception instanceof QuickBooksException && $exception->isUnauthorized() === true) {
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
            } elseif ($record === true) {
                $this->lastError = ($plan['permanent'] === true && $outcome === 'failed') ? $plan['error'] : null;
                $this->recordRow($ledger, $connection, $plan['row'], $outcome, $batch, $trigger, $error);
            }
            if ($halt === true) {
                break;
            }
        }

        if ($kind !== 'invoice') {
            $this->writeBuffer = null;

            return $halt;
        }

        if ($unauthorized === true || empty($ledger->connections[$companyUuid]['needs_reauth']) === false) {
            $queued            = $this->queuedPaymentUuids();
            $this->writeBuffer = null;
            $keep              = [];
            foreach ($finished as $uuid => $item) {
                if (isset($queued[(string) $uuid]) === false) {
                    $keep[$uuid] = $item;
                }
            }
            $this->commitFinished($ledger, $connection, $keep, $batch, $trigger, $record);
            if ($unauthorized === true) {
                $this->tokenRejected($ledger, $companyUuid);
                $batch['failed']++;
            }

            return true;
        }

        if ($this->settlePaymentBuffer($ledger, $connection, $finished, $batch, $settings, $now, $trigger, $record) === true) {
            return true;
        }

        return $halt;
    }

    /**
     * Invoice writes are durable before payment reads begin. A later payment failure
     * therefore cannot leave an invoice that QuickBooks created or updated unlinked.
     *
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool}>                                           $planned
     * @param array<string, array{ok: bool, body: array<string, mixed>, rows: array<int, array<string, mixed>>, error: string|null, status: int, halt: bool}> $results
     * @param array<string, mixed>                                                                                                                            $settings
     */
    private function persistSuccessfulInvoiceWrites(SyncLedger $ledger, array $connection, array $planned, array $results, array $settings): void
    {
        foreach ($planned as $uuid => $plan) {
            $result = $results['invoice:' . $uuid] ?? null;
            if (is_array($result) === false || empty($result['ok']) === true || is_array($result['body'] ?? null) === false || ($result['body']['Id'] ?? '') === '') {
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
        if ($record === false) {
            return;
        }
        foreach ($finished as $item) {
            $this->lastError = ($item['permanent'] === true && $item['outcome'] === 'failed') ? $item['planError'] : null;
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
            if (str_starts_with($bId, 'payment:') === true) {
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
            if ($exception instanceof QuickBooksException && ($exception->isUnauthorized() === true || $exception->isRateLimit() === true)) {
                if ($exception->isUnauthorized() === true) {
                    $this->commitFinished($ledger, $connection, $this->finishedBeforePayment($finished, $queued), $batch, $trigger, $record);
                    $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                    $batch['failed']++;

                    return true;
                }
                $stop = $this->firstQueuedUuid($finished, $queued);
                if ($stop !== null && isset($finished[$stop]) === true) {
                    $finished[$stop]['outcome']   = $this->failure($ledger, $connection, $finished[$stop]['row'], $exception, $settings, $now);
                    $finished[$stop]['error']     = $this->exceptionError($exception);
                    $finished[$stop]['permanent'] = false;
                }
                $this->commitFinished($ledger, $connection, $this->finishedThrough($finished, $stop), $batch, $trigger, $record);

                return true;
            }
            $error = $this->exceptionError($exception);
            foreach (array_keys($queued) as $uuid) {
                if (isset($finished[$uuid]) === false) {
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
            if (is_array($result) === false) {
                continue;
            }
            if (empty($result['halt']) === false) {
                $status = (int) ($result['status'] ?? 0);
                if ($status === 401) {
                    $this->commitFinished($ledger, $connection, $this->finishedThrough($finished, (string) $uuid, false), $batch, $trigger, $record);
                    $this->tokenRejected($ledger, (string) $connection['company_uuid']);
                    $batch['failed']++;

                    return true;
                }
                $message                      = (string) ($result['error'] ?? '');
                $finished[$uuid]['outcome']   = $this->failure($ledger, $connection, $item['row'], new QuickBooksException($status, $message), $settings, $now);
                $finished[$uuid]['error']     = $this->batchItemAttemptError($message, $status);
                $finished[$uuid]['permanent'] = false;
                $this->commitFinished($ledger, $connection, $this->finishedThrough($finished, (string) $uuid, true), $batch, $trigger, $record);

                return true;
            }
            if (empty($result['ok']) === true) {
                $message                      = (string) ($result['error'] ?? '');
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
            if (is_array($result['body'] ?? null) === true && ($result['body']['Id'] ?? '') !== '') {
                $invoiceLink = $ledger->link((string) $connection['company_uuid'], (string) $connection['realm_id'], 'invoice', (string) $uuid);
                $invoiceId   = is_array($invoiceLink) === true ? trim((string) ($invoiceLink['qbo_id'] ?? '')) : '';
                $this->storePaymentLink($ledger, $connection, (string) $uuid, $invoiceId, $result['body']);
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
            if (isset($queued[(string) $uuid]) === true) {
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
                if ($includeStop === true) {
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
        /** @var array<int, array{bId: string, query: string, uuid: string, email: string, name: string}> $nameOnly */
        $nameOnly = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $customer = $ledger->customers[$uuid] ?? null;
            $link     = $ledger->link($companyUuid, $realm, 'customer', $uuid);
            if (is_array($link) === true && (string) ($link['qbo_id'] ?? '') !== '') {
                $queries[] = [
                    'bId'   => 'customer:' . $uuid,
                    'query' => "select * from Customer where Id = '" . QuickBooksClient::escapeQuery((string) $link['qbo_id']) . "'",
                    'uuid'  => $uuid,
                    'by'    => 'id',
                    'value' => (string) $link['qbo_id'],
                ];
                continue;
            }
            if (is_array($customer) === false) {
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
                continue;
            }
            $payload = $this->customers->toQuickBooks($this->customers->fromParty($customer));
            $name    = trim((string) ($payload['DisplayName'] ?? ''));
            if ($name !== '') {
                $nameOnly[] = [
                    'bId'   => 'customer-name:' . $uuid,
                    'query' => "select * from Customer where DisplayName = '" . QuickBooksClient::escapeQuery($name) . "' maxresults 1",
                    'uuid'  => $uuid,
                    'email' => '',
                    'name'  => $name,
                ];
            }
        }
        if ($queries === [] && $nameOnly === []) {
            return;
        }
        if ($queries !== [] && count($queries) === 1) {
            $this->prefetchOneCustomer($connection, $queries[0]);
        } elseif ($queries !== []) {
            $items = [];
            foreach ($queries as $query) {
                $items[] = ['bId' => $query['bId'], 'query' => $query['query']];
            }
            try {
                $results = $this->client->batch($connection, $items);
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
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
                if (is_array($result) === true && empty($result['halt']) === false) {
                    throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
                }
                if (is_array($result) === false || empty($result['ok']) === true) {
                    $message                           = is_array($result) === true ? (string) ($result['error'] ?? '') : '';
                    $status                            = is_array($result) === true ? (int) ($result['status'] ?? 0) : 0;
                    $this->customerLookupFailed[$uuid] = $this->batchItemAttemptError($message, $status);
                    continue;
                }
                $remote                           = $result['rows'][0] ?? null;
                $this->customerRemoteCache[$uuid] = is_array($remote) === true ? $remote : null;
            }
            foreach ($queries as $query) {
                if (isset($seen[$query['uuid']]) === false && isset($this->customerLookupFailed[$query['uuid']]) === false) {
                    $this->customerLookupFailed[$query['uuid']] = self::SYNC_FAILED_MESSAGE;
                }
            }
        }

        /** @var array<int, array{bId: string, query: string, uuid: string, email: string, name: string}> $nameQueries */
        $nameQueries = [];
        foreach ($queries as $query) {
            $uuid = $query['uuid'];
            if ($query['by'] !== 'email' || isset($this->customerLookupFailed[$uuid]) === true || array_key_exists($uuid, $this->customerRemoteCache) === false || $this->customerRemoteCache[$uuid] !== null) {
                continue;
            }
            $customer = $ledger->customers[$uuid] ?? null;
            if (is_array($customer) === false) {
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
        foreach ($nameOnly as $query) {
            if (isset($this->customerLookupFailed[$query['uuid']]) === false) {
                $nameQueries[] = $query;
            }
        }
        $this->prefetchCustomerNames($connection, $nameQueries, (string) ($settings['customer_reference'] ?? 'fleetbase') !== 'fleetbase');
    }

    /**
     * @param array<string, mixed>                                                       $connection
     * @param array{bId: string, query: string, uuid: string, by: string, value: string} $query
     */
    private function prefetchOneCustomer(array $connection, array $query): void
    {
        try {
            $remote = $query['by'] === 'id'
                ? $this->client->getCustomer($connection, $query['value'])
                : $this->client->findCustomerByEmail($connection, $query['value']);
        } catch (QuickBooksException $exception) {
            if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                throw $exception;
            }
            $this->customerLookupFailed[$query['uuid']] = $this->exceptionError($exception);

            return;
        }
        $this->customerRemoteCache[$query['uuid']] = is_array($remote) === true ? $remote : null;
    }

    /**
     * @param array<string, mixed>                                                                     $connection
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
                    if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                        throw $exception;
                    }
                    $this->customerLookupFailed[$query['uuid']] = $this->exceptionError($exception);
                    continue;
                }
                $this->rememberCustomerByName($query, is_array($remote) === true ? $remote : null, $allowDifferentEmail);
                continue;
            }
            try {
                $results = $this->client->batch($connection, array_map(
                    static fn (array $query): array => ['bId' => $query['bId'], 'query' => $query['query']],
                    $chunk
                ));
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                    throw $exception;
                }
                foreach ($chunk as $query) {
                    $this->customerLookupFailed[$query['uuid']] = $this->exceptionError($exception);
                }
                continue;
            }
            foreach ($chunk as $query) {
                $result = $results[$query['bId']] ?? null;
                if (is_array($result) === true && empty($result['halt']) === false) {
                    throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
                }
                if (is_array($result) === false || empty($result['ok']) === true) {
                    $message                                    = is_array($result) === true ? (string) ($result['error'] ?? '') : '';
                    $status                                     = is_array($result) === true ? (int) ($result['status'] ?? 0) : 0;
                    $this->customerLookupFailed[$query['uuid']] = $this->batchItemAttemptError($message, $status);
                    continue;
                }
                $remote = $result['rows'][0] ?? null;
                $this->rememberCustomerByName($query, is_array($remote) === true ? $remote : null, $allowDifferentEmail);
            }
        }
    }

    /**
     * A display-name miss is cached so the row is not queried again one name at a time.
     *
     * @param array{bId: string, query: string, uuid: string, email: string, name: string} $query
     * @param array<string, mixed>|null                                                    $remote
     */
    private function rememberCustomerByName(array $query, ?array $remote, bool $allowDifferentEmail): void
    {
        if (is_array($remote) === false) {
            $this->customerRemoteCache[$query['uuid']] = null;

            return;
        }
        $remoteEmail = strtolower(trim((string) ($remote['PrimaryEmailAddr']['Address'] ?? '')));
        $localEmail  = strtolower($query['email']);
        if ($allowDifferentEmail === true || $localEmail === '' || $remoteEmail === '' || $remoteEmail === $localEmail) {
            $this->customerRemoteCache[$query['uuid']] = $remote;

            return;
        }
        $this->customerRemoteCache[$query['uuid']] = null;
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
        if ($this->entityEnabled($settings, 'invoice') === false || $this->pushesRemote($this->direction($settings, 'invoice')) === false) {
            return;
        }
        if ($this->customTxnNumbers($connection) === false) {
            return;
        }

        $creates = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if ($uuid !== '' && $this->invoiceCreateNeedsDocNumber($ledger, $connection, $uuid) === true) {
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
        if (is_array($invoice) === false) {
            return false;
        }

        $status = (string) ($invoice['status'] ?? '');
        if (in_array($status, ['void', 'voided', 'cancelled', 'canceled', 'deleted'], true) === true || empty($invoice['deleted_at']) === false) {
            return false;
        }

        $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
        $companyUuid  = (string) $connection['company_uuid'];
        $realm        = (string) $connection['realm_id'];
        $customerLink = $customerUuid === '' ? null : $ledger->link($companyUuid, $realm, 'customer', $customerUuid);
        if ($customerLink === null) {
            return false;
        }

        // A linked invoice is not a first create, even when the QuickBooks read is empty.
        if ($ledger->link($companyUuid, $realm, 'invoice', $uuid) !== null) {
            return false;
        }

        $number   = trim((string) ($invoice['number'] ?? ''));
        $existing = ($number !== '' && $this->invoiceByDoc !== null) ? ($this->invoiceByDoc[$number] ?? null) : null;
        if (is_array($existing) === true && $this->invoiceCustomerMatches($existing, (string) ($customerLink['qbo_id'] ?? '')) === true) {
            return false;
        }

        return true;
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
            if (is_array($link) === true && (string) ($link['qbo_id'] ?? '') !== '') {
                $ids[] = (string) $link['qbo_id'];
            } elseif (is_array($invoice) === true && trim((string) ($invoice['customer_uuid'] ?? '')) !== '' && trim((string) ($invoice['number'] ?? '')) !== '') {
                $docs[] = trim((string) $invoice['number']);
            }
        }
        $ids                        = array_values(array_unique($ids));
        $docs                       = array_values(array_unique($docs));
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
        $this->paymentById        = [];
        $this->paymentReadFailed  = [];
        $companyUuid              = (string) $connection['company_uuid'];
        $realm                    = (string) $connection['realm_id'];
        $invoiceIds               = [];
        foreach ($rows as $row) {
            $uuid    = (string) ($row['local_uuid'] ?? '');
            $invoice = $ledger->invoices[$uuid] ?? null;
            if (is_array($invoice) === false) {
                continue;
            }
            $customerUuid = (string) ($invoice['customer_uuid'] ?? '');
            $customerLink = $customerUuid === '' ? null : $ledger->link($companyUuid, $realm, 'customer', $customerUuid);
            $link         = $ledger->link($companyUuid, $realm, 'invoice', $uuid);
            $customerId   = is_array($customerLink) === true ? trim((string) ($customerLink['qbo_id'] ?? '')) : '';
            $invoiceId    = is_array($link) === true ? trim((string) ($link['qbo_id'] ?? '')) : '';
            if ($customerId !== '' && $invoiceId !== '') {
                $invoiceIds[] = $invoiceId;
            }
        }
        $localUuids = [];
        foreach ($rows as $row) {
            $uuid = (string) ($row['local_uuid'] ?? '');
            if ($uuid !== '') {
                $localUuids[] = $uuid;
            }
        }
        $this->paymentPrefetchIds = array_fill_keys($invoiceIds, true);
        $this->loadPaymentsForInvoices(
            $connection,
            $invoiceIds,
            array_keys($this->rememberedPaymentIds($ledger, $connection, $localUuids))
        );
    }

    /**
     * A DocNumber match can link an invoice after the block payment prefetch.
     * The invoice is already in memory, so its LinkedTxn ids are loaded once.
     *
     * @param array<string, mixed>                                                                                  $connection
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
            if (is_array($link) === false) {
                continue;
            }
            $invoiceId = trim((string) ($link['qbo_id'] ?? ''));
            if ($invoiceId === '' || isset($known[$invoiceId]) === true || isset($this->blockPayments[$invoiceId]) === true) {
                continue;
            }
            $invoice      = $ledger->invoices[(string) $uuid] ?? null;
            $customerUuid = is_array($invoice) === true ? (string) ($invoice['customer_uuid'] ?? '') : '';
            $customerLink = $customerUuid === '' ? null : $ledger->link($company, $realm, 'customer', $customerUuid);
            $customerId   = is_array($customerLink) === true ? trim((string) ($customerLink['qbo_id'] ?? '')) : '';
            if ($customerId === '') {
                continue;
            }
            $missing[$invoiceId] = $customerId;
        }
        if ($missing === []) {
            return;
        }

        $invoiceIds = [];
        foreach (array_keys($missing) as $invoiceId) {
            if (is_array($this->invoiceById[$invoiceId] ?? null) === false) {
                continue;
            }
            $invoiceIds[]                                  = (string) $invoiceId;
            $this->paymentPrefetchIds[(string) $invoiceId] = true;
        }
        $this->loadPaymentsForInvoices($connection, $invoiceIds);
    }

    /**
     * Payment ids come from invoices already loaded. Each payment is read once.
     *
     * @param array<string, mixed> $connection
     * @param array<int, string>   $invoiceIds
     * @param array<int, string>   $alsoPaymentIds Payment ids remembered locally for these invoices
     */
    private function loadPaymentsForInvoices(array $connection, array $invoiceIds, array $alsoPaymentIds = []): void
    {
        $paymentIds = [];
        foreach ($alsoPaymentIds as $id) {
            $id = trim($id);
            if ($id !== '') {
                $paymentIds[$id] = true;
            }
        }
        foreach ($invoiceIds as $invoiceId) {
            $invoice = $this->invoiceById[$invoiceId] ?? null;
            if (is_array($invoice) === false) {
                continue;
            }
            foreach ($this->linkedPaymentIds($invoice) as $id) {
                $paymentIds[$id] = true;
            }
        }
        $this->loadPayments($connection, array_keys($paymentIds));
        $this->assignBlockPayments($invoiceIds);
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<int, string>   $ids
     */
    private function loadPayments(array $connection, array $ids): void
    {
        if ($this->paymentById === null) {
            $this->paymentById = [];
        }
        $missing = [];
        foreach ($ids as $id) {
            $id = trim($id);
            if ($id === '' || array_key_exists($id, $this->paymentById) === true || isset($this->paymentReadFailed[$id]) === true) {
                continue;
            }
            $missing[] = $id;
        }
        if ($missing === []) {
            return;
        }

        $loaded = $this->queryWhereIn(
            $connection,
            'Payment',
            'Id',
            $missing,
            fn (string $id): ?array => $this->client->getPayment($connection, $id)
        );
        $found = [];
        foreach ($loaded['rows'] as $payment) {
            $id = trim((string) ($payment['Id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $this->paymentById[$id] = $payment;
            $found[$id]             = true;
        }
        foreach ($loaded['failed'] as $id => $error) {
            $this->paymentReadFailed[(string) $id] = $error;
        }
        foreach ($missing as $id) {
            if (isset($found[$id]) === false && isset($this->paymentReadFailed[$id]) === false) {
                $this->paymentById[$id] = null;
            }
        }
    }

    /**
     * @param array<int, string> $invoiceIds
     */
    private function assignBlockPayments(array $invoiceIds): void
    {
        if ($this->blockPayments === null || $this->paymentById === null) {
            return;
        }
        foreach ($invoiceIds as $invoiceId) {
            $invoice = $this->invoiceById[$invoiceId] ?? null;
            if (is_array($invoice) === false) {
                continue;
            }
            $payments = [];
            foreach ($this->linkedPaymentIds($invoice) as $id) {
                $payment = $this->paymentById[$id] ?? null;
                if (is_array($payment) === true) {
                    $payments[] = $payment;
                }
            }
            if ($payments !== []) {
                $this->blockPayments[$invoiceId] = $payments;
            }
        }
    }

    /**
     * @param array<string, mixed> $connection
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
     * @param array<string, mixed>                                                                 $connection
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
                'halt'   => $exception->isUnauthorized() === true || $exception->isRateLimit() === true,
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
        if ($error === '' || str_starts_with($error, 'QuickBooks ') === true || $status === 400) {
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
     * @param array<string, mixed>                                                                                  $connection
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool}> $planned
     * @param array<string, mixed>                                                                                  $settings
     */
    private function prefetchLinkedPayments(array $connection, SyncLedger $ledger, array $planned, array $settings): void
    {
        if ($this->entityPaused($settings, 'payment') === true) {
            $this->paymentById = [];

            return;
        }
        if ($planned === []) {
            return;
        }

        $ids = [];
        foreach ($this->paymentLinkRows($ledger, $connection) as $link) {
            $id = trim((string) ($link['qbo_id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        $this->loadPayments($connection, $ids);
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
        if (count($acctNums) === 1 && $loaded !== [] && isset($this->accountByAcctNum[$acctNums[0]]) === false) {
            $this->accountByAcctNum[$acctNums[0]] = $loaded[0];
        }

        $names = [];
        foreach ($plans as $plan) {
            $linkedFound  = $plan['linked'] !== '' && isset($this->accountById[$plan['linked']]) === true;
            $linkedFailed = $plan['linked'] !== '' && isset($this->accountReadFailed[$plan['linked']]) === true;
            $acctFound    = $plan['linked'] === '' && $plan['acct'] !== '' && isset($this->accountByAcctNum[$plan['acct']]) === true;
            if ($linkedFound === true || $linkedFailed === true || $acctFound === true || $plan['name'] === '') {
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
        if (count($names) === 1 && $found !== [] && isset($this->accountsByName[$names[0]]) === false) {
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

    /**
     * A successful query that omits a value is a real miss. A failed request marks those values failed
     * and does not call them missing. One value uses $readOne. Larger sets use batch() in chunks of 30,
     * and each query is paged until a short page.
     *
     * @param array<string, mixed>       $connection
     * @param array<int, string>         $values
     * @param callable(string): (?array) $readOne
     *
     * @return array{rows: array<int, array<string, mixed>>, failed: array<string, string>}
     */
    private function queryWhereIn(array $connection, string $entity, string $field, array $values, callable $readOne): array
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $values
        ), static fn (string $value): bool => $value !== '')));
        if ($values === [] || in_array($field, ['Id', 'DocNumber', 'AcctNum', 'Name'], true) === false) {
            return ['rows' => [], 'failed' => []];
        }
        if (in_array($entity, ['Customer', 'Invoice', 'Payment', 'Account'], true) === false) {
            return ['rows' => [], 'failed' => []];
        }
        if (count($values) === 1) {
            try {
                $remote = $readOne($values[0]);
            } catch (QuickBooksException $exception) {
                if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                    throw $exception;
                }

                return ['rows' => [], 'failed' => [$values[0] => $this->exceptionError($exception)]];
            }

            return ['rows' => is_array($remote) === true ? [$remote] : [], 'failed' => []];
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
                    if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                        throw $exception;
                    }
                    $error = $this->exceptionError($exception);
                    foreach ($chunk as $value) {
                        if (isset($matched[$value]) === false) {
                            $failed[$value] = $error;
                        }
                    }
                    break;
                }

                $bId    = 'read-' . $chunkIndex . '-' . $start;
                $result = $results[$bId] ?? null;
                if (is_array($result) === true && empty($result['halt']) === false) {
                    throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
                }
                if (is_array($result) === false || empty($result['ok']) === true) {
                    $message = is_array($result) === true ? (string) ($result['error'] ?? '') : '';
                    $status  = is_array($result) === true ? (int) ($result['status'] ?? 0) : 0;
                    $error   = $this->batchItemAttemptError($message, $status);
                    foreach ($chunk as $value) {
                        if (isset($matched[$value]) === false) {
                            $failed[$value] = $error;
                        }
                    }
                    break;
                }

                $pageRows = is_array($result['rows'] ?? null) === true ? $result['rows'] : [];
                $count    = 0;
                $added    = 0;
                foreach ($pageRows as $remote) {
                    if (is_array($remote) === false) {
                        continue;
                    }
                    $count++;
                    $id = trim((string) ($remote['Id'] ?? ''));
                    if ($id !== '') {
                        if (isset($seenIds[$id]) === true) {
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
     * Several ids are queried in chunks of BATCH_LIMIT. Each query is paged with
     * startposition and maxresults so a row past QuickBooks' default page is not
     * treated as missing. A successful query that omits an id is a miss.
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

            return is_array($remote) === true ? [$id => $remote] : [];
        }

        $mapped = [];
        foreach (array_chunk($ids, QuickBooksClient::BATCH_LIMIT) as $chunkIndex => $chunk) {
            if (count($chunk) === 1) {
                foreach ($this->readRemoteSet($connection, $entity, $chunk) as $id => $remote) {
                    $mapped[$id] = $remote;
                }
                continue;
            }

            $start = 1;
            $pages = 0;
            while ($pages < 100) {
                $pages++;
                $query = 'select * from ' . $entity . ' where Id IN (' . QuickBooksClient::quotedList($chunk) . ')'
                    . ' startposition ' . $start . ' maxresults ' . self::QUERY_PAGE_SIZE;
                $bId = 'read-' . $chunkIndex . '-' . $start;
                try {
                    $results = $this->client->batch($connection, [[
                        'bId'   => $bId,
                        'query' => $query,
                    ]]);
                } catch (QuickBooksException $exception) {
                    if ($exception->isUnauthorized() === true || $exception->isRateLimit() === true) {
                        throw $exception;
                    }

                    throw new QuickBooksException($exception->status, $this->exceptionError($exception), $exception->retryAfter, $exception->faultCode);
                }

                $result = $results[$bId] ?? null;
                if (is_array($result) === true && empty($result['halt']) === false) {
                    throw new QuickBooksException((int) ($result['status'] ?? 0), (string) ($result['error'] ?? ''));
                }
                if (is_array($result) === false || empty($result['ok']) === true) {
                    $message = is_array($result) === true ? (string) ($result['error'] ?? '') : '';
                    $status  = is_array($result) === true ? (int) ($result['status'] ?? 0) : 0;
                    $error   = $this->batchItemAttemptError($message, $status);
                    throw new QuickBooksException($status, $error !== '' ? $error : self::SYNC_FAILED_MESSAGE);
                }

                $pageRows = is_array($result['rows'] ?? null) === true ? $result['rows'] : [];
                $count    = 0;
                foreach ($pageRows as $remote) {
                    if (is_array($remote) === false) {
                        continue;
                    }
                    $count++;
                    $id = (string) ($remote['Id'] ?? '');
                    if ($id !== '') {
                        $mapped[$id] = $remote;
                    }
                }
                if ($count < self::QUERY_PAGE_SIZE) {
                    break;
                }
                $start += self::QUERY_PAGE_SIZE;
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
        if (array_key_exists($key, $settings) === false) {
            return true;
        }
        $value = $settings[$key];
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value) === true) {
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
        return $this->entityEnabled($settings, $kind) === false || $this->direction($settings, $kind) === 'off';
    }

    private function direction(array $settings, string $kind): string
    {
        $value = $settings[$kind . '_direction'] ?? 'both';
        if (is_string($value) === false || in_array($value, ['both', 'outbound', 'inbound', 'off'], true) === false) {
            return 'both';
        }

        return $value;
    }

    /**
     * QuickBooks is the field source when it wins conflicts, or when nothing is pushed back.
     * Primary Fleetbase with a push keeps the Fleetbase row.
     *
     * @param array<string, mixed> $settings
     */
    private function quickbooksSupplies(array $settings, string $kind): bool
    {
        $direction = $this->direction($settings, $kind);
        if ($this->copiesRemote($direction) === false) {
            return false;
        }
        if ((string) ($settings[$kind . '_conflict'] ?? 'fleetbase') === 'quickbooks') {
            return true;
        }

        return $this->pushesRemote($direction) === false;
    }

    /**
     * A push sends a Fleetbase clear when Fleetbase wins, and also when QuickBooks
     * is primary but this direction does not copy QuickBooks back onto the clear.
     */
    private function sendsClears(string $conflict, bool $push, bool $copy): bool
    {
        return $push === true && ($conflict === 'fleetbase' || $copy === false);
    }

    /**
     * A foreign-currency invoice is not posted in the home currency. Without multi-currency
     * in QuickBooks the row fails with the currency message; the caller returns 'failed'.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $connection
     */
    private function rejectInvoiceCurrency(array $payload, SyncLedger $ledger, array $connection): bool
    {
        $ref      = $payload['CurrencyRef'] ?? null;
        $currency = is_array($ref) === true ? (string) ($ref['value'] ?? '') : '';
        $error    = $this->invoiceCurrencyError($ledger, $connection, $currency);
        if ($error === null) {
            return false;
        }
        $this->lastError = $error;

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $remote
     */
    private function keepBillAddrId(array &$payload, array $remote): void
    {
        if (isset($payload['BillAddr']) === false || is_array($payload['BillAddr']) === false) {
            return;
        }
        $bill = $remote['BillAddr'] ?? null;
        if (is_array($bill) === false) {
            return;
        }
        $id = trim((string) ($bill['Id'] ?? ''));
        if ($id !== '') {
            $payload['BillAddr']['Id'] = $id;
        }
    }

    /**
     * An empty QuickBooks description must not replace a Fleetbase description.
     *
     * @param array<string, mixed> $remote
     *
     * @return array<string, mixed>
     */
    private function withoutEmptyDescription(array $remote): array
    {
        if (trim((string) ($remote['Description'] ?? '')) === '') {
            unset($remote['Description']);
        }

        return $remote;
    }

    /**
     * Customer and currency come back only when QuickBooks is the source.
     * A currency that is not the home currency is left alone; the other fields still copy.
     * An empty value does not clear Fleetbase.
     *
     * @param array<string, mixed> $remote
     */
    private function copyInvoiceCustomerAndCurrency(SyncLedger $ledger, string $uuid, array $remote): bool
    {
        $changed        = false;
        $company        = (string) ($ledger->invoices[$uuid]['company_uuid'] ?? '');
        $connection     = $ledger->connections[$company] ?? null;
        $realm          = is_array($connection) === true ? (string) ($connection['realm_id'] ?? '') : '';
        $remoteCustomer = $this->remoteInvoiceCustomerId($remote);
        if ($remoteCustomer !== '' && $realm !== '') {
            $link          = $ledger->linkForRemote($realm, 'Customer', $remoteCustomer);
            $localCustomer = is_array($link) === true ? trim((string) ($link['local_uuid'] ?? '')) : '';
            if ($localCustomer !== '' && $localCustomer !== (string) ($ledger->invoices[$uuid]['customer_uuid'] ?? '')) {
                $ledger->invoices[$uuid]['customer_uuid'] = $localCustomer;
                $changed                                  = true;
            }
        }
        $currency = $this->remoteInvoiceCurrency($remote);
        if ($currency !== '' && is_array($connection) === true && $this->invoiceCurrencyError($ledger, $connection, $currency) === null) {
            $current = strtoupper(trim((string) ($ledger->invoices[$uuid]['currency'] ?? '')));
            if ($current !== $currency) {
                $ledger->invoices[$uuid]['currency'] = $currency;
                $changed                             = true;
            }
        }

        return $changed;
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
        if ($this->lastError !== null && $tracksPending === true) {
            $ledger->updatePending((string) $connection['company_uuid'], (string) $row['local_type'], (string) $row['local_uuid'], [
                'status' => 'failed',
            ]);
        }
        $this->lastError = null;
        if ($outcome !== 'failed' && $outcome !== 'skipped' && $tracksPending === true) {
            $ledger->updatePending((string) $connection['company_uuid'], (string) $row['local_type'], (string) $row['local_uuid'], ['status' => 'done']);
        }
        if ($outcome === 'skipped' && $this->closeSkipped === true && $tracksPending === true) {
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
        if ($record === false) {
            return false;
        }
        foreach ($rows as $row) {
            if (is_array($row) === false) {
                continue;
            }
            $this->closeSkipped = true;
            $this->recordRow($ledger, $connection, $row, 'skipped', $batch, $trigger, null);
        }

        return false;
    }
}

/**
 * Sends SyncEngine's QuickBooks calls through SyncEngine::runAuthorized.
 * The inner client stays the real or fake client; this brackets HTTP and repeats a call
 * once with a refreshed access token when QuickBooks answers 401.
 */
class QuickBooksHttpGate extends QuickBooksClient
{
    public function __construct(private QuickBooksClient $inner, private SyncEngine $engine)
    {
    }

    public function homeCurrency(array $connection): ?string
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?string => $this->inner->homeCurrency($connection));
    }

    public function customTxnNumbers(array $connection): bool
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): bool => $this->inner->customTxnNumbers($connection));
    }

    public function multiCurrencyEnabled(array $connection): bool
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): bool => $this->inner->multiCurrencyEnabled($connection));
    }

    public function createCustomer(array $connection, array $payload): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->createCustomer($connection, $payload));
    }

    public function updateCustomer(array $connection, string $id, string $syncToken, array $payload): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->updateCustomer($connection, $id, $syncToken, $payload));
    }

    public function getCustomer(array $connection, string $id): ?array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?array => $this->inner->getCustomer($connection, $id));
    }

    public function createInvoice(array $connection, array $payload): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->createInvoice($connection, $payload));
    }

    public function updateInvoice(array $connection, string $id, string $syncToken, array $payload): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->updateInvoice($connection, $id, $syncToken, $payload));
    }

    public function nextInvoiceDocNumber(array $connection): ?string
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?string => $this->inner->nextInvoiceDocNumber($connection));
    }

    public function nextInvoiceDocNumbers(array $connection, int $count): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->nextInvoiceDocNumbers($connection, $count));
    }

    public function findInvoiceByDocNumber(array $connection, string $docNumber): ?array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?array => $this->inner->findInvoiceByDocNumber($connection, $docNumber));
    }

    public function getInvoice(array $connection, string $id): ?array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?array => $this->inner->getInvoice($connection, $id));
    }

    public function voidInvoice(array $connection, string $id, string $syncToken): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->voidInvoice($connection, $id, $syncToken));
    }

    public function createPayment(array $connection, array $payload): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->createPayment($connection, $payload));
    }

    public function updatePayment(array $connection, string $id, string $syncToken, array $payload): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->updatePayment($connection, $id, $syncToken, $payload));
    }

    public function getPayment(array $connection, string $id): ?array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?array => $this->inner->getPayment($connection, $id));
    }

    public function findPaymentForInvoice(array $connection, string $customerId, string $invoiceId): ?array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?array => $this->inner->findPaymentForInvoice($connection, $customerId, $invoiceId));
    }

    public function findPaymentsForCustomers(array $connection, array $customerIds, bool $asBatch = false): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->findPaymentsForCustomers($connection, $customerIds, $asBatch));
    }

    public function findCustomerByDisplayName(array $connection, string $name): ?array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?array => $this->inner->findCustomerByDisplayName($connection, $name));
    }

    public function findCustomerByEmail(array $connection, string $email): ?array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?array => $this->inner->findCustomerByEmail($connection, $email));
    }

    public function createAccount(array $connection, array $payload): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->createAccount($connection, $payload));
    }

    public function updateAccount(array $connection, string $id, string $syncToken, array $payload): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->updateAccount($connection, $id, $syncToken, $payload));
    }

    public function getAccount(array $connection, string $id): ?array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?array => $this->inner->getAccount($connection, $id));
    }

    public function findAccountByAcctNum(array $connection, string $acctNum): ?array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): ?array => $this->inner->findAccountByAcctNum($connection, $acctNum));
    }

    public function findAccountsByName(array $connection, string $name): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->findAccountsByName($connection, $name));
    }

    public function batch(array $connection, array $items): array
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): array => $this->inner->batch($connection, $items));
    }

    public function ensureServiceItem(array $connection): string
    {
        return $this->engine->runAuthorized($connection, fn (array $connection): string => $this->inner->ensureServiceItem($connection));
    }
}
