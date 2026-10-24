<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $table = 'quickbooks_pending_syncs';

    private string $index = 'quickbooks_pending_due';

    /** @var array<int, string> */
    private array $oldColumns = ['company_uuid', 'status', 'next_attempt_at', 'uuid'];

    /** @var array<int, string> */
    private array $newColumns = ['company_uuid', 'status', 'priority', 'next_attempt_at', 'uuid'];

    /**
     * Edits made in Fleetbase or QuickBooks (priority 0) are loaded before the
     * reconcile and catalog backlog (priority 1), so a large backlog does not
     * hold up day-to-day edits. The due index leads with priority to keep the
     * batch read an index range.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table) === false) {
            return;
        }

        if (Schema::hasColumn($this->table, 'priority') === false) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->unsignedTinyInteger('priority')->default(0);
            });
        }

        if (Schema::hasIndex($this->table, $this->newColumns) === true) {
            return;
        }

        $this->dropDueIndex();
        Schema::table($this->table, function (Blueprint $table) {
            $table->index($this->newColumns, $this->index);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable($this->table) === false) {
            return;
        }

        if (Schema::hasIndex($this->table, $this->newColumns) === true) {
            $this->dropDueIndex();
            Schema::table($this->table, function (Blueprint $table) {
                $table->index($this->oldColumns, $this->index);
            });
        }

        if (Schema::hasColumn($this->table, 'priority') === true) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropColumn('priority');
            });
        }
    }

    private function dropDueIndex(): void
    {
        if (Schema::hasIndex($this->table, $this->index) === false) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->dropIndex($this->index);
        });
    }
};
