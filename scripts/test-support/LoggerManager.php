<?php

declare(strict_types=1);

namespace Fleetbase\TestSupport;

use Psr\Log\NullLogger;
use Stringable;

class LoggerManager extends NullLogger
{
    public static array $records = [];

    public function channel(?string $name = null): self
    {
        return $this;
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        self::$records[] = compact('level', 'message', 'context');
    }
}
