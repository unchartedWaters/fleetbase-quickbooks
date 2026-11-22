<?php

namespace Fleetbase\Quickbooks\Support;

/**
 * One sync run as Activity shows it: who ran it, how it ended and how many rows ended each way.
 * Every writer fills the counters that apply to it and the rest stay 0. FleetbaseDirectory saves it
 * as a SyncBatch row. A run that did not start because another run holds the company lock comes back
 * with status 'busy' and is never saved.
 */
final class Batch
{
    public ?string $companyUuid = null;

    public int $created = 0;

    public int $updated = 0;

    public int $aligned = 0;

    public int $voided = 0;

    public int $unmatched = 0;

    public int $failed = 0;

    public int $linked = 0;

    public int $skipped = 0;

    /**
     * A customer import that stopped at its time limit: the job runs again from where it left off.
     */
    public bool $continue = false;

    /** Unix seconds. */
    public ?int $startedAt = null;

    /** Unix seconds. */
    public ?int $finishedAt = null;

    public function __construct(
        public string $trigger,
        public string $status = 'finished',
        public string $direction = 'outbound',
    ) {
    }

    /**
     * A batch for a company, with the times it started and finished.
     */
    public static function forCompany(
        string $companyUuid,
        string $trigger,
        string $status,
        string $direction,
        ?int $startedAt,
        ?int $finishedAt,
    ): self {
        $batch              = new self($trigger, $status, $direction);
        $batch->companyUuid = $companyUuid;
        $batch->startedAt   = $startedAt;
        $batch->finishedAt  = $finishedAt;

        return $batch;
    }

    /**
     * Counts one row that ended as $outcome, or takes one back with a negative $amount. A count never
     * goes below 0. An outcome this batch does not count is ignored.
     */
    public function add(string $outcome, int $amount = 1): void
    {
        match ($outcome) {
            'created' => $this->created = max(0, $this->created + $amount),
            'updated' => $this->updated = max(0, $this->updated + $amount),
            'aligned' => $this->aligned = max(0, $this->aligned + $amount),
            'voided'  => $this->voided  = max(0, $this->voided + $amount),
            'failed'  => $this->failed  = max(0, $this->failed + $amount),
            'linked'  => $this->linked  = max(0, $this->linked + $amount),
            'skipped' => $this->skipped = max(0, $this->skipped + $amount),
            default   => null,
        };
    }
}
