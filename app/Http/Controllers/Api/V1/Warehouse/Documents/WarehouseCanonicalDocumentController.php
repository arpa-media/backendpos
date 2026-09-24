<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Documents;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\Documents\WarehouseCanonicalDocumentService;
use App\Services\Warehouse\Documents\WarehouseCanonicalPdfRenderer;
use App\Services\Warehouse\WarehouseReceivingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WarehouseCanonicalDocumentController extends Controller
{
    public function __construct(
        private readonly WarehouseCanonicalDocumentService $documents,
        private readonly WarehouseCanonicalPdfRenderer $pdf,
        private readonly WarehouseReceivingService $receiving,
    ) {}

    public function purchaseRequest(Request $request, string $id): JsonResponse { return $this->json($this->documents->purchaseRequest($this->warehouseId($request), $id)); }
    public function purchaseOrder(Request $request, string $id): JsonResponse { return $this->json($this->documents->purchaseOrder($this->warehouseId($request), $id)); }
    public function goodsReceipt(Request $request, string $id): JsonResponse { return $this->json($this->documents->goodsReceipt($this->warehouseId($request), $id)); }
    public function treasury(Request $request): JsonResponse
    {
        return $this->json($this->documents->treasury($this->treasuryWarehouseId($request), $this->treasuryType($request), $this->routeId($request)));
    }

    public function purchaseRequestPdf(Request $request, string $id): Response { return $this->pdfResponse($this->documents->purchaseRequest($this->warehouseId($request), $id)); }
    public function purchaseOrderPdf(Request $request, string $id): Response { return $this->pdfResponse($this->documents->purchaseOrder($this->warehouseId($request), $id)); }
    public function goodsReceiptPdf(Request $request, string $id): Response
    {
        $warehouseId = $this->warehouseId($request);
        $this->receiving->markPrinted($id, null, $warehouseId, (string) $request->user()->id);
        return $this->pdfResponse($this->documents->goodsReceipt($warehouseId, $id));
    }
    public function treasuryPdf(Request $request): Response
    {
        return $this->pdfResponse($this->documents->treasury($this->treasuryWarehouseId($request), $this->treasuryType($request), $this->routeId($request)));
    }

    private function json(array $document): JsonResponse
    {
        return response()->json(['data' => $document]);
    }

    private function pdfResponse(array $document): Response
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($document['number'] ?? 'warehouse_document')).'.pdf';
        return response($this->pdf->render([$document]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }


    private function treasuryWarehouseId(Request $request): string
    {
        $id = $this->routeId($request);
        $owner = trim((string) \Illuminate\Support\Facades\DB::table('wh_v4_treasury_transactions')->where('id', $id)->value('warehouse_id'));
        abort_if($owner === '', 404, 'Dokumen Treasury Warehouse tidak ditemukan.');
        $scope = (array) $request->attributes->get('warehouse_scope', []);
        $allowed = collect($scope['warehouses'] ?? [])->pluck('id')->filter()->map(fn ($v) => (string) $v)->all();
        if ($allowed !== [] && ! in_array($owner, $allowed, true)) abort(403, 'Warehouse dokumen tidak termasuk scope akses Anda.');
        return $owner;
    }

    private function routeId(Request $request): string
    {
        $id = trim((string) $request->route('id', ''));
        abort_if($id === '', 404);
        return $id;
    }

    private function treasuryType(Request $request): string
    {
        $type = (string) $request->route('type', '');
        abort_unless(in_array($type, ['cash_in','cash_out','bank_in','bank_out'], true), 404);
        return $type;
    }

    private function warehouseId(Request $request): string
    {
        foreach (['warehouse_scope_id','warehouse_id'] as $key) {
            $value = trim((string) $request->attributes->get($key, ''));
            if ($value !== '') return $value;
        }
        $scope = (array) $request->attributes->get('warehouse_scope', []);
        if (isset($scope['selected']->id)) return (string) $scope['selected']->id;
        abort(422, 'Pilih Warehouse terlebih dahulu.');
    }
}
