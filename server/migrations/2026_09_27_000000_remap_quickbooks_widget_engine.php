<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('dashboard_widgets')) {
            return;
        }

        $rows = DB::table('dashboard_widgets')
            ->where('component', 'like', '%quickbooks-integration-engine%')
            ->get(['id', 'component']);

        foreach ($rows as $row) {
            DB::table('dashboard_widgets')->where('id', $row->id)->update([
                'component' => str_replace(
                    '@courierstars/quickbooks-integration-engine',
                    '@unchartedwaters/quickbooks-engine',
                    (string) $row->component
                ),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('dashboard_widgets')) {
            return;
        }

        $rows = DB::table('dashboard_widgets')
            ->where('component', 'like', '%@unchartedwaters/quickbooks-engine:widget/quickbooks-sync%')
            ->get(['id', 'component']);

        foreach ($rows as $row) {
            DB::table('dashboard_widgets')->where('id', $row->id)->update([
                'component' => str_replace(
                    '@unchartedwaters/quickbooks-engine',
                    '@courierstars/quickbooks-integration-engine',
                    (string) $row->component
                ),
            ]);
        }
    }
};
