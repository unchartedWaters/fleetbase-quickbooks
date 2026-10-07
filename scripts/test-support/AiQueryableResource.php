<?php

declare(strict_types=1);

namespace Fleetbase\Ai\Support;

class AiQueryableResource
{
    public string $key;

    public array $fields;

    public array $aliases;

    public function __construct(
        string $key,
        string $label = '',
        string $module = '',
        string $modelClass = '',
        string $permission = '',
        array $aliases = [],
        array $fields = [],
        array $sampleFields = [],
        ?string $locationField = null,
        ?string $directivePermission = null,
        int $maxLimit = 100,
    ) {
        $this->key = $key;
        $this->fields = $fields;
        $this->aliases = $aliases;
    }

    public function hasField(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }
}
