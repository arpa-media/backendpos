<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Outlet;
use App\Models\StockInventory\StockCancellationRequest;
use App\Models\StockInventory\StockRequest;
use App\Services\StockInventory\StockSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockDashboardController extends StockInventoryBaseController
{
    public function __construct(private readonly StockSnapshotService $snapshots)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $outlet = Outlet::query()->find($outletId);
        if (! $outlet) {
            return ApiResponse::error('Outlet tidak ditemukan.', 'OUTLET_NOT_FOUND', 404);
        }

        $snapshot = $this->snapshots->build($outletId, $validated['date'] ?? null, true);
        $asOfDate = (string) ($snapshot['as_of_date'] ?? $validated['date'] ?? now()->toDateString());

        $requestBase = StockRequest::query()
            ->where('outlet_id', $outletId)
            ->whereDate('request_date', '<=', $asOfDate);

        $submittedTotal = (clone $requestBase)
            ->whereNotIn('status', [StockRequest::STATUS_DRAFT, StockRequest::STATUS_CANCELLED])
            ->count();

        $pendingApproval = (clone $requestBase)
            ->where('status', StockRequest::STATUS_SUBMITTED)
            ->whereDoesntHave('cancellationRequests', function ($query) {
                $query->where('status', StockCancellationRequest::STATUS_PENDING);
            })
            ->count();

        $snapshot['summary'] = array_merge($snapshot['summary'] ?? [], [
            'stock_requests_submitted_total' => $submittedTotal,
            'stock_requests_pending_approval' => $pendingApproval,
        ]);
        $snapshot['outlet'] = [
            'id' => (string) $outlet->id,
            'code' => (string) ($outlet->code ?? ''),
            'name' => (string) $outlet->name,
            'timezone' => (string) ($outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
        ];

        return ApiResponse::ok($snapshot);
    }
}
