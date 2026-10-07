<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('quickbooks_connections') === false) {
            Schema::create('quickbooks_connections', function (Blueprint $table) {
                $table->char('uuid', 36)->primary();
                $table->char('company_uuid', 36)->index();
                $table->string('realm_id')->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->string('environment')->default('sandbox');
                $table->string('default_item_id')->nullable();
                $table->timestamp('rate_limited_until')->nullable();
                $table->unsignedInteger('last_rate_limit_wait')->nullable();
                $table->boolean('needs_reauth')->default(false);
                $table->string('home_currency', 3)->nullable();
                $table->timestamp('last_batch_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('quickbooks_links') === false) {
            Schema::create('quickbooks_links', function (Blueprint $table) {
                $table->char('uuid', 36)->primary();
                $table->char('company_uuid', 36)->index();
                $table->string('realm_id');
                $table->string('local_type');
                $table->char('local_uuid', 36);
                $table->string('qbo_entity');
                $table->string('qbo_id');
                $table->string('sync_token')->default('0');
                $table->timestamps();
                $table->unique(['company_uuid', 'local_type', 'local_uuid'], 'quickbooks_links_local_unique');
            });
        }

        if (Schema::hasTable('quickbooks_pending_syncs') === false) {
            Schema::create('quickbooks_pending_syncs', function (Blueprint $table) {
                $table->char('uuid', 36)->primary();
                $table->char('company_uuid', 36)->index();
                $table->string('local_type');
                $table->char('local_uuid', 36);
                $table->string('reason')->nullable();
                $table->string('status')->default('pending');
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamps();
                $table->index(['company_uuid', 'status'], 'quickbooks_pending_company_status');
            });
        }

        if (Schema::hasTable('quickbooks_sync_batches') === false) {
            Schema::create('quickbooks_sync_batches', function (Blueprint $table) {
                $table->char('uuid', 36)->primary();
                $table->char('company_uuid', 36)->index();
                $table->string('trigger');
                $table->string('direction')->default('outbound');
                $table->string('status');
                $table->unsignedInteger('created_count')->default(0);
                $table->unsignedInteger('updated_count')->default(0);
                $table->unsignedInteger('aligned_count')->default(0);
                $table->unsignedInteger('voided_count')->default(0);
                $table->unsignedInteger('unmatched_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedInteger('linked_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('quickbooks_sync_attempts') === false) {
            Schema::create('quickbooks_sync_attempts', function (Blueprint $table) {
                $table->char('uuid', 36)->primary();
                $table->char('batch_uuid', 36)->nullable()->index();
                $table->char('company_uuid', 36)->index();
                $table->string('local_type');
                $table->char('local_uuid', 36);
                $table->string('outcome');
                $table->text('error')->nullable();
                $table->json('diff')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_sync_attempts');
        Schema::dropIfExists('quickbooks_sync_batches');
        Schema::dropIfExists('quickbooks_pending_syncs');
        Schema::dropIfExists('quickbooks_links');
        Schema::dropIfExists('quickbooks_connections');
    }
};
