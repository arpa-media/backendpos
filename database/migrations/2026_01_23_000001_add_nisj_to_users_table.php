<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NISJ (Nomor Induk Squad Jaya) untuk login.
            // Nullable untuk data lama; untuk user baru wajib diisi lewat request/seeder.
            $table->string('nisj', 32)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nisj']);
            $table->dropColumn('nisj');
        });
    }
};
