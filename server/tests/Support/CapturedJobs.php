<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Jobs\QueueCompanyScope;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Collects the jobs a callback dispatches without running them.
 */
class CapturedJobs
{
    /**
     * @return array<int, object>
     */
    public static function capture(callable $callback): array
    {
        $dispatcher = new class implements Dispatcher {
            /** @var array<int, object> */
            public array $jobs = [];

            public function dispatch($command)
            {
                $this->jobs[] = $command;

                return $command;
            }

            public function dispatchSync($command, $handler = null)
            {
                return $command;
            }

            public function dispatchNow($command, $handler = null)
            {
                return $command;
            }

            public function hasCommandHandler($command)
            {
                return false;
            }

            public function getCommandHandler($command)
            {
                return false;
            }

            public function pipeThrough(array $pipes)
            {
                return $this;
            }

            public function map(array $map)
            {
                return $this;
            }
        };
        $container          = Container::getInstance();
        $previousDispatcher = $container->bound(Dispatcher::class) === true ? $container->make(Dispatcher::class) : null;
        $container->instance(Dispatcher::class, $dispatcher);

        try {
            $callback();
        } finally {
            if ($previousDispatcher !== null) {
                $container->instance(Dispatcher::class, $previousDispatcher);
            } else {
                $container->forgetInstance(Dispatcher::class);
            }
        }

        return $dispatcher->jobs;
    }

    /**
     * The [company, enabled] pairs of the per-organization queue jobs, in dispatch order.
     *
     * @param array<int, object> $jobs
     *
     * @return array<int, array{0: string, 1: array<string, bool>}>
     */
    public static function queued(array $jobs): array
    {
        $queued = [];
        foreach ($jobs as $job) {
            if ($job instanceof QueueCompanyScope) {
                $queued[] = [$job->companyUuid, $job->enabled];
            }
        }

        return $queued;
    }
}
