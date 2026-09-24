<?php

namespace App\Services\Console;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MaintenanceModeService
{
    private const CACHE_KEY = 'console:maintenance-mode:status:v1';

    public function status(): array
    {
        return Cache::remember(self::CACHE_KEY, 20, function (): array {
            if (! Schema::hasTable('system_maintenance_settings')) {
                return $this->disabledPayload();
            }

            $row = DB::table('system_maintenance_settings as s')
                ->leftJoin('users as u', 'u.id', '=', 's.updated_by_user_id')
                ->where('s.id', 'default')
                ->select([
                    's.enabled',
                    's.message',
                    's.updated_by_user_id',
                    's.updated_at',
                    'u.name as updated_by_name',
                    'u.username as updated_by_username',
                ])
                ->first();

            if (! $row) {
                return $this->disabledPayload();
            }

            return [
                'enabled' => (bool) ($row->enabled ?? false),
                'message' => trim((string) ($row->message ?? '')) ?: 'Sistem sedang dalam proses maintenance.',
                'updated_by_user_id' => $row->updated_by_user_id ? (string) $row->updated_by_user_id : null,
                'updated_by' => trim((string) ($row->updated_by_name ?? $row->updated_by_username ?? '')) ?: null,
                'updated_at' => $row->updated_at ? (string) $row->updated_at : null,
            ];
        });
    }

    public function update(bool $enabled, ?string $message, ?string $userId): array
    {
        if (! Schema::hasTable('system_maintenance_settings')) {
            throw new \RuntimeException('Tabel system_maintenance_settings belum tersedia. Jalankan migration terlebih dahulu.');
        }

        $cleanMessage = trim((string) $message);
        if ($cleanMessage === '') {
            $cleanMessage = 'Sistem sedang dalam proses maintenance.';
        }

        $existing = DB::table('system_maintenance_settings')->where('id', 'default')->first();
        DB::table('system_maintenance_settings')->updateOrInsert(
            ['id' => 'default'],
            [
                'enabled' => $enabled,
                'message' => $cleanMessage,
                'updated_by_user_id' => $userId ?: null,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]
        );

        Cache::forget(self::CACHE_KEY);

        return $this->status();
    }

    private function disabledPayload(): array
    {
        return [
            'enabled' => false,
            'message' => 'Sistem sedang dalam proses maintenance.',
            'updated_by_user_id' => null,
            'updated_by' => null,
            'updated_at' => null,
        ];
    }
}
