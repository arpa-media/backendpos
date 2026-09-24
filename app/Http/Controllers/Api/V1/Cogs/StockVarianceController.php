<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Cogs\StockVariance;
use App\Models\StockInventory\StockOpname;
use App\Services\Cogs\StockVarianceQueryService;
use App\Services\Cogs\StockVarianceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockVarianceController extends CogsBaseController
{
    public function __construct(
        private readonly StockVarianceService $engine,
        private readonly StockVarianceQueryService $queries,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        return ApiResponse::ok($this->queries->overview($outletId, $this->dateFilters($request)));
    }

    public function candidates(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        return ApiResponse::ok($this->queries->candidates($outletId, $this->dateFilters($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        return ApiResponse::ok($this->queries->paginate($outletId, $this->filters($request)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $detail = $this->queries->detail($id, $outletId);
        if (! $detail) {
            return ApiResponse::error('Stock Variance tidak ditemukan pada scope outlet.', 'NOT_FOUND', 404);
        }
        return ApiResponse::ok($detail);
    }

    public function calculate(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $validated = $request->validate([
            'stock_opname_id' => ['required', 'ulid', Rule::exists('stk_stock_opnames', 'id')],
        ]);

        $opname = StockOpname::query()
            ->where('outlet_id', $outletId)
            ->find($validated['stock_opname_id']);
        if (! $opname) {
            return ApiResponse::error('Stock Opname tidak ditemukan pada scope outlet.', 'NOT_FOUND', 404);
        }

        $variance = $this->engine->calculateForOpname(
            $opname,
            $request->user()?->id ? (string) $request->user()->id : null,
            'backoffice_calculation',
        );

        return ApiResponse::ok(
            $this->queries->detail((string) $variance->id, $outletId),
            'Stock Variance berhasil dihitung.',
        );
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $this->normalizeBooleanQuery($request, 'confirm_attention');
        $validated = $request->validate([
            'confirm_attention' => ['nullable', 'boolean'],
        ]);

        $variance = StockVariance::query()->where('outlet_id', $outletId)->find($id);
        if (! $variance) {
            return ApiResponse::error('Stock Variance tidak ditemukan pada scope outlet.', 'NOT_FOUND', 404);
        }

        $variance = $this->engine->submit(
            (string) $variance->id,
            $request->user()?->id ? (string) $request->user()->id : null,
            (bool) ($validated['confirm_attention'] ?? false),
        );

        return ApiResponse::ok(
            $this->queries->detail((string) $variance->id, $outletId),
            'Stock Variance berhasil disubmit dan dikunci.',
        );
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $filters = $this->dateFilters($request);
        $filename = "cogs-stock-variance-{$filters['date_from']}-{$filters['date_to']}.csv";

        return response()->streamDownload(function () use ($outletId, $filters): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Variance Date', 'Status', 'Opening Date', 'SKU Code', 'SKU', 'Base UOM',
                'Opening Actual Qty', 'Goods Receipt Qty', 'Sale Consumption Qty', 'Other Movement Qty',
                'Net Movement Qty', 'Theoretical Qty', 'Actual Qty', 'Variance Qty', 'Average Unit Cost',
                'Shortage Value', 'Surplus Value', 'Net Variance Value', 'Movement Count', 'Warnings',
            ]);
            foreach ($this->queries->csvRows($outletId, $filters) as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function dateFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $this->guardRange($validated['date_from'], $validated['date_to']);
        return $validated;
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                StockVariance::STATUS_CALCULATED,
                StockVariance::STATUS_SUBMITTED,
                StockVariance::STATUS_CANCELLED,
            ])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $this->guardRange($validated['date_from'], $validated['date_to']);
        return $validated;
    }

    private function guardRange(string $from, string $to): void
    {
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw ValidationException::withMessages([
                'date_to' => ['Rentang tanggal maksimal 366 hari.'],
            ]);
        }
    }
}
