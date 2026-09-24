<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Cogs\CogsCalculationRun;
use App\Services\Cogs\CogsCalculationQueryService;
use App\Services\Cogs\CogsCalculationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CogsCalculationController extends CogsBaseController
{
    public function __construct(
        private readonly CogsCalculationService $engine,
        private readonly CogsCalculationQueryService $queries,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $filters = $this->dateFilters($request);
        return ApiResponse::ok($this->queries->overview($outletId, $filters['date_from'], $filters['date_to']));
    }

    public function index(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        return ApiResponse::ok($this->queries->paginate($outletId, $this->filters($request)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $detail = $this->queries->detail($id, $outletId);
        if (! $detail) return ApiResponse::error('COGS Calculation tidak ditemukan pada scope outlet.', 'NOT_FOUND', 404);
        return ApiResponse::ok($detail);
    }

    public function calculate(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $validated = $this->dateFilters($request, 'period_from', 'period_to');
        $run = $this->engine->calculate(
            $outletId,
            $validated['period_from'],
            $validated['period_to'],
            $request->user()?->id ? (string) $request->user()->id : null,
            'backoffice_calculation',
        );
        return ApiResponse::ok($this->queries->detail((string) $run->id, $outletId), 'COGS berhasil dihitung.');
    }

    public function reconcile(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $this->assertScope($id, $outletId);
        $validated = $request->validate(['confirm_attention' => ['nullable', 'boolean']]);
        $run = $this->engine->reconcile(
            $id,
            $request->user()?->id ? (string) $request->user()->id : null,
            (bool) ($validated['confirm_attention'] ?? false),
        );
        return ApiResponse::ok($this->queries->detail((string) $run->id, $outletId), 'COGS berhasil direkonsiliasi.');
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $this->assertScope($id, $outletId);
        $validated = $request->validate([
            'confirm_attention' => ['nullable', 'boolean'],
            'close_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $run = $this->engine->close(
            $id,
            $request->user()?->id ? (string) $request->user()->id : null,
            (bool) ($validated['confirm_attention'] ?? false),
            $validated['close_notes'] ?? null,
        );
        return ApiResponse::ok($this->queries->detail((string) $run->id, $outletId), 'Periode COGS berhasil ditutup dan dikunci.');
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $this->assertScope($id, $outletId);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $run = $this->engine->cancel($id, $request->user()?->id ? (string) $request->user()->id : null, $validated['reason']);
        return ApiResponse::ok($this->queries->detail((string) $run->id, $outletId), 'COGS Calculation dibatalkan.');
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $filters = $this->dateFilters($request);
        $filename = "cogs-final-reconciliation-{$filters['date_from']}-{$filters['date_to']}.csv";

        return response()->streamDownload(function () use ($outletId, $filters): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Period From', 'Period To', 'Status', 'SKU Code', 'SKU', 'Base UOM',
                'Opening Qty', 'Opening Value', 'Receipt Qty', 'Receipt Value',
                'Net Consumption Qty', 'Net Consumption Value', 'Other Movement Qty', 'Other Movement Value',
                'Closing Qty', 'Closing Value', 'Variance Qty', 'Variance Value',
                'Final COGS', 'Inventory Bridge COGS', 'Reconciliation Difference', 'Warnings',
            ]);
            foreach ($this->queries->csvRows($outletId, $filters) as $row) fputcsv($handle, $row);
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate']);
    }

    private function assertScope(string $id, string $outletId): CogsCalculationRun
    {
        $run = CogsCalculationRun::query()->where('outlet_id', $outletId)->find($id);
        if (! $run) abort(404, 'COGS Calculation tidak ditemukan pada scope outlet.');
        return $run;
    }

    private function dateFilters(Request $request, string $fromKey = 'date_from', string $toKey = 'date_to'): array
    {
        $validated = $request->validate([
            $fromKey => ['required', 'date_format:Y-m-d'],
            $toKey => ['required', 'date_format:Y-m-d', "after_or_equal:{$fromKey}"],
        ]);
        if (Carbon::parse($validated[$fromKey])->diffInDays(Carbon::parse($validated[$toKey])) > 366) {
            throw ValidationException::withMessages([$toKey => ['Rentang tanggal maksimal 366 hari.']]);
        }
        return $validated;
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::in([
                CogsCalculationRun::STATUS_CALCULATED,
                CogsCalculationRun::STATUS_RECONCILED,
                CogsCalculationRun::STATUS_CLOSED,
                CogsCalculationRun::STATUS_CANCELLED,
            ])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        if (Carbon::parse($validated['date_from'])->diffInDays(Carbon::parse($validated['date_to'])) > 366) {
            throw ValidationException::withMessages(['date_to' => ['Rentang tanggal maksimal 366 hari.']]);
        }
        return $validated;
    }
}
