<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\InventoryAudit;
use App\Models\GeneralAffair\InventoryAuditLine;
use App\Models\Outlet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryAuditService
{
    public const CONDITIONS = ['Baru', 'Bekas Baru', 'Baik', 'Sedikit Rusak', 'Rusak'];

    public function start(array $data, ?string $userId): InventoryAudit
    {
        return DB::transaction(function () use ($data, $userId): InventoryAudit {
            $outlet = Outlet::query()->lockForUpdate()->findOrFail((string) $data['outlet_id']);
            $year = (int) $data['audit_year'];
            $month = (int) $data['audit_month'];

            $existing = InventoryAudit::query()
                ->where('outlet_id', $outlet->id)
                ->where('audit_year', $year)
                ->where('audit_month', $month)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    'outlet_id' => sprintf('Outlet ini sudah memiliki audit inventory periode %02d/%d. Gunakan Re-Audit jika audit terakhir sudah selesai.', $month, $year),
                ]);
            }

            return $this->createAudit($outlet, $data, $userId, 1, null);
        }, 5);
    }

    public function reAudit(InventoryAudit $source, array $data, ?string $userId): InventoryAudit
    {
        return DB::transaction(function () use ($source, $data, $userId): InventoryAudit {
            $lockedSource = InventoryAudit::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($lockedSource->status !== 'COMPLETED') {
                throw ValidationException::withMessages(['audit' => 'Re-Audit hanya dapat dibuat dari audit yang sudah selesai.']);
            }

            $outlet = Outlet::query()->lockForUpdate()->findOrFail((string) $lockedSource->outlet_id);
            $latest = InventoryAudit::query()
                ->where('outlet_id', $lockedSource->outlet_id)
                ->where('audit_year', $lockedSource->audit_year)
                ->where('audit_month', $lockedSource->audit_month)
                ->orderByDesc('audit_sequence')
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $latest->id !== (string) $lockedSource->id) {
                throw ValidationException::withMessages(['audit' => 'Audit ini bukan audit terakhir pada periode tersebut. Buka audit terakhir untuk melakukan Re-Audit.']);
            }
            if ($latest->status !== 'COMPLETED') {
                throw ValidationException::withMessages(['audit' => 'Masih ada audit periode ini yang sedang berjalan.']);
            }

            $payload = $data + [
                'outlet_id' => (string) $lockedSource->outlet_id,
                'audit_year' => (int) $lockedSource->audit_year,
                'audit_month' => (int) $lockedSource->audit_month,
            ];

            return $this->createAudit(
                $outlet,
                $payload,
                $userId,
                ((int) $latest->audit_sequence) + 1,
                (string) $lockedSource->id,
            );
        }, 5);
    }

    public function updateLine(InventoryAudit $audit, InventoryAuditLine $line, array $data, ?string $userId, ?array $photoMeta = null): InventoryAuditLine
    {
        return DB::transaction(function () use ($audit, $line, $data, $userId, $photoMeta): InventoryAuditLine {
            $lockedAudit = InventoryAudit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();
            if ($lockedAudit->status === 'COMPLETED') {
                throw ValidationException::withMessages(['audit' => 'Audit sudah selesai dan dikunci.']);
            }
            $lockedLine = InventoryAuditLine::query()->whereKey($line->id)->where('audit_id', $lockedAudit->id)->lockForUpdate()->firstOrFail();
            $actualQty = round((float) $data['actual_quantity'], 3);
            if ($lockedLine->item_type === 'ASSET' && abs($actualQty - round($actualQty)) > 0.00001) {
                throw ValidationException::withMessages(['actual_quantity' => 'Jumlah fisik Asset harus berupa bilangan bulat.']);
            }
            if ($actualQty < 0) throw ValidationException::withMessages(['actual_quantity' => 'Jumlah fisik tidak boleh negatif.']);

            $actualCondition = trim((string) $data['actual_condition']);
            $actualLocation = trim((string) $data['actual_location']);
            $variance = round($actualQty - (float) $lockedLine->system_quantity, 3);
            $qtyDiff = abs($variance) > 0.00001;
            $conditionDiff = $this->normalize($actualCondition) !== $this->normalize((string) $lockedLine->system_condition);
            $locationDiff = $this->normalize($actualLocation) !== $this->normalize((string) $lockedLine->system_location);
            $hasDiff = $qtyDiff || $conditionDiff || $locationDiff;
            $notes = $this->nullable($data['notes'] ?? null);
            if ($hasDiff && $notes === null) {
                throw ValidationException::withMessages(['notes' => 'Catatan wajib diisi jika terdapat selisih qty, kondisi, atau lokasi.']);
            }

            $lockedLine->fill([
                'actual_quantity' => $actualQty,
                'actual_condition' => $actualCondition,
                'actual_location' => $actualLocation,
                'quantity_variance' => $variance,
                'has_quantity_discrepancy' => $qtyDiff,
                'has_condition_discrepancy' => $conditionDiff,
                'has_location_discrepancy' => $locationDiff,
                'has_discrepancy' => $hasDiff,
                'is_checked' => true,
                'notes' => $notes,
                'checked_at' => now(),
                'checked_by_user_id' => $userId,
            ] + ($photoMeta ?: []));
            $lockedLine->save();
            $this->refreshCounts($lockedAudit);
            return $lockedLine->fresh();
        }, 5);
    }

    public function complete(InventoryAudit $audit, ?string $userId, ?string $notes = null): InventoryAudit
    {
        return DB::transaction(function () use ($audit, $userId, $notes): InventoryAudit {
            $locked = InventoryAudit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'COMPLETED') return $locked;
            $unchecked = InventoryAuditLine::query()->where('audit_id', $locked->id)->where('is_checked', false)->count();
            if ($unchecked > 0) {
                throw ValidationException::withMessages(['lines' => 'Masih ada '.$unchecked.' item yang belum dicek. Selesaikan seluruh line sebelum finalisasi.']);
            }
            $this->refreshCounts($locked);
            $locked->status = 'COMPLETED';
            // audit_year/audit_month adalah periode laporan dan tidak boleh berubah saat finalisasi.
            $locked->completed_at = now();
            $locked->completed_by_user_id = $userId;
            if ($this->nullable($notes) !== null) $locked->notes = trim((string) $notes);
            $locked->save();
            return $locked->fresh();
        }, 5);
    }

    public function refreshCounts(InventoryAudit $audit): void
    {
        $base = InventoryAuditLine::query()->where('audit_id', $audit->id);
        InventoryAudit::query()->whereKey($audit->id)->update([
            'snapshot_line_count' => (clone $base)->count(),
            'checked_line_count' => (clone $base)->where('is_checked', true)->count(),
            'discrepancy_line_count' => (clone $base)->where('has_discrepancy', true)->count(),
            'updated_at' => now(),
        ]);
    }

    private function createAudit(Outlet $outlet, array $data, ?string $userId, int $sequence, ?string $reAuditOfId): InventoryAudit
    {
        $year = (int) $data['audit_year'];
        $month = (int) $data['audit_month'];
        $started = now();
        $code = strtoupper((string) ($outlet->code ?: Str::substr((string) $outlet->id, -6)));
        $code = preg_replace('/[^A-Z0-9]+/', '', $code) ?: 'OUTLET';

        $audit = InventoryAudit::query()->create([
            'id' => (string) Str::ulid(),
            'audit_number' => sprintf('GA-AUD-%04d%02d-%s-R%02d', $year, $month, $code, $sequence),
            'outlet_id' => (string) $outlet->id,
            'outlet_code_snapshot' => $outlet->code,
            'outlet_name_snapshot' => $outlet->name,
            'audit_year' => $year,
            'audit_month' => $month,
            'audit_date' => (string) $data['audit_date'],
            'audit_sequence' => $sequence,
            're_audit_of_id' => $reAuditOfId,
            'status' => 'IN_PROGRESS',
            'auditor_name' => trim((string) $data['auditor_name']),
            'auditor_nisj' => $this->nullable($data['auditor_nisj'] ?? null),
            'notes' => $this->nullable($data['notes'] ?? null),
            'started_at' => $started,
            'started_by_user_id' => $userId,
        ]);

        $this->snapshotAssets($audit);
        $this->snapshotInventory($audit);
        $this->refreshCounts($audit);
        return $audit->fresh();
    }

    private function snapshotAssets(InventoryAudit $audit): void
    {
        $rows = DB::table('ga_asset_location_balances as b')
            ->join('ga_assets as a', 'a.id', '=', 'b.asset_id')
            ->where('b.location_type', 'OUTLET')->where('b.outlet_id', $audit->outlet_id)->where('b.quantity', '>', 0)
            ->whereNull('a.deleted_at')
            ->select(['b.id as balance_id', 'b.quantity', 'b.condition', 'b.location_label', 'b.location_detail',
                'a.id as item_id', 'a.asset_code as item_code', 'a.item_name', 'a.serial_number'])
            ->orderBy('a.asset_code')->get();
        $this->insertSnapshotRows($audit, 'ASSET', $rows);
    }

    private function snapshotInventory(InventoryAudit $audit): void
    {
        $rows = DB::table('ga_inventory_location_balances as b')
            ->join('ga_inventory_items as i', 'i.id', '=', 'b.inventory_item_id')
            ->where('b.location_type', 'OUTLET')->where('b.outlet_id', $audit->outlet_id)->where('b.quantity', '>', 0)
            ->whereNull('i.deleted_at')
            ->select(['b.id as balance_id', 'b.quantity', 'b.condition', 'b.location_label', 'b.location_detail',
                'i.id as item_id', 'i.inventory_code as item_code', 'i.item_name'])
            ->orderBy('i.inventory_code')->get();
        $this->insertSnapshotRows($audit, 'INVENTORY', $rows);
    }

    private function insertSnapshotRows(InventoryAudit $audit, string $type, $rows): void
    {
        $now = now();
        $payload = [];
        foreach ($rows as $row) {
            $location = trim((string) ($row->location_detail ?? '')) ?: trim((string) ($row->location_label ?? $audit->outlet_name_snapshot));
            $payload[] = [
                'id' => (string) Str::ulid(), 'audit_id' => (string) $audit->id, 'item_type' => $type,
                'asset_id' => $type === 'ASSET' ? (string) $row->item_id : null,
                'inventory_item_id' => $type === 'INVENTORY' ? (string) $row->item_id : null,
                'balance_id_snapshot' => (string) $row->balance_id,
                'item_code_snapshot' => (string) $row->item_code, 'item_name_snapshot' => (string) $row->item_name,
                'serial_number_snapshot' => $type === 'ASSET' ? $this->nullable($row->serial_number ?? null) : null,
                'system_quantity' => round((float) $row->quantity, 3), 'system_condition' => (string) ($row->condition ?: 'Baik'),
                'system_location' => $location, 'system_location_detail' => $this->nullable($row->location_detail ?? null),
                'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($payload) >= 250) { DB::table('ga_inventory_audit_lines')->insert($payload); $payload = []; }
        }
        if ($payload) DB::table('ga_inventory_audit_lines')->insert($payload);
    }

    private function nullable($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }
}
