<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_sync_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('contract');
            $table->unsignedInteger('version')->default(1);
            $table->string('snapshot_checksum')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->boolean('is_dry_run')->default(false);
            $table->longText('summary_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_sync_runs');
    }
};
