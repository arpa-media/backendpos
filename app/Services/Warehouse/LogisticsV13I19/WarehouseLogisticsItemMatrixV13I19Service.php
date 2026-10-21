<?php

namespace App\Services\Warehouse\LogisticsV13I19;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only SKU/outlet matrix across the existing Logistics V3 source-of-truth documents.
 * No stock posting, no projection writes and no schema migrations.
 */
final class WarehouseLogisticsItemMatrixV13I19Service
{
    private const MAP = [
        'checker' => [
            'document' => 'wh_v3_logistics_prepare_requests', 'line' => 'wh_v3_logistics_prepare_items',
            'fk' => 'prepare_request_id', 'number' => 'prepare_number',
        ],
        'delivery-orders' => [
            'document' => 'wh_v3_delivery_orders', 'line' => 'wh_v3_delivery_order_items',
            'fk' => 'delivery_order_id', 'number' => 'delivery_number',
        ],
        'goods-receipts' => [
            'document' => 'wh_v3_goods_receipts', 'line' => 'wh_v3_goods_receipt_items',
            'fk' => 'goods_receipt_id', 'number' => 'goods_receipt_number',
        ],
    ];

    public function matrix(string $warehouseId, string $type, array $filters): array
    {
        if (! isset(self::MAP[$type])) {
            throw ValidationException::withMessages(['type' => 'Jenis rekap tidak dikenal.']);
        }
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');
        if ($from === '' || $to === '') {
            throw ValidationException::withMessages(['from' => 'Tanggal awal dan akhir wajib diisi untuk rekap Item.']);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 93) {
            throw ValidationException::withMessages(['to' => 'Rentang Item maksimal 93 hari agar akses tetap ringan.']);
        }
        $perPage = min(50, max(10, (int) ($filters['per_page'] ?? 25)));
        $base = $this->baseQuery($warehouseId, $type, $filters);
        $metrics = $this->metrics($type);
        $selectMetrics = implode(', ', array_map(fn ($k) => "COALESCE(SUM(i.{$k}),0) as {$k}", $metrics));

        // Page by SKU, never by raw document rows. This prevents missing outlet
        // allocations on the current page and avoids loading historical details in JS.
        $pageQuery = (clone $base)
            ->selectRaw("s.id as sku_id, s.sku_code, s.name as item_name, COALESCE(u.code,'BASE') as base_uom, COUNT(DISTINCT d.id) as document_count, {$selectMetrics}")
            ->groupBy('s.id', 's.sku_code', 's.name', 'u.code')
            ->orderBy('s.name')->orderBy('s.id');
        $page = $pageQuery->paginate($perPage);
        $skuIds = collect($page->items())->pluck('sku_id')->all();
        $outlets = collect();
        if ($skuIds) {
            // Stock requests may target outlets, warehouses or customers. Join
            // outlets for known outlet codes; preserve type/id for all other cases.
            $outlets = (clone $base)
                ->leftJoin('outlets as destination_outlet', 'destination_outlet.id', '=', 'd.destination_id')
                ->whereIn('i.sku_id', $skuIds)
                ->selectRaw("i.sku_id, d.destination_type, d.destination_id, destination_outlet.code as outlet_code, destination_outlet.name as outlet_name, COUNT(DISTINCT d.id) as document_count, {$selectMetrics}")
                ->groupBy('i.sku_id', 'd.destination_type', 'd.destination_id', 'destination_outlet.code', 'destination_outlet.name')
                ->orderBy('destination_outlet.name')->get()->groupBy('sku_id');
        }

        $items = collect($page->items())->map(function ($sku) use ($metrics, $outlets): array {
            $data = [
                'sku_id' => (string) $sku->sku_id, 'sku_code' => (string) $sku->sku_code,
                'item_name' => (string) $sku->item_name, 'base_uom' => (string) $sku->base_uom,
                'document_count' => (int) $sku->document_count,
            ];
            foreach ($metrics as $k) $data[$k] = round((float) $sku->{$k}, 4);
            $data['destinations'] = collect($outlets->get((string) $sku->sku_id, []))->map(function ($r) use ($metrics): array {
                $out = [
                    'destination_type' => (string) $r->destination_type,
                    'destination_id' => (string) $r->destination_id,
                    'destination_code' => $r->outlet_code ? (string) $r->outlet_code : null,
                    'destination_name' => $r->outlet_name ? (string) $r->outlet_name : (string) $r->destination_type.' · '.(string) $r->destination_id,
                    'document_count' => (int) $r->document_count,
                ];
                foreach ($metrics as $k) $out[$k] = round((float) $r->{$k}, 4);
                return $out;
            })->values()->all();
            return $data;
        })->all();

        return [
            'resource' => $type, 'unit' => 'BASE_UOM',
            'date_range' => ['from' => $from, 'to' => $to],
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total_skus' => $page->total(),
            ],
        ];
    }

    /** @return list<string> */
    private function metrics(string $type): array
    {
        return match ($type) {
            'checker' => ['requested_qty_base', 'approved_qty_base', 'ready_qty_base'],
            'delivery-orders' => ['requested_qty_base', 'approved_qty_base', 'sent_qty_base'],
            default => ['requested_qty_base', 'sent_qty_base', 'received_qty_base', 'not_received_qty_base'],
        };
    }

    private function baseQuery(string $warehouseId, string $type, array $filters): Builder
    {
        $spec = self::MAP[$type];
        $query = DB::table($spec['document'].' as d')
            ->join($spec['line'].' as i', 'i.'.$spec['fk'], '=', 'd.id')
            ->join('stk_skus as s', 's.id', '=', 'i.sku_id')
            ->leftJoin('stk_uoms as u', 'u.id', '=', 's.base_uom_id')
            ->where('d.warehouse_id', $warehouseId);

        if ($type === 'goods-receipts') {
            $query->join('wh_v3_delivery_orders as source_do', 'source_do.id', '=', 'd.delivery_order_id');
        }
        if ($type === 'checker') {
            $query->leftJoin('stk_requests as source_request', function ($join): void {
                $join->on('source_request.id', '=', 'd.source_id')
                    ->where('d.source_type', '=', 'stock_request');
            });
        }
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $number = 'd.'.$spec['number'];
            $query->where(function ($q) use ($term, $number, $type): void {
                $q->where($number, 'like', $term)
                    ->orWhere('s.name', 'like', $term)
                    ->orWhere('s.sku_code', 'like', $term);
                if ($type === 'checker' || $type === 'delivery-orders') {
                    $q->orWhere('d.source_number', 'like', $term);
                } else {
                    $q->orWhere('source_do.delivery_number', 'like', $term)
                        ->orWhere('source_do.destination_name_snapshot', 'like', $term);
                }
            });
        }
        if (! empty($filters['status'])) $query->where('d.status', (string) $filters['status']);

        $from = (string) $filters['from'];
        $to = (string) $filters['to'];
        $fromUtc = Carbon::parse($from, 'Asia/Jakarta')->startOfDay()->utc()->format('Y-m-d H:i:s');
        $untilUtc = Carbon::parse($to, 'Asia/Jakarta')->startOfDay()->addDay()->utc()->format('Y-m-d H:i:s');
        if ($type === 'checker') {
            $query->where(function ($q) use ($from, $fromUtc): void {
                $q->where(fn ($s) => $s->where('d.source_type', 'stock_request')->where('source_request.request_date', '>=', $from))
                  ->orWhere(fn ($s) => $s->where('d.source_type', '!=', 'stock_request')->where('d.created_at', '>=', $fromUtc));
            })->where(function ($q) use ($to, $untilUtc): void {
                $q->where(fn ($s) => $s->where('d.source_type', 'stock_request')->where('source_request.request_date', '<=', $to))
                  ->orWhere(fn ($s) => $s->where('d.source_type', '!=', 'stock_request')->where('d.created_at', '<', $untilUtc));
            });
        } elseif ($type === 'delivery-orders') {
            $query->whereRaw('COALESCE(d.dispatched_at,d.created_at) >= ?', [$fromUtc])
                ->whereRaw('COALESCE(d.dispatched_at,d.created_at) < ?', [$untilUtc]);
        } else {
            $query->where(fn ($q) => $q->where(fn ($s) => $s->whereNotNull('d.receipt_date')->where('d.receipt_date', '>=', $from))
                ->orWhere(fn ($s) => $s->whereNull('d.receipt_date')->where('d.created_at', '>=', $fromUtc)))
                ->where(fn ($q) => $q->where(fn ($s) => $s->whereNotNull('d.receipt_date')->where('d.receipt_date', '<=', $to))
                ->orWhere(fn ($s) => $s->whereNull('d.receipt_date')->where('d.created_at', '<', $untilUtc)));
        }
        return $query;
    }
}
