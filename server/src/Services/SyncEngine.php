<?php

namespace Fleetbase\Quickbooks\Services;

use Fleetbase\Quickbooks\Services\Concerns\AppliesRemotePayments;
use Fleetbase\Quickbooks\Services\Concerns\BatchesCustomers;
use Fleetbase\Quickbooks\Services\Concerns\BatchesPayments;
use Fleetbase\Quickbooks\Services\Concerns\BatchesWallets;
use Fleetbase\Quickbooks\Services\Concerns\DeactivatesRemote;
use Fleetbase\Quickbooks\Services\Concerns\StoresPaymentLinks;
use Fleetbase\Quickbooks\Services\Concerns\SyncsCustomers;
use Fleetbase\Quickbooks\Services\Concerns\SyncsInvoices;
use Fleetbase\Quickbooks\Services\Concerns\SyncsPayments;
use Fleetbase\Quickbooks\Services\Concerns\SyncsProducts;
use Fleetbase\Quickbooks\Services\Concerns\SyncsTax;
use Fleetbase\Quickbooks\Services\Concerns\SyncsWallets;
use Fleetbase\Quickbooks\Support\Amounts;
use Fleetbase\Quickbooks\Support\BackoffPolicy;
use Fleetbase\Quickbooks\Support\ConnectionGate;
use Fleetbase\Quickbooks\Support\CustomerMapper;
use Fleetbase\Quickbooks\Support\FleetbaseTax;
use Fleetbase\Quickbooks\Support\InvoiceMapper;
use Fleetbase\Quickbooks\Support\InvoiceTax;
use Fleetbase\Quickbooks\Support\ProductMapper;
use Fleetbase\Quickbooks\Support\QuickBooksChanges;
use Fleetbase\Quickbooks\Support\QuickBooksException;
use Fleetbase\Quickbooks\Support\Translation;
use Fleetbase\Quickbooks\Support\WalletMapper;

/**
 * @phpstan-type RemovedLine array{id: string, description: string, amount: int}
 * @phpstan-type StoredLines array{
 *     removed?: array<int, RemovedLine>,
 *     mapped?: bool,
 *     codes?: array<string, array<int, string>>,
 *     fleetbase_tax?: bool
 * }
 * @phpstan-type BufferedWrite array{
 *     bId: string,
 *     operation: string,
 *     entity: string,
 *     payload: array<string, mixed>,
 *     key?: string
 * }
 * @phpstan-type BatchResult array{
 *     ok: bool,
 *     body: array<string, mixed>,
 *     rows: array<int, array<string, mixed>>,
 *     error: string|null,
 *     status: int,
 *     halt: bool
 * }
 *
 * @phpstan-import-type Settings from InvoiceTax as InvoiceTaxSettings
 * @phpstan-import-type Rates from FleetbaseTax as FleetbaseTaxRates
 */
class SyncEngine
{
    use AppliesRemotePayments;
    use BatchesCustomers;
    use BatchesPayments;
    use BatchesWallets;
    use DeactivatesRemote;
    use StoresPaymentLinks;
    use SyncsCustomers;
    use SyncsInvoices;
    use SyncsPayments;
    use SyncsProducts;
    use SyncsTax;
    use SyncsWallets;

    private const RATE_LIMITED_MESSAGE = 'quickbooks.messages.rate-limited';

    public const TOKEN_REJECTED_MESSAGE = ConnectionTokens::REAUTH_MESSAGE;

    /**
     * Webhook entity for "read this invoice's amount paid again", sent after a QuickBooks payment
     * delete. It reads the QuickBooks invoice under the payment sync settings, never the invoice ones.
     */
    public const INVOICE_PAYMENTS = 'InvoicePayments';

    private const SYNC_FAILED_MESSAGE = 'quickbooks.messages.sync-failed';

    private const ITEM_REJECTED_MESSAGE = 'quickbooks.messages.item-rejected';

    /** Stored QuickBooks lines of an invoice whose lines were just copied from QuickBooks. */
    private const NO_STORED_LINES = ['removed' => [], 'uuids' => [], 'mapped' => false];

    /** Pending reasons that QuickBooks or a scheduled check queued, not a Fleetbase change. */
    private const REMOTE_REASONS = ['webhook', 'reconcile', 'customer-linked'];

    /**
     * QuickBooks returns at most this many rows unless the query asks for more.
     * A full page is not the end of an id lookup.
     */
    private const QUERY_PAGE_SIZE = 100;

    /**
     * QuickBooks rejects an invoice whose DocNumber is longer than this.
     */
    private const DOC_NUMBER_MAX = 21;

    /**
     * Intuit fault code for "Duplicate Name Exists": a customer, vendor or employee already has the name.
     */
    private const DUPLICATE_NAME_FAULT = '6240';

    /**
     * The invoice link column holding the QuickBooks customer an invoice is kept on.
     */
    private const KEPT_CUSTOMER = 'kept_customer';

    /**
     * The invoice link column marking an invoice first sent while Fleetbase worked out tax
     * (FLEETBASE_TAX): its tax stays Fleetbase's.
     */
    private const TAX_SOURCE = 'tax_source';

    private const FLEETBASE_TAX = 'fleetbase';

    /**
     * The invoice link column holding the QuickBooks SyncToken and the tax (minor units) of a write
     * whose Fleetbase tax QuickBooks did not keep, as "token|tax".
     */
    private const REFUSED_TAX = 'refused_tax';

    /**
     * The invoice link column holding the Fleetbase customer an invoice kept meanwhile.
     */
    private const KEPT_FROM = 'kept_from';

    private ?string $lastError = null;

    /**
     * Rows of the current run, by company, whose failure QuickBooks would give again for the
     * same data: a 4xx validation fault, or a check Fleetbase made before sending. Sending
     * them again soon does not help, so the customer catalog moves past them.
     *
     * @var array<string, array<string, true>>
     */
    private array $permanentFailures = [];

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
     * Account names this run asked QuickBooks to create, lower case.
     *
     * @var array<string, true>
     */
    private array $accountNamesCreated = [];

    /**
     * Sync settings of the run in progress.
     *
     * @var array<string, mixed>
     */
    private array $runSettings = [];

    /**
     * Service item id already resolved for this invoice block, keyed by company uuid.
     * An empty string means ensureServiceItem ran and QuickBooks has no item.
     * Null means this invoice is alone.
     *
     * @var array<string, string>|null
     */
    private ?array $serviceItemByCompany = null;

    /**
     * Service item lookups QuickBooks refused in the current invoice block, by company uuid.
     * Each later invoice in the block fails with the same error instead of asking again.
     *
     * @var array<string, QuickBooksException>|null
     */
    private ?array $serviceItemErrors = null;

    /**
     * Whether the current invoice block has checked the stored service item after an invalid
     * reference fault. Null outside a block, where every such fault checks.
     */
    private ?bool $serviceItemChecked = null;

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
     * @var array<int, BufferedWrite>|null
     */
    private ?array $writeBuffer = null;

    /**
     * The open pending row that drives each record's write in this run, as row uuid by
     * "type:local uuid". A retry of a lost create comes from the same row, so its requestid
     * stays the same; a record created again later (deleted in QuickBooks) comes from a new
     * row and gets a new requestid, instead of Intuit's answer for the deleted record.
     *
     * @var array<string, string>
     */
    private array $writeRows = [];

    /**
     * Line ids to record once a buffered invoice write succeeds, keyed by Fleetbase uuid.
     *
     * Edited marks an update QuickBooks as Primary sent because only Fleetbase changed the invoice.
     *
     * @var array<string, array{
     *     invoice: string,
     *     payload: array<string, mixed>,
     *     cleared: array<int, string>,
     *     edited?: bool,
     *     owns?: bool
     * }>
     */
    private array $bufferedLineMaps = [];

    /**
     * Buffered invoice writes whose Fleetbase tax QuickBooks did not keep, with the error, by
     * Fleetbase invoice uuid. The batch result then fails them (refusedOwnTax).
     *
     * @var array<string, string>
     */
    private array $refusedTaxRows = [];

    /**
     * Prefetched QuickBooks customers for a block, keyed by Fleetbase uuid.
     * Null is a successful email and display-name miss; a missing key was not looked up.
     *
     * @var array<string, array<string, mixed>|null>|null
     */
    private ?array $customerRemoteCache = null;

    /**
     * Fleetbase uuids whose block lookup failed. Failure is not "no such customer". Each keeps
     * the status QuickBooks gave, so it is thrown again as a refusal or as an outage.
     *
     * @var array<string, QuickBooksException>
     */
    private array $customerLookupFailed = [];

    /**
     * QuickBooks sales tax settings by company for an invoice block. A failed read is kept, so the
     * other invoices of the block fail with it instead of reading again. Null outside a block.
     *
     * @var array<string, InvoiceTaxSettings|QuickBooksException>|null
     */
    private ?array $salesTaxByCompany = null;

    /**
     * QuickBooks customers read for the tax of an invoice block, by QuickBooks id. Null is no such
     * customer; an exception is a failed read, thrown again with its status. Null outside a block.
     *
     * @var array<string, array<string, mixed>|QuickBooksException|null>|null
     */
    private ?array $taxCustomers = null;

    /**
     * The currency of the invoice a customer is created for, by Fleetbase customer uuid. A
     * QuickBooks customer's currency is fixed when it is created, so a customer first billed in
     * a foreign currency is created in it.
     *
     * @var array<string, string>
     */
    private array $customerCreateCurrency = [];

    /**
     * QuickBooks items read for the tax of an invoice block, by id, by company. Null is no such item.
     * Null outside a block.
     *
     * @var array<string, array<string, array<string, mixed>|null>>|null
     */
    private ?array $taxItems = null;

    /**
     * Activity notes for rows that did not fail, by "type:uuid".
     *
     * @var array<string, string>
     */
    private array $rowNotes = [];

    /**
     * Rows whose Fleetbase difference QuickBooks as Primary replaced because QuickBooks had
     * also changed the record, by "type:uuid". Read once, by recordRow.
     *
     * @var array<string, true>
     */
    private array $primaryCopies = [];

    /**
     * Records a first link left with Fleetbase values QuickBooks lacks (followUpFirstLink), by
     * "type:uuid". Their rows stay pending, so the next run sends those values.
     *
     * @var array<string, true>
     */
    private array $followUps = [];

    /**
     * The rows this run left pending for a first-link follow-up (closesRow), with the outcome
     * and Activity row recorded for them. process() syncs them once more before the run ends.
     *
     * @var array<int, array{row: array<string, mixed>, outcome: string, attempt: int|null}>
     */
    private array $followUpRows = [];

    /**
     * The rows recorded since the follow-up pass began (recordRow), with their outcome and
     * Activity row. Only a record its follow-up reported loses its first pass.
     *
     * @var array<string, array{outcome: string, attempt: int|null}>
     */
    private array $recordedRows = [];

    /**
     * QuickBooks invoice ids whose block read failed. Absence from invoiceById is a miss only when the id is not here.
     * Each is thrown again with the status QuickBooks gave.
     *
     * @var array<string, QuickBooksException>
     */
    private array $invoiceReadFailed = [];

    /**
     * Invoice numbers whose block read failed. A failed read is not "no invoice with this number".
     * Each is thrown again with the status QuickBooks gave.
     *
     * @var array<string, QuickBooksException>
     */
    private array $invoiceDocReadFailed = [];

    /**
     * QuickBooks account ids whose block read failed.
     * Each is thrown again with the status QuickBooks gave.
     *
     * @var array<string, QuickBooksException>
     */
    private array $accountReadFailed = [];

    /**
     * Account field lookups that failed, keyed by "AcctNum:value" or "Name:value".
     * Each is thrown again with the status QuickBooks gave.
     *
     * @var array<string, QuickBooksException>
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
     * QuickBooks payment ids whose block read failed, thrown again with the status QuickBooks gave.
     *
     * @var array<string, QuickBooksException>
     */
    private array $paymentReadFailed = [];

    private bool $closeSkipped = false;

    private bool $customersPrepared = false;

    /**
     * The QuickBooks product of each product name handled in this run (ProductItems::prepare),
     * by name key, so a name is read and written once per run.
     *
     * @var array<string, array{
     *     id: string|null, item: array<string, mixed>|null, outcome: string, error: string|null,
     *     transient: bool, note: string|null
     * }>
     */
    private array $productResults = [];

    /**
     * QuickBooks products read or written in this run, by id, for the line tax codes.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $itemRows = [];

    /**
     * Product links of a run by company and realm (managedItems), cleared when products are made.
     *
     * @var array<string, array<string, string>>
     */
    private array $managedItems = [];

    /**
     * Live Storefront product names of a run by company: name key => true (lineKey).
     *
     * @var array<string, array<string, true>>
     */
    private array $liveProductKeys = [];

    /**
     * Products invoice lines of a run name in their meta (ProductMapper::lineProduct): name key =>
     * name. These are kept like a fee's product (ProductItems::prepare).
     *
     * @var array<string, string>
     */
    private array $lineProducts = [];

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
     * Companies whose token refresh failed for a temporary reason in this run, with the message.
     * Later 401s in the run fail at once instead of calling Intuit again for every row.
     *
     * @var array<string, string>
     */
    private array $refreshFailed = [];

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
     * Whether this run stopped because the token could not be refreshed for now. The caller
     * then waits for the next scheduled run instead of queueing another one straight away.
     */
    public function refreshFailed(string $companyUuid): bool
    {
        return isset($this->refreshFailed[$companyUuid]);
    }

    /**
     * How many rows of the last run for this company failed in a way that sending the same
     * data again would not fix. Every other failed row may pass on a retry.
     */
    public function permanentFailures(string $companyUuid): int
    {
        return count($this->permanentFailures[$companyUuid] ?? []);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function notePermanence(string $companyUuid, array $row, bool $permanent): void
    {
        $key = (string) ($row['local_type'] ?? '') . '|' . (string) ($row['local_uuid'] ?? '');
        if ($permanent === true) {
            $this->permanentFailures[$companyUuid][$key] = true;

            return;
        }
        unset($this->permanentFailures[$companyUuid][$key]);
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
        if (isset($this->refreshFailed[$companyUuid]) === true) {
            throw new QuickBooksException(503, $this->refreshFailed[$companyUuid]);
        }
        $sent  = (string) ($connection['access_token'] ?? '');
        $fresh = $this->tokens?->refreshNow($connection);
        if ($fresh === null || empty($fresh['needs_reauth']) === false) {
            return null;
        }
        if (empty($fresh['refresh_error']) === false) {
            $this->refreshFailed[$companyUuid] = (string) $fresh['refresh_error'];

            throw new QuickBooksException(503, $this->refreshFailed[$companyUuid]);
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
        // A note left by a run that stopped before recording its row is not shown on this run.
        $this->rowNotes = [];
        $trigger        = $force === true ? 'now' : 'scheduled';
        $connection     = $ledger->connection($companyUuid);
        if (is_array($connection) === false || ConnectionGate::hasRealm($connection) === false) {
            return $this->emptyBatch($trigger, 'skipped');
        }
        if (empty($connection['needs_reauth']) === false) {
            if ($force === true) {
                return $this->recordSkipped($ledger, $companyUuid, $trigger, ConnectionTokens::REAUTH_MESSAGE);
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
        // A note left by a run that stopped before recording its row is not shown on this run.
        $this->rowNotes = [];
        $connection     = $ledger->connection($companyUuid);
        if (is_array($connection) === false || ConnectionGate::hasRealm($connection) === false) {
            return $this->emptyBatch('manual', 'skipped');
        }
        if (empty($connection['needs_reauth']) === false) {
            return $this->recordSkipped($ledger, $companyUuid, 'manual', ConnectionTokens::REAUTH_MESSAGE);
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
        // A note left by a run that stopped before recording its row is not shown on this run.
        $this->rowNotes = [];
        $connection     = $ledger->connection($companyUuid);
        if (is_array($connection) === false || ConnectionGate::hasRealm($connection) === false || empty($connection['needs_reauth']) === false) {
            return;
        }

        $ledger->rebuildIndex();
        $before = ['customer' => $ledger->customers, 'invoice' => $ledger->invoices, 'wallet' => $ledger->wallets];
        try {
            foreach ($this->wantedRemoteLinks($ledger, $connection, $entities, $settings) as $name => $linksById) {
                $this->applyWantedRemote($ledger, $connection, $companyUuid, $settings, (string) $name, $linksById);
            }
        } finally {
            $this->recordInboundChanges($ledger, $companyUuid, $before, $now);
        }
    }

    /**
     * Activity lists what a QuickBooks change altered in Fleetbase as one inbound run. A
     * change that altered nothing, such as the echo of a Fleetbase write, adds no row.
     *
     * @param array<string, array<string, array<string, mixed>>> $before rows by local type, then uuid
     */
    private function recordInboundChanges(SyncLedger $ledger, string $companyUuid, array $before, int $now): void
    {
        $after    = ['customer' => $ledger->customers, 'invoice' => $ledger->invoices, 'wallet' => $ledger->wallets];
        $attempts = [];
        foreach ($after as $type => $rows) {
            foreach ($rows as $uuid => $row) {
                $previous = $before[$type][$uuid] ?? null;
                $attempt  = $this->inboundAttempt($companyUuid, $type, (string) $uuid, $previous, $row);
                if ($attempt !== null) {
                    $attempts[] = $attempt;
                }
            }
        }
        if ($attempts === []) {
            return;
        }
        $skipped           = count(array_keys(array_column($attempts, 'outcome'), 'skipped', true));
        $ledger->batches[] = array_merge($this->emptyBatch('webhook', 'finished'), [
            'company_uuid' => $companyUuid,
            'direction'    => 'inbound',
            'updated'      => count($attempts) - $skipped,
            'skipped'      => $skipped,
            'started_at'   => $now,
            'finished_at'  => $now,
        ]);
        array_push($ledger->attempts, ...$attempts);
    }

    /**
     * A row the QuickBooks change altered, or one it left alone with a note saying why.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     *
     * @return array<string, mixed>|null
     */
    private function inboundAttempt(
        string $companyUuid,
        string $type,
        string $uuid,
        ?array $before,
        array $after,
    ): ?array {
        $note = $this->rowNotes[$type . ':' . $uuid] ?? null;
        unset($this->rowNotes[$type . ':' . $uuid]);
        $changed = self::withoutRemoteMarkers($before) !== self::withoutRemoteMarkers($after);
        if ($changed === false && $note === null) {
            return null;
        }

        return [
            'company_uuid' => $companyUuid,
            'local_type'   => $type,
            'local_uuid'   => $uuid,
            'outcome'      => $changed === true ? 'updated' : 'skipped',
            'error'        => $note,
        ];
    }

    /**
     * A row without the *_from_quickbooks markers, which only say how a change is saved and are set on every apply.
     *
     * @return array<string, mixed>|null
     */
    private static function withoutRemoteMarkers(mixed $row): ?array
    {
        if (is_array($row) === false) {
            return null;
        }

        return array_filter(
            $row,
            static fn (int|string $key): bool => str_ends_with((string) $key, '_from_quickbooks') === false,
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $entities
     * @param array<string, mixed>             $settings
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function wantedRemoteLinks(SyncLedger $ledger, array $connection, array $entities, array $settings): array
    {
        $wanted = [];
        foreach ($entities as $entity) {
            $link = $this->wantedRemoteLink($ledger, $connection, $settings, $entity);
            if ($link === null) {
                continue;
            }
            $wanted[$link['name']][$link['remote_id']] = $link['link'];
        }

        return $wanted;
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     *
     * @return array{name: string, remote_id: string, link: array<string, mixed>}|null
     */
    private function wantedRemoteLink(SyncLedger $ledger, array $connection, array $settings, mixed $entity): ?array
    {
        if (is_array($entity) === false) {
            return null;
        }
        $name     = (string) ($entity['entity'] ?? '');
        $remoteId = (string) ($entity['id'] ?? '');
        if ($remoteId === '' || strtolower((string) ($entity['operation'] ?? '')) === 'delete') {
            return null;
        }
        $kind = match ($name) {
            'Customer'             => 'customer',
            'Invoice'              => 'invoice',
            'Payment'              => 'payment',
            self::INVOICE_PAYMENTS => 'payment',
            'Account'              => 'wallet',
            default                => '',
        };
        if ($kind === '' || $this->entityEnabled($settings, $kind) === false || $this->quickbooksSupplies($settings, $kind) === false) {
            return null;
        }
        $link = $ledger->linkForRemote((string) $connection['realm_id'], self::readEntity($name), $remoteId);
        if ($link === null) {
            return null;
        }

        return ['name' => $name, 'remote_id' => $remoteId, 'link' => $link];
    }

    /**
     * @param array<string, mixed>                $connection
     * @param array<string, mixed>                $settings
     * @param array<string, array<string, mixed>> $linksById
     */
    private function applyWantedRemote(SyncLedger $ledger, array $connection, string $companyUuid, array $settings, string $name, array $linksById): void
    {
        $remoteIds = array_map('strval', array_keys($linksById));
        $remotes   = $this->readRemoteIds($connection, self::readEntity($name), $remoteIds);
        foreach ($linksById as $remoteId => $link) {
            $remote = $remotes[$remoteId] ?? null;
            if (is_array($remote) === false) {
                continue;
            }
            $this->applyOneRemote($ledger, $connection, $companyUuid, $settings, $name, $link, $remote, $remotes);
        }
    }

    /**
     * @param array<string, mixed>                $connection
     * @param array<string, mixed>                $settings
     * @param array<string, mixed>                $link
     * @param array<string, mixed>                $remote
     * @param array<string, array<string, mixed>> $remotes
     */
    private function applyOneRemote(SyncLedger $ledger, array $connection, string $companyUuid, array $settings, string $name, array $link, array $remote, array $remotes): void
    {
        if ($this->webhookEcho($settings, $name, $link, $remote) === true) {
            return;
        }
        $applied = $this->applyRemoteRecord(
            $ledger,
            $connection,
            $companyUuid,
            $settings,
            $name,
            $link,
            $remote,
            $remotes
        );
        if ($applied === true) {
            $this->rememberAppliedToken($ledger, $connection, $settings, $name, $link, $remote);
        }
    }

    /**
     * Under QuickBooks as Primary in two-way sync, a record whose SyncToken is the one the link
     * stored has not changed in QuickBooks since both sides were last equal: it is the echo of a
     * Fleetbase write, and copying it would revert a Fleetbase edit made since. An invoice
     * payments refresh is not compared: its link holds the invoice's token.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $link
     * @param array<string, mixed> $remote
     */
    private function webhookEcho(array $settings, string $name, array $link, array $remote): bool
    {
        $kinds = ['Customer' => 'customer', 'Invoice' => 'invoice', 'Payment' => 'payment', 'Account' => 'wallet'];
        $kind  = $kinds[$name] ?? '';

        return $kind !== ''
            && $this->tokenDecides($settings, $kind) === true
            && QuickBooksChanges::since($link, $remote) === false;
    }

    /**
     * Apply one QuickBooks record. True when it was applied as a whole, so the link may store
     * its SyncToken. An invoice payments refresh copies only the amount paid, so it is not.
     *
     * @param array<string, mixed>                $connection
     * @param array<string, mixed>                $settings
     * @param array<string, mixed>                $link
     * @param array<string, mixed>                $remote
     * @param array<string, array<string, mixed>> $remotes
     */
    private function applyRemoteRecord(
        SyncLedger $ledger,
        array $connection,
        string $companyUuid,
        array $settings,
        string $name,
        array $link,
        array $remote,
        array $remotes,
    ): bool {
        $localUuid = (string) ($link['local_uuid'] ?? '');
        if ($name === 'Customer' && isset($ledger->customers[$localUuid]) === true) {
            $clears = $this->copiesClears($settings, 'customer', $link, $remote);
            $this->applyCustomerFromRemote($ledger, $localUuid, $remote, $clears);

            return true;
        }
        if ($name === self::INVOICE_PAYMENTS && isset($ledger->invoices[$localUuid]) === true) {
            $this->applyAmountPaidFromBalance($ledger, $localUuid, $remote);

            return false;
        }
        if ($name === 'Invoice' && isset($ledger->invoices[$localUuid]) === true) {
            $this->applyRemoteInvoice($ledger, $connection, $settings, $link, $remote);

            return true;
        }
        if ($name === 'Payment') {
            return $this->applyPaymentRemote($ledger, $connection, $companyUuid, $link, $remote, $remotes);
        }
        if ($name === 'Account' && isset($ledger->wallets[$localUuid]) === true) {
            $reference = (string) ($settings['wallet_reference'] ?? 'fleetbase');
            $clears    = $this->copiesClears($settings, 'wallet', $link, $remote);
            $account   = $this->withoutEmptyDescription($remote, $clears);
            $this->applyWalletFromRemote($ledger, $localUuid, $account, $reference);

            return true;
        }

        return false;
    }

    /**
     * A QuickBooks change applied from a webhook usually leaves both sides equal, so the link
     * stores its SyncToken: a later Fleetbase edit is then not taken for a QuickBooks change.
     * A copy that left a difference (an emptied QuickBooks field, a customer Fleetbase has not
     * linked) keeps the older token, so Fleetbase never sends that difference back.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $link
     * @param array<string, mixed> $remote
     */
    private function rememberAppliedToken(
        SyncLedger $ledger,
        array $connection,
        array $settings,
        string $name,
        array $link,
        array $remote,
    ): void {
        $token = trim((string) ($remote['SyncToken'] ?? ''));
        if ($token === '' || (string) ($link['qbo_id'] ?? '') !== (string) ($remote['Id'] ?? '')) {
            return;
        }
        $uuid   = (string) ($link['local_uuid'] ?? '');
        $inSync = match ($name) {
            'Customer' => $this->customerInSync($ledger, $uuid, $remote),
            'Invoice'  => $this->invoiceInSync($ledger, $connection, $uuid, $remote, $settings, self::NO_STORED_LINES),
            'Account'  => $this->walletInSync($ledger, $uuid, $remote, $settings),
            default    => true,
        };
        // The apply may have stored more on the link (a payment's invoice tokens): keep it.
        $current = $ledger->link(
            (string) ($link['company_uuid'] ?? ''),
            (string) ($link['realm_id'] ?? ''),
            (string) ($link['local_type'] ?? ''),
            $uuid
        ) ?? $link;
        $kind   = ['Customer' => 'customer', 'Invoice' => 'invoice', 'Account' => 'wallet'][$name] ?? 'payment';
        $stored = ['sync_token' => $this->tokenAfterSync($link, $remote, $inSync, $kind, $settings)];
        if ($name === 'Invoice') {
            $stored += $this->keptInvoiceCustomer($ledger, $connection, $settings, $uuid, $remote);
        }
        $ledger->putLink(array_merge($current, $stored));
        // A provisional token waits for a follow-up that sends what Fleetbase holds and QuickBooks lacks.
        // Fleetbase's tax wins, so a QuickBooks tax the copy left different is sent back too.
        $ownTax = $name === 'Invoice'
            && $this->comparesOwnTax($connection, $this->ownsTax($settings, $current)) === true
            && $this->ownTaxDiffers($ledger->invoices[$uuid] ?? [], $remote) === true;
        if (QuickBooksChanges::provisionalLink($stored) === true || $ownTax === true) {
            $ledger->upsertPending((string) $connection['company_uuid'], $kind, $uuid, 'webhook');
        }
    }

    /**
     * Amount paid is what QuickBooks no longer shows as owed. Only the payment fields change.
     *
     * @param array<string, mixed> $remote
     */
    private function applyAmountPaidFromBalance(SyncLedger $ledger, string $uuid, array $remote): bool
    {
        $invoice = $ledger->invoices[$uuid];
        $status  = (string) ($invoice['status'] ?? '');
        $total   = $remote['TotalAmt'] ?? null;
        $balance = $remote['Balance'] ?? null;
        $voided  = in_array($status, ['void', 'voided', 'cancelled', 'canceled', 'deleted'], true) === true;
        if ($voided === true || empty($invoice['deleted_at']) === false) {
            return false;
        }
        if ($total === null || $total === '' || $balance === null || $balance === '') {
            return false;
        }
        $before   = $invoice;
        $currency = (string) ($invoice['currency'] ?? '');
        $paid     = $this->majorUnits($total, $currency) - $this->majorUnits($balance, $currency);

        $ledger->invoices[$uuid]['amount_paid']             = max(0, $paid);
        $ledger->invoices[$uuid]['payment_from_quickbooks'] = true;
        $this->applyPaymentStatus($ledger, $uuid, $remote);

        return $this->paymentRecordChanged($before, $ledger->invoices[$uuid]);
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
        // A note left by a run that stopped before recording its row is not shown on this run.
        $this->rowNotes = [];
        $connection     = $ledger->connection($companyUuid);
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
        $pending = $this->dueInvoicePending($ledger, $companyUuid, $now, $limit);
        if ($pending !== []) {
            return $pending;
        }

        $rows = $this->reconcileInvoiceRows($ledger, $companyUuid);
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
        $this->customTxnNumbers  = [];
        $this->runSettings       = $settings;
        $this->productResults    = [];
        $this->itemRows          = [];
        $this->managedItems      = [];
        $this->liveProductKeys   = [];
        $this->lineProducts      = [];
        $this->followUps         = [];
        $this->followUpRows      = [];
        $this->recordedRows      = [];
        $batch                   = $this->newOutboundBatch($connection, $trigger, $now);
        $companyUuid             = (string) $connection['company_uuid'];
        // A worker can reuse this engine, so a refresh that failed in an earlier run is tried again.
        unset($this->refreshFailed[$companyUuid]);
        $this->permanentFailures[$companyUuid] = [];
        $ledger->rebuildIndex();
        $connection = $this->processRows($ledger, $connection, $rows, $settings, $batch, $now, $trigger, $companyUuid);
        $connection = $this->runFollowUps($ledger, $connection, $settings, $batch, $now, $trigger);

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
     * First links that left Fleetbase values QuickBooks lacks send them before the run ends, so
     * a QuickBooks change has little time to land first. One more pass only: a row still
     * waiting stays pending for the next run.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $batch
     *
     * @return array<string, mixed>
     */
    private function runFollowUps(
        SyncLedger $ledger,
        array $connection,
        array $settings,
        array &$batch,
        int $now,
        string $trigger,
    ): array {
        $companyUuid = (string) $connection['company_uuid'];
        $followUps   = $this->takeFollowUpRows();
        $halted      = $this->isRateLimited($connection, $now) === true
            || empty($ledger->connections[$companyUuid]['needs_reauth']) === false
            || isset($this->refreshFailed[$companyUuid]) === true;
        if ($followUps !== [] && $halted === false) {
            $this->recordedRows = [];
            $connection         = $this->processRows(
                $ledger,
                $connection,
                array_column($followUps, 'row'),
                $settings,
                $batch,
                $now,
                $trigger,
                $companyUuid
            );
            $this->withoutFirstPass($ledger, $followUps, $batch);
        }
        $this->followUpRows = [];
        $this->recordedRows = [];

        return $connection;
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    private function newOutboundBatch(array $connection, string $trigger, int $now): array
    {
        return [
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
    }

    /**
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     *
     * @return array<string, mixed>
     */
    private function processRows(SyncLedger $ledger, array $connection, array $rows, array $settings, array &$batch, int $now, string $trigger, string $companyUuid): array
    {
        $counts                    = $this->rowTypeCounts($rows);
        $handled                   = [];
        $this->accountNamesCreated = [];
        $this->writeRows           = self::writeRows($rows);
        $this->prepareLineProducts($ledger, $connection, $rows, $settings);
        foreach ($rows as $row) {
            if ($this->isRateLimited($connection, $now) === true) {
                break;
            }
            $type = (string) ($row['local_type'] ?? 'invoice');
            $key  = $type . '|' . (string) ($row['local_uuid'] ?? '');
            if (isset($handled[$key]) === true) {
                continue;
            }
            if ($type === 'product' || $type === 'item') {
                $block      = array_merge(
                    $this->blockOfType($rows, 'product', $handled),
                    $this->blockOfType($rows, 'item', $handled)
                );
                $halt       = $this->syncProductRows($ledger, $connection, $block, $settings, $batch, $now, $trigger);
                $connection = $ledger->connection($companyUuid) ?? $connection;
                if ($halt === true || isset($this->refreshFailed[$companyUuid]) === true) {
                    break;
                }
                continue;
            }
            if (($counts[$type] ?? 0) > 1) {
                $halt       = $this->runBlock($ledger, $connection, $this->blockOfType($rows, $type, $handled), $type, $settings, $batch, $now, $trigger, $companyUuid);
                $connection = $ledger->connection($companyUuid) ?? $connection;
                if ($halt === true || isset($this->refreshFailed[$companyUuid]) === true) {
                    break;
                }
                continue;
            }

            $handled[$key] = true;
            $this->runOne($ledger, $connection, $row, $settings, $batch, $now, $trigger);
            $connection = $ledger->connection($companyUuid) ?? $connection;
            if (empty($ledger->connections[$companyUuid]['needs_reauth']) === false || isset($this->refreshFailed[$companyUuid]) === true) {
                break;
            }
        }

        return $connection;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    private static function writeRows(array $rows): array
    {
        $writeRows = [];
        foreach ($rows as $row) {
            $rowUuid = (string) ($row['uuid'] ?? '');
            $record  = (string) ($row['local_type'] ?? 'invoice') . ':' . (string) ($row['local_uuid'] ?? '');
            if ($rowUuid !== '') {
                $writeRows[$record] = $rowUuid;
            }
        }

        return $writeRows;
    }

    /**
     * The requestid key of one record's write: the record, and the pending row driving it.
     */
    private function writeKey(string $record): string
    {
        $rowUuid = $this->writeRows[$record] ?? '';

        return $rowUuid === '' ? $record : $record . ':' . $rowUuid;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function rowTypeCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $type          = (string) ($row['local_type'] ?? 'invoice');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Every row of one type, in order, marked as handled so the loop does not send it again.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, true>              $handled
     *
     * @return array<int, array<string, mixed>>
     */
    private function blockOfType(array $rows, string $type, array &$handled): array
    {
        $block = [];
        foreach ($rows as $candidate) {
            if ((string) ($candidate['local_type'] ?? 'invoice') !== $type) {
                continue;
            }
            $block[]                                                                                                     = $candidate;
            $handled[(string) ($candidate['local_type'] ?? 'invoice') . '|' . (string) ($candidate['local_uuid'] ?? '')] = true;
        }

        return $block;
    }

    /**
     * Send one block of rows of the same type. True when the run must stop.
     *
     * @param array<string, mixed>             $connection
     * @param array<int, array<string, mixed>> $block
     * @param array<string, mixed>             $settings
     * @param array<string, mixed>             $batch
     */
    private function runBlock(SyncLedger $ledger, array &$connection, array $block, string $type, array $settings, array &$batch, int $now, string $trigger, string $companyUuid): bool
    {
        try {
            return match ($type) {
                'customer' => $this->syncCustomerBlock($ledger, $connection, $block, $settings, $batch, $now, $trigger, true),
                'wallet'   => $this->syncWalletBlock($ledger, $connection, $block, $settings, $batch, $now, $trigger),
                default    => $this->syncInvoiceBlock($ledger, $connection, $block, $settings, $batch, $now, $trigger),
            };
        } catch (QuickBooksException $exception) {
            // The token could not be refreshed for now. Keep what this run already did and
            // leave the rest pending; the next run refreshes again.
            if (isset($this->refreshFailed[$companyUuid]) === false) {
                throw $exception;
            }

            return true;
        }
    }

    /**
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     */
    private function failure(SyncLedger $ledger, array $connection, array $row, \Throwable $exception, array $settings, int $now): string
    {
        $companyUuid = (string) $connection['company_uuid'];
        $permanent   = $exception instanceof QuickBooksException
            && $exception->isTransient() === false
            && $exception->isUnauthorized() === false;
        $this->notePermanence($companyUuid, $row, $permanent);
        if ($exception instanceof QuickBooksException && $exception->isRateLimit() === true) {
            $previous                                                  = isset($connection['last_rate_limit_wait']) === true ? (int) $connection['last_rate_limit_wait'] : null;
            $wait                                                      = $this->backoff->rateLimitWait($exception->retryAfter, $previous);
            $ledger->connections[$companyUuid]['rate_limited_until']   = $now + $wait;
            $ledger->connections[$companyUuid]['last_rate_limit_wait'] = $wait;

            return 'failed';
        }

        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $base     = (int) $settings['default_backoff_seconds'];
        $outage   = $exception instanceof QuickBooksException && $exception->isTransient() === true;
        $wait     = $outage === true
            ? $this->backoff->outageWait($base, $attempts, self::outageCapSeconds())
            : $this->backoff->defaultWait($base, $attempts);
        $status = $attempts > (int) $settings['retry_limit'] ? 'failed' : 'pending';
        if ($outage === true) {
            $status = $this->outageStatus($row, $now);
        }
        $ledger->updatePending($companyUuid, (string) $row['local_type'], (string) $row['local_uuid'], [
            'attempts'        => $attempts,
            'status'          => $status,
            'next_attempt_at' => $now + $wait,
        ]);

        return 'failed';
    }

    /**
     * QuickBooks could not be reached, so the row keeps retrying whatever its attempts,
     * until it has waited longer than the outage window. A row without a readable
     * created_at (memory mode) has no window yet.
     *
     * @param array<string, mixed> $row
     */
    private function outageStatus(array $row, int $now): string
    {
        $created = strtotime((string) ($row['created_at'] ?? ''));
        $hours   = max(1, (int) config('quickbooks.sync.outage_retry_hours', 72));

        return $created !== false && $now - $created > $hours * 3600 ? 'failed' : 'pending';
    }

    private static function outageCapSeconds(): int
    {
        return (int) config('quickbooks.sync.outage_backoff_cap_seconds', 3600);
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
    private function currencyError(SyncLedger $ledger, array $connection, string $currency, string $key): ?string
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return null;
        }

        $home = $this->homeCurrency($ledger, $connection);
        if ($home === '' || $home === $currency) {
            return null;
        }

        return Translation::encode($key, ['currency' => $currency, 'home' => $home]);
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
     * The QuickBooks entity read for a webhook entity name. An invoice payments refresh reads the invoice.
     */
    private static function readEntity(string $name): string
    {
        return $name === self::INVOICE_PAYMENTS ? 'Invoice' : $name;
    }

    /**
     * A QuickBooks amount in Fleetbase units of the invoice currency (whole units when zero-decimal).
     */
    private function majorUnits(mixed $amount, string $currency = ''): int
    {
        if (is_float($amount) === true) {
            throw new \InvalidArgumentException('Money must not be a float.');
        }
        if (is_int($amount) === false && is_string($amount) === false) {
            return 0;
        }

        return Amounts::toMinorUnits($amount, $currency);
    }

    /**
     * The QuickBooks id linked to a Fleetbase record, or '' when it has no link.
     */
    private function linkedQuickBooksId(SyncLedger $ledger, string $company, string $realm, string $type, string $uuid): string
    {
        $link = $uuid === '' ? null : $ledger->link($company, $realm, $type, $uuid);

        return is_array($link) === true ? trim((string) ($link['qbo_id'] ?? '')) : '';
    }

    /**
     * The invoice mapper for this Fleetbase invoice's currency.
     *
     * @param array<string, mixed> $invoice
     *
     * @SuppressWarnings("PHPMD.UnusedPrivateMethod") Called only from the Syncs* traits.
     */
    private function invoiceMapper(array $invoice): InvoiceMapper
    {
        return $this->invoices->forCurrency((string) ($invoice['currency'] ?? ''));
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
     * Store the SyncToken of the QuickBooks record this sync left both sides equal with, so
     * the next difference shows which system changed. Returns $outcome. See tokenAfterSync()
     * for a sync that left a difference.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>|null $baseline   the link as it was before this sync
     */
    private function synced(
        SyncLedger $ledger,
        array $connection,
        string $type,
        string $uuid,
        array $remote,
        string $outcome,
        bool $inSync,
        ?array $baseline,
    ): string {
        $entity = ['customer' => 'Customer', 'invoice' => 'Invoice', 'wallet' => 'Account'][$type] ?? '';
        if ($entity !== '' && trim((string) ($remote['Id'] ?? '')) !== '') {
            $token = $this->tokenAfterSync($baseline, $remote, $inSync, $type, $this->runSettings);
            if ($type === 'invoice') {
                $this->putInvoiceLink($ledger, $connection, $uuid, $remote, $this->runSettings, $token);
            } else {
                $fresh = $this->linkFrom($connection, $type, $uuid, $entity, $remote);
                $ledger->putLink(['sync_token' => $token] + $fresh);
            }
            $this->followUpFirstLink($ledger, $connection, $type, $uuid, $token);
        }

        return $outcome;
    }

    /**
     * Links a Fleetbase record to the QuickBooks record it matched, without a token: no sync
     * left both sides equal yet. synced() stores one, so a sync that fails before it leaves no
     * baseline, and the next one merges as a first link.
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $remote
     */
    private function putMatchedLink(
        SyncLedger $ledger,
        array $connection,
        string $type,
        string $uuid,
        string $entity,
        array $remote,
    ): void {
        $ledger->putLink(['sync_token' => ''] + $this->linkFrom($connection, $type, $uuid, $entity, $remote));
    }

    /**
     * A first link that left Fleetbase values QuickBooks lacks stores a provisional token
     * (tokenAfterSync). The record is synced again in a follow-up, which sends them: at the end
     * of this run (process), or on the next one. Its row stays pending, or a row is queued.
     *
     * @param array<string, mixed> $connection
     */
    private function followUpFirstLink(
        SyncLedger $ledger,
        array $connection,
        string $type,
        string $uuid,
        string $token,
    ): void {
        if (QuickBooksChanges::provisionalLink(['sync_token' => $token]) === false) {
            return;
        }
        $this->followUps[$type . ':' . $uuid] = true;
        $ledger->upsertPending((string) $connection['company_uuid'], $type, $uuid, 'saved');
    }

    /**
     * Whether a row that did not fail is done. A first link waiting for its follow-up stays
     * pending (followUpFirstLink), and process() syncs it once more.
     *
     * @param array<string, mixed> $row
     * @param int|null             $attempt the index of the Activity row recorded for it
     */
    private function closesRow(array $row, string $outcome, bool $tracksPending, ?int $attempt): bool
    {
        $followUp = $this->takeFollowUp($row);
        if ($followUp === true && $outcome !== 'failed') {
            $this->followUpRows[] = ['row' => $row, 'outcome' => $outcome, 'attempt' => $attempt];
        }

        return $outcome !== 'failed' && $outcome !== 'skipped' && $tracksPending === true && $followUp === false;
    }

    /**
     * The rows this run left for a first-link follow-up, once.
     *
     * @return array<int, array{row: array<string, mixed>, outcome: string, attempt: int|null}>
     */
    private function takeFollowUpRows(): array
    {
        $rows               = $this->followUpRows;
        $this->followUpRows = [];

        return $rows;
    }

    /**
     * Takes the first pass of each record its follow-up reported out of the batch counts and
     * Activity, so it is reported once. A record the follow-up did not reach (the run halted),
     * or that failed there, keeps its first pass. A first-pass note the follow-up row lacks
     * moves onto it; otherwise, or when the follow-up has no Activity row, the first pass stays.
     *
     * @param array<int, array{row: array<string, mixed>, outcome: string, attempt: int|null}> $followUps
     * @param array<string, mixed>                                                             $batch
     */
    private function withoutFirstPass(SyncLedger $ledger, array $followUps, array &$batch): void
    {
        foreach ($followUps as $followUp) {
            $second = $this->recordedRows[$this->rowKey($followUp['row'])] ?? null;
            if ($second === null || $second['outcome'] === 'failed') {
                continue;
            }
            $first = $followUp['attempt'];
            if ($first !== null && $this->movesFirstNote($ledger, $first, $second['attempt']) === false) {
                continue;
            }
            $batch[$followUp['outcome']] = max(0, (int) ($batch[$followUp['outcome']] ?? 0) - 1);
            if ($first !== null) {
                unset($ledger->attempts[$first]);
            }
        }
        $ledger->attempts = array_values($ledger->attempts);
    }

    /**
     * Whether the first-pass Activity row can go: the follow-up has a row of its own, and the
     * first has no note or its note moved onto the follow-up row, which had none.
     */
    private function movesFirstNote(SyncLedger $ledger, int $first, ?int $second): bool
    {
        if ($second === null || isset($ledger->attempts[$second]) === false) {
            return false;
        }
        $note = $ledger->attempts[$first]['error'] ?? null;
        if ($note === null) {
            return true;
        }
        if ($ledger->attempts[$second]['error'] !== null) {
            return false;
        }
        $ledger->attempts[$second]['error'] = $note;

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowKey(array $row): string
    {
        return (string) ($row['local_type'] ?? '') . ':' . (string) ($row['local_uuid'] ?? '');
    }

    /**
     * Whether this row's record waits for the follow-up of a first link. Read once.
     *
     * @param array<string, mixed> $row
     */
    private function takeFollowUp(array $row): bool
    {
        $key      = $this->rowKey($row);
        $followUp = isset($this->followUps[$key]);
        unset($this->followUps[$key]);

        return $followUp;
    }

    /**
     * synced(), updated when this sync changed the Fleetbase record and aligned otherwise.
     *
     * @param array<string, mixed>      $connection
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>|null $baseline
     */
    private function syncedIf(
        SyncLedger $ledger,
        array $connection,
        string $type,
        string $uuid,
        array $remote,
        bool $changed,
        bool $inSync,
        ?array $baseline,
    ): string {
        $outcome = $changed === true ? 'updated' : 'aligned';

        return $this->synced($ledger, $connection, $type, $uuid, $remote, $outcome, $inSync, $baseline);
    }

    /**
     * The token a link stores after a sync. Both sides equal: the QuickBooks SyncToken. A
     * difference left on a link that had a token (QuickBooks emptied a field or moved an invoice
     * to a customer Fleetbase has not linked, and the copy could not take it) keeps the older
     * token, so the next sync still sees a QuickBooks change and never sends the difference
     * back. Under QuickBooks as Primary in two-way sync, a link without a baseline that leaves
     * a difference takes the new token as a provisional one: a follow-up sends what Fleetbase
     * holds and QuickBooks lacks (followUpFirstLink), and a QuickBooks change copied meanwhile
     * empties nothing in Fleetbase.
     *
     * @param array<string, mixed>|null $baseline
     * @param array<string, mixed>      $remote
     * @param array<string, mixed>      $settings
     */
    private function tokenAfterSync(
        ?array $baseline,
        array $remote,
        bool $inSync,
        string $kind,
        array $settings,
    ): string {
        $token = (string) ($remote['SyncToken'] ?? '0');
        if ($inSync === true) {
            return $token;
        }
        if ($baseline !== null && QuickBooksChanges::known($baseline, $remote) === true) {
            return trim((string) $baseline['sync_token']);
        }
        // A follow-up that found QuickBooks unchanged sent what it could: the difference stays.
        $followedUp = QuickBooksChanges::provisionalLink($baseline) === true
            && QuickBooksChanges::since($baseline, $remote) === false;
        if (trim($token) === '' || $followedUp === true || $this->tokenDecides($settings, $kind) === false) {
            return $token;
        }

        return QuickBooksChanges::provisional($token);
    }

    /**
     * QuickBooks as Primary copies a difference only when QuickBooks changed the record since
     * the last sync. When it did not, and the type syncs both ways, the Fleetbase change is sent.
     *
     * @param array<string, mixed>      $settings
     * @param array<string, mixed>|null $baseline the link as it was before this sync
     * @param array<string, mixed>      $remote
     *
     * @SuppressWarnings("PHPMD.UnusedPrivateMethod") Called only from the Syncs* traits.
     */
    private function fleetbaseLeads(array $settings, string $kind, ?array $baseline, array $remote): bool
    {
        return $this->tokenDecides($settings, $kind) === true && QuickBooksChanges::since($baseline, $remote) === false;
    }

    /**
     * QuickBooks as Primary in two-way sync copies what QuickBooks emptied when QuickBooks changed
     * the record since a known baseline: it wins the whole record, so both sides are equal again
     * and the link stores the new token. Without a baseline (a first link) nothing is emptied.
     *
     * @param array<string, mixed>      $settings
     * @param array<string, mixed>|null $baseline the link as it was before this sync
     * @param array<string, mixed>      $remote
     */
    private function copiesClears(array $settings, string $kind, ?array $baseline, array $remote): bool
    {
        return $this->tokenDecides($settings, $kind) === true
            && QuickBooksChanges::known($baseline, $remote) === true
            && QuickBooksChanges::since($baseline, $remote) === true;
    }

    /**
     * The stored SyncToken decides a difference only for QuickBooks Primary in two-way sync.
     * Fleetbase Primary always sends, and From QuickBooks always copies.
     *
     * @param array<string, mixed> $settings
     */
    private function tokenDecides(array $settings, string $kind): bool
    {
        $direction = $this->direction($settings, $kind);

        return (string) ($settings[$kind . '_conflict'] ?? 'fleetbase') === 'quickbooks'
            && $this->copiesRemote($direction) === true
            && $this->pushesRemote($direction) === true;
    }

    /**
     * A two-way copy under QuickBooks Primary replaced a Fleetbase difference because QuickBooks
     * had also changed the record. Activity says so when Fleetbase queued the row.
     *
     * @SuppressWarnings("PHPMD.UnusedPrivateMethod") Called only from the Syncs* traits.
     */
    private function notePrimaryCopy(string $type, string $uuid, bool $push, bool $changed = true): void
    {
        if ($push === true && $changed === true) {
            $this->primaryCopies[$type . ':' . $uuid] = true;
        }
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
            // Every write of this row carries a requestid named for the row, so a retried create whose
            // first answer was lost is applied once by QuickBooks. Nested writes share the key.
            $outcome = $this->client->withWriteKey(
                $this->writeKey($row['local_type'] . ':' . $row['local_uuid']),
                fn (): string => match ($row['local_type']) {
                    'customer' => $this->syncCustomer($ledger, $connection, (string) $row['local_uuid'], $settings),
                    'wallet'   => $this->syncWallet($ledger, $connection, (string) $row['local_uuid'], $settings),
                    default    => $this->syncInvoice($ledger, $connection, (string) $row['local_uuid'], $settings),
                }
            );
        } catch (\Throwable $exception) {
            $this->lastError = null;
            if ($exception instanceof QuickBooksException && $exception->isUnauthorized() === true) {
                $this->tokenRejected($ledger, $companyUuid);
                $batch['failed']++;

                return;
            }
            $retryError = $this->exceptionError($exception);
            if ($exception instanceof QuickBooksException) {
                $type       = (string) $row['local_type'];
                $uuid       = (string) $row['local_uuid'];
                $nameTaken  = $this->customerNameTakenError($ledger, $type, $uuid, $exception->faultCode);
                $retryError = $nameTaken ?? $retryError;
                $this->forgetInactiveServiceItem($ledger, $connection, $type, $exception->faultCode);
            }
            $outcome = $this->failure($ledger, $connection, $row, $exception, $settings, $now);
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
                        new QuickBooksException(
                            (int) ($result['status'] ?? 400),
                            (string) ($result['error'] ?? self::ITEM_REJECTED_MESSAGE)
                        ),
                        $settings,
                        $now
                    );
                    $code  = $this->batchFaultCode($result);
                    $this->forgetInactiveServiceItem($ledger, $connection, $kind, $code);
                    $error = $this->customerNameTakenError($ledger, $kind, (string) $uuid, $code)
                        ?? $this->batchItemAttemptError(
                            (string) ($result['error'] ?? ''),
                            (int) ($result['status'] ?? 0)
                        );
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
            if (isset($this->refusedTaxRows[(string) $uuid]) === true) {
                $outcome           = 'failed';
                $error             = $this->refusedTaxRows[(string) $uuid];
                $plan['permanent'] = true;
                $plan['error']     = $error;
                unset($this->refusedTaxRows[(string) $uuid]);
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
     * @param array<string, array{row: array<string, mixed>, outcome: string, error: string|null, permanent: bool, planError: string|null}> $finished
     * @param array<string, true>                                                                                                           $queued
     */
    private function firstQueuedUuid(array $finished, array $queued): ?string
    {
        foreach (array_keys($finished) as $uuid) {
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
     * The Intuit fault code QuickBooksClient copies onto a refused batch item, or ''.
     *
     * @param array<string, mixed> $result
     */
    private function batchFaultCode(array $result): string
    {
        return is_scalar($result['code'] ?? null) === true ? (string) $result['code'] : '';
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
        foreach ($ops as $index => $op) {
            $ops[$index]['key'] = $this->writeKey($op['bId']);
        }

        return $this->client->batch($connection, $ops);
    }

    /**
     * @param array<string, mixed>                                                                 $connection
     * @param array{bId: string, operation: string, entity: string, payload: array<string, mixed>} $op
     *
     * @return array{
     *     ok: bool,
     *     body: array<string, mixed>,
     *     rows: array<int, array<string, mixed>>,
     *     error: string|null,
     *     status: int,
     *     halt: bool,
     *     code?: string
     * }
     */
    private function executeOneWrite(array $connection, array $op): array
    {
        try {
            $payload = $op['payload'];
            $key     = $this->writeKey($op['bId']);
            $body    = $this->client->withWriteKey($key, fn () => match ($op['entity'] . ':' . $op['operation']) {
                'Customer:create' => $this->client->createCustomer($connection, $payload),
                'Customer:update' => $this->client->updateCustomer($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
                'Invoice:create'  => $this->client->createInvoice($connection, $payload),
                'Invoice:update'  => $this->client->updateInvoice($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
                'Invoice:void'    => $this->client->voidInvoice($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0')),
                'Payment:create'  => $this->client->createPayment($connection, $payload),
                'Payment:update'  => $this->client->updatePayment($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
                'Account:create'  => $this->client->createAccount($connection, $payload),
                'Account:update'  => $this->client->updateAccount($connection, (string) ($payload['Id'] ?? ''), (string) ($payload['SyncToken'] ?? '0'), $payload),
                default           => throw new QuickBooksException(400, 'quickbooks.messages.batch-item-unsupported'),
            });

            return ['ok' => true, 'body' => $body, 'rows' => [], 'error' => null, 'status' => 200, 'halt' => false];
        } catch (QuickBooksException $exception) {
            $result = [
                'ok'     => false,
                'body'   => [],
                'rows'   => [],
                'error'  => $exception->getMessage(),
                'status' => $exception->status,
                'halt'   => $exception->isUnauthorized() === true || $exception->isRateLimit() === true,
            ];
            if ($exception->faultCode !== null && $exception->faultCode !== '') {
                $result['code'] = $exception->faultCode;
            }

            return $result;
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
        if ($error === '' || Translation::isMessage($error) === true || $status === 400) {
            return $error;
        }

        return self::SYNC_FAILED_MESSAGE;
    }

    /**
     * A failed batch item kept for the other rows of the block. It keeps the status QuickBooks
     * gave, so a refusal thrown again stops at the retry limit and an outage keeps retrying.
     */
    private function batchItemFailure(string $error, int $status): QuickBooksException
    {
        return new QuickBooksException($status, $this->batchItemAttemptError($error, $status));
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
     * A successful query that omits a value is a real miss. A failed request marks those values failed
     * and does not call them missing. One value uses $readOne. Larger sets use batch() in chunks of 30,
     * and each query is paged until a short page.
     *
     * @param array<string, mixed>       $connection
     * @param array<int, string>         $values
     * @param callable(string): (?array) $readOne
     *
     * @return array{rows: array<int, array<string, mixed>>, failed: array<string, QuickBooksException>}
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

                return ['rows' => [], 'failed' => [$values[0] => $exception]];
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
                    foreach ($chunk as $value) {
                        if (isset($matched[$value]) === false) {
                            $failed[$value] = $exception;
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
                    $error   = $this->batchItemFailure($message, $status);
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
        $batch[$outcome] = ($batch[$outcome] ?? 0) + 1;
        // rowNote() is read once, so the error is worked out once.
        $copied = $this->takePrimaryCopy($row);
        $error  = $this->lastError
            ?? $retryError
            ?? $this->rowNote($row, $outcome)
            ?? $this->primaryReplacedNote($row, $outcome, $copied);
        // The daily catalog finds most customers unchanged; the batch counts them, Activity skips them.
        $attempt = null;
        if ($trigger !== 'catalog' || $outcome !== 'aligned' || $error !== null) {
            $attempt            = count($ledger->attempts);
            $ledger->attempts[] = [
                'company_uuid' => $connection['company_uuid'],
                'local_type'   => $row['local_type'],
                'local_uuid'   => $row['local_uuid'],
                'outcome'      => $outcome,
                'error'        => $error,
            ];
        }
        // A failure Fleetbase decided itself (lastError) is final for this data. A failure
        // from QuickBooks was classified by failure(). A row that did not fail is not counted.
        if ($outcome !== 'failed' || $this->lastError !== null) {
            $this->notePermanence((string) $connection['company_uuid'], $row, $outcome === 'failed');
        }
        $tracksPending = in_array($trigger, ['scheduled', 'now', 'manual', 'catalog'], true);
        if ($this->lastError !== null && $tracksPending === true) {
            $ledger->updatePending((string) $connection['company_uuid'], (string) $row['local_type'], (string) $row['local_uuid'], [
                'status' => 'failed',
            ]);
        }
        $this->lastError                         = null;
        $this->recordedRows[$this->rowKey($row)] = ['outcome' => $outcome, 'attempt' => $attempt];
        if ($this->closesRow($row, $outcome, $tracksPending, $attempt) === true) {
            $ledger->updatePending((string) $connection['company_uuid'], (string) $row['local_type'], (string) $row['local_uuid'], ['status' => 'done']);
        }
        if ($outcome === 'skipped' && $this->closeSkipped === true && $tracksPending === true) {
            $ledger->updatePending((string) $connection['company_uuid'], (string) $row['local_type'], (string) $row['local_uuid'], ['status' => 'done']);
        }
        $this->closeSkipped = false;
    }

    /**
     * The note this sync left on a row that did not fail. It is read once.
     *
     * @param array<string, mixed> $row
     */
    private function rowNote(array $row, string $outcome): ?string
    {
        $key  = (string) ($row['local_type'] ?? '') . ':' . (string) ($row['local_uuid'] ?? '');
        $note = $this->rowNotes[$key] ?? null;
        unset($this->rowNotes[$key]);

        return $outcome === 'failed' ? null : $note;
    }

    /**
     * Whether QuickBooks as Primary replaced this row's Fleetbase difference (notePrimaryCopy).
     * Read once.
     *
     * @param array<string, mixed> $row
     */
    private function takePrimaryCopy(array $row): bool
    {
        $key    = (string) ($row['local_type'] ?? '') . ':' . (string) ($row['local_uuid'] ?? '');
        $copied = isset($this->primaryCopies[$key]);
        unset($this->primaryCopies[$key]);

        return $copied;
    }

    /**
     * A row queued by a QuickBooks webhook that Fleetbase then updated in QuickBooks had a change
     * made there. With Fleetbase as Primary that change was replaced, which Activity says. With
     * QuickBooks as Primary, a row Fleetbase queued whose change was replaced because QuickBooks
     * had also changed the record says that instead.
     *
     * @param array<string, mixed> $row
     */
    private function primaryReplacedNote(array $row, string $outcome, bool $copied = false): ?string
    {
        $type   = (string) ($row['local_type'] ?? '');
        $reason = (string) ($row['reason'] ?? '');
        if ($outcome !== 'updated' || in_array($type, ['customer', 'invoice', 'wallet'], true) === false) {
            return null;
        }
        if ((string) ($this->runSettings[$type . '_conflict'] ?? 'fleetbase') === 'quickbooks') {
            $queuedByFleetbase = $reason !== '' && in_array($reason, self::REMOTE_REASONS, true) === false;

            return $copied === true && $queuedByFleetbase === true
                ? Translation::encode('quickbooks.messages.quickbooks-primary-replaced')
                : null;
        }

        return $reason === 'webhook' ? Translation::encode('quickbooks.messages.fleetbase-primary-replaced') : null;
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
