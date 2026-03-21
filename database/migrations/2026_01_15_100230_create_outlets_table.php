<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name', 150);
            $table->text('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('timezone', 64)->default('Asia/Jakarta');

            // PHASE2: sync fields (optional)
            // $table->string('sync_status', 20)->nullable()->index();

            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // User belongs to one outlet (Phase 1 simple)
            $table->ulid('outlet_id')->nullable()->after('id')->index();

            $table->foreign('outlet_id')
                ->references('id')
                ->on('outlets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['outlet_id']);
            $table->dropColumn('outlet_id');
        });

        Schema::dropIfExists('outlets');
    }
};
