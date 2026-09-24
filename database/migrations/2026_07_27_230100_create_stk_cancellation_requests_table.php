<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stk_cancellation_requests')) {
            return;
        }

        Schema::create('stk_cancellation_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('document_type', 30)->index();
            $table->ulid('document_id');
            $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('document_reference', 80)->nullable();
            $table->date('document_date')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignUlid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['document_type', 'document_id', 'status'], 'stk_cancel_document_status_idx');
            $table->index(['outlet_id', 'status', 'requested_at'], 'stk_cancel_outlet_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stk_cancellation_requests');
    }
};
