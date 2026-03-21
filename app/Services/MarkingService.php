<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\OutletMarkingSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkingService
{
    public const STATUS_NORMAL = 'NORMAL';
    public const STATUS_NON_ACTIVE = 'NON_ACTIVE';
    public const STATUS_ACTIVE = 'ACTIVE';

    public function normalizeStatus(?string $status): string
    {
        $value = strtoupper(trim((string) $status));

        return match ($value) {
            'AKTIF', 'ACTIVE' => self::STATUS_ACTIVE,
            'NON AKTIF', 'NON_AKTIF', 'NONACTIVE', 'NON_ACTIVE', 'INACTIVE' => self::STATUS_NON_ACTIVE,
            default => self::STATUS_NORMAL,
        };
    }

    public function getSetting(string $outletId): OutletMarkingSetting
    {
        return OutletMarkingSetting::query()->firstOrCreate(
            ['outlet_id' => $outletId],
            [
                'status' => self::STATUS_NORMAL,
                'interval_value' => null,
                'sequence_counter' => 0,
            ]
        );
    }


    public function getConfigPayloadForOutlet(string $outletId): array
    {
        $outlet = Outlet::query()
            ->select(['id', 'code', 'name', 'type', 'is_active'])
            ->whereKey($outletId)
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->first();

        if (!$outlet) {
            throw ValidationException::withMessages([
                'outlet_id' => ['Outlet not found.'],
            ]);
        }

        return [
            'outlet' => [
                'id' => (string) $outlet->id,
                'code' => (string) $outlet->code,
                'name' => (string) $outlet->name,
                'type' => (string) ($outlet->type ?? 'outlet'),
                'is_active' => (bool) ($outlet->is_active ?? true),
            ],
            'config' => $this->getConfigPayload($outletId),
        ];
    }

    public function getConfigsForAllOutlets(): array
    {
        $outlets = Outlet::query()
            ->select(['id', 'code', 'name', 'type', 'is_active'])
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->orderBy('name')
            ->get();

        $settings = OutletMarkingSetting::query()
            ->whereIn('outlet_id', $outlets->pluck('id'))
            ->get()
            ->keyBy(fn ($row) => (string) $row->outlet_id);

        return $outlets->map(function ($outlet) use ($settings) {
            $setting = $settings->get((string) $outlet->id);

            return [
                'outlet' => [
                    'id' => (string) $outlet->id,
                    'code' => (string) $outlet->code,
                    'name' => (string) $outlet->name,
                    'type' => (string) ($outlet->type ?? 'outlet'),
                    'is_active' => (bool) ($outlet->is_active ?? true),
                ],
                'config' => [
                    'status' => (string) ($setting?->status ?? self::STATUS_NORMAL),
                    'interval' => $setting?->interval_value ? (int) $setting->interval_value : null,
                    'sequence_counter' => (int) ($setting?->sequence_counter ?? 0),
                ],
            ];
        })->values()->all();
    }

    public function getConfigPayload(string $outletId): array
    {
        $setting = $this->getSetting($outletId);

        return [
            'status' => (string) $setting->status,
            'interval' => $setting->interval_value ? (int) $setting->interval_value : null,
            'sequence_counter' => (int) ($setting->sequence_counter ?? 0),
        ];
    }

    public function determineNextMarking(string $outletId): int
    {
        $setting = OutletMarkingSetting::query()->where('outlet_id', $outletId)->lockForUpdate()->first();
        if (!$setting) {
            $setting = OutletMarkingSetting::query()->create([
                'outlet_id' => $outletId,
                'status' => self::STATUS_NORMAL,
                'interval_value' => null,
                'sequence_counter' => 0,
            ]);
        }

        $status = $this->normalizeStatus($setting->status);

        if ($status === self::STATUS_NON_ACTIVE) {
            return 0;
        }

        if ($status === self::STATUS_NORMAL) {
            return 1;
        }

        $interval = max(1, (int) ($setting->interval_value ?? 1));
        $sequence = (int) ($setting->sequence_counter ?? 0);
        $blockIndex = intdiv($sequence, $interval);
        $marking = ($blockIndex % 2 === 0) ? 1 : 0;

        $setting->sequence_counter = $sequence + 1;
        $setting->save();

        return $marking;
    }

    public function applyMode(string $outletId, string $status, ?int $interval = null): array
    {
        $normalized = $this->normalizeStatus($status);
        $interval = $normalized === self::STATUS_ACTIVE ? max(1, (int) ($interval ?? 1)) : null;

        if ($normalized === self::STATUS_ACTIVE && (int) $interval <= 0) {
            throw ValidationException::withMessages([
                'interval' => ['Interval must be greater than 0.'],
            ]);
        }

        return DB::transaction(function () use ($outletId, $normalized, $interval) {
            $setting = OutletMarkingSetting::query()->lockForUpdate()->firstOrCreate(
                ['outlet_id' => $outletId],
                ['status' => self::STATUS_NORMAL, 'interval_value' => null, 'sequence_counter' => 0]
            );

            $setting->status = $normalized;
            $setting->interval_value = $interval;
            if ($normalized !== self::STATUS_ACTIVE) {
                $setting->sequence_counter = 0;
            }
            $setting->save();

            return [
                'status' => $normalized,
                'interval' => $interval,
                'sequence_counter' => (int) ($setting->sequence_counter ?? 0),
                'affected_transactions' => 0,
                'marked_transactions' => 0,
                'applies_to' => 'NEXT_TRANSACTIONS',
            ];
        });
    }

    public function toggleSale(string $outletId, string $saleId): array
    {
        $row = DB::table('sales')
            ->where('outlet_id', $outletId)
            ->where('id', $saleId)
            ->select(['id', 'marking'])
            ->first();

        if (!$row) {
            throw ValidationException::withMessages([
                'sale_id' => ['Sale not found for this outlet.'],
            ]);
        }

        $next = ((int) ($row->marking ?? 0)) === 1 ? 0 : 1;

        DB::table('sales')->where('id', $saleId)->update([
            'marking' => $next,
            'updated_at' => now(),
        ]);

        return [
            'sale_id' => (string) $saleId,
            'marking' => $next,
        ];
    }

    private function resolveMarkingByMode(string $status, ?int $interval, int $sequence): int
    {
        if ($status === self::STATUS_NON_ACTIVE) {
            return 0;
        }

        if ($status === self::STATUS_NORMAL) {
            return 1;
        }

        $size = max(1, (int) ($interval ?? 1));
        $blockIndex = intdiv($sequence, $size);

        return ($blockIndex % 2 === 0) ? 1 : 0;
    }
}
