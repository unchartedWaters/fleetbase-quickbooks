<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('quickbooks_connections') === FALSE || Schema::hasColumn('quickbooks_connections', 'customer_import_start') === TRUE) {
            return;
        }

        Schema::table('quickbooks_connections', function (Blueprint $table) {
            $table->unsignedInteger('customer_import_start')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('quickbooks_connections') === FALSE || Schema::hasColumn('quickbooks_connections', 'customer_import_start') === FALSE) {
            return;
        }

        Schema::table('quickbooks_connections', function (Blueprint $table) {
            $table->dropColumn('customer_import_start');
        });
    }
};
