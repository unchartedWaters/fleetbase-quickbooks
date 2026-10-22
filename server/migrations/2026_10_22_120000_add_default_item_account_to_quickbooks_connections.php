<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $table = 'quickbooks_connections';

    /**
     * The name of the income account the "Fleetbase service" product posts to, shown on the
     * connection card. Read from QuickBooks when invoices sync.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table) === false || Schema::hasColumn($this->table, 'default_item_account') === true) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->string('default_item_account')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable($this->table) === false || Schema::hasColumn($this->table, 'default_item_account') === false) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->dropColumn('default_item_account');
        });
    }
};
