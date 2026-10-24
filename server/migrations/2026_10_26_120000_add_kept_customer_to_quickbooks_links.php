<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $table = 'quickbooks_links';

    private string $column = 'kept_customer';

    private string $from = 'kept_from';

    private string $index = 'quickbooks_links_company_realm_kept_customer_index';

    /**
     * A QuickBooks invoice link keeps the QuickBooks customer QuickBooks moved the invoice to
     * while no Fleetbase customer is linked to it, and the Fleetbase customer the invoice kept.
     * The invoice follows that QuickBooks customer once Fleetbase links it.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table) === false || Schema::hasColumn($this->table, $this->column) === true) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->string($this->column)->nullable();
            $table->string($this->from)->nullable();
            $table->index(['company_uuid', 'realm_id', $this->column], $this->index);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable($this->table) === false || Schema::hasColumn($this->table, $this->column) === false) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->dropIndex($this->index);
            $table->dropColumn([$this->column, $this->from]);
        });
    }
};
