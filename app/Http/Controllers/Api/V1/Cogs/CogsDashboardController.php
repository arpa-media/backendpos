<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Outlet;
use App\Services\Cogs\CogsDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CogsDashboardController extends CogsBaseController
{
    public function __construct(private readonly CogsDashboardService $dashboard)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $dateFrom = (string) ($validated['date_from'] ?? $today);
        $dateTo = (string) ($validated['date_to'] ?? $dateFrom);

        if (Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) > 366) {
            return ApiResponse::error(
                'Rentang tanggal maksimal 366 hari.',
                'DATE_RANGE_TOO_LARGE',
                422,
                ['date_to' => ['Rentang tanggal maksimal 366 hari.']]
            );
        }

        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $outlet = Outlet::query()->find($outletId);
        if (! $outlet) {
            return ApiResponse::error('Outlet tidak ditemukan.', 'OUTLET_NOT_FOUND', 404);
        }

        $payload = $this->dashboard->build($outletId, $dateFrom, $dateTo);
        $payload['outlet'] = [
            'id' => (string) $outlet->id,
            'code' => (string) ($outlet->code ?? ''),
            'name' => (string) $outlet->name,
            'timezone' => (string) ($outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
        ];

        return ApiResponse::ok($payload);
    }
}
