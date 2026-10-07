<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $table  = 'quickbooks_pending_syncs';
    private string $column = 'open_identity';
    private string $index  = 'quickbooks_pending_open_unique';

    public function up(): void
    {
        if (Schema::hasTable($this->table) === FALSE) {
            return;
        }

        if (Schema::hasColumn($this->table, $this->column) === TRUE && Schema::hasIndex($this->table, $this->index) === TRUE) {
            return;
        }

        $duplicates = DB::table($this->table)
            ->select('company_uuid', 'local_type', 'local_uuid')
            ->where('status', 'pending')
            ->groupBy('company_uuid', 'local_type', 'local_uuid')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $keep = DB::table($this->table)
                ->where('company_uuid', $duplicate->company_uuid)
                ->where('local_type', $duplicate->local_type)
                ->where('local_uuid', $duplicate->local_uuid)
                ->where('status', 'pending')
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->value('uuid');

            if ($keep === null) {
                continue;
            }

            DB::table($this->table)
                ->where('company_uuid', $duplicate->company_uuid)
                ->where('local_type', $duplicate->local_type)
                ->where('local_uuid', $duplicate->local_uuid)
                ->where('status', 'pending')
                ->where('uuid', '!=', $keep)
                ->delete();
        }

        if (Schema::hasColumn($this->table, $this->column) === FALSE) {
            Schema::table($this->table, function (Blueprint $table) {
                // char(36) + ':' + varchar(255) + ':' + char(36)
                $table->string($this->column, 329)->nullable()->storedAs(
                    "IF(`status` = 'pending', CONCAT(`company_uuid`, ':', `local_type`, ':', `local_uuid`), NULL)"
                );
            });
        }

        if (Schema::hasIndex($this->table, $this->index) === FALSE) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->unique($this->column, $this->index);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable($this->table) === FALSE) {
            return;
        }

        if (Schema::hasIndex($this->table, $this->index) === TRUE) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropUnique($this->index);
            });
        }

        if (Schema::hasColumn($this->table, $this->column) === TRUE) {
            Schema::table($this->table, function (Blueprint $table) {
                $table->dropColumn($this->column);
            });
        }
    }
};
