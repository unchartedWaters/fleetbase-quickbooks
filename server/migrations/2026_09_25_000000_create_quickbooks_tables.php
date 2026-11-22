<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->createConnections();
        $this->createLinks();
        $this->createPendingSyncs();
        $this->createSyncBatches();
        $this->createSyncAttempts();
    }

    private function createConnections(): void
    {
        if (Schema::hasTable('quickbooks_connections') === false) {
            Schema::create('quickbooks_connections', function (Blueprint $table) {
                $table->char('uuid', 36)->primary();
                $table->char('company_uuid', 36)->unique();
                $table->string('realm_id')->nullable()->index();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->string('environment')->default('sandbox');
                $table->string('default_item_id')->nullable();
                $table->string('default_item_account')->nullable();
                $table->timestamp('rate_limited_until')->nullable();
                $table->unsignedInteger('last_rate_limit_wait')->nullable();
                $table->boolean('needs_reauth')->default(false);
                $table->string('home_currency', 3)->nullable();
                $table->timestamp('last_batch_at')->nullable();
                $table->unsignedInteger('customer_import_start')->nullable();
                $table->timestamps();
            });
        }
    }

    private function createLinks(): void
    {
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
                $table->string('name')->nullable();
                $table->string('state')->nullable();
                $table->text('invoice_tokens')->nullable();
                $table->string('kept_customer')->nullable();
                $table->string('kept_from')->nullable();
                $table->string('tax_source')->nullable();
                $table->string('refused_tax')->nullable();
                $table->timestamps();
                $table->unique(['company_uuid', 'local_type', 'local_uuid'], 'quickbooks_links_local_unique');
                $table->index(['company_uuid', 'realm_id', 'qbo_id'], 'quickbooks_links_company_realm_qbo');
                $table->index(['realm_id', 'qbo_id'], 'quickbooks_links_realm_qbo');
                $table->index(
                    ['company_uuid', 'realm_id', 'kept_customer'],
                    'quickbooks_links_company_realm_kept_customer_index'
                );
            });
        }
    }

    private function createPendingSyncs(): void
    {
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
                // A running sync leases the rows it loaded; the lease expires by itself when a worker dies.
                $table->timestamp('claimed_until')->nullable();
                $table->string('claimed_by', 64)->nullable();
                // Edits (0) load before the reconcile and catalog backlog (1).
                $table->unsignedTinyInteger('priority')->default(0);
                $table->timestamps();
                $table->index(['company_uuid', 'status'], 'quickbooks_pending_company_status');
                $table->index(
                    ['company_uuid', 'status', 'priority', 'next_attempt_at', 'uuid'],
                    'quickbooks_pending_due'
                );
                $table->index('claimed_by', 'qb_pending_claimed_by_idx');
                $table->index(['status', 'updated_at'], 'qb_pending_status_updated_idx');
            });
            // One open row per record. SQLite takes a stored column only through an added column.
            Schema::table('quickbooks_pending_syncs', function (Blueprint $table) {
                // char(36) + ':' + varchar(255) + ':' + char(36)
                $table->string('open_identity', 329)->nullable()->storedAs(
                    "IF(`status` = 'pending', CONCAT(`company_uuid`, ':', `local_type`, ':', `local_uuid`), NULL)"
                );
            });
            Schema::table('quickbooks_pending_syncs', function (Blueprint $table) {
                $table->unique('open_identity', 'quickbooks_pending_open_unique');
            });
        }
    }

    private function createSyncBatches(): void
    {
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
                // Activity lists a company's batches newest first, the widget reads its latest finished one,
                // and the daily prune walks the table by age.
                $table->index(['company_uuid', 'created_at'], 'qb_batches_company_created_idx');
                $table->index(['company_uuid', 'status', 'finished_at'], 'qb_batches_company_status_finished_idx');
                $table->index('created_at', 'qb_batches_created_idx');
            });
        }
    }

    private function createSyncAttempts(): void
    {
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
                $table->index('created_at', 'qb_attempts_created_idx');
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
