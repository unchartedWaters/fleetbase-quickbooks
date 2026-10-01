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
            ->where('component', 'like', '%@courierstars/quickbooks%')
            ->get(['id', 'component']);

        foreach ($rows as $row) {
            $component = str_replace(
                '@courierstars/quickbooks-integration-engine',
                '@unchartedwaters/quickbooks-engine',
                (string) $row->component
            );
            $component = str_replace(
                '@courierstars/quickbooks-engine',
                '@unchartedwaters/quickbooks-engine',
                $component
            );

            DB::table('dashboard_widgets')->where('id', $row->id)->update([
                'component' => $component,
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('dashboard_widgets')) {
            return;
        }

        $rows = DB::table('dashboard_widgets')
            ->where('component', 'like', '%@unchartedwaters/quickbooks-engine%')
            ->get(['id', 'component']);

        foreach ($rows as $row) {
            DB::table('dashboard_widgets')->where('id', $row->id)->update([
                'component' => str_replace(
                    '@unchartedwaters/quickbooks-engine',
                    '@courierstars/quickbooks-engine',
                    (string) $row->component
                ),
            ]);
        }
    }
};
