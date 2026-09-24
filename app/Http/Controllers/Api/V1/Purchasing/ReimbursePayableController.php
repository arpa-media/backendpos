<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Services\Purchasing\ReimbursePayableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReimbursePayableController extends Controller
{
    public function __construct(private readonly ReimbursePayableService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'in:WAITING_PAYMENT,PAID,CANCELLED'],
            'outlet_id' => ['nullable', 'string', 'max:64'],
            'due_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        return response()->json(['success' => true, 'data' => $this->service->paginate($filters)]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->show($id)]);
    }

    public function confirmPaid(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate([
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'in:BANK_TRANSFER,CASH,PETTY_CASH,GIRO,VIRTUAL_ACCOUNT,OTHER'],
            'reference_number' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);
        return response()->json([
            'success' => true,
            'data' => $this->service->confirmPaid($id, $payload, $request->user()),
            'message' => 'Reimburse berhasil dibayar; recognition dan settlement journal telah POSTED.',
        ]);
    }

    public function receipt(string $executionId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->service->receiptData($executionId)]);
    }
}
