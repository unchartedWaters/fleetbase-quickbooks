<?php

declare(strict_types=1);

namespace Fleetbase\Quickbooks\ReleaseCheck;

/**
 * Prints one line per release check and remembers whether any failed.
 */
final class Report
{
    private int $failures = 0;

    /**
     * @param array<int, string> $problems empty when the check passed
     */
    public function check(string $label, array $problems): void
    {
        if ($problems === []) {
            echo 'ok   ' . $label . PHP_EOL;

            return;
        }
        $this->failures++;
        echo 'FAIL ' . $label . ': ' . implode('; ', $problems) . PHP_EOL;
    }

    public function exitCode(): int
    {
        return $this->failures === 0 ? 0 : 1;
    }
}
