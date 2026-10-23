<?php

namespace Fleetbase\Quickbooks\Tests\Support;

/**
 * A Storefront product p-1 of company-uuid as the product observer sees it.
 */
class ProductRecord
{
    public string $company_uuid = 'company-uuid';

    public string $uuid = 'p-1';

    public string $name;

    public bool $wasRecentlyCreated;

    /**
     * @param array<string, mixed> $state
     */
    public function __construct(private array $state)
    {
        $this->name               = (string) ($state['name'] ?? 'Mug');
        $this->wasRecentlyCreated = ($state['created'] ?? false) === true;
    }

    /**
     * @param array<int, string>|string $fields
     */
    public function wasChanged(array|string $fields): bool
    {
        return array_intersect((array) $fields, $this->state['changed'] ?? []) !== [];
    }

    public function getOriginal(string $field): mixed
    {
        return $field === 'name' ? ($this->state['original'] ?? null) : null;
    }
}
