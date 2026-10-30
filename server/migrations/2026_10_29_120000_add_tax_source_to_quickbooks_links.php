<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $table = 'quickbooks_links';

    private string $column = 'tax_source';

    private string $refused = 'refused_tax';

    /**
     * A QuickBooks invoice link marks an invoice first sent while Fleetbase worked out tax
     * (tax_source fleetbase), so its tax stays Fleetbase's, and keeps the QuickBooks SyncToken and
     * tax of a write whose Fleetbase tax QuickBooks did not keep, so it is not sent again unchanged.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table) === false || Schema::hasColumn($this->table, $this->column) === true) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->string($this->column)->nullable();
            $table->string($this->refused)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable($this->table) === false || Schema::hasColumn($this->table, $this->column) === false) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->dropColumn([$this->column, $this->refused]);
        });
    }
};
