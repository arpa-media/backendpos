<?php

namespace App\Http\Controllers\Api\V1\Warehouse\FinalV3;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\Documents\WarehouseCanonicalBulkPackageService;
use App\Services\Warehouse\FinalV3\WarehouseBulkDocumentPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WarehouseBulkDocumentController extends Controller
{
    public function __construct(
        private readonly WarehouseBulkDocumentPdfService $legacy,
        private readonly WarehouseCanonicalBulkPackageService $canonical,
    ) {}

    public function purchaseRequest(Request $request): Response { return $this->download($request, 'purchase-request'); }
    public function purchaseOrder(Request $request): Response { return $this->download($request, 'purchase-order'); }
    public function stockRequest(Request $request): Response { return $this->download($request, 'stock-request'); }
    public function deliveryOrder(Request $request): Response { return $this->download($request, 'delivery-order'); }
    public function goodsReceipt(Request $request): Response { return $this->download($request, 'goods-receipt'); }

    private function download(Request $request, string $type): Response
    {
        $payload = $request->validate(['ids' => ['required','array','min:1','max:50'], 'ids.*' => ['required','string','max:64']]);
        $warehouseId = $this->warehouseId($request);
        if (in_array($type, ['purchase-request','purchase-order'], true)) {
            $result = $this->canonical->render($warehouseId, $type, $payload['ids']);
        } else {
            $legacy = $this->legacy->render($warehouseId, $type, $payload['ids']);
            $result = $legacy + ['content_type' => 'application/pdf'];
        }
        return response($result['content'], 200, [
            'Content-Type' => $result['content_type'] ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function warehouseId(Request $request): string
    {
        foreach (['warehouse_id','warehouse_scope_id'] as $key) {
            $value = $request->attributes->get($key);
            if (is_string($value) && $value !== '') return $value;
        }
        $scope = (array) $request->attributes->get('warehouse_scope', []);
        if (isset($scope['selected']->id)) return (string) $scope['selected']->id;
        foreach (['warehouse_scope_ids','warehouse_ids'] as $key) {
            $value = collect((array) $request->attributes->get($key, []))->filter()->first();
            if ($value) return (string) $value;
        }
        abort(422, 'Warehouse aktif tidak ditemukan.');
    }
}
