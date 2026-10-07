<?php

declare(strict_types=1);

namespace Fleetbase\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';
}
