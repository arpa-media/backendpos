<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_device_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('outlet_code', 64)->index();
            $table->string('token_hash', 64)->unique();
            $table->string('device_fingerprint', 64)->nullable()->index();
            $table->string('app_variant', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('abilities')->nullable();
            $table->string('last_user_nisj', 64)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_device_tokens');
    }
};
