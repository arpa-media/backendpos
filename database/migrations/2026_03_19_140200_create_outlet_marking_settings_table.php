<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('outlet_marking_settings')) {
            Schema::create('outlet_marking_settings', function (Blueprint $table) {
                $table->id();
                $table->ulid('outlet_id')->unique();
                $table->string('status', 20)->default('NORMAL');
                $table->unsignedInteger('interval_value')->nullable();
                $table->unsignedBigInteger('sequence_counter')->default(0);
                $table->timestamps();

                $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
                $table->index(['status', 'outlet_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_marking_settings');
    }
};
