<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\ParStock;
use App\Services\StockInventory\OpeningStockService;
use App\Services\StockInventory\StockSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParStockController extends StockInventoryBaseController
{
    public function __construct(
        private readonly StockSnapshotService $snapshots,
        private readonly OpeningStockService $openings,
    ) {
    }

    /**
     * Hanya mengembalikan Par Stock yang benar-benar sudah tersimpan pada
     * outlet aktif. SKU yang belum dikonfigurasi tersedia melalui catalogs().
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        return ApiResponse::ok(
            $this->snapshots->build($outletId, $validated['date'] ?? null, true)
        );
    }

    /**
     * Catalog untuk modal Tambah Par Stock. Hanya berisi SKU aktif yang belum
     * pernah ditambahkan pada outlet aktif, sehingga tidak ada duplikasi.
     */
    public function catalogs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $snapshot = $this->snapshots->build($outletId, $validated['date'] ?? null);
        $items = collect($snapshot['items'] ?? [])
            ->filter(fn (array $row): bool => empty($row['par_stock_id']))
            ->values();

        return ApiResponse::ok([
            'as_of_date' => $snapshot['as_of_date'] ?? null,
            'latest_opname' => $snapshot['latest_opname'] ?? null,
            'categories' => $items
                ->map(fn (array $row): array => [
                    'id' => (string) ($row['category_id'] ?? ''),
                    'name' => (string) ($row['category_name'] ?? '-'),
                ])
                ->filter(fn (array $row): bool => $row['id'] !== '')
                ->unique('id')
                ->sortBy('name')
                ->values()
                ->all(),
            'items' => $items->all(),
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.sku_id' => ['required', 'ulid', 'distinct', Rule::exists('stk_skus', 'id')->whereNull('deleted_at')],
            'items.*.par_qty' => ['required', 'numeric', 'min:0', 'max:999999999999.9999'],
            'items.*.minimum_qty' => ['required', 'numeric', 'min:0', 'max:999999999999.9999'],
            'items.*.is_active' => ['nullable', 'boolean'],
            'items.*.initial_stock_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999'],
            'items.*.initial_unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999999.999999'],
            'items.*.initial_effective_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        foreach ($data['items'] as $index => $item) {
            if ((float) $item['minimum_qty'] > (float) $item['par_qty']) {
                throw ValidationException::withMessages([
                    "items.{$index}.minimum_qty" => ['Minimum stock tidak boleh lebih besar dari Par Stock.'],
                ]);
            }
        }

        $userId = $request->user()?->id;
        DB::transaction(function () use ($data, $outletId, $userId): void {
            foreach ($data['items'] as $item) {
                $record = ParStock::query()->firstOrNew([
                    'outlet_id' => $outletId,
                    'sku_id' => $item['sku_id'],
                ]);

                if (! $record->exists) {
                    $record->created_by_user_id = $userId;
                }

                $record->fill([
                    'par_qty' => round((float) $item['par_qty'], 4),
                    'minimum_qty' => round((float) $item['minimum_qty'], 4),
                    'is_active' => (bool) ($item['is_active'] ?? true),
                    'updated_by_user_id' => $userId,
                ])->save();

                if (array_key_exists('initial_stock_qty', $item)) {
                    $this->openings->upsert(
                        $outletId,
                        (string) $item['sku_id'],
                        $item['initial_stock_qty'] === null ? null : (float) $item['initial_stock_qty'],
                        array_key_exists('initial_unit_cost', $item) && $item['initial_unit_cost'] !== null ? (float) $item['initial_unit_cost'] : null,
                        $userId ? (string) $userId : null,
                        $item['initial_effective_date'] ?? null,
                    );
                }
            }
        });

        return ApiResponse::ok(
            $this->snapshots->build($outletId, null, true),
            'Par Stock berhasil disimpan.'
        );
    }

    public function destroy(Request $request, string $skuId): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $record = ParStock::query()
            ->where('outlet_id', $outletId)
            ->where('sku_id', $skuId)
            ->first();

        if (! $record) {
            return ApiResponse::error('Par Stock tidak ditemukan.', 'NOT_FOUND', 404);
        }

        $record->delete();

        return ApiResponse::ok(null, 'Par Stock berhasil dihapus untuk outlet terpilih.');
    }
}
