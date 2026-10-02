<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('spreadsheet_transfer_batches')) {
            Schema::create('spreadsheet_transfer_batches', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('module_key', 120);
                $table->string('operation', 16)->default('IMPORT');
                $table->string('status', 24)->default('PENDING');
                $table->string('idempotency_key', 120)->nullable();
                $table->string('original_filename')->nullable();
                $table->string('source_path')->nullable();
                $table->string('output_path')->nullable();
                $table->char('file_sha256', 64)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->unsignedInteger('chunk_size')->default(30);
                $table->unsignedInteger('source_rows')->default(0);
                $table->unsignedInteger('processed_rows')->default(0);
                $table->unsignedInteger('inserted_rows')->default(0);
                $table->unsignedInteger('updated_rows')->default(0);
                $table->unsignedInteger('unchanged_rows')->default(0);
                $table->unsignedInteger('restored_rows')->default(0);
                $table->unsignedInteger('skipped_rows')->default(0);
                $table->unsignedInteger('error_rows')->default(0);
                $table->unsignedInteger('last_offset')->default(0);
                $table->unsignedInteger('current_chunk')->default(0);
                $table->unsignedInteger('total_chunks')->default(0);
                $table->json('metadata_json')->nullable();
                $table->json('result_json')->nullable();
                $table->text('failure_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('heartbeat_at')->nullable();
                $table->timestamp('cancel_requested_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('files_purged_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'module_key', 'operation', 'created_at'], 'spr_batch_user_module_idx');
                $table->index(['status', 'updated_at'], 'spr_batch_status_idx');
                $table->unique(['user_id', 'module_key', 'operation', 'idempotency_key'], 'spr_batch_idempotency_uq');
            });
        }

        if (! Schema::hasTable('spreadsheet_transfer_rows')) {
            Schema::create('spreadsheet_transfer_rows', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('batch_id')->constrained('spreadsheet_transfer_batches')->cascadeOnDelete();
                $table->unsignedInteger('row_number');
                $table->string('row_key', 191)->nullable();
                $table->string('status', 24);
                $table->char('fingerprint', 64)->nullable();
                $table->json('details_json')->nullable();
                $table->json('errors_json')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['batch_id', 'row_number'], 'spr_row_batch_number_uq');
                $table->index(['batch_id', 'status'], 'spr_row_batch_status_idx');
                $table->index(['batch_id', 'row_key'], 'spr_row_batch_key_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_transfer_rows');
        Schema::dropIfExists('spreadsheet_transfer_batches');
    }
};
