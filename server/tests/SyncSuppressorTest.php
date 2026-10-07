<?php

use Fleetbase\Quickbooks\Support\SyncSuppressor;

beforeEach(function () {
    while (SyncSuppressor::paused() === true) {
        SyncSuppressor::resume();
    }
});

afterEach(function () {
    while (SyncSuppressor::paused() === true) {
        SyncSuppressor::resume();
    }
});

test('nested pause stays paused until every pause is resumed', function () {
    SyncSuppressor::pause();
    SyncSuppressor::pause();

    SyncSuppressor::resume();

    expect(SyncSuppressor::paused())->toBeTrue();

    SyncSuppressor::resume();

    expect(SyncSuppressor::paused())->toBeFalse();
});

test('an extra resume at zero leaves suppression off', function () {
    SyncSuppressor::resume();
    SyncSuppressor::resume();

    expect(SyncSuppressor::paused())->toBeFalse();

    SyncSuppressor::pause();
    SyncSuppressor::resume();
    SyncSuppressor::resume();

    expect(SyncSuppressor::paused())->toBeFalse();
});

test('one resume after two pauses stays paused', function () {
    SyncSuppressor::pause();
    SyncSuppressor::pause();
    SyncSuppressor::resume();

    expect(SyncSuppressor::paused())->toBeTrue();
});
