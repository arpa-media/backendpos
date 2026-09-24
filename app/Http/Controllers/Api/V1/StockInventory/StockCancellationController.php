<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\StockCancellationRequest;
use App\Services\StockInventory\StockCancellationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockCancellationController extends StockInventoryBaseController
{
    public function __construct(private readonly StockCancellationService $service)
    {
    }

    public function requestStockRequest(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        return ApiResponse::ok(
            $this->service->requestStockRequestCancellation(
                $id,
                $outletId,
                $validated['reason'],
                (string) $request->user()->id
            ),
            'Pengajuan pembatalan Request Stock dikirim ke Admin.',
            201
        );
    }

    public function requestStockOpname(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        return ApiResponse::ok(
            $this->service->requestStockOpnameCancellation(
                $id,
                $outletId,
                $validated['reason'],
                (string) $request->user()->id
            ),
            'Pengajuan pembatalan Stock Opname dikirim ke Admin.',
            201
        );
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([
                StockCancellationRequest::STATUS_PENDING,
                StockCancellationRequest::STATUS_APPROVED,
                StockCancellationRequest::STATUS_REJECTED,
            ])],
            'document_type' => ['nullable', Rule::in([
                StockCancellationRequest::TYPE_STOCK_REQUEST,
                StockCancellationRequest::TYPE_STOCK_OPNAME,
            ])],
            'outlet_id' => ['nullable', 'string', Rule::exists('outlets', 'id')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = StockCancellationRequest::query()
            ->with(['outlet:id,code,name,timezone', 'requestedBy:id,name,nisj', 'decidedBy:id,name,nisj']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['document_type'])) {
            $query->where('document_type', $validated['document_type']);
        }
        if (! empty($validated['outlet_id'])) {
            $query->where('outlet_id', $validated['outlet_id']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('requested_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('requested_at', '<=', $validated['date_to']);
        }
        if (! empty($validated['q'])) {
            $q = $validated['q'];
            $query->where(function ($inner) use ($q) {
                $inner->where('document_reference', 'like', '%'.$q.'%')
                    ->orWhere('reason', 'like', '%'.$q.'%')
                    ->orWhereHas('outlet', fn ($outlet) => $outlet->where('name', 'like', '%'.$q.'%'));
            });
        }

        $paginator = $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('requested_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::ok([
            'items' => collect($paginator->items())
                ->map(fn (StockCancellationRequest $item) => $this->service->serialize($item))
                ->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $cancellation = StockCancellationRequest::query()->findOrFail($id);

        return ApiResponse::ok($this->service->serialize($cancellation));
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in([
                StockCancellationRequest::STATUS_APPROVED,
                StockCancellationRequest::STATUS_REJECTED,
            ])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::ok(
            $this->service->decide(
                $id,
                $validated['decision'],
                $validated['notes'] ?? null,
                (string) $request->user()->id
            ),
            $validated['decision'] === StockCancellationRequest::STATUS_APPROVED
                ? 'Pembatalan disetujui.'
                : 'Pembatalan ditolak.'
        );
    }
}
