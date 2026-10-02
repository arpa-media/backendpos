<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\Asset;
use App\Models\GeneralAffair\AssetLocationBalance;
use App\Models\GeneralAffair\InventoryItem;
use App\Models\GeneralAffair\InventoryLocationBalance;
use App\Models\GeneralAffair\InventoryMovement;
use App\Models\Outlet;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryMovementService
{
    public const ITEM_TYPES = ['ASSET', 'INVENTORY'];
    public const STATUSES = ['PINDAH', 'MASUK', 'KELUAR', 'PINJAM'];
    public const LOCATION_TYPES = ['OUTLET', 'WAREHOUSE', 'HEADQUARTER', 'OTHER'];
    public const CONDITIONS = ['Baru', 'Bekas Baru', 'Baik', 'Sedikit Rusak', 'Rusak'];

    public function __construct(private readonly InventoryMovementPhotoService $photos) {}

    public function post(array $data, ?UploadedFile $photo, ?string $userId): InventoryMovement
    {
        $itemType = strtoupper((string) $data['item_type']);
        $status = strtoupper((string) $data['movement_status']);
        $movementAt = Carbon::parse((string) $data['movement_at']);
        $movementNumber = 'GA-MOV-'.$movementAt->format('Ymd-His').'-'.strtoupper(substr((string) Str::ulid(), -6));

        [$itemCode, $itemName] = $this->itemIdentity($itemType, (string) $data['item_id']);
        $photoMeta = $photo ? $this->photos->store($photo, $movementNumber, $itemCode, $itemName, $movementAt) : [];

        try {
            return DB::transaction(function () use ($data, $itemType, $status, $movementAt, $movementNumber, $photoMeta, $userId): InventoryMovement {
                $item = $this->lockedItem($itemType, (string) $data['item_id']);
                $this->ensureInitialBalance($itemType, $item, $userId);

                $qty = round((float) $data['quantity'], 3);
                $itemQtyBefore = round((float) $item->quantity, 3);
                if ($itemType === 'ASSET' && abs($qty - round($qty)) > 0.00001) {
                    throw ValidationException::withMessages(['quantity' => 'Jumlah Asset harus berupa bilangan bulat.']);
                }
                if ($itemType === 'ASSET' && filled($item->serial_number)) {
                    $active = collect($this->positiveBalances('ASSET', (string) $item->id, true));
                    $activeQty = (float) $active->sum(fn ($b) => (float) $b->quantity);
                    if ($active->count() > 1 || $activeQty > 1.000001) {
                        throw ValidationException::withMessages(['item_id' => 'Asset serial individual terdeteksi berada di lebih dari satu lokasi. Periksa saldo lokasi sebelum melanjutkan.']);
                    }
                    if (abs($qty - 1) > 0.00001) {
                        throw ValidationException::withMessages(['quantity' => 'Asset dengan Serial Number wajib dipindahkan sebanyak 1 unit.']);
                    }
                    if ($status === 'MASUK' && $activeQty > 0.000001) {
                        throw ValidationException::withMessages(['movement_status' => 'Asset serial individual masih aktif di lokasi lain dan tidak boleh MASUK dua kali.']);
                    }
                }

                $source = null;
                $sourceQtyBefore = null;
                $sourceQtyAfter = null;
                if ($status !== 'MASUK') {
                    $source = $this->lockedSourceBalance($itemType, (string) ($data['source_balance_id'] ?? ''), (string) $item->id);
                    $sourceQtyBefore = round((float) $source->quantity, 3);
                    if ($sourceQtyBefore + 0.000001 < $qty) {
                        throw ValidationException::withMessages(['quantity' => 'Jumlah melebihi saldo lokasi asal. Saldo tersedia: '.$sourceQtyBefore.'.']);
                    }
                    $source->quantity = round($sourceQtyBefore - $qty, 3);
                    $source->updated_by_user_id = $userId;
                    $source->save();
                    $sourceQtyAfter = round((float) $source->quantity, 3);
                }

                $sourceSnapshot = $source
                    ? $this->snapshotFromBalance($source)
                    : $this->resolveLocation($data, 'source', $status === 'MASUK');

                $destinationSnapshot = $this->resolveLocation($data, 'destination', true);
                $destinationKey = null;
                $destinationQtyBefore = null;
                $destinationQtyAfter = null;
                $candidateDestinationKey = $this->locationKey(
                    (string) $destinationSnapshot['type'],
                    (string) ($destinationSnapshot['outlet_id'] ?? ''),
                    (string) $destinationSnapshot['label'],
                    $destinationSnapshot['detail'],
                    (string) $data['condition'],
                );
                if ($source && in_array($status, ['PINDAH', 'PINJAM'], true) && $source->location_key === $candidateDestinationKey) {
                    throw ValidationException::withMessages(['destination_type' => 'Lokasi tujuan dan kondisi sama dengan lokasi asal. Pilih tujuan atau kondisi lain.']);
                }
                if ($status !== 'KELUAR') {
                    $destinationState = $this->addDestinationBalance(
                        $itemType,
                        (string) $item->id,
                        $destinationSnapshot,
                        $qty,
                        (string) $data['condition'],
                        $userId,
                    );
                    $destinationKey = $destinationState['key'];
                    $destinationQtyBefore = $destinationState['before'];
                    $destinationQtyAfter = $destinationState['after'];
                }

                $this->syncMaster($itemType, $item, $userId);
                $item->refresh();
                $itemQtyAfter = round((float) $item->quantity, 3);

                return InventoryMovement::query()->create([
                    'id' => (string) Str::ulid(),
                    'movement_number' => $movementNumber,
                    'item_type' => $itemType,
                    'asset_id' => $itemType === 'ASSET' ? (string) $item->id : null,
                    'inventory_item_id' => $itemType === 'INVENTORY' ? (string) $item->id : null,
                    'item_code_snapshot' => $itemType === 'ASSET' ? $item->asset_code : $item->inventory_code,
                    'item_name_snapshot' => $item->item_name,
                    'movement_at' => $movementAt,
                    'movement_status' => $status,
                    'quantity' => $qty,
                    'item_qty_before' => $itemQtyBefore,
                    'item_qty_after' => $itemQtyAfter,
                    'source_qty_before' => $sourceQtyBefore,
                    'source_qty_after' => $sourceQtyAfter,
                    'destination_qty_before' => $destinationQtyBefore,
                    'destination_qty_after' => $destinationQtyAfter,
                    'condition' => (string) $data['condition'],
                    'source_type' => $sourceSnapshot['type'],
                    'source_outlet_id' => $sourceSnapshot['outlet_id'],
                    'source_label' => $sourceSnapshot['label'],
                    'source_detail' => $sourceSnapshot['detail'],
                    'source_balance_key' => $source?->location_key,
                    'destination_type' => $destinationSnapshot['type'],
                    'destination_outlet_id' => $destinationSnapshot['outlet_id'],
                    'destination_label' => $destinationSnapshot['label'],
                    'destination_detail' => $destinationSnapshot['detail'],
                    'destination_balance_key' => $destinationKey,
                    'reason' => trim((string) $data['reason']),
                    'created_by_user_id' => $userId,
                ] + $photoMeta)->fresh();
            }, 5);
        } catch (\Throwable $e) {
            if ($photoMeta) $this->photos->delete($photoMeta);
            throw $e;
        }
    }

    public function ensureAndBalances(string $itemType, string $itemId, ?string $userId = null): array
    {
        $itemType = strtoupper($itemType);
        $item = $itemType === 'ASSET'
            ? Asset::query()->findOrFail($itemId)
            : InventoryItem::query()->findOrFail($itemId);
        $this->ensureInitialBalance($itemType, $item, $userId);
        return $this->positiveBalances($itemType, $itemId);
    }

    private function itemIdentity(string $itemType, string $itemId): array
    {
        $item = $itemType === 'ASSET' ? Asset::query()->findOrFail($itemId) : InventoryItem::query()->findOrFail($itemId);
        return [$itemType === 'ASSET' ? (string) $item->asset_code : (string) $item->inventory_code, (string) $item->item_name];
    }

    private function lockedItem(string $itemType, string $itemId): Asset|InventoryItem
    {
        return $itemType === 'ASSET'
            ? Asset::query()->lockForUpdate()->findOrFail($itemId)
            : InventoryItem::query()->lockForUpdate()->findOrFail($itemId);
    }

    private function ensureInitialBalance(string $itemType, Asset|InventoryItem $item, ?string $userId): void
    {
        $table = $itemType === 'ASSET' ? 'ga_asset_location_balances' : 'ga_inventory_location_balances';
        $fk = $itemType === 'ASSET' ? 'asset_id' : 'inventory_item_id';
        if (DB::table($table)->where($fk, $item->id)->exists()) return;

        $qty = max(0, (float) $item->quantity);
        if ($qty <= 0) return;
        $detail = $itemType === 'INVENTORY' ? $this->nullable($item->location) : null;
        $label = trim((string) $item->outlet_name_snapshot) ?: 'Outlet';
        $key = $this->locationKey('OUTLET', (string) $item->outlet_id, $label, $detail, (string) ($item->condition ?: 'Baik'));
        DB::table($table)->insertOrIgnore([
            'id' => (string) Str::ulid(),
            $fk => (string) $item->id,
            'location_key' => $key,
            'location_type' => 'OUTLET',
            'outlet_id' => (string) $item->outlet_id,
            'location_label' => $label,
            'location_detail' => $detail,
            'quantity' => $qty,
            'condition' => (string) ($item->condition ?: 'Baik'),
            'updated_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lockedSourceBalance(string $itemType, string $balanceId, string $itemId): AssetLocationBalance|InventoryLocationBalance
    {
        if ($balanceId === '') throw ValidationException::withMessages(['source_balance_id' => 'Lokasi asal wajib dipilih.']);
        $query = $itemType === 'ASSET' ? AssetLocationBalance::query() : InventoryLocationBalance::query();
        $fk = $itemType === 'ASSET' ? 'asset_id' : 'inventory_item_id';
        $balance = $query->whereKey($balanceId)->where($fk, $itemId)->lockForUpdate()->first();
        if (! $balance || (float) $balance->quantity <= 0) {
            throw ValidationException::withMessages(['source_balance_id' => 'Saldo lokasi asal tidak tersedia. Muat ulang data item.']);
        }
        return $balance;
    }

    private function addDestinationBalance(string $itemType, string $itemId, array $location, float $qty, string $condition, ?string $userId): array
    {
        $table = $itemType === 'ASSET' ? 'ga_asset_location_balances' : 'ga_inventory_location_balances';
        $fk = $itemType === 'ASSET' ? 'asset_id' : 'inventory_item_id';
        $key = $this->locationKey((string) $location['type'], (string) ($location['outlet_id'] ?? ''), (string) $location['label'], $location['detail'], $condition);

        DB::table($table)->insertOrIgnore([
            'id' => (string) Str::ulid(),
            $fk => $itemId,
            'location_key' => $key,
            'location_type' => $location['type'],
            'outlet_id' => $location['outlet_id'],
            'location_label' => $location['label'],
            'location_detail' => $location['detail'],
            'quantity' => 0,
            'condition' => $condition,
            'updated_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $query = $itemType === 'ASSET' ? AssetLocationBalance::query() : InventoryLocationBalance::query();
        $balance = $query->where($fk, $itemId)->where('location_key', $key)->lockForUpdate()->firstOrFail();
        $before = round((float) $balance->quantity, 3);
        $balance->quantity = round($before + $qty, 3);
        $balance->condition = $condition;
        $balance->location_type = $location['type'];
        $balance->outlet_id = $location['outlet_id'];
        $balance->location_label = $location['label'];
        $balance->location_detail = $location['detail'];
        $balance->updated_by_user_id = $userId;
        $balance->save();
        return ['key' => $key, 'before' => $before, 'after' => round((float) $balance->quantity, 3)];
    }

    private function resolveLocation(array $data, string $prefix, bool $required): array
    {
        $type = strtoupper(trim((string) ($data[$prefix.'_type'] ?? '')));
        if ($type === '' && ! $required) return ['type' => null, 'outlet_id' => null, 'label' => null, 'detail' => null];
        if (! in_array($type, self::LOCATION_TYPES, true)) {
            throw ValidationException::withMessages([$prefix.'_type' => 'Tipe lokasi tidak valid.']);
        }

        $outletId = $this->nullable($data[$prefix.'_outlet_id'] ?? null);
        $label = $this->nullable($data[$prefix.'_label'] ?? null);
        $detail = $this->nullable($data[$prefix.'_detail'] ?? null);
        if ($type === 'OUTLET') {
            if (! $outletId) throw ValidationException::withMessages([$prefix.'_outlet_id' => 'Outlet wajib dipilih.']);
            $outlet = Outlet::query()->find($outletId);
            if (! $outlet) throw ValidationException::withMessages([$prefix.'_outlet_id' => 'Outlet tidak ditemukan.']);
            $label = (string) $outlet->name;
        } elseif (! $label) {
            if ($type === 'HEADQUARTER') $label = 'Headquarter';
            else throw ValidationException::withMessages([$prefix.'_label' => 'Nama lokasi wajib diisi.']);
        }
        return ['type' => $type, 'outlet_id' => $outletId, 'label' => $label, 'detail' => $detail];
    }

    private function snapshotFromBalance(AssetLocationBalance|InventoryLocationBalance $balance): array
    {
        return [
            'type' => (string) $balance->location_type,
            'outlet_id' => $balance->outlet_id ? (string) $balance->outlet_id : null,
            'label' => (string) $balance->location_label,
            'detail' => $this->nullable($balance->location_detail),
        ];
    }

    private function syncMaster(string $itemType, Asset|InventoryItem $item, ?string $userId): void
    {
        $balances = $this->positiveBalances($itemType, (string) $item->id, true);
        $totalQty = round(collect($balances)->sum(fn ($b) => (float) $b->quantity), 3);
        $positive = collect($balances)->filter(fn ($b) => (float) $b->quantity > 0.000001)->values();
        $single = $positive->count() === 1 ? $positive->first() : null;

        if ($itemType === 'ASSET') {
            $oldTotal = max(0, (float) $item->purchase_total);
            $rate = $oldTotal > 0 ? min(1, max(0, (float) $item->total_depreciation / $oldTotal)) : 0;
            $purchaseTotal = round($totalQty * (float) $item->purchase_price, 2);
            $payload = [
                'quantity' => (int) round($totalQty),
                'purchase_total' => $purchaseTotal,
                'total_depreciation' => round(min($purchaseTotal, $purchaseTotal * $rate), 2),
                'updated_by_user_id' => $userId,
            ];
            if ($single && $single->outlet_id) $payload += $this->outletSnapshotPayload((string) $single->outlet_id);
            if ($single) $payload['condition'] = (string) $single->condition;
            $item->forceFill($payload)->save();
            return;
        }

        $payload = [
            'quantity' => $totalQty,
            'total_value' => round($totalQty * (float) $item->unit_price, 2),
            'updated_by_user_id' => $userId,
        ];
        if ($single) {
            if ($single->outlet_id) $payload += $this->outletSnapshotPayload((string) $single->outlet_id);
            $payload['location'] = $this->nullable($single->location_detail) ?: (string) $single->location_label;
            $payload['condition'] = (string) $single->condition;
        } elseif ($positive->count() > 1) {
            $payload['location'] = 'Multi Lokasi ('.$positive->count().')';
        }
        $item->forceFill($payload)->save();
    }

    private function positiveBalances(string $itemType, string $itemId, bool $models = false): array
    {
        $query = $itemType === 'ASSET' ? AssetLocationBalance::query() : InventoryLocationBalance::query();
        $fk = $itemType === 'ASSET' ? 'asset_id' : 'inventory_item_id';
        $rows = $query->where($fk, $itemId)->where('quantity', '>', 0)->orderBy('location_label')->get();
        if ($models) return $rows->all();
        return $rows->map(fn ($row) => [
            'id' => (string) $row->id,
            'location_type' => $row->location_type,
            'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
            'location_label' => $row->location_label,
            'location_detail' => $row->location_detail,
            'quantity' => (float) $row->quantity,
            'condition' => $row->condition,
        ])->values()->all();
    }

    private function outletSnapshotPayload(string $outletId): array
    {
        $outlet = Outlet::query()->find($outletId);
        if (! $outlet) return [];
        return ['outlet_id' => (string) $outlet->id, 'outlet_code_snapshot' => $outlet->code, 'outlet_name_snapshot' => $outlet->name];
    }

    private function locationKey(string $type, string $outletId, string $label, ?string $detail, string $condition): string
    {
        $identity = $type === 'OUTLET' && $outletId !== '' ? $outletId : (Str::slug($label) ?: 'location');
        return strtoupper($type).'|'.$identity.'|'.(Str::slug((string) $detail) ?: '-').'|'.(Str::slug($condition) ?: 'baik');
    }

    private function nullable($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }
}
