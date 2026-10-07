<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $table = 'quickbooks_pending_syncs';

    private string $index = 'quickbooks_pending_due';

    public function up(): void
    {
        if (Schema::hasTable($this->table) === false || Schema::hasIndex($this->table, $this->index) === true) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->index(['company_uuid', 'status', 'next_attempt_at', 'uuid'], $this->index);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable($this->table) === false || Schema::hasIndex($this->table, $this->index) === false) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->dropIndex($this->index);
        });
    }
};
