<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\ParStock;
use App\Models\StockInventory\StockOpname;
use App\Models\StockInventory\StockOpnameItem;
use App\Services\StockInventory\ActualStockService;
use App\Services\StockInventory\StockSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockOpnameController extends StockInventoryBaseController
{
    public function __construct(
        private readonly StockSnapshotService $snapshots,
        private readonly ActualStockService $actualStock,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $document = StockOpname::query()
            ->with(['items', 'latestCancellation.requestedBy:id,name,nisj', 'latestCancellation.decidedBy:id,name,nisj'])
            ->where('outlet_id', $outletId)
            ->whereDate('opname_date', $validated['date'])
            ->first();

        $snapshot = $this->snapshots->build($outletId, $validated['date'], true);
        $documentItems = $document?->items?->keyBy(fn ($item) => (string) $item->sku_id) ?? collect();

        $snapshot['items'] = collect($snapshot['items'])->map(function ($row) use ($documentItems, $outletId) {
            $item = $documentItems->get((string) $row['sku_id']);
            return [
                ...$row,
                'current_stock_qty' => $row['current_stock_qty'] ?? $row['actual_qty'] ?? 0,
                // I02: Stock Opname is an absolute physical count. Do not expose a
                // historical/current balance as a maximum input constraint.
                'is_opening_actual' => $this->actualStock->lastActualCap((string) $outletId, (string) $row['sku_id']) === null,
                'actual_qty' => $item ? (float) $item->actual_qty : null,
                'item_notes' => $item?->notes,
            ];
        })->values()->all();
        $snapshot['document'] = $this->serializeDocument($document);

        return ApiResponse::ok($snapshot);
    }

    public function store(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'max:1000'],
            'items.*.sku_id' => ['required', 'ulid', 'distinct', Rule::exists('stk_skus', 'id')->whereNull('deleted_at')],
            'items.*.actual_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $configuredSkuIds = ParStock::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->pluck('sku_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $submittedSkuIds = collect($data['items'])->pluck('sku_id')->map(fn ($id) => (string) $id)->all();
        $invalidSkuIds = array_values(array_diff($submittedSkuIds, $configuredSkuIds));
        if ($invalidSkuIds !== []) {
            throw ValidationException::withMessages([
                'items' => ['Sebagian SKU belum memiliki Par Stock aktif di outlet terpilih.'],
            ]);
        }


        $userId = $request->user()?->id;
        $document = DB::transaction(function () use ($data, $outletId, $userId) {
            $document = StockOpname::query()->firstOrNew([
                'outlet_id' => $outletId,
                'opname_date' => $data['date'],
            ]);

            if ($document->exists && $document->status === 'submitted') {
                throw ValidationException::withMessages([
                    'date' => ['Stock Opname tanggal ini sudah disubmit dan dikunci.'],
                ]);
            }

            if (! $document->exists) {
                $document->created_by_user_id = $userId;
            }

            $document->fill([
                'status' => 'draft',
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'updated_by_user_id' => $userId,
            ])->save();

            foreach ($data['items'] as $item) {
                if ($item['actual_qty'] === null || $item['actual_qty'] === '') {
                    StockOpnameItem::query()
                        ->where('stock_opname_id', $document->id)
                        ->where('sku_id', $item['sku_id'])
                        ->delete();
                    continue;
                }

                StockOpnameItem::query()->updateOrCreate(
                    [
                        'stock_opname_id' => $document->id,
                        'sku_id' => $item['sku_id'],
                    ],
                    [
                        'actual_qty' => round((float) $item['actual_qty'], 4),
                        'notes' => filled($item['notes'] ?? null) ? trim($item['notes']) : null,
                    ]
                );
            }

            return $document->fresh('items');
        });

        return ApiResponse::ok($this->serializeDocument($document), 'Draft Stock Opname berhasil disimpan.');
    }

    public function submit(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $document = StockOpname::query()
            ->with(['items', 'latestCancellation.requestedBy:id,name,nisj', 'latestCancellation.decidedBy:id,name,nisj'])
            ->where('outlet_id', $outletId)
            ->whereDate('opname_date', $data['date'])
            ->first();

        if (! $document) {
            return ApiResponse::error('Simpan draft Stock Opname terlebih dahulu.', 'DRAFT_NOT_FOUND', 404);
        }
        if ($document->status === 'submitted') {
            return ApiResponse::ok($this->serializeDocument($document), 'Stock Opname sudah pernah disubmit.');
        }

        $requiredSkuIds = ParStock::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->pluck('sku_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $countedSkuIds = $document->items->pluck('sku_id')->map(fn ($id) => (string) $id)->all();
        $missing = array_values(array_diff($requiredSkuIds, $countedSkuIds));

        if ($requiredSkuIds === []) {
            throw ValidationException::withMessages([
                'items' => ['Par Stock belum dikonfigurasi untuk outlet ini.'],
            ]);
        }
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'items' => ['Semua SKU dengan Par Stock aktif wajib diisi sebelum Stock Opname disubmit.'],
            ]);
        }

        $userId = $request->user()?->id;
        DB::transaction(function () use ($document, $userId): void {
            $locked = StockOpname::query()->with('items')->where('id', $document->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'submitted') return;
            // Iteration 11: the document must already be authoritative when
            // postSubmittedOpname() rebuilds the current balance. Previously the
            // service rebuilt while this row was still DRAFT, so the just-posted
            // Opname could disappear from Current Stock until another movement.
            $locked->forceFill([
                'status' => 'submitted',
                'submitted_by_user_id' => $userId,
                'submitted_at' => now(),
                'updated_by_user_id' => $userId,
            ])->save();
            $this->actualStock->postSubmittedOpname($locked->fresh('items'), $userId);
        }, 5);

        return ApiResponse::ok($this->serializeDocument($document->fresh('items')), 'Stock Opname berhasil disubmit, dikunci, dan menjadi Actual Stock terbaru.');
    }

    private function serializeDocument(?StockOpname $document): ?array
    {
        if (! $document) {
            return null;
        }

        $document->loadMissing([
            'outlet:id,code,name,timezone',
            'latestCancellation.requestedBy:id,name,nisj',
            'latestCancellation.decidedBy:id,name,nisj',
        ]);

        $cancellation = $document->latestCancellation;

        return [
            'id' => (string) $document->id,
            'outlet_id' => (string) $document->outlet_id,
            'date' => $document->opname_date?->toDateString(),
            'status' => (string) $document->status,
            'notes' => $document->notes,
            'item_count' => $document->relationLoaded('items') ? $document->items->count() : $document->items()->count(),
            'submitted_at' => $document->submitted_at?->toIso8601String(),
            'timezone' => (string) ($document->outlet?->timezone ?? config('app.timezone', 'Asia/Jakarta')),
            'cancellation' => $cancellation ? [
                'id' => (string) $cancellation->id,
                'status' => (string) $cancellation->status,
                'reason' => (string) $cancellation->reason,
                'requested_at' => $cancellation->requested_at?->toIso8601String(),
                'requested_by' => $cancellation->requestedBy ? [
                    'id' => (string) $cancellation->requestedBy->id,
                    'name' => (string) ($cancellation->requestedBy->name ?? $cancellation->requestedBy->nisj ?? '-'),
                    'nisj' => (string) ($cancellation->requestedBy->nisj ?? ''),
                ] : null,
                'decided_at' => $cancellation->decided_at?->toIso8601String(),
                'decided_by' => $cancellation->decidedBy ? [
                    'id' => (string) $cancellation->decidedBy->id,
                    'name' => (string) ($cancellation->decidedBy->name ?? $cancellation->decidedBy->nisj ?? '-'),
                    'nisj' => (string) ($cancellation->decidedBy->nisj ?? ''),
                ] : null,
                'decision_notes' => $cancellation->decision_notes,
            ] : null,
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }
}
