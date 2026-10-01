<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $index = 'quickbooks_connections_company_uuid_unique';

    public function up(): void
    {
        if (!Schema::hasTable('quickbooks_connections') || Schema::hasIndex('quickbooks_connections', $this->index)) {
            return;
        }

        $duplicates = DB::table('quickbooks_connections')
            ->select('company_uuid')
            ->groupBy('company_uuid')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('company_uuid');

        foreach ($duplicates as $companyUuid) {
            $keep = DB::table('quickbooks_connections')
                ->where('company_uuid', $companyUuid)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->value('uuid');

            DB::table('quickbooks_connections')
                ->where('company_uuid', $companyUuid)
                ->where('uuid', '!=', $keep)
                ->delete();
        }

        Schema::table('quickbooks_connections', function (Blueprint $table) {
            $table->unique('company_uuid', $this->index);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('quickbooks_connections') || !Schema::hasIndex('quickbooks_connections', $this->index)) {
            return;
        }

        Schema::table('quickbooks_connections', function (Blueprint $table) {
            $table->dropUnique($this->index);
        });
    }
};
