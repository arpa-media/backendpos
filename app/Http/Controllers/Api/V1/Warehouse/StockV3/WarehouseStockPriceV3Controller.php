<?php

namespace App\Http\Controllers\Api\V1\Warehouse\StockV3;

use App\Http\Controllers\Api\V1\Warehouse\Inventory\WarehouseInventoryBaseController;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehousePricePolicyV3;
use App\Services\Warehouse\Billing\WarehouseBillingUomService;
use App\Services\Warehouse\Billing\WarehouseOutgoingInvoiceRepriceService;
use App\Services\Warehouse\StockV3\WarehouseStockPriceSpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseStockPriceV3Controller extends WarehouseInventoryBaseController
{
    public function options(Request $request, WarehouseStockPriceSpreadsheetService $sheet, WarehouseBillingUomService $billing): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok([
            'outlets' => $sheet->targets($warehouseId, 'outlet'),
            'customers' => $sheet->targets($warehouseId, 'customer'),
            'skus' => $billing->activeSkuCatalog(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $filters = $request->validate([
            'target_type' => ['nullable', Rule::in(['outlet','customer'])], 'target_id' => ['nullable','ulid'],
            'q' => ['nullable','string','max:180'], 'per_page' => ['nullable','integer','min:1','max:200'],
        ]);
        $query = WarehousePricePolicyV3::query()
            ->with(['sku.baseUom:id,code,name,symbol', 'priceUom:id,code,name,symbol'])
            ->where('warehouse_id', $warehouseId);
        if (! empty($filters['target_type'])) $query->where('target_type', $filters['target_type']);
        if (! empty($filters['target_id'])) $query->where('target_id', $filters['target_id']);
        if (! empty($filters['q'])) {
            $q = trim($filters['q']);
            $query->whereHas('sku', fn ($x) => $x->where('sku_code','like',"%{$q}%")->orWhere('name','like',"%{$q}%"));
        }
        $p = $query->orderByDesc('updated_at')->paginate((int) ($filters['per_page'] ?? 50));
        $items = collect($p->items())->map(function ($row) {
            $target = $row->target_type === 'outlet'
                ? \Illuminate\Support\Facades\DB::table('outlets')->where('id',$row->target_id)->first(['code','name'])
                : \Illuminate\Support\Facades\DB::table('wh_customers')->where('id',$row->target_id)->first(['code','name']);
            $baseCode = (string) ($row->sku?->baseUom?->code ?? '');
            $priceCode = (string) ($row->priceUom?->code ?? $row->price_uom_code_snapshot ?? $baseCode);
            return [
                'id' => $row->id, 'target_type' => $row->target_type, 'target_id' => $row->target_id,
                'target_code' => $target->code ?? '-', 'target_name' => $target->name ?? '-',
                'sku_id' => $row->sku_id, 'sku_code' => $row->sku?->sku_code, 'sku_name' => $row->sku?->name,
                'base_uom' => $baseCode,
                'price_uom_id' => $row->price_uom_id,
                'price_uom' => $priceCode,
                'uom' => $priceCode, // compatibility for older page adapters
                'price_conversion_factor' => (float) ($row->price_conversion_factor_snapshot ?: 1),
                'price_basis' => $row->price_basis ?: 'PER_PRICE_UOM',
                'price_uom_review_required' => (bool) $row->price_uom_review_required,
                'price' => (float) $row->price,
                'effective_from' => $row->effective_from?->format('Y-m-d'), 'effective_to' => $row->effective_to?->format('Y-m-d'),
                'is_active' => (bool) $row->is_active, 'updated_at' => $row->updated_at?->toIso8601String(),
            ];
        })->values();
        return ApiResponse::ok(['items'=>$items,'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]]);
    }

    public function upsert(
        Request $request,
        WarehouseStockPriceSpreadsheetService $sheet,
        WarehouseBillingUomService $billing,
        WarehouseOutgoingInvoiceRepriceService $reprice,
    ): JsonResponse {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $data = $request->validate([
            'target_type' => ['required',Rule::in(['outlet','customer'])], 'target_id'=>['required','ulid'],
            'sku_id'=>['required','ulid','exists:stk_skus,id'], 'price_uom_id'=>['required','ulid','exists:stk_uoms,id'],
            'price'=>['required','numeric','min:0'],
            'effective_from'=>['nullable','date'], 'effective_to'=>['nullable','date','after_or_equal:effective_from'], 'is_active'=>['nullable','boolean'],
        ]);
        $validTarget = collect($sheet->targets($warehouseId, $data['target_type']))->contains(fn ($x) => (string) $x->id === (string) $data['target_id']);
        if (! $validTarget) return ApiResponse::error('Target harga tidak aktif atau tidak berada pada scope Warehouse ini.', 'INVALID_TARGET', 422);

        $priceUom = $billing->resolveForSku((string) $data['sku_id'], (string) $data['price_uom_id']);
        $row = WarehousePricePolicyV3::query()->firstOrNew([
            'warehouse_id'=>$warehouseId,'target_type'=>$data['target_type'],'target_id'=>$data['target_id'],'sku_id'=>$data['sku_id'],
        ]);
        $new = ! $row->exists;
        $row->fill([
            'price_uom_id'=>$priceUom['price_uom_id'],
            'price_uom_code_snapshot'=>$priceUom['price_uom_code'],
            'price_conversion_factor_snapshot'=>$priceUom['conversion_factor'],
            'price_basis'=>'PER_PRICE_UOM',
            'price_uom_review_required'=>false,
            'price'=>$data['price'],
            'effective_from'=>$data['effective_from'] ?? now('Asia/Jakarta')->toDateString(),
            'effective_to'=>$data['effective_to'] ?? null,
            'is_active'=>$data['is_active'] ?? true,
            'updated_by_user_id'=>$request->user()?->id,
        ]);
        if ($new) $row->created_by_user_id = $request->user()?->id;
        $row->save();

        $repriced = $reprice->repriceForPolicy($warehouseId, $data['target_type'], (string) $data['target_id'], (string) $data['sku_id'], (string) $request->user()?->id);
        return ApiResponse::ok([
            'id'=>$row->id,
            'price_uom'=>$priceUom,
            'repriced_draft_invoices'=>$repriced,
        ], ($new ? 'Harga berhasil ditambahkan.' : 'Harga berhasil diperbarui.').($repriced > 0 ? " {$repriced} draft invoice diselaraskan ulang." : ''), $new ? 201 : 200);
    }

    public function template(Request $request, WarehouseStockPriceSpreadsheetService $sheet)
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $data = $request->validate(['target_type'=>['required',Rule::in(['outlet','customer'])],'target_id'=>['required','ulid']]);
        return $sheet->template($warehouseId, $data['target_type'], $data['target_id']);
    }

    public function export(Request $request, WarehouseStockPriceSpreadsheetService $sheet)
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $data = $request->validate(['target_type'=>['required',Rule::in(['outlet','customer'])],'target_id'=>['nullable','ulid']]);
        return $sheet->export($warehouseId, $data['target_type'], $data['target_id'] ?? null);
    }

    public function import(Request $request, WarehouseStockPriceSpreadsheetService $sheet): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $data = $request->validate(['file'=>['required','file','mimes:xlsx','max:20480']]);
        $result = $sheet->import($data['file'], $warehouseId, (string) $request->user()?->id);
        return ApiResponse::ok($result, "Import selesai: {$result['inserted']} baru, {$result['updated']} berubah, {$result['skipped']} tidak berubah/skip. {$result['repriced_draft_invoices']} draft invoice diselaraskan.");
    }
}
