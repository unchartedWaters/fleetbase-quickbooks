<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $table = 'quickbooks_pending_syncs';

    private string $index = 'qb_pending_claimed_by_idx';

    /**
     * A running sync leases the pending rows it loaded. A row stays status 'pending'
     * while leased, so the open-identity unique index is unchanged. The lease expires
     * by itself when a worker dies.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table) === false) {
            return;
        }

        if (Schema::hasColumn($this->table, 'claimed_until') === false) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->timestamp('claimed_until')->nullable();
            });
        }

        if (Schema::hasColumn($this->table, 'claimed_by') === false) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->string('claimed_by', 64)->nullable();
            });
        }

        // Releasing a lease finds its rows by token; without an index that scans the table.
        if (Schema::hasIndex($this->table, $this->index) === false) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->index('claimed_by', $this->index);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable($this->table) === false) {
            return;
        }

        if (Schema::hasIndex($this->table, $this->index) === true) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropIndex($this->index);
            });
        }

        foreach (['claimed_by', 'claimed_until'] as $column) {
            if (Schema::hasColumn($this->table, $column) === true) {
                Schema::table($this->table, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
