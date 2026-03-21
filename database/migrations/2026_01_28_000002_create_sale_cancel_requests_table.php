<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sale_cancel_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('sale_id')->index();
            $table->ulid('outlet_id')->index();

            $table->ulid('requested_by_user_id')->index();
            $table->string('requested_by_name', 120)->nullable();
            $table->string('reason', 500)->nullable();

            // PENDING | APPROVED | REJECTED
            $table->string('status', 20)->default('PENDING')->index();

            $table->ulid('decided_by_user_id')->nullable()->index();
            $table->string('decided_by_name', 120)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('requested_by_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('decided_by_user_id')->references('id')->on('users')->nullOnDelete();

            // NOTE: We enforce "one PENDING per sale" in application logic.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_cancel_requests');
    }
};
