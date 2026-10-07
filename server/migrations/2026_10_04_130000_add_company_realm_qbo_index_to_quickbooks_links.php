<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $table = 'quickbooks_links';

    private string $index = 'quickbooks_links_company_realm_qbo';

    public function up(): void
    {
        if (Schema::hasTable($this->table) === FALSE || Schema::hasIndex($this->table, $this->index) === TRUE) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->index(['company_uuid', 'realm_id', 'qbo_id'], $this->index);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable($this->table) === FALSE || Schema::hasIndex($this->table, $this->index) === FALSE) {
            return;
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->dropIndex($this->index);
        });
    }
};
