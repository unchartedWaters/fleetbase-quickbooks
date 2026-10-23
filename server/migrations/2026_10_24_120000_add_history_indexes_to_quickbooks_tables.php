<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Indexes for the history reads: the daily prune walks each table by age a
     * chunk at a time, Activity lists a company's batches newest first, and the
     * widget reads a company's latest finished batch.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private array $indexes = [
        'quickbooks_sync_attempts' => [
            'qb_attempts_created_idx' => ['created_at'],
        ],
        'quickbooks_sync_batches' => [
            'qb_batches_company_created_idx'         => ['company_uuid', 'created_at'],
            'qb_batches_company_status_finished_idx' => ['company_uuid', 'status', 'finished_at'],
            'qb_batches_created_idx'                 => ['created_at'],
        ],
        'quickbooks_pending_syncs' => [
            'qb_pending_status_updated_idx' => ['status', 'updated_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (Schema::hasTable($table) === false) {
                continue;
            }
            foreach ($indexes as $index => $columns) {
                if (Schema::hasIndex($table, $index) === true) {
                    continue;
                }
                Schema::table($table, function (Blueprint $blueprint) use ($columns, $index) {
                    $blueprint->index($columns, $index);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (Schema::hasTable($table) === false) {
                continue;
            }
            foreach (array_keys($indexes) as $index) {
                if (Schema::hasIndex($table, $index) === false) {
                    continue;
                }
                Schema::table($table, function (Blueprint $blueprint) use ($index) {
                    $blueprint->dropIndex($index);
                });
            }
        }
    }
};
