<?php

declare(strict_types=1);

namespace Fleetbase\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stands in for the Storefront product model, which Storefront keeps in its own database. Tests
 * that read products create a products table on the default connection.
 */
class Product extends Model
{
    use SoftDeletes;

    protected $table = 'products';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** A test sets a connection name to read through, such as one that is not configured. */
    public static ?string $testConnection = null;

    public function getConnectionName()
    {
        return static::$testConnection ?? parent::getConnectionName();
    }
}
