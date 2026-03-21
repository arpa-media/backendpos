<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('jenis_pajak', 80);
            $table->string('display_name', 120);
            $table->unsignedInteger('percent')->default(0);

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->unsignedInteger('sort_order')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['jenis_pajak'], 'uq_taxes_jenis_pajak');
            $table->index(['is_active', 'is_default'], 'idx_taxes_active_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
