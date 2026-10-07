<?php

declare(strict_types=1);

namespace Fleetbase\Ai\Models;

class AiTask
{
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
