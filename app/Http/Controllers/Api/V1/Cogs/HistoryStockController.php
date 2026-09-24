<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Cogs\HistoryStockService;
use App\Support\OutletScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HistoryStockController extends CogsBaseController
{
    public function __construct(private readonly HistoryStockService $history)
    {
    }

    public function catalogs(Request $request): JsonResponse
    {
        $scopeOutletId = OutletScope::id($request);

        $supplierQuery = DB::table('cogs_purchasing_cost_snapshots as pcs')
            ->whereNotNull('pcs.supplier_source_id');
        $skuQuery = DB::table('cogs_purchasing_cost_snapshots as pcs');
        $movementTypeQuery = DB::table('stk_inventory_movements as im');

        if ($scopeOutletId) {
            $supplierQuery->where('pcs.outlet_id', $scopeOutletId);
            $skuQuery->where('pcs.outlet_id', $scopeOutletId);
            $movementTypeQuery->where('im.outlet_id', $scopeOutletId);
        }

        $movementLabels = [
            'goods_receipt' => 'Goods Receipt',
            'sale_consumption' => 'Sale Consumption',
            'sale_consumption_reversal' => 'Sale Reversal',
            'stock_variance' => 'Stock Variance',
            'manual_adjustment' => 'Manual Adjustment',
        ];
        $actualMovementTypes = $movementTypeQuery->distinct()->orderBy('movement_type')->pluck('movement_type')
            ->filter()->map(fn ($type) => (string) $type)->all();
        $movementTypes = collect(array_unique(array_merge(array_keys($movementLabels), $actualMovementTypes)))
            ->map(fn ($type) => [
                'value' => $type,
                'label' => $movementLabels[$type] ?? ucwords(str_replace('_', ' ', $type)),
            ])->values()->all();

        return ApiResponse::ok([
            'scope' => [
                'mode' => $scopeOutletId ? 'outlet' : 'global',
                'outlet_id' => $scopeOutletId,
                'locked' => (bool) $request->attributes->get('outlet_scope_locked', false),
                'can_adjust' => (bool) $request->attributes->get('outlet_scope_can_adjust', false),
            ],
            // Catalog labels come from immutable snapshots, not live masters. Renaming or
            // deactivating a supplier/SKU therefore never rewrites historical evidence.
            'suppliers' => $supplierQuery
                ->select([
                    'pcs.supplier_source_id as id',
                    'pcs.supplier_code_snapshot as code',
                    'pcs.supplier_name_snapshot as name',
                    'pcs.supplier_type_snapshot as source_type',
                ])
                ->distinct()
                ->orderBy('pcs.supplier_name_snapshot')
                ->get()
                ->map(fn ($supplier) => [
                    'id' => $supplier->id ? (string) $supplier->id : null,
                    'code' => (string) ($supplier->code ?? ''),
                    'name' => (string) ($supplier->name ?? 'Supplier legacy'),
                    'source_type' => (string) ($supplier->source_type ?? ''),
                    'is_active' => true,
                ])->all(),
            'skus' => $skuQuery
                ->select([
                    'pcs.sku_id as id',
                    'pcs.sku_code_snapshot as sku_code',
                    'pcs.sku_name_snapshot as name',
                    'pcs.base_uom_code_snapshot as uom_code',
                    'pcs.base_uom_symbol_snapshot as uom_symbol',
                ])
                ->distinct()
                ->orderBy('pcs.sku_name_snapshot')
                ->get()
                ->map(fn ($sku) => [
                    'id' => (string) $sku->id,
                    'sku_code' => (string) ($sku->sku_code ?? ''),
                    'name' => (string) ($sku->name ?? ''),
                    'uom_code' => $sku->uom_code,
                    'uom_symbol' => $sku->uom_symbol,
                    'is_active' => true,
                ])->all(),
            'movement_types' => $movementTypes,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        return ApiResponse::ok($this->history->receipts($filters, OutletScope::id($request)));
    }

    public function show(Request $request, string $receiptId): JsonResponse
    {
        $detail = $this->history->receiptDetail($receiptId, OutletScope::id($request));
        if (! $detail) {
            return ApiResponse::error('History Goods Receipt tidak ditemukan pada scope pengguna.', 'NOT_FOUND', 404);
        }

        return ApiResponse::ok($detail);
    }

    public function movements(Request $request): JsonResponse
    {
        $filters = $this->validatedMovementFilters($request);
        return ApiResponse::ok($this->history->movements($filters, OutletScope::id($request)));
    }

    public function timeline(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => ['required', 'string', 'exists:outlets,id'],
            'sku_id' => ['required', 'string', 'exists:stk_skus,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $this->guardDateRange($validated['date_from'] ?? null, $validated['date_to'] ?? null);

        $rows = $this->history->averageCostTimeline(
            $validated['outlet_id'],
            $validated['sku_id'],
            $validated,
            OutletScope::id($request),
        );

        if (OutletScope::id($request) && $rows === [] && OutletScope::id($request) !== $validated['outlet_id']) {
            return ApiResponse::error('Outlet tidak termasuk scope pengguna.', 'OUTLET_SCOPE_FORBIDDEN', 403);
        }

        return ApiResponse::ok(['items' => $rows]);
    }

    public function traceability(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->history->traceabilitySummary(OutletScope::id($request)));
    }

    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'dataset' => ['required', Rule::in(['receipts', 'movements'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'receipt_type' => ['nullable', Rule::in(['warehouse', 'manual'])],
            'supplier_source_id' => ['nullable', 'string', 'max:40'],
            'sku_id' => ['nullable', 'string', 'exists:stk_skus,id'],
            'movement_type' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $this->guardDateRange($validated['date_from'] ?? null, $validated['date_to'] ?? null);

        $dataset = $validated['dataset'];
        $scopeOutletId = OutletScope::id($request);
        $fileName = sprintf('history-stock-%s-%s.csv', $dataset, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($dataset, $validated, $scopeOutletId): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");

            if ($dataset === 'receipts') {
                fputcsv($handle, [
                    'Receipt Date', 'GR Number', 'Receipt Type', 'Release Status', 'Outlet Code', 'Outlet Name',
                    'Supplier Code', 'Supplier Name', 'Stock Request', 'PO Number', 'Shipment Code', 'Supplier Document',
                    'SKU Code', 'SKU Name', 'Base UOM', 'Ordered Qty', 'Received Qty', 'Unit Cost',
                    'Line Total', 'Currency', 'Average Cost Before', 'Average Cost After',
                    'Balance Qty After', 'Inventory Value After', 'Traceability', 'Released At',
                ]);
                foreach ($this->history->receiptCsvRows($validated, $scopeOutletId) as $row) {
                    fputcsv($handle, $row);
                }
            } else {
                fputcsv($handle, [
                    'Business Date', 'Outlet Code', 'Outlet Name', 'SKU Code', 'SKU Name', 'Base UOM',
                    'Movement Type', 'Quantity', 'Unit Cost', 'Total Cost', 'Balance Qty After',
                    'Average Cost After', 'Inventory Value After', 'Source Document', 'Stock Request',
                    'PO Number', 'Supplier', 'Reference Type', 'Reference ID', 'Created At',
                ]);
                foreach ($this->history->movementCsvRows($validated, $scopeOutletId) as $row) {
                    fputcsv($handle, $row);
                }
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'receipt_type' => ['nullable', Rule::in(['warehouse', 'manual'])],
            'supplier_source_id' => ['nullable', 'string', 'max:40'],
            'sku_id' => ['nullable', 'string', 'exists:stk_skus,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $this->guardDateRange($validated['date_from'] ?? null, $validated['date_to'] ?? null);
        return $validated;
    }

    private function validatedMovementFilters(Request $request): array
    {
        $validated = $request->validate([
            'outlet_id' => ['nullable', 'string', 'exists:outlets,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'movement_type' => ['nullable', 'string', 'max:40'],
            'sku_id' => ['nullable', 'string', 'exists:stk_skus,id'],
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $this->guardDateRange($validated['date_from'] ?? null, $validated['date_to'] ?? null);

        $scopeOutletId = OutletScope::id($request);
        if ($scopeOutletId && ! empty($validated['outlet_id']) && $scopeOutletId !== $validated['outlet_id']) {
            abort(403, 'Outlet tidak termasuk scope pengguna.');
        }
        return $validated;
    }

    private function guardDateRange(?string $from, ?string $to): void
    {
        if (! $from || ! $to) {
            return;
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw ValidationException::withMessages([
                'date_to' => ['Rentang tanggal maksimal 366 hari.'],
            ]);
        }
    }
}
