<?php

use Fleetbase\Quickbooks\Support\SyncSchedule;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->lockCache = Cache::getFacadeRoot();
    Cache::swap(new Repository(new ArrayStore()));
});

afterEach(function () {
    Cache::swap($this->lockCache);
});

test('shouldStart is true when the schedule cache is unset', function () {
    expect(SyncSchedule::shouldStart(1_700_000_000))->toBeTrue();
});

test('defer makes shouldStart false until that timestamp', function () {
    $now = 1_700_000_000;
    SyncSchedule::defer($now, 300);

    expect(SyncSchedule::shouldStart($now))->toBeFalse()
        ->and(SyncSchedule::shouldStart($now + 299))->toBeFalse()
        ->and(SyncSchedule::shouldStart($now + 300))->toBeTrue();
});

test('a numeric string from the cache still holds the next start', function () {
    $now = 1_700_000_000;
    Cache::forever('quickbooks.schedule.not_before', (string) ($now + 300));

    expect(SyncSchedule::shouldStart($now + 299))->toBeFalse()
        ->and(SyncSchedule::shouldStart($now + 300))->toBeTrue();
});

test('wake makes shouldStart true again', function () {
    $now = 1_700_000_000;
    SyncSchedule::defer($now, 300);
    SyncSchedule::wake();

    expect(SyncSchedule::shouldStart($now))->toBeTrue();
});

test('defer of a small value still waits at least 60 seconds', function () {
    $now = 1_700_000_000;
    SyncSchedule::defer($now, 1);

    expect(SyncSchedule::shouldStart($now + 59))->toBeFalse()
        ->and(SyncSchedule::shouldStart($now + 60))->toBeTrue();
});

test('a missing connection does not start sync even when the interval hold is open', function () {
    $now = 1_700_000_000;

    expect(SyncSchedule::shouldRun($now, false))->toBeFalse();

    SyncSchedule::wake();

    expect(SyncSchedule::shouldRun($now, false))->toBeFalse()
        ->and(SyncSchedule::shouldRun($now, true))->toBeTrue();
});

test('a connection still waits out its interval and a one minute hold opens on the next minute', function () {
    $now = 1_700_000_000;
    SyncSchedule::defer($now, 60);

    expect(SyncSchedule::shouldRun($now + 59, true))->toBeFalse()
        ->and(SyncSchedule::shouldRun($now + 60, true))->toBeTrue()
        ->and(SyncSchedule::shouldRun($now + 60, false))->toBeFalse();
});
