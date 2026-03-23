<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Tax;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxService
{
    public function paginateForOutlet(string $outletId, array $filters): LengthAwarePaginator
    {
        $this->assertOutletExists($outletId);
        $this->ensureOutletPivotCoverage($outletId);
        $this->ensureFallbackDefault($outletId);

        $q = $filters['q'] ?? null;
        $isActive = array_key_exists('is_active', $filters) ? (bool) $filters['is_active'] : null;
        $isDefault = array_key_exists('is_default', $filters) ? (bool) $filters['is_default'] : null;
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = Tax::query()
            ->with(['outlets' => function ($sub) use ($outletId) {
                $sub->where('outlets.id', $outletId);
            }]);

        $query->whereHas('outlets', function ($sub) use ($outletId, $isActive, $isDefault) {
            $sub->where('outlets.id', $outletId);
            if (!is_null($isActive)) {
                $sub->where('outlet_tax.is_active', $isActive);
            }
            if (!is_null($isDefault)) {
                $sub->where('outlet_tax.is_default', $isDefault);
            }
        });

        if ($q) {
            $query->where(function ($w) use ($q) {
                $w->where('jenis_pajak', 'like', '%'.$q.'%')
                    ->orWhere('display_name', 'like', '%'.$q.'%');
            });
        }

        return $query
            ->orderByRaw('COALESCE(sort_order, 999999) ASC')
            ->orderBy('display_name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(string $outletId, array $data): Tax
    {
        return DB::transaction(function () use ($outletId, $data) {
            $this->assertOutletExists($outletId);
            $this->assertUniqueJenisPajak(trim((string) $data['jenis_pajak']));

            /** @var Tax $tax */
            $tax = Tax::query()->create([
                'jenis_pajak' => trim((string) $data['jenis_pajak']),
                'display_name' => trim((string) $data['display_name']),
                'percent' => (int) ($data['percent'] ?? 0),
                'sort_order' => array_key_exists('sort_order', $data) && $data['sort_order'] !== null ? (int) $data['sort_order'] : null,
                'is_active' => true,
                'is_default' => false,
            ]);

            $isActiveInOutlet = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;
            $isDefaultInOutlet = $isActiveInOutlet && (bool) ($data['is_default'] ?? false);

            $outletIds = Outlet::query()->pluck('id')->all();
            $now = now();
            $rows = [];
            foreach ($outletIds as $oid) {
                $rows[] = [
                    'outlet_id' => (string) $oid,
                    'tax_id' => (string) $tax->id,
                    'is_active' => ((string) $oid === (string) $outletId) ? $isActiveInOutlet : false,
                    'is_default' => ((string) $oid === (string) $outletId) ? $isDefaultInOutlet : false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('outlet_tax')->upsert(
                $rows,
                ['outlet_id', 'tax_id'],
                ['is_active', 'is_default', 'updated_at']
            );

            $this->stabilizeForOutlet($outletId, $tax);

            return $tax->fresh()->load(['outlets' => function ($q) use ($outletId) {
                $q->where('outlets.id', $outletId);
            }]);
        });
    }

    public function update(string $outletId, Tax $tax, array $data): Tax
    {
        return DB::transaction(function () use ($outletId, $tax, $data) {
            $this->assertOutletExists($outletId);
            $this->ensureOutletPivotCoverage($outletId, $tax);

            if (array_key_exists('jenis_pajak', $data)) {
                $jenisPajak = trim((string) $data['jenis_pajak']);
                $this->assertUniqueJenisPajak($jenisPajak, (string) $tax->id);
                $tax->jenis_pajak = $jenisPajak;
            }

            if (array_key_exists('display_name', $data)) {
                $tax->display_name = trim((string) $data['display_name']);
            }

            if (array_key_exists('percent', $data)) {
                $tax->percent = (int) ($data['percent'] ?? 0);
            }

            if (array_key_exists('sort_order', $data)) {
                $tax->sort_order = $data['sort_order'] === null ? null : (int) $data['sort_order'];
            }

            $tax->save();

            if (array_key_exists('is_active', $data)) {
                $this->setActiveForOutlet($outletId, $tax, (bool) $data['is_active']);
            } else {
                $this->ensureOutletPivotExists($outletId, $tax);
            }

            if (array_key_exists('is_default', $data) && (bool) $data['is_default'] === true) {
                $this->setDefaultForOutlet($outletId, $tax);
            } else {
                $this->stabilizeForOutlet($outletId, $tax);
            }

            return $tax->fresh()->load(['outlets' => function ($q) use ($outletId) {
                $q->where('outlets.id', $outletId);
            }]);
        });
    }

    public function setActiveForOutlet(string $outletId, Tax $tax, bool $isActive): Tax
    {
        $this->assertOutletExists($outletId);
        $this->ensureOutletPivotCoverage($outletId, $tax);

        DB::table('outlet_tax')->updateOrInsert(
            ['tax_id' => (string) $tax->id, 'outlet_id' => (string) $outletId],
            [
                'is_active' => $isActive,
                'is_default' => $isActive ? DB::raw('is_default') : false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        if (! $isActive) {
            DB::table('outlet_tax')
                ->where('tax_id', (string) $tax->id)
                ->where('outlet_id', (string) $outletId)
                ->update(['is_default' => false, 'updated_at' => now()]);
        }

        $this->ensureFallbackDefault($outletId);

        return $tax->fresh()->load(['outlets' => function ($q) use ($outletId) {
            $q->where('outlets.id', $outletId);
        }]);
    }

    public function setDefaultForOutlet(string $outletId, Tax $tax): Tax
    {
        $this->assertOutletExists($outletId);
        $this->ensureOutletPivotCoverage($outletId, $tax);

        return DB::transaction(function () use ($outletId, $tax) {
            DB::table('outlet_tax')
                ->where('outlet_id', (string) $outletId)
                ->where('tax_id', '!=', (string) $tax->id)
                ->update(['is_default' => false, 'updated_at' => now()]);

            DB::table('outlet_tax')->updateOrInsert(
                ['tax_id' => (string) $tax->id, 'outlet_id' => (string) $outletId],
                ['is_active' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
            );

            return $tax->fresh()->load(['outlets' => function ($q) use ($outletId) {
                $q->where('outlets.id', $outletId);
            }]);
        });
    }

    public function getActiveDefaultForOutlet(string $outletId): ?Tax
    {
        $this->assertOutletExists($outletId);
        $this->ensureOutletPivotCoverage($outletId);
        $this->ensureFallbackDefault($outletId);

        return Tax::query()
            ->whereHas('outlets', function ($sub) use ($outletId) {
                $sub->where('outlets.id', $outletId)
                    ->where('outlet_tax.is_active', true)
                    ->where('outlet_tax.is_default', true);
            })
            ->with(['outlets' => function ($sub) use ($outletId) {
                $sub->where('outlets.id', $outletId);
            }])
            ->first();
    }

    public function ensureFallbackDefault(string $outletId): ?Tax
    {
        $this->assertOutletExists($outletId);
        $this->ensureOutletPivotCoverage($outletId);

        $existingId = DB::table('outlet_tax')
            ->where('outlet_id', (string) $outletId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('tax_id');

        if ($existingId) {
            return Tax::query()->find($existingId);
        }

        $fallback = Tax::query()
            ->whereHas('outlets', function ($sub) use ($outletId) {
                $sub->where('outlets.id', $outletId)
                    ->where('outlet_tax.is_active', true);
            })
            ->orderByRaw('COALESCE(sort_order, 999999) ASC')
            ->orderBy('updated_at')
            ->orderBy('created_at')
            ->first();

        if (! $fallback) {
            return null;
        }

        DB::table('outlet_tax')
            ->where('outlet_id', (string) $outletId)
            ->update(['is_default' => false, 'updated_at' => now()]);

        DB::table('outlet_tax')
            ->where('outlet_id', (string) $outletId)
            ->where('tax_id', (string) $fallback->id)
            ->update(['is_default' => true, 'updated_at' => now()]);

        return $fallback->fresh();
    }

    public function ensureOutletPivotCoverage(string $outletId, ?Tax $onlyTax = null): void
    {
        $this->assertOutletExists($outletId);

        $taxIds = $onlyTax
            ? [(string) $onlyTax->id]
            : Tax::query()->pluck('id')->map(fn ($id) => (string) $id)->all();

        if (empty($taxIds)) {
            return;
        }

        $existing = DB::table('outlet_tax')
            ->where('outlet_id', (string) $outletId)
            ->whereIn('tax_id', $taxIds)
            ->pluck('tax_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $existingMap = array_fill_keys($existing, true);
        $rows = [];
        $now = now();
        foreach ($taxIds as $taxId) {
            if (isset($existingMap[$taxId])) {
                continue;
            }
            $rows[] = [
                'outlet_id' => (string) $outletId,
                'tax_id' => (string) $taxId,
                'is_active' => false,
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            DB::table('outlet_tax')->insert($rows);
        }
    }

    private function stabilizeForOutlet(string $outletId, Tax $tax): Tax
    {
        $this->ensureOutletPivotCoverage($outletId, $tax);
        $this->ensureFallbackDefault($outletId);
        return $tax->fresh();
    }

    private function ensureOutletPivotExists(string $outletId, Tax $tax): void
    {
        $exists = DB::table('outlet_tax')
            ->where('tax_id', (string) $tax->id)
            ->where('outlet_id', (string) $outletId)
            ->exists();

        if (! $exists) {
            DB::table('outlet_tax')->insert([
                'tax_id' => (string) $tax->id,
                'outlet_id' => (string) $outletId,
                'is_active' => false,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function assertUniqueJenisPajak(string $jenisPajak, ?string $ignoreId = null): void
    {
        $query = Tax::query()->where('jenis_pajak', $jenisPajak);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'jenis_pajak' => ['Jenis pajak already exists.'],
            ]);
        }
    }

    private function assertOutletExists(string $outletId): void
    {
        if (! Outlet::query()->whereKey($outletId)->exists()) {
            throw ValidationException::withMessages([
                'outlet_id' => ['Outlet not found.'],
            ]);
        }
    }
}
