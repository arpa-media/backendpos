<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\PaymentMethod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentMethodService
{
    public function paginateForOutlet(string $outletId, array $filters): LengthAwarePaginator
    {
        $this->assertOutletExists($outletId);
        $this->ensureOutletPivotCoverage($outletId);

        $q = $filters['q'] ?? null;
        $type = $filters['type'] ?? null;
        $isActive = array_key_exists('is_active', $filters) ? (bool) $filters['is_active'] : null;

        $perPage = (int) ($filters['per_page'] ?? 15);
        $sort = $filters['sort'] ?? 'sort_order';
        $forPos = (bool) ($filters['for_pos'] ?? false);
        $dir = $filters['dir'] ?? 'asc';

        $query = PaymentMethod::query()
            ->where('is_active', true)
            ->with(['outlets' => function ($sub) use ($outletId) {
                $sub->where('outlets.id', $outletId);
            }]);

        if ($forPos) {
            $query->whereHas('outlets', function ($sub) use ($outletId) {
                $sub->where('outlets.id', $outletId)
                    ->where('outlet_payment_method.is_active', true);
            });
        } else {
            if (!is_null($isActive)) {
                $query->whereHas('outlets', function ($sub) use ($outletId, $isActive) {
                    $sub->where('outlets.id', $outletId)
                        ->where('outlet_payment_method.is_active', $isActive);
                });
            } else {
                $query->whereHas('outlets', function ($sub) use ($outletId) {
                    $sub->where('outlets.id', $outletId);
                });
            }
        }

        if ($q) {
            $query->where('name', 'like', '%'.$q.'%');
        }

        if ($type) {
            $query->where('type', $type);
        }

        return $query
            ->orderBy($sort, $dir)
            ->orderBy('name', 'asc')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(string $outletId, array $data): PaymentMethod
    {
        return DB::transaction(function () use ($outletId, $data) {
            $this->assertOutletExists($outletId);

            $name = trim($data['name']);
            $this->assertUniqueName($name, (string) $data['type']);

            /** @var PaymentMethod $pm */
            $pm = PaymentMethod::query()->create([
                'name' => $name,
                'type' => $data['type'],
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => true,
            ]);

            $isActiveInOutlet = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

            $outletIds = Outlet::query()->pluck('id')->all();
            $now = now();
            $rows = [];
            foreach ($outletIds as $oid) {
                $rows[] = [
                    'outlet_id' => (string) $oid,
                    'payment_method_id' => (string) $pm->id,
                    'is_active' => ((string) $oid === (string) $outletId) ? $isActiveInOutlet : false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('outlet_payment_method')->upsert(
                $rows,
                ['outlet_id', 'payment_method_id'],
                ['is_active', 'updated_at']
            );

            return $pm->load(['outlets' => function ($q) use ($outletId) {
                $q->where('outlets.id', $outletId);
            }]);
        });
    }

    public function update(string $outletId, PaymentMethod $method, array $data): PaymentMethod
    {
        return DB::transaction(function () use ($outletId, $method, $data) {
            $this->assertOutletExists($outletId);
            $this->ensureOutletPivotCoverage($outletId, $method);

            if (array_key_exists('name', $data)) {
                $name = trim($data['name']);
                $typeForUniq = array_key_exists('type', $data) ? (string) $data['type'] : (string) $method->type;
                $this->assertUniqueName($name, $typeForUniq, (string) $method->id);
                $method->name = $name;
            }

            if (array_key_exists('type', $data)) {
                $method->type = $data['type'];
            }

            if (array_key_exists('sort_order', $data)) {
                $method->sort_order = (int) ($data['sort_order'] ?? 0);
            }

            $method->save();

            if (array_key_exists('is_active', $data)) {
                $this->setActiveForOutlet($outletId, $method, (bool) $data['is_active']);
            } else {
                $this->ensureOutletPivotExists($outletId, $method);
            }

            return $method->load(['outlets' => function ($q) use ($outletId) {
                $q->where('outlets.id', $outletId);
            }]);
        });
    }

    public function setActiveForOutlet(string $outletId, PaymentMethod $method, bool $isActive): PaymentMethod
    {
        $this->assertOutletExists($outletId);
        $this->ensureOutletPivotCoverage($outletId, $method);

        DB::table('outlet_payment_method')->updateOrInsert(
            ['payment_method_id' => (string) $method->id, 'outlet_id' => (string) $outletId],
            ['is_active' => $isActive, 'created_at' => now(), 'updated_at' => now()]
        );

        return $method->fresh()->load(['outlets' => function ($q) use ($outletId) {
            $q->where('outlets.id', $outletId);
        }]);
    }

    public function ensureOutletPivotCoverage(string $outletId, ?PaymentMethod $onlyMethod = null): void
    {
        $this->assertOutletExists($outletId);

        $methodIds = $onlyMethod
            ? [(string) $onlyMethod->id]
            : PaymentMethod::query()->pluck('id')->map(fn ($id) => (string) $id)->all();

        if (empty($methodIds)) {
            return;
        }

        $existing = DB::table('outlet_payment_method')
            ->where('outlet_id', (string) $outletId)
            ->whereIn('payment_method_id', $methodIds)
            ->pluck('payment_method_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $existingMap = array_fill_keys($existing, true);
        $now = now();
        $rows = [];

        foreach ($methodIds as $methodId) {
            if (isset($existingMap[$methodId])) {
                continue;
            }

            $rows[] = [
                'outlet_id' => (string) $outletId,
                'payment_method_id' => (string) $methodId,
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($rows)) {
            DB::table('outlet_payment_method')->insert($rows);
        }
    }

    private function ensureOutletPivotExists(string $outletId, PaymentMethod $method): void
    {
        $exists = DB::table('outlet_payment_method')
            ->where('payment_method_id', (string) $method->id)
            ->where('outlet_id', (string) $outletId)
            ->exists();

        if (! $exists) {
            DB::table('outlet_payment_method')->insert([
                'payment_method_id' => (string) $method->id,
                'outlet_id' => (string) $outletId,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function delete(PaymentMethod $method): void
    {
        $method->delete();
    }

    private function assertUniqueName(string $name, string $type, ?string $ignoreId = null): void
    {
        $q = PaymentMethod::query()->where('name', $name)->where('type', $type);
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($q->exists()) {
            throw ValidationException::withMessages([
                'name' => ['Payment method name already exists.'],
            ]);
        }
    }

    public function ensureOutletExists(string $outletId): void
    {
        if (! Outlet::query()->whereKey($outletId)->exists()) {
            throw ValidationException::withMessages([
                'outlet_id' => ['Outlet not found.'],
            ]);
        }
    }

    private function assertOutletExists(string $outletId): void
    {
        $this->ensureOutletExists($outletId);
    }
}

