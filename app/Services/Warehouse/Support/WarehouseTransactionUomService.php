<?php

namespace App\Services\Warehouse\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WarehouseTransactionUomService
{
    public function catalog(string $skuId): array
    {
        $sku = DB::table('stk_skus as sku')
            ->leftJoin('stk_uoms as base_uom', 'base_uom.id', '=', 'sku.base_uom_id')
            ->leftJoin('stk_uoms as purchase_uom', 'purchase_uom.id', '=', 'sku.purchase_uom_id')
            ->where('sku.id', $skuId)
            ->where('sku.is_active', true)
            ->whereNull('sku.deleted_at')
            ->first([
                'sku.id','sku.sku_code','sku.name','sku.base_uom_id','sku.purchase_uom_id','sku.purchase_conversion_factor',
                'base_uom.code as base_uom_code','base_uom.name as base_uom_name',
                'purchase_uom.code as purchase_uom_code','purchase_uom.name as purchase_uom_name',
            ]);

        if (! $sku) {
            throw ValidationException::withMessages(['sku_id' => ['SKU aktif tidak ditemukan.']]);
        }

        $base = [
            'id' => (string) $sku->base_uom_id,
            'code' => (string) ($sku->base_uom_code ?: 'UNIT'),
            'name' => (string) ($sku->base_uom_name ?: $sku->base_uom_code ?: 'Unit'),
            'conversion_factor' => 1.0,
            'is_base' => true,
            'is_purchase_default' => (string) $sku->purchase_uom_id === (string) $sku->base_uom_id,
            'is_request_enabled' => true,
        ];

        $byId = [(string) $base['id'] => $base];

        if (
            Schema::hasTable('wh_sku_uoms')
            && Schema::hasColumn('wh_sku_uoms', 'sku_id')
            && Schema::hasColumn('wh_sku_uoms', 'uom_id')
            && Schema::hasColumn('wh_sku_uoms', 'conversion_factor')
        ) {
            $query = DB::table('wh_sku_uoms as map')
                ->join('stk_uoms as uom', 'uom.id', '=', 'map.uom_id')
                ->where('map.sku_id', $skuId)
                ->where('map.is_active', true)
                ->where('uom.is_active', true);

            $columns = ['uom.id','uom.code','uom.name','map.conversion_factor'];
            if (Schema::hasColumn('wh_sku_uoms', 'is_purchase_default')) $columns[] = 'map.is_purchase_default';
            if (Schema::hasColumn('wh_sku_uoms', 'is_request_enabled')) $columns[] = 'map.is_request_enabled';

            foreach ($query->get($columns) as $row) {
                $factor = round((float) $row->conversion_factor, 8);
                if ($factor <= 0) continue;

                $id = (string) $row->id;
                $byId[$id] = [
                    'id' => $id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'conversion_factor' => $id === (string) $sku->base_uom_id ? 1.0 : $factor,
                    'is_base' => $id === (string) $sku->base_uom_id,
                    'is_purchase_default' => property_exists($row, 'is_purchase_default')
                        ? (bool) $row->is_purchase_default
                        : $id === (string) $sku->purchase_uom_id,
                    'is_request_enabled' => property_exists($row, 'is_request_enabled')
                        ? (bool) $row->is_request_enabled
                        : true,
                ];
            }
        }

        // Historical dumps do not always contain wh_sku_uoms although SKU-level
        // Purchase UOM is available. Keep Purchase UOM usable as a transaction UOM.
        if (
            $sku->purchase_uom_id
            && $sku->purchase_uom_code
            && (float) $sku->purchase_conversion_factor > 0
        ) {
            $purchaseId = (string) $sku->purchase_uom_id;
            $existing = $byId[$purchaseId] ?? null;
            $byId[$purchaseId] = [
                'id' => $purchaseId,
                'code' => (string) $sku->purchase_uom_code,
                'name' => (string) ($sku->purchase_uom_name ?: $sku->purchase_uom_code),
                'conversion_factor' => $purchaseId === (string) $sku->base_uom_id
                    ? 1.0
                    : round((float) $sku->purchase_conversion_factor, 8),
                'is_base' => $purchaseId === (string) $sku->base_uom_id,
                'is_purchase_default' => true,
                'is_request_enabled' => $existing ? (bool) $existing['is_request_enabled'] : true,
            ];
        }

        // Base UOM must never disappear, even when a bad mapping row exists.
        $byId[(string) $base['id']] = array_merge($byId[(string) $base['id']] ?? [], $base);

        $uoms = array_values($byId);
        usort($uoms, static function (array $a, array $b): int {
            $aRank = $a['is_purchase_default'] ? 0 : ($a['is_base'] ? 1 : 2);
            $bRank = $b['is_purchase_default'] ? 0 : ($b['is_base'] ? 1 : 2);
            if ($aRank !== $bRank) return $aRank <=> $bRank;
            return strcmp((string) $a['code'], (string) $b['code']);
        });

        $requestUoms = array_values(array_filter($uoms, fn (array $row): bool => (bool) $row['is_request_enabled']));
        $defaultRequest = collect($requestUoms)->firstWhere('is_purchase_default', true)
            ?? ($requestUoms[0] ?? $base);

        return [
            'sku_id' => (string) $sku->id,
            'sku_code' => (string) $sku->sku_code,
            'base_uom' => $base,
            'purchase_uom' => collect($uoms)->firstWhere('is_purchase_default', true),
            'default_request_uom_id' => (string) $defaultRequest['id'],
            'default_output_uom_id' => (string) $base['id'],
            'uoms' => $uoms,
        ];
    }

    public function resolve(string $skuId, string $uomId, bool $requestOnly = false): array
    {
        $catalog = $this->catalog($skuId);
        $selected = collect($catalog['uoms'])->first(fn (array $row): bool => (string) $row['id'] === (string) $uomId);

        if (! $selected) {
            throw ValidationException::withMessages(['uom_id' => ['UOM tidak aktif untuk SKU.']]);
        }
        if ($requestOnly && ! $selected['is_request_enabled']) {
            throw ValidationException::withMessages(['uom_id' => ['UOM tidak diaktifkan untuk transaksi request SKU ini.']]);
        }
        if ((float) $selected['conversion_factor'] <= 0) {
            throw ValidationException::withMessages(['uom_id' => ['Conversion factor UOM harus lebih besar dari nol.']]);
        }

        return [
            'uom_id' => (string) $selected['id'],
            'uom_code' => (string) $selected['code'],
            'uom_name' => (string) $selected['name'],
            'conversion_factor' => round((float) $selected['conversion_factor'], 8),
            'base_uom_id' => (string) $catalog['base_uom']['id'],
            'base_uom_code' => (string) $catalog['base_uom']['code'],
            'base_uom_name' => (string) $catalog['base_uom']['name'],
            'is_purchase_default' => (bool) $selected['is_purchase_default'],
            'is_request_enabled' => (bool) $selected['is_request_enabled'],
        ];
    }
}
