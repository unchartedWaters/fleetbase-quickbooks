<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Fleetbase\Quickbooks\Listeners\FlagProductListener;
use Fleetbase\Quickbooks\Services\SyncFlagger;
use Illuminate\Container\Container;

/**
 * Storefront product events for the product observer tests.
 */
class ProductEvents
{
    /**
     * Binds a product listener whose directory keeps the pending rows it would write.
     *
     * @return \ArrayObject<int, array<string, mixed>>
     */
    public static function listener(): \ArrayObject
    {
        $written   = new \ArrayObject();
        $directory = new PendingRecorder($written);
        Container::getInstance()->instance(
            FlagProductListener::class,
            new FlagProductListener($directory, new SyncFlagger())
        );

        return $written;
    }

    /**
     * A product p-1 of company-uuid. $state: changed (fields), created, original (name), name.
     *
     * @param array<string, mixed> $state
     */
    public static function record(array $state): object
    {
        return new ProductRecord($state);
    }

    /**
     * @param \ArrayObject<int, array<string, mixed>> $written
     *
     * @return array<int, string>
     */
    public static function rows(\ArrayObject $written): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['local_type'] . ':' . (string) $row['local_uuid'],
            $written->getArrayCopy()
        );
    }
}
