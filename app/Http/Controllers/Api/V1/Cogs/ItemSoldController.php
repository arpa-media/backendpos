<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Cogs\SaleConsumption;
use App\Models\Outlet;
use App\Services\Cogs\ItemSoldQueryService;
use App\Services\Cogs\SaleConsumptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemSoldController extends CogsBaseController
{
    public function __construct(
        private readonly SaleConsumptionService $engine,
        private readonly ItemSoldQueryService $queries,
    ) {
    }

    public function catalogs(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $outlet = Outlet::query()->find($outletId);
        if (! $outlet) {
            return ApiResponse::error('Outlet tidak ditemukan.', 'OUTLET_NOT_FOUND', 404);
        }

        return ApiResponse::ok([
            'outlet' => [
                'id' => (string) $outlet->id,
                'code' => (string) ($outlet->code ?? ''),
                'name' => (string) $outlet->name,
                'timezone' => (string) ($outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
            ],
            'consumption_statuses' => [
                ['value' => SaleConsumption::STATUS_POSTED, 'label' => 'Posted'],
                ['value' => SaleConsumption::STATUS_REVERSED, 'label' => 'Reversed'],
            ],
            'exception_statuses' => [
                ['value' => SaleConsumption::STATUS_OPEN, 'label' => 'Open'],
                ['value' => SaleConsumption::STATUS_RESOLVED, 'label' => 'Resolved'],
            ],
            'exception_codes' => [
                ['value' => 'RECIPE_NOT_FOUND', 'label' => 'Recipe belum tersedia'],
                ['value' => 'RECIPE_EMPTY', 'label' => 'Recipe tanpa ingredient'],
                ['value' => 'INVALID_RECIPE_YIELD', 'label' => 'Yield recipe tidak valid'],
                ['value' => 'SALE_NOT_FOUND', 'label' => 'Sale parent tidak ditemukan'],
            ],
            'ledger_precision' => 4,
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $outletId = $this->requiredOutlet($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        return ApiResponse::ok($this->queries->overview($outletId, $this->filters($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $outletId = $this->requiredOutlet($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        return ApiResponse::ok($this->queries->consumptions($outletId, $this->filters($request, true)));
    }

    public function ingredients(Request $request): JsonResponse
    {
        $outletId = $this->requiredOutlet($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        return ApiResponse::ok($this->queries->ingredientSummary($outletId, $this->filters($request, true)));
    }

    public function exceptions(Request $request): JsonResponse
    {
        $outletId = $this->requiredOutlet($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        return ApiResponse::ok($this->queries->exceptions($outletId, $this->exceptionFilters($request)));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $outletId = $this->requiredOutlet($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $detail = $this->queries->detail($id, $outletId);
        if (! $detail) {
            return ApiResponse::error('Consumption record tidak ditemukan pada scope outlet.', 'NOT_FOUND', 404);
        }
        return ApiResponse::ok($detail);
    }

    public function process(Request $request): JsonResponse
    {
        $outletId = $this->requiredOutlet($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $filters = $this->dateFilters($request);
        if (Carbon::parse($filters['date_from'])->diffInDays(Carbon::parse($filters['date_to'])) > 31) {
            return ApiResponse::error(
                'Proses dari halaman dibatasi maksimal 31 hari. Gunakan command rebuild untuk periode lebih panjang.',
                'PROCESS_RANGE_TOO_LARGE',
                422,
                ['date_to' => ['Rentang proses maksimal 31 hari.']]
            );
        }

        $result = $this->engine->rebuildRange(
            $outletId,
            $filters['date_from'],
            $filters['date_to'],
            $request->user()?->id ? (string) $request->user()->id : null,
            'backoffice_rebuild',
        );

        return ApiResponse::ok($result, 'Item Sold dan recipe consumption selesai direkonsiliasi.');
    }

    public function retryException(Request $request, string $id): JsonResponse
    {
        $outletId = $this->requiredOutlet($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $exception = SaleConsumption::query()
            ->where('outlet_id', $outletId)
            ->where('movement_type', SaleConsumption::TYPE_EXCEPTION)
            ->find($id);
        if (! $exception) {
            return ApiResponse::error('Exception queue tidak ditemukan pada scope outlet.', 'NOT_FOUND', 404);
        }

        $result = $this->engine->retryException(
            $exception,
            $request->user()?->id ? (string) $request->user()->id : null,
        );

        return ApiResponse::ok($result, 'Exception selesai diproses ulang.');
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $outletId = $this->requiredOutlet($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $validated = $request->validate([
            'dataset' => ['required', Rule::in(['items', 'ingredients'])],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $this->guardRange($validated['date_from'], $validated['date_to']);

        $dataset = (string) $validated['dataset'];
        $filename = "cogs-{$dataset}-{$validated['date_from']}-{$validated['date_to']}.csv";

        return response()->streamDownload(function () use ($dataset, $outletId, $validated): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            if ($dataset === 'ingredients') {
                fputcsv($handle, [
                    'SKU ID', 'SKU Code', 'Ingredient', 'Base UOM Code', 'Base UOM Symbol',
                    'Consumption Qty', 'Estimate COGS', 'Transactions', 'Sale Item Lines',
                ]);
                foreach ($this->queries->ingredientCsvRows($outletId, $validated) as $row) {
                    fputcsv($handle, $row);
                }
            } else {
                fputcsv($handle, [
                    'Business Date', 'Sale Number', 'Sale ID', 'Sale Item ID', 'Product', 'Variant',
                    'Sold Qty', 'Recipe Version', 'Status', 'Ingredient Qty', 'Estimate COGS',
                    'Movement Count', 'Processed At', 'Reversed At',
                ]);
                foreach ($this->queries->consumptionCsvRows($outletId, $validated) as $row) {
                    fputcsv($handle, $row);
                }
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function requiredOutlet(Request $request): string|JsonResponse
    {
        return $this->outletId($request);
    }

    private function filters(Request $request, bool $withPage = false): array
    {
        $rules = [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([SaleConsumption::STATUS_POSTED, SaleConsumption::STATUS_REVERSED])],
        ];
        if ($withPage) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', 'integer', 'min:5', 'max:100'];
        }
        $validated = $request->validate($rules);
        $this->guardRange($validated['date_from'], $validated['date_to']);
        return $validated;
    }

    private function exceptionFilters(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([SaleConsumption::STATUS_OPEN, SaleConsumption::STATUS_RESOLVED])],
            'exception_code' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $this->guardRange($validated['date_from'], $validated['date_to']);
        return $validated;
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

    private function guardRange(string $from, string $to): void
    {
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw ValidationException::withMessages([
                'date_to' => ['Rentang tanggal maksimal 366 hari.'],
            ]);
        }
    }
}
