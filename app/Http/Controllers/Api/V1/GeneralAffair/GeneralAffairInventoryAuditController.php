<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Models\GeneralAffair\InventoryAudit;
use App\Models\GeneralAffair\InventoryAuditLine;
use App\Models\Outlet;
use App\Services\GeneralAffair\InventoryAuditPhotoService;
use App\Services\GeneralAffair\InventoryAuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GeneralAffairInventoryAuditController extends Controller
{
    public function __construct(
        private readonly InventoryAuditService $service,
        private readonly InventoryAuditPhotoService $photos,
    ) {}

    public function meta(Request $request): JsonResponse
    {
        $year = (int) ($request->integer('year') ?: now()->year);
        $auditedIds = InventoryAudit::query()->where('audit_year', $year)->pluck('outlet_id')->map(fn ($v) => (string) $v)->all();
        return response()->json(['data' => [
            'outlets' => Outlet::query()->orderBy('name')->get(['id', 'code', 'name']),
            'unaudited_outlets' => Outlet::query()->whereNotIn('id', $auditedIds)->orderBy('name')->get(['id', 'code', 'name']),
            'conditions' => InventoryAuditService::CONDITIONS,
            'current_year' => now()->year,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'outlet_id' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::in(['NOT_AUDITED', 'IN_PROGRESS', 'COMPLETED'])],
        ]);
        $year = (int) ($data['year'] ?? now()->year);
        $outlets = Outlet::query()->when($data['outlet_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('name')->get(['id', 'code', 'name']);
        $audits = InventoryAudit::query()->where('audit_year', $year)->whereIn('outlet_id', $outlets->pluck('id'))
            ->get()->keyBy(fn (InventoryAudit $a) => (string) $a->outlet_id);

        $rows = $outlets->map(function ($outlet) use ($audits, $year) {
            $audit = $audits->get((string) $outlet->id);
            return [
                'outlet' => ['id' => (string) $outlet->id, 'code' => $outlet->code, 'name' => $outlet->name],
                'year' => $year,
                'status' => $audit?->status ?: 'NOT_AUDITED',
                'audit' => $audit ? $this->serializeAudit($audit) : null,
            ];
        });
        if (filled($data['month'] ?? null) && ($data['status'] ?? '') !== 'NOT_AUDITED') {
            $month = (int) $data['month'];
            $rows = $rows->filter(fn ($row) => $row['audit'] && (int) $row['audit']['audit_month'] === $month)->values();
        }
        if (filled($data['status'] ?? null)) {
            $status = (string) $data['status'];
            $rows = $rows->filter(fn ($row) => $row['status'] === $status)->values();
        }

        $all = collect($outlets)->map(fn ($o) => $audits->get((string) $o->id));
        return response()->json(['data' => [
            'items' => $rows->values(),
            'summary' => [
                'outlets' => $outlets->count(),
                'completed' => $all->filter(fn ($a) => $a?->status === 'COMPLETED')->count(),
                'in_progress' => $all->filter(fn ($a) => $a?->status === 'IN_PROGRESS')->count(),
                'not_audited' => $all->filter(fn ($a) => ! $a)->count(),
            ],
        ]]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'string', 'exists:outlets,id'],
            'audit_year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'auditor_name' => ['required', 'string', 'max:180'],
            'auditor_nisj' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $audit = $this->service->start($data, $request->user()?->id);
        return response()->json(['data' => $this->serializeAudit($audit), 'message' => 'Audit dimulai. Snapshot Asset dan Inventory outlet sudah dikunci sebagai baseline.'], 201);
    }

    public function show(string $id): JsonResponse
    {
        $audit = InventoryAudit::query()->with(['startedBy:id,name,nisj', 'completedBy:id,name,nisj'])->findOrFail($id);
        return response()->json(['data' => $this->serializeAudit($audit)]);
    }

    public function lines(Request $request, string $id): JsonResponse
    {
        $audit = InventoryAudit::query()->findOrFail($id);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'item_type' => ['nullable', Rule::in(['ASSET', 'INVENTORY'])],
            'state' => ['nullable', Rule::in(['UNCHECKED', 'CHECKED', 'DISCREPANCY', 'MATCH'])],
        ]);
        $q = InventoryAuditLine::query()->where('audit_id', $audit->id)->with('checkedBy:id,name,nisj');
        if (filled($data['q'] ?? null)) {
            $term = '%'.trim((string) $data['q']).'%';
            $q->where(fn (Builder $x) => $x->where('item_code_snapshot', 'like', $term)->orWhere('item_name_snapshot', 'like', $term)->orWhere('serial_number_snapshot', 'like', $term));
        }
        if (filled($data['item_type'] ?? null)) $q->where('item_type', $data['item_type']);
        match ($data['state'] ?? '') {
            'UNCHECKED' => $q->where('is_checked', false),
            'CHECKED' => $q->where('is_checked', true),
            'DISCREPANCY' => $q->where('has_discrepancy', true),
            'MATCH' => $q->where('is_checked', true)->where('has_discrepancy', false),
            default => null,
        };
        $lines = $q->orderBy('item_type')->orderBy('item_code_snapshot')->get();
        $recapBase = InventoryAuditLine::query()->where('audit_id', $audit->id);
        $recap = [
            'asset' => (clone $recapBase)->where('item_type', 'ASSET')->count(),
            'inventory' => (clone $recapBase)->where('item_type', 'INVENTORY')->count(),
            'matches' => (clone $recapBase)->where('is_checked', true)->where('has_discrepancy', false)->count(),
            'quantity_discrepancy' => (clone $recapBase)->where('has_quantity_discrepancy', true)->count(),
            'condition_discrepancy' => (clone $recapBase)->where('has_condition_discrepancy', true)->count(),
            'location_discrepancy' => (clone $recapBase)->where('has_location_discrepancy', true)->count(),
        ];
        return response()->json(['data' => ['audit' => $this->serializeAudit($audit), 'items' => $lines->map(fn ($line) => $this->serializeLine($line))->values(), 'recap' => $recap]]);
    }

    public function updateLine(Request $request, string $id, string $lineId): JsonResponse
    {
        $audit = InventoryAudit::query()->findOrFail($id);
        $line = InventoryAuditLine::query()->where('audit_id', $audit->id)->findOrFail($lineId);
        $data = $request->validate([
            'actual_quantity' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'actual_condition' => ['required', Rule::in(InventoryAuditService::CONDITIONS)],
            'actual_location' => ['required', 'string', 'max:240'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $photoMeta = null;
        $oldPhoto = [$line->photo_disk, $line->photo_path];
        if ($request->hasFile('photo')) {
            $line->load('audit');
            $photoMeta = $this->photos->store($request->file('photo'), (string) $line->audit->audit_number, (string) $line->item_code_snapshot);
        }
        try {
            $updated = $this->service->updateLine($audit, $line, $data, $request->user()?->id, $photoMeta);
        } catch (\Throwable $e) {
            if ($photoMeta) $this->photos->delete($photoMeta['photo_disk'] ?? null, $photoMeta['photo_path'] ?? null);
            throw $e;
        }
        if ($photoMeta) $this->photos->delete($oldPhoto[0] ?? null, $oldPhoto[1] ?? null);
        $updated->load('checkedBy:id,name,nisj');
        return response()->json(['data' => $this->serializeLine($updated), 'message' => $updated->has_discrepancy ? 'Hasil audit tersimpan dengan selisih.' : 'Hasil audit tersimpan dan sesuai baseline.']);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:3000']]);
        $audit = InventoryAudit::query()->findOrFail($id);
        $done = $this->service->complete($audit, $request->user()?->id, $data['notes'] ?? null);
        return response()->json(['data' => $this->serializeAudit($done), 'message' => 'Audit selesai dan dikunci. Hasil audit tidak mengubah saldo inventory secara otomatis.']);
    }

    private function serializeAudit(InventoryAudit $audit): array
    {
        return [
            'id' => (string) $audit->id, 'audit_number' => $audit->audit_number,
            'outlet_id' => (string) $audit->outlet_id, 'outlet_code' => $audit->outlet_code_snapshot, 'outlet_name' => $audit->outlet_name_snapshot,
            'audit_year' => (int) $audit->audit_year, 'audit_month' => (int) $audit->audit_month, 'status' => $audit->status,
            'auditor_name' => $audit->auditor_name, 'auditor_nisj' => $audit->auditor_nisj, 'notes' => $audit->notes,
            'snapshot_line_count' => (int) $audit->snapshot_line_count, 'checked_line_count' => (int) $audit->checked_line_count,
            'discrepancy_line_count' => (int) $audit->discrepancy_line_count,
            'started_at' => $audit->started_at?->toIso8601String(), 'completed_at' => $audit->completed_at?->toIso8601String(),
            'started_by' => $audit->relationLoaded('startedBy') && $audit->startedBy ? ['name' => $audit->startedBy->name, 'nisj' => $audit->startedBy->nisj] : null,
            'completed_by' => $audit->relationLoaded('completedBy') && $audit->completedBy ? ['name' => $audit->completedBy->name, 'nisj' => $audit->completedBy->nisj] : null,
        ];
    }

    private function serializeLine(InventoryAuditLine $line): array
    {
        return [
            'id' => (string) $line->id, 'item_type' => $line->item_type, 'item_code' => $line->item_code_snapshot,
            'item_name' => $line->item_name_snapshot, 'serial_number' => $line->serial_number_snapshot,
            'system_quantity' => (float) $line->system_quantity, 'system_condition' => $line->system_condition,
            'system_location' => $line->system_location, 'system_location_detail' => $line->system_location_detail,
            'actual_quantity' => $line->actual_quantity !== null ? (float) $line->actual_quantity : null,
            'actual_condition' => $line->actual_condition, 'actual_location' => $line->actual_location,
            'quantity_variance' => $line->quantity_variance !== null ? (float) $line->quantity_variance : null,
            'has_quantity_discrepancy' => (bool) $line->has_quantity_discrepancy,
            'has_condition_discrepancy' => (bool) $line->has_condition_discrepancy,
            'has_location_discrepancy' => (bool) $line->has_location_discrepancy,
            'has_discrepancy' => (bool) $line->has_discrepancy, 'is_checked' => (bool) $line->is_checked,
            'notes' => $line->notes, 'photo_url' => $this->photos->url($line), 'checked_at' => $line->checked_at?->toIso8601String(),
            'checked_by' => $line->checkedBy ? ['name' => $line->checkedBy->name, 'nisj' => $line->checkedBy->nisj] : null,
        ];
    }
}
