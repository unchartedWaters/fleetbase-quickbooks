<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $table = 'quickbooks_links';

    /**
     * A QuickBooks product link stands for one product name of the company. It keeps that name,
     * and whether the product was already in QuickBooks (adopted, never written to) or was made
     * inactive by Fleetbase (deactivated, made active again when the name is used again).
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table) === false) {
            return;
        }
        if (Schema::hasColumn($this->table, 'name') === true) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('state')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable($this->table) === false) {
            return;
        }
        if (Schema::hasColumn($this->table, 'name') === false) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->dropColumn(['name', 'state']);
        });
    }
};
