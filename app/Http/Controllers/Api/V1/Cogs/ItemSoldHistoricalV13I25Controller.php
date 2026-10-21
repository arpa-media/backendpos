<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Cogs\V13\ItemSoldHistoricalI25Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ItemSoldHistoricalV13I25Controller extends CogsBaseController
{
    public function __construct(private readonly ItemSoldHistoricalI25Service $service) {}

    public function report(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $v = $request->validate([
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['posted', 'reversed', 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);
        if (Carbon::parse($v['date_from'])->diffInDays(Carbon::parse($v['date_to'])) > 92) {
            throw ValidationException::withMessages(['date_to' => ['Rentang maksimal 93 hari; gunakan beberapa periode untuk laporan panjang.']]);
        }
        $this->checkSchema();
        return ApiResponse::ok($this->service->report((string) $outletId, $v));
    }

    public function detail(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $this->checkSchema();
        $detail = $this->service->ingredients((string) $outletId, $id);
        if (! $detail) {
            return ApiResponse::error('Snapshot item sold tidak ditemukan untuk outlet ini.', 'ITEM_SOLD_NOT_FOUND', 404);
        }
        return ApiResponse::ok($detail);
    }

    private function checkSchema(): void
    {
        foreach (['cogs_sale_consumptions','cogs_sale_consumption_items','cogs_recipes','sale_items','sale_item_addons'] as $table) {
            if (! Schema::hasTable($table)) {
                throw ValidationException::withMessages(['database' => ["Tabel {$table} belum tersedia untuk laporan HPP Item Sold."]]);
            }
        }
    }
}
