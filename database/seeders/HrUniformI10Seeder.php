<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HrUniformI10Seeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('HR_uniform_items') || ! Schema::hasTable('HR_uniform_stock_balances')) return;

        $catalogFile = database_path('data/hr_uniform_i10_catalog.php');
        if (! is_file($catalogFile)) {
            throw new \RuntimeException('Catalog Data Uniform I10 tidak ditemukan.');
        }
        $items = require $catalogFile;
        if (! is_array($items)) {
            throw new \RuntimeException('Catalog Data Uniform I10 tidak valid.');
        }

        DB::transaction(function () use ($items): void {
            $now = now();
            foreach ($items as $row) {
                $code = trim((string) ($row['code'] ?? ''));
                $codeKey = $this->codeKey($code);
                if ($code === '' || $codeKey === '') continue;

                $existing = DB::table('HR_uniform_items')->where('code_key', $codeKey)->first();
                $itemId = (string) ($existing->id ?? Str::ulid());
                DB::table('HR_uniform_items')->updateOrInsert(['code_key' => $codeKey], [
                    'id' => $itemId,
                    'code' => $code,
                    'name' => trim((string) ($row['name'] ?? $code)),
                    'company_code' => strtoupper(trim((string) ($row['company_code'] ?? ''))),
                    'item_kind' => strtoupper(trim((string) ($row['item_kind'] ?? 'UNIFORM'))),
                    'size' => ($row['size'] ?? null) !== null ? strtoupper(trim((string) $row['size'])) : null,
                    'low_stock_threshold' => max(0, (int) ($row['low_stock_threshold'] ?? 10)),
                    'source_row' => isset($row['source_row']) ? (int) $row['source_row'] : null,
                    'source_template' => 'TEMPLATE REKAP SERAGAM & ATRIBUT TKJ.xlsx',
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]);

                $balance = DB::table('HR_uniform_stock_balances')->where('uniform_item_id', $itemId)->first();
                if (! $balance) {
                    DB::table('HR_uniform_stock_balances')->insert([
                        'id' => (string) Str::ulid(),
                        'uniform_item_id' => $itemId,
                        'opening_qty' => 0,
                        'inbound_qty' => 0,
                        'outbound_qty' => 0,
                        'current_qty' => 0,
                        'lock_version' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $balance = (object) ['current_qty' => 0];
                }

                if (Schema::hasTable('HR_uniform_stock_alerts')) {
                    $threshold = max(0, (int) ($row['low_stock_threshold'] ?? 10));
                    $currentQty = (int) ($balance->current_qty ?? 0);
                    $alert = DB::table('HR_uniform_stock_alerts')->where('uniform_item_id', $itemId)->first();
                    $isLow = $currentQty <= $threshold;
                    DB::table('HR_uniform_stock_alerts')->updateOrInsert(['uniform_item_id' => $itemId], [
                        'id' => (string) ($alert->id ?? Str::ulid()),
                        'status' => $isLow ? 'OPEN' : 'RESOLVED',
                        'threshold' => $threshold,
                        'current_qty' => $currentQty,
                        'last_movement_id' => $alert->last_movement_id ?? null,
                        'triggered_at' => $isLow ? ($alert->triggered_at ?? $now) : ($alert->triggered_at ?? null),
                        'resolved_at' => $isLow ? null : ($alert->resolved_at ?? $now),
                        'created_at' => $alert->created_at ?? $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    private function codeKey(string $value): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?: ''));
    }
}
