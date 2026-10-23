<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $table = 'quickbooks_links';

    private string $column = 'invoice_tokens';

    /**
     * A QuickBooks payment link keeps, by Fleetbase invoice, the payment SyncToken each invoice
     * it pays last synced (a JSON object). One payment can pay several invoices.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table) === false || Schema::hasColumn($this->table, $this->column) === true) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->text($this->column)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable($this->table) === false || Schema::hasColumn($this->table, $this->column) === false) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->dropColumn($this->column);
        });
    }
};
