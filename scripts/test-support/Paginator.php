<?php

declare(strict_types=1);

namespace Illuminate\Pagination;

use Illuminate\Support\Collection;

class Paginator implements \JsonSerializable
{
    protected $items;

    public function __construct(
        $items,
        protected int $perPage,
        protected ?int $currentPage = null,
        protected array $options = [],
    ) {
        $this->items = $items instanceof Collection === true ? $items : collect($items);
    }

    public static function resolveCurrentPage($pageName = 'page', $default = 1): int
    {
        return $default;
    }

    public static function resolveCurrentPath($default = '/'): string
    {
        return $default;
    }

    public function first()
    {
        return $this->items->first();
    }

    public function mapInto(string $class)
    {
        return $this->items->mapInto($class);
    }

    public function toBase()
    {
        return $this->items->toBase();
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        return [
            'data' => $this->items->values()->all(),
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage ?? 1,
        ];
    }
}

class LengthAwarePaginator extends Paginator
{
    public function __construct(
        $items,
        protected int $total,
        int $perPage,
        ?int $currentPage = null,
        array $options = [],
    ) {
        parent::__construct($items, $perPage, $currentPage, $options);
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'total' => $this->total,
            'last_page' => max(1, (int) ceil($this->total / $this->perPage)),
        ]);
    }
}
