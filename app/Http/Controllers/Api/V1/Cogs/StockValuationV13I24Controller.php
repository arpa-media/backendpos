<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Cogs\V13\StockValuationI24Service;
use App\Support\OutletScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StockValuationV13I24Controller extends CogsBaseController
{
    public function __construct(private readonly StockValuationI24Service $service) {}

    public function catalogs(Request $request): JsonResponse
    {
        return ApiResponse::ok([
            'outlets' => $this->service->outlets(OutletScope::id($request)),
            'scope' => ['mode' => OutletScope::id($request) ? 'outlet' : 'global', 'outlet_id' => OutletScope::id($request)],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'source' => ['required', Rule::in(['inventory','warehouse'])],
            'outlet_id' => ['nullable','string','exists:outlets,id'],
            'q' => ['nullable','string','max:120'],
            'include_zero' => ['nullable','boolean'],
            'page' => ['nullable','integer','min:1'],
            'per_page' => ['nullable','integer','min:5','max:50'],
        ]);
        return ApiResponse::ok($this->service->overview(
            $v['source'], OutletScope::id($request), $v['outlet_id'] ?? null,
            trim((string) ($v['q'] ?? '')), (bool) ($v['include_zero'] ?? false),
            (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 20)
        ));
    }

    public function movements(Request $request): JsonResponse
    {
        $v = $request->validate([
            'source' => ['required', Rule::in(['inventory','warehouse'])],
            'outlet_id' => ['required','string','exists:outlets,id'],
            'sku_id' => ['required','string','exists:stk_skus,id'],
            'date_from' => ['nullable','date_format:Y-m-d'],
            'date_to' => ['nullable','date_format:Y-m-d','after_or_equal:date_from'],
            'page' => ['nullable','integer','min:1'],
            'per_page' => ['nullable','integer','min:5','max:50'],
        ]);
        if (! empty($v['date_from']) && ! empty($v['date_to']) &&
            Carbon::parse($v['date_from'])->diffInDays(Carbon::parse($v['date_to'])) > 366) {
            throw ValidationException::withMessages(['date_to'=>['Rentang tanggal maksimal 366 hari.']]);
        }
        return ApiResponse::ok($this->service->movements(
            $v['source'],$v['outlet_id'],$v['sku_id'],OutletScope::id($request),
            $v['date_from']??null,$v['date_to']??null,(int) ($v['page']??1),(int)($v['per_page']??20)
        ));
    }
}
