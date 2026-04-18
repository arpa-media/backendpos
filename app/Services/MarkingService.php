<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\OutletMarkingSetting;
use App\Support\AnalyticsResponseCache;
use App\Support\OwnerOverviewCacheVersion;
use App\Support\ReportPortalMarkedScopeVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    public function applyExistingMarking(string $outletId): array
    {
        return DB::transaction(function () use ($outletId) {
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

            $status = $this->normalizeStatus($setting->status);
            $show = $this->resolveShowCount($setting);
            $hide = $this->resolveHideCount($setting);

            $sales = DB::table('sales')
                ->where('outlet_id', $outletId)
                ->where('status', 'PAID')
                ->select(['id'])
                ->orderBy('created_at')
                ->orderBy('sale_number')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $sequence = 0;
            $groups = [0 => [], 1 => []];

            foreach ($sales as $row) {
                $marking = 1;

                if ($status === self::STATUS_NON_ACTIVE) {
                    $marking = 0;
                } elseif ($status === self::STATUS_ACTIVE) {
                    $marking = $this->resolveMarkingByPattern($show, $hide, $sequence);
                    $sequence++;
                }

                $groups[$marking][] = (string) $row->id;
            }

            foreach ($groups as $marking => $ids) {
                if (empty($ids)) {
                    continue;
                }

                DB::table('sales')
                    ->whereIn('id', $ids)
                    ->update([
                        'marking' => (int) $marking,
                        'updated_at' => now(),
                    ]);

                $this->syncBusinessDateMarking($ids, (int) $marking);
            }

            $setting->sequence_counter = $status === self::STATUS_ACTIVE ? $sequence : 0;
            $setting->save();

            $this->bumpReportResponseCache('apply-existing-marking:' . $outletId);
            $this->bumpOwnerOverviewCacheVersion('apply-existing-marking:' . $outletId);
            $this->bumpSalesReportMarkedScopeVersion('apply-existing-marking:' . $outletId);

            return [
                ...$this->buildConfigPayload($setting),
                'affected_transactions' => count($groups[0]) + count($groups[1]),
                'marked_transactions' => count($groups[1]),
                'applies_to' => 'EXISTING_TRANSACTIONS',
                'action' => 'APPLY_EXISTING_MARKING',
            ];
        });
    }

    public function removeAllMarking(string $outletId): array
    {
        return DB::transaction(function () use ($outletId) {
            $sales = DB::table('sales')
                ->where('outlet_id', $outletId)
                ->where('status', 'PAID')
                ->select(['id'])
                ->lockForUpdate()
                ->get();

            $ids = $sales->pluck('id')->map(fn ($id) => (string) $id)->filter()->values()->all();

            if ($ids !== []) {
                DB::table('sales')
                    ->whereIn('id', $ids)
                    ->update([
                        'marking' => 1,
                        'updated_at' => now(),
                    ]);

                $this->syncBusinessDateMarking($ids, 1);
            }

            $this->bumpReportResponseCache('remove-all-marking:' . $outletId);
            $this->bumpOwnerOverviewCacheVersion('remove-all-marking:' . $outletId);
            $this->bumpSalesReportMarkedScopeVersion('remove-all-marking:' . $outletId);

            return [
                ...$this->buildConfigPayload($this->getSetting($outletId)),
                'affected_transactions' => count($ids),
                'marked_transactions' => count($ids),
                'applies_to' => 'EXISTING_TRANSACTIONS',
                'action' => 'REMOVE_MARKING',
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

        $this->syncBusinessDateMarking([$saleId], $next);
        $this->bumpReportResponseCache('toggle-marking:' . $saleId . ':' . $next);
        $this->bumpOwnerOverviewCacheVersion('toggle-marking:' . $saleId . ':' . $next);
        $this->bumpSalesReportMarkedScopeVersion('toggle-marking:' . $saleId . ':' . $next);
        $this->triggerImmediateReportRefresh($saleId);

        return [
            'sale_id' => (string) $saleId,
            'marking' => $next,
        ];
    }

    private function triggerImmediateReportRefresh(?string $saleId): void
    {
        $saleId = trim((string) ($saleId ?? ''));
        if ($saleId === '') {
            return;
        }

        try {
            app(ReportImmediateRefreshBridge::class)->refreshForSaleId($saleId);
        } catch (\Throwable $e) {
            Log::warning('Marking toggle saved but immediate report refresh failed.', [
                'sale_id' => $saleId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncBusinessDateMarking(array $saleIds, int $marking): void
    {
        $saleIds = array_values(array_filter(array_map(fn ($id) => trim((string) $id), $saleIds)));
        if ($saleIds === [] || ! Schema::hasTable('report_sale_business_dates')) {
            return;
        }

        $update = ['marking' => $marking];
        try {
            $columns = array_flip(Schema::getColumnListing('report_sale_business_dates'));
            if (isset($columns['updated_at'])) {
                $update['updated_at'] = now();
            }
        } catch (\Throwable $e) {
            // keep minimal update payload
        }

        DB::table('report_sale_business_dates')
            ->whereIn('sale_id', $saleIds)
            ->update($update);
    }

    private function bumpReportResponseCache(string $reason): void
    {
        try {
            AnalyticsResponseCache::bumpVersion($reason);
        } catch (\Throwable $e) {
            Log::warning('Failed to bump analytics response cache version after marking change.', [
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function bumpOwnerOverviewCacheVersion(string $reason): void
    {
        try {
            OwnerOverviewCacheVersion::bump($reason);
        } catch (\Throwable $e) {
            Log::warning('Failed to bump owner overview cache version after marking change.', [
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function bumpSalesReportMarkedScopeVersion(string $reason): void
    {
        try {
            ReportPortalMarkedScopeVersion::bump($reason);
        } catch (\Throwable $e) {
            Log::warning('Failed to bump sales report portal marked scope version after marking change.', [
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
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

        $show = array_key_exists('show', $payload) ? (int) ($payload['show'] ?? 0) : null;
        $hide = array_key_exists('hide', $payload) ? (int) ($payload['hide'] ?? 0) : null;
        $interval = array_key_exists('interval', $payload) ? (int) ($payload['interval'] ?? 0) : null;

        if ($status === null) {
            if ($active !== null) {
                $status = $active ? self::STATUS_ACTIVE : self::STATUS_NON_ACTIVE;
            } else {
                $status = self::STATUS_NORMAL;
            }
        }

        if ($show === null) {
            $show = $interval ?? 3;
        }
        if ($hide === null) {
            $hide = 1;
        }

        $show = max(1, $show);
        $hide = max(1, $hide);

        if ($status !== self::STATUS_ACTIVE) {
            return [
                'status' => $status,
                'show' => $show,
                'hide' => $hide,
            ];
        }

        if ($show <= 0 || $hide <= 0) {
            throw ValidationException::withMessages([
                'show' => ['Show and hide are required when marking active.'],
                'hide' => ['Show and hide are required when marking active.'],
            ]);
        }

        return [
            'status' => $status,
            'show' => $show,
            'hide' => $hide,
        ];
    }

    private function resolveShowCount(?OutletMarkingSetting $setting): int
    {
        return max(1, (int) ($setting?->show_count ?? $setting?->interval_value ?? 3));
    }

    private function resolveHideCount(?OutletMarkingSetting $setting): int
    {
        return max(1, (int) ($setting?->hide_count ?? 1));
    }

    private function resolveMarkingByPattern(int $show, int $hide, int $sequence): int
    {
        $cycle = max(1, $show + $hide);
        $position = $sequence % $cycle;

        return $position < $show ? 1 : 0;
    }
}
