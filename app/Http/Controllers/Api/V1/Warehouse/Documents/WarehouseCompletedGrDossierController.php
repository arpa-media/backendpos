<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Documents;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\Documents\WarehouseCompletedGrDossierService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WarehouseCompletedGrDossierController extends Controller
{
    public function __invoke(Request $request, string $id, WarehouseCompletedGrDossierService $service): Response
    {
        $warehouseId = (string) ($request->attributes->get('warehouse_scope_id') ?: $request->attributes->get('warehouse_id'));
        if ($warehouseId === '') $warehouseId = (string) data_get($request->attributes->get('warehouse_scope', []), 'selected.id', '');
        abort_if($warehouseId === '', 422, 'Pilih Warehouse sebelum mengunduh dokumen.');
        $result = $service->render($warehouseId, $id);
        return response($result['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
