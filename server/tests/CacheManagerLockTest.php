<?php

use Fleetbase\Quickbooks\Services\BatchRunner;
use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;

// In a Laravel app the Cache facade resolves to the CacheManager, not to a Repository.
beforeEach(function () {
    $container                 = Container::getInstance();
    $config                    = $container->make('config');
    $this->previousCache       = Cache::getFacadeRoot();
    $this->previousCacheConfig = $config->get('cache');
    $config->set('cache', [
        'default' => 'array',
        'stores'  => ['array' => ['driver' => 'array']],
        'prefix'  => '',
    ]);
    $this->manager = new CacheManager($container);
    Cache::swap($this->manager);
});

afterEach(function () {
    Cache::swap($this->previousCache);
    Container::getInstance()->make('config')->set('cache', $this->previousCacheConfig);
});

test('the company lock is available when the cache facade is the cache manager', function () {
    expect(Cache::getFacadeRoot())->toBeInstanceOf(CacheManager::class);

    $lock = BatchRunner::lock('company-uuid');

    try {
        expect($lock)->not->toBeNull()
            ->and($lock->get())->toBeTrue();

        $other = $this->manager->store()->lock('quickbooks.batch.company-uuid', BatchRunner::LOCK_SECONDS);
        expect($other->get())->toBeFalse();

        $lock->release();

        expect($other->get())->toBeTrue();
        $other->release();
    } finally {
        // A held lock is remembered by BatchRunner, so a failed expectation must not leave it behind.
        $lock?->release();
    }
});
