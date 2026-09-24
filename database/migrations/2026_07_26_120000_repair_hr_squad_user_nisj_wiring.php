<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_squads') || ! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('HR_squads', 'user_id') || ! Schema::hasColumn('HR_squads', 'nisj') || ! Schema::hasColumn('users', 'nisj')) {
            return;
        }

        // 1. user_id lama yang bertentangan dengan NISJ dilepas. Data user dan Data Squad tidak diubah.
        DB::table('HR_squads')
            ->whereNull('deleted_at')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $squad) {
                    $user = DB::table('users')->where('id', $squad->user_id)->first(['id', 'nisj']);
                    $squadNisj = mb_strtolower(trim((string) ($squad->nisj ?? '')));
                    $userNisj = mb_strtolower(trim((string) ($user->nisj ?? '')));

                    if (! $user || $squadNisj === '' || $userNisj === '' || $squadNisj !== $userNisj) {
                        DB::table('HR_squads')->where('id', $squad->id)->update([
                            'user_id' => null,
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

        // 2. Backfill reference teknis user_id berdasarkan pivot bisnis NISJ.
        DB::table('HR_squads')
            ->whereNull('deleted_at')
            ->whereNotNull('nisj')
            ->whereRaw("TRIM(`nisj`) <> ''")
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $squad) {
                    $nisj = mb_strtolower(trim((string) $squad->nisj));
                    $user = DB::table('users')
                        ->whereNotNull('nisj')
                        ->whereRaw('LOWER(TRIM(`nisj`)) = ?', [$nisj])
                        ->first(['id']);

                    if (! $user) {
                        continue;
                    }

                    // Unique user_id aman: lepaskan salah-link lain sebelum memasang pivot yang benar.
                    DB::table('HR_squads')
                        ->where('user_id', (string) $user->id)
                        ->where('id', '<>', $squad->id)
                        ->update([
                            'user_id' => null,
                            'updated_at' => now(),
                        ]);

                    DB::table('HR_squads')->where('id', $squad->id)->update([
                        'user_id' => (string) $user->id,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Repair data bersifat non-destruktif dan tidak dibalik agar tidak menghidupkan wiring yang salah.
    }
};
