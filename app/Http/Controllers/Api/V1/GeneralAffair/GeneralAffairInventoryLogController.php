<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Models\GeneralAffair\Asset;
use App\Models\GeneralAffair\InventoryItem;
use App\Models\GeneralAffair\InventoryMovement;
use App\Models\Outlet;
use App\Services\GeneralAffair\InventoryMovementPhotoService;
use App\Services\GeneralAffair\InventoryMovementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class GeneralAffairInventoryLogController extends Controller
{
    public function __construct(
        private readonly InventoryMovementService $movementService,
        private readonly InventoryMovementPhotoService $photos,
    ) {}

    public function meta(): JsonResponse
    {
        return response()->json(['data' => [
            'outlets' => Outlet::query()->orderBy('name')->get(['id', 'code', 'name']),
            'item_types' => InventoryMovementService::ITEM_TYPES,
            'statuses' => InventoryMovementService::STATUSES,
            'location_types' => InventoryMovementService::LOCATION_TYPES,
            'conditions' => InventoryMovementService::CONDITIONS,
            'warehouse_presets' => ['Warehouse Penataran 41', 'Warehouse Penataran 43', 'Warehouse Waringin'],
            'headquarter_presets' => ['Headquarter'],
        ]]);
    }

    public function items(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_type' => ['required', Rule::in(InventoryMovementService::ITEM_TYPES)],
            'q' => ['nullable', 'string', 'max:160'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $type = strtoupper((string) $data['item_type']);
        $q = trim((string) ($data['q'] ?? ''));
        $limit = (int) ($data['limit'] ?? 30);

        if ($type === 'ASSET') {
            $query = Asset::query()->orderBy('item_name')->orderBy('asset_code');
            if ($q !== '') {
                $term = '%'.$q.'%';
                $query->where(fn (Builder $x) => $x->where('asset_code', 'like', $term)->orWhere('item_name', 'like', $term)->orWhere('serial_number', 'like', $term));
            }
            $rows = $query->limit($limit)->get();
            $items = $rows->map(fn (Asset $item) => [
                'id' => (string) $item->id,
                'item_type' => 'ASSET',
                'code' => $item->asset_code,
                'name' => $item->item_name,
                'quantity' => (float) $item->quantity,
                'condition' => $item->condition,
                'outlet_id' => (string) $item->outlet_id,
                'outlet_name' => $item->outlet_name_snapshot,
                'balances' => $this->movementService->ensureAndBalances('ASSET', (string) $item->id, $request->user()?->id),
            ])->values();
        } else {
            $query = InventoryItem::query()->orderBy('item_name')->orderBy('inventory_code');
            if ($q !== '') {
                $term = '%'.$q.'%';
                $query->where(fn (Builder $x) => $x->where('inventory_code', 'like', $term)->orWhere('item_name', 'like', $term)->orWhere('location', 'like', $term));
            }
            $rows = $query->limit($limit)->get();
            $items = $rows->map(fn (InventoryItem $item) => [
                'id' => (string) $item->id,
                'item_type' => 'INVENTORY',
                'code' => $item->inventory_code,
                'name' => $item->item_name,
                'quantity' => (float) $item->quantity,
                'condition' => $item->condition,
                'location' => $item->location,
                'outlet_id' => (string) $item->outlet_id,
                'outlet_name' => $item->outlet_name_snapshot,
                'balances' => $this->movementService->ensureAndBalances('INVENTORY', (string) $item->id, $request->user()?->id),
            ])->values();
        }

        return response()->json(['data' => ['items' => $items]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'item_type' => ['nullable', Rule::in(InventoryMovementService::ITEM_TYPES)],
            'movement_status' => ['nullable', Rule::in(InventoryMovementService::STATUSES)],
            'condition' => ['nullable', Rule::in(InventoryMovementService::CONDITIONS)],
            'outlet_id' => ['nullable', 'string', 'max:64'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = $this->filtered($filters);
        $page = (clone $query)->with('actor:id,name,nisj')->orderByDesc('movement_at')->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 25));
        $summaryBase = $this->filtered($filters);
        $statusCounts = (clone $summaryBase)->selectRaw('movement_status, COUNT(*) as total')->groupBy('movement_status')->pluck('total', 'movement_status');

        return response()->json(['data' => [
            'items' => collect($page->items())->map(fn (InventoryMovement $row) => $this->serialize($row))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
            'summary' => [
                'count' => $page->total(),
                'total_quantity' => (float) (clone $summaryBase)->sum('quantity'),
                'by_status' => collect(InventoryMovementService::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($statusCounts[$s] ?? 0)]),
            ],
        ]]);
    }

    public function show(string $id): JsonResponse
    {
        $row = InventoryMovement::query()->with('actor:id,name,nisj')->findOrFail($id);
        return response()->json(['data' => $this->serialize($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_type' => ['required', Rule::in(InventoryMovementService::ITEM_TYPES)],
            'item_id' => ['required', 'string', 'max:64'],
            'movement_at' => ['required', 'date'],
            'movement_status' => ['required', Rule::in(InventoryMovementService::STATUSES)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'condition' => ['required', Rule::in(InventoryMovementService::CONDITIONS)],
            'source_balance_id' => ['nullable', 'string', 'max:64'],
            'source_type' => ['nullable', Rule::in(InventoryMovementService::LOCATION_TYPES)],
            'source_outlet_id' => ['nullable', 'string', 'max:64'],
            'source_label' => ['nullable', 'string', 'max:200'],
            'source_detail' => ['nullable', 'string', 'max:200'],
            'destination_type' => ['required', Rule::in(InventoryMovementService::LOCATION_TYPES)],
            'destination_outlet_id' => ['nullable', 'string', 'max:64'],
            'destination_label' => ['nullable', 'string', 'max:200'],
            'destination_detail' => ['nullable', 'string', 'max:200'],
            'reason' => ['required', 'string', 'max:2000'],
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $type = strtoupper((string) $data['item_type']);
        $status = strtoupper((string) $data['movement_status']);
        $exists = $type === 'ASSET' ? Asset::query()->whereKey($data['item_id'])->exists() : InventoryItem::query()->whereKey($data['item_id'])->exists();
        if (! $exists) abort(422, 'Asset / Inventory tidak ditemukan atau sudah diarsipkan.');
        if ($status !== 'MASUK' && blank($data['source_balance_id'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['source_balance_id' => 'Lokasi asal wajib dipilih untuk status '.$status.'.']);
        }

        $movement = $this->movementService->post($data, $request->file('photo'), $request->user()?->id);
        $movement->load('actor:id,name,nisj');
        return response()->json(['data' => $this->serialize($movement), 'message' => 'Perpindahan barang berhasil diposting dan saldo lokasi diperbarui.'], 201);
    }

    private function filtered(array $filters): Builder
    {
        $q = InventoryMovement::query();
        if (filled($filters['q'] ?? null)) {
            $term = '%'.trim((string) $filters['q']).'%';
            $q->where(fn (Builder $x) => $x->where('movement_number', 'like', $term)
                ->orWhere('item_code_snapshot', 'like', $term)
                ->orWhere('item_name_snapshot', 'like', $term)
                ->orWhere('reason', 'like', $term));
        }
        if (filled($filters['item_type'] ?? null)) $q->where('item_type', $filters['item_type']);
        if (filled($filters['movement_status'] ?? null)) $q->where('movement_status', $filters['movement_status']);
        if (filled($filters['condition'] ?? null)) $q->where('condition', $filters['condition']);
        if (filled($filters['outlet_id'] ?? null)) {
            $outletId = (string) $filters['outlet_id'];
            $q->where(fn (Builder $x) => $x->where('source_outlet_id', $outletId)->orWhere('destination_outlet_id', $outletId));
        }
        if (filled($filters['date_from'] ?? null)) $q->where('movement_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay());
        if (filled($filters['date_to'] ?? null)) $q->where('movement_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay());
        return $q;
    }

    private function serialize(InventoryMovement $row): array
    {
        return [
            'id' => (string) $row->id,
            'movement_number' => $row->movement_number,
            'item_type' => $row->item_type,
            'item_id' => $row->item_type === 'ASSET' ? (string) $row->asset_id : (string) $row->inventory_item_id,
            'item_code' => $row->item_code_snapshot,
            'item_name' => $row->item_name_snapshot,
            'movement_at' => $row->movement_at?->toIso8601String(),
            'movement_status' => $row->movement_status,
            'quantity' => (float) $row->quantity,
            'state' => [
                'item_qty_before' => (float) $row->item_qty_before,
                'item_qty_after' => (float) $row->item_qty_after,
                'source_qty_before' => $row->source_qty_before !== null ? (float) $row->source_qty_before : null,
                'source_qty_after' => $row->source_qty_after !== null ? (float) $row->source_qty_after : null,
                'destination_qty_before' => $row->destination_qty_before !== null ? (float) $row->destination_qty_before : null,
                'destination_qty_after' => $row->destination_qty_after !== null ? (float) $row->destination_qty_after : null,
            ],
            'condition' => $row->condition,
            'source' => [
                'type' => $row->source_type, 'outlet_id' => $row->source_outlet_id ? (string) $row->source_outlet_id : null,
                'label' => $row->source_label, 'detail' => $row->source_detail,
            ],
            'destination' => [
                'type' => $row->destination_type, 'outlet_id' => $row->destination_outlet_id ? (string) $row->destination_outlet_id : null,
                'label' => $row->destination_label, 'detail' => $row->destination_detail,
            ],
            'reason' => $row->reason,
            'photo_url' => $this->photos->url($row),
            'actor' => $row->actor ? ['id' => (string) $row->actor->id, 'name' => $row->actor->name, 'nisj' => $row->actor->nisj] : null,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
