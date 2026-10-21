<?php
namespace App\Http\Controllers\Api\V1\Warehouse\PurchasingV3;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\PurchasingV3\WarehousePurchaseOrderDossierV13Service;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WarehousePurchaseOrderDossierV13Controller extends Controller
{
    public function __invoke(Request $request, string $id, WarehousePurchaseOrderDossierV13Service $service): Response
    {
        $warehouseId = trim((string) ($request->attributes->get('warehouse_scope_id') ?: $request->attributes->get('warehouse_id')));
        if ($warehouseId === '') {
            $selected = data_get($request->attributes->get('warehouse_scope', []), 'selected.id');
            $warehouseId = trim((string) $selected);
        }
        abort_if($warehouseId === '', 422, 'Pilih Warehouse sebelum mengunduh berkas.');
        $pdf = $service->render($warehouseId, $id);
        return response($pdf['content'], 200, [
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'inline; filename="'.$pdf['filename'].'"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
