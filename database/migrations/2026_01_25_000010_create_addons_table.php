<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();

            $table->string('name', 120);
            $table->unsignedBigInteger('price'); // in smallest currency unit (IDR)
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->index(['outlet_id', 'name']);
            $table->unique(['outlet_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};
