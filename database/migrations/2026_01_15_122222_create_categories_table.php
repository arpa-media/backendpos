<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();

            $table->string('name', 120);
            $table->string('slug', 140);
            $table->unsignedInteger('sort_order')->default(0);

            // PHASE2: sync fields (optional)
            // $table->string('sync_status', 20)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('outlet_id')
                ->references('id')
                ->on('outlets')
                ->cascadeOnDelete();

            // unique per outlet
            $table->unique(['outlet_id', 'slug']);
            $table->index(['outlet_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
