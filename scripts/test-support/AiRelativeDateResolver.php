<?php

declare(strict_types=1);

namespace Fleetbase\Ai\Support;

use Illuminate\Support\Carbon;

class AiRelativeDateResolver
{
    public function __construct($parser = null)
    {
    }

    public function resolveDateTime(string $prompt, ?string $timezone = null): ?Carbon
    {
        if (preg_match('/(\d+)\s+days?\s+from\s+now/i', $prompt, $matches) === 1) {
            return Carbon::now($timezone)->addDays((int) $matches[1]);
        }

        return null;
    }

    public function resolveWindow(string $prompt, ?string $timezone = null): ?array
    {
        $timezone = ($timezone !== null && $timezone !== '' && $timezone !== '0') ? $timezone : date_default_timezone_get();
        $now = Carbon::now($timezone);

        if (str_contains(strtolower($prompt), 'last week') === true) {
            $start = $now->copy()->subWeek()->startOfWeek();
            $end = $now->copy()->subWeek()->endOfWeek();

            return [
                'label' => 'last week',
                'timezone' => $timezone,
                'start' => $start,
                'end' => $end,
            ];
        }

        if (str_contains(strtolower($prompt), 'yesterday') === true) {
            $start = $now->copy()->subDay()->startOfDay();
            $end = $now->copy()->subDay()->endOfDay();

            return [
                'label' => 'yesterday',
                'timezone' => $timezone,
                'start' => $start,
                'end' => $end,
            ];
        }

        return null;
    }
}
