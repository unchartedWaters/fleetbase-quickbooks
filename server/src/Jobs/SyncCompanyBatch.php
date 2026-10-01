<?php

namespace Fleetbase\Quickbooks\Jobs;

use Fleetbase\Quickbooks\Services\BatchRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncCompanyBatch implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CONTINUATION_RETRY_LIMIT = 3;

    public const CONTINUATION_RETRY_DELAY_SECONDS = 5;

    /**
     * Pending rows own retries. Laravel must not retry this job on its own.
     */
    public int $tries = 1;

    public int $timeout = BatchRunner::LOCK_SECONDS;

    public function __construct(
        public string $companyUuid,
        public string $trigger = 'scheduled',
        public int $continuationRetry = 0,
    )
    {
    }

    public static function dispatch(string $companyUuid, string $trigger = 'scheduled'): void
    {
        $dispatcher = Container::getInstance()->make(Dispatcher::class);
        if ($dispatcher instanceof Dispatcher) {
            $dispatcher->dispatch(new self($companyUuid, $trigger));
        }
    }

    public function handle(BatchRunner $runner): void
    {
        $result = $runner->run($this->companyUuid, $this->trigger);
        if (($result['reason'] ?? null) !== 'busy' || !in_array($this->trigger, ['drain', 'catalog'], true)) {
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
        if ($dispatcher instanceof Dispatcher) {
            $dispatcher->dispatch($retry);
        }
    }
}
