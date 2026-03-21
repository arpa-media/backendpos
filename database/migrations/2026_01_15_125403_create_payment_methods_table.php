<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();

            $table->string('name', 120);
            $table->string('type', 30); // CASH/CARD/QRIS/BANK_TRANSFER/OTHER
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            // PHASE2: sync fields (optional)
            // $table->string('sync_status', 20)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('outlet_id')
                ->references('id')
                ->on('outlets')
                ->cascadeOnDelete();

            // unique name per outlet (practical constraint)
            $table->unique(['outlet_id', 'name']);
            $table->index(['outlet_id', 'type']);
            $table->index(['outlet_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
