<?php

namespace Fleetbase\Quickbooks\Jobs;

use Fleetbase\Quickbooks\Services\BatchRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class SyncCompanyBatch implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CONTINUATION_RETRY_LIMIT = 3;

    public const CONTINUATION_RETRY_DELAY_SECONDS = 5;

    /**
     * Pending rows own retries. A thrown error fails this job once (maxExceptions).
     * tries is 2 only so the Redis redelivery at retry_after is not failed while
     * this run is still going. That second pop shares this queue job id and is dropped.
     */
    public int $tries = BatchRunner::SYNC_JOB_TRIES;

    public int $maxExceptions = 1;

    /**
     * Shorter than the company lock, so the worker is killed while the lock remains.
     * Longer than Redis retry_after, because a full batch can take more than 90 seconds.
     */
    public int $timeout = BatchRunner::LOCK_SECONDS - BatchRunner::LOCK_MARGIN_SECONDS;

    public function __construct(
        public string $companyUuid,
        public string $trigger = 'scheduled',
        public int $continuationRetry = 0,
    ) {
    }

    public static function dispatch(string $companyUuid, string $trigger = 'scheduled'): void
    {
        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch(new self($companyUuid, $trigger));
        }
    }

    /**
     * Redis can pop this same job again after retry_after. The second worker must
     * not run it or release it (a release would become another attempt and then
     * fail this run). A follow-up drain is a new queue job and is not blocked.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->redeliveryKey()))
                ->expireAfter(BatchRunner::LOCK_SECONDS)
                ->dontRelease(),
        ];
    }

    /**
     * Same id for a Redis redelivery of this payload. Empty until the worker
     * sets the queue job, which happens before middleware runs.
     */
    public function redeliveryKey(): string
    {
        $id = $this->job?->uuid();
        if (is_string($id) === true && $id !== '') {
            return $id;
        }

        return $this->companyUuid . ':' . $this->trigger . ':' . $this->continuationRetry;
    }

    public function handle(BatchRunner $runner): void
    {
        $result = $runner->run($this->companyUuid, $this->trigger);
        if (($result['reason'] ?? null) !== 'busy' || in_array($this->trigger, ['drain', 'catalog'], true) === false) {
            return;
        }
        if ($this->continuationRetry >= self::CONTINUATION_RETRY_LIMIT) {
            return;
        }

        $retry = new self($this->companyUuid, $this->trigger, $this->continuationRetry + 1);
        $retry->delay(min(
            self::CONTINUATION_RETRY_DELAY_SECONDS * ($this->continuationRetry + 1),
            self::CONTINUATION_RETRY_DELAY_SECONDS * self::CONTINUATION_RETRY_LIMIT
        ));

        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher === true) {
            $dispatcher->dispatch($retry);
        }
    }
}
