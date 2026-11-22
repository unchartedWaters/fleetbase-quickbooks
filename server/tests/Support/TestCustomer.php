<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for the Fleet-Ops customer: a table with a JSON meta column, which CompanyReset reads the import marker from.
 */
class TestCustomer extends Model
{
    protected $connection = 'sqlite';

    protected $table = 'test_customers';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['meta' => 'array'];
}
