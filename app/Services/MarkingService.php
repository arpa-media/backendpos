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
                'show_count' => 3,
                'hide_count' => 1,
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
                'config' => $this->buildConfigPayload($setting),
            ];
        })->values()->all();
    }

    public function getConfigPayload(string $outletId): array
    {
        $setting = $this->getSetting($outletId);

        return $this->buildConfigPayload($setting);
    }

    public function determineNextMarking(string $outletId): int
    {
        $setting = OutletMarkingSetting::query()->where('outlet_id', $outletId)->lockForUpdate()->first();
        if (!$setting) {
            $setting = OutletMarkingSetting::query()->create([
                'outlet_id' => $outletId,
                'status' => self::STATUS_NORMAL,
                'interval_value' => null,
                'show_count' => 3,
                'hide_count' => 1,
                'sequence_counter' => 0,
            ]);
        }

        $status = $this->normalizeStatus($setting->status);

        if ($status === self::STATUS_NON_ACTIVE) {
            return 0;
        }

        if ($status !== self::STATUS_ACTIVE) {
            return 1;
        }

        $show = $this->resolveShowCount($setting);
        $hide = $this->resolveHideCount($setting);
        $sequence = (int) ($setting->sequence_counter ?? 0);
        $marking = $this->resolveMarkingByPattern($show, $hide, $sequence);

        $setting->sequence_counter = $sequence + 1;
        $setting->save();

        return $marking;
    }

    public function applyMode(string $outletId, array $payload): array
    {
        $requested = $this->normalizeIncomingPayload($payload);

        return DB::transaction(function () use ($outletId, $requested) {
            $setting = OutletMarkingSetting::query()->lockForUpdate()->firstOrCreate(
                ['outlet_id' => $outletId],
                [
                    'status' => self::STATUS_NORMAL,
                    'interval_value' => null,
                    'show_count' => 3,
                    'hide_count' => 1,
                    'sequence_counter' => 0,
                ]
            );

            $setting->status = $requested['status'];
            $setting->interval_value = $requested['status'] === self::STATUS_ACTIVE ? $requested['show'] : null;
            $setting->show_count = $requested['show'];
            $setting->hide_count = $requested['hide'];
            $setting->sequence_counter = 0;
            $setting->save();

            return [
                ...$this->buildConfigPayload($setting),
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

    private function buildConfigPayload(?OutletMarkingSetting $setting): array
    {
        $status = $this->normalizeStatus($setting?->status);
        $active = $status === self::STATUS_ACTIVE;
        $show = $this->resolveShowCount($setting);
        $hide = $this->resolveHideCount($setting);
        $sequence = (int) ($setting?->sequence_counter ?? 0);

        return [
            'status' => $status,
            'active' => $active,
            'show' => $show,
            'hide' => $hide,
            'interval' => $show,
            'sequence_counter' => $sequence,
            'preview' => $active
                ? sprintf('%d transaksi marking 1, lalu %d transaksi marking 0, berulang.', $show, $hide)
                : 'Aktif mati. Semua transaksi baru marking 1.',
        ];
    }

    private function normalizeIncomingPayload(array $payload): array
    {
        $status = array_key_exists('status', $payload)
            ? $this->normalizeStatus((string) ($payload['status'] ?? ''))
            : null;

        $active = array_key_exists('active', $payload)
            ? filter_var($payload['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($active === true) {
            $status = self::STATUS_ACTIVE;
        } elseif ($active === false && $status !== self::STATUS_NON_ACTIVE) {
            $status = self::STATUS_NORMAL;
        }

        $status = $status ?: self::STATUS_NORMAL;
        $legacyInterval = isset($payload['interval']) ? max(1, (int) $payload['interval']) : null;
        $show = isset($payload['show']) ? max(1, (int) $payload['show']) : $legacyInterval;
        $hide = isset($payload['hide']) ? max(1, (int) $payload['hide']) : $legacyInterval;

        if ($status === self::STATUS_ACTIVE) {
            if (!$show || !$hide) {
                throw ValidationException::withMessages([
                    'show' => ['Show and hide are required when marking active.'],
                    'hide' => ['Show and hide are required when marking active.'],
                ]);
            }
        }

        if ($status !== self::STATUS_ACTIVE) {
            $show = $show ?: 3;
            $hide = $hide ?: 1;
        }

        return [
            'status' => $status,
            'show' => max(1, (int) ($show ?: 3)),
            'hide' => max(1, (int) ($hide ?: 1)),
        ];
    }

    private function resolveShowCount(?OutletMarkingSetting $setting): int
    {
        return max(1, (int) ($setting?->show_count ?? $setting?->interval_value ?? 3));
    }

    private function resolveHideCount(?OutletMarkingSetting $setting): int
    {
        return max(1, (int) ($setting?->hide_count ?? $setting?->interval_value ?? 1));
    }

    private function resolveMarkingByPattern(int $show, int $hide, int $sequence): int
    {
        $cycle = max(1, $show + $hide);
        $position = $sequence % $cycle;

        return $position < $show ? 1 : 0;
    }
}
