<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Hardening;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseMobileScannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseMobileScannerController extends Controller
{
    public function __construct(private readonly WarehouseMobileScannerService $service)
    {
    }

    public function tasks(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->tasks($this->warehouseId($request), (string) $request->user()->id));
    }

    public function issueTokens(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'workflow' => ['required', Rule::in(array_keys(WarehouseMobileScannerService::WORKFLOWS))],
            'target_id' => ['required', 'string', 'max:40'],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        return ApiResponse::ok($this->service->issueTokens(
            $this->warehouseId($request),
            (string) $request->user()->id,
            $payload['workflow'],
            $payload['target_id'],
            (int) ($payload['count'] ?? 10),
            (string) $request->attributes->get('warehouse_request_id')
        ), 'Signed scan token berhasil diterbitkan.');
    }

    public function scan(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'signed_token' => ['required', 'string', 'max:8000'],
            'barcode' => ['required', 'string', 'max:120'],
            'idempotency_key' => ['required', 'string', 'max:160'],
        ]);
        return ApiResponse::ok($this->service->replayScan(
            $this->warehouseId($request),
            (string) $request->user()->id,
            $payload['signed_token'],
            $payload['barcode'],
            $payload['idempotency_key'],
            (string) $request->attributes->get('warehouse_request_id')
        ), 'Signed mobile scan berhasil diproses.');
    }

    private function warehouseId(Request $request): string
    {
        $warehouseId = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        abort_if($warehouseId === '', 422, 'Warehouse scope belum dipilih.');
        return $warehouseId;
    }
}
