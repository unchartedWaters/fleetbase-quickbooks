<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for the Fleet-Ops place that holds a customer's billing address.
 */
class TestPlace extends Model
{
    protected $connection = 'sqlite';

    protected $table = 'test_places';

    public $timestamps = false;

    protected $guarded = [];
}
