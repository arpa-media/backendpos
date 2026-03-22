<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_report_outlet_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('portal_code', 100);
            $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'portal_code', 'outlet_id'], 'user_report_outlet_unique');
            $table->index(['portal_code', 'outlet_id'], 'user_report_outlet_portal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_report_outlet_assignments');
    }
};
