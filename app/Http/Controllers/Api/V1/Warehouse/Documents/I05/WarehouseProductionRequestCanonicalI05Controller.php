<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Documents\I05;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\Documents\I05\WarehouseProductionRequestCanonicalI05Service;
use App\Services\Warehouse\Documents\I05\WarehouseProductionRequestPdfRendererI05;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WarehouseProductionRequestCanonicalI05Controller extends Controller
{
    public function __construct(
        private readonly WarehouseProductionRequestCanonicalI05Service $documents,
        private readonly WarehouseProductionRequestPdfRendererI05 $pdf,
    ) {}

    public function pdf(Request $request, string $id): Response
    {
        $document = $this->documents->document($this->warehouseId($request), $id);
        return $this->response($this->pdf->render([$document]), $this->safe((string) $document['number']).'.pdf');
    }

    public function bulk(Request $request): Response
    {
        $payload = $request->validate(['ids'=>['required','array','min:1','max:50'],'ids.*'=>['required','string','max:64']]);
        $ids = array_values(array_unique(array_map('strval', $payload['ids'])));
        $warehouseId = $this->warehouseId($request);
        $documents = array_map(fn($id) => $this->documents->document($warehouseId, $id), $ids);
        $name = 'warehouse_production_request_bulk_'.now('Asia/Jakarta')->format('Ymd_His').'.pdf';
        return $this->response($this->pdf->render($documents), $name);
    }

    private function response(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="'.$filename.'"',
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    private function safe(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?: 'production_request';
    }

    private function warehouseId(Request $request): string
    {
        $id = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        abort_if($id === '', 422, 'Pilih Warehouse terlebih dahulu.');
        return $id;
    }
}
