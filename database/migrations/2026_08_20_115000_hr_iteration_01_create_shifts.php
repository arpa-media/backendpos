<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('HR_shifts')) {
            return;
        }

        Schema::create('HR_shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['outlet_id', 'start_time'], 'hr_shift_outlet_start_idx');
            $table->index(['outlet_id', 'name'], 'hr_shift_outlet_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('HR_shifts');
    }
};
