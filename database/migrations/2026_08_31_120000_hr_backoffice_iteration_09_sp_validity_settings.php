<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_sp_validity_settings')) {
            Schema::create('HR_sp_validity_settings', function (Blueprint $table): void {
                $table->unsignedTinyInteger('sp_level')->primary();
                $table->unsignedInteger('validity_days');
                $table->ulid('updated_by_user_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('HR_sp_validity_setting_logs')) {
            Schema::create('HR_sp_validity_setting_logs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->unsignedTinyInteger('sp_level')->index();
                $table->unsignedInteger('old_validity_days');
                $table->unsignedInteger('new_validity_days');
                $table->ulid('changed_by_user_id')->nullable()->index();
                $table->timestamp('changed_at')->nullable()->index();
                $table->timestamps();
            });
        }

        $now = now();
        foreach ([1 => 30, 2 => 60, 3 => 90] as $level => $days) {
            if (! DB::table('HR_sp_validity_settings')->where('sp_level', $level)->exists()) {
                DB::table('HR_sp_validity_settings')->insert([
                    'sp_level' => $level,
                    'validity_days' => $days,
                    'updated_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive by design: setting dan audit validity SP dipertahankan.
    }
};
