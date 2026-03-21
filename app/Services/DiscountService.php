<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiscountService
{
    public function paginateForOutlet(string $outletId, array $filters): LengthAwarePaginator
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $isActive = $filters['is_active'] ?? null;
        $appliesTo = $filters['applies_to'] ?? null;
        $sort = $filters['sort'] ?? 'updated_at';
        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = Discount::query()->where('outlet_id', $outletId);

        if ($q !== '') {
            $query->where(function (Builder $b) use ($q) {
                $b->where('code', 'like', '%'.$q.'%')
                    ->orWhere('name', 'like', '%'.$q.'%');
            });
        }

        if ($isActive !== null && $isActive !== '') {
            $query->where('is_active', (int) $isActive === 1);
        }

        if ($appliesTo) {
            $query->where('applies_to', strtoupper((string) $appliesTo));
        }

        if (!in_array($sort, ['code', 'name', 'is_active', 'updated_at', 'created_at'], true)) {
            $sort = 'updated_at';
        }

        return $query->orderBy($sort, $dir)->paginate($perPage);
    }

    public function create(string $outletId, array $data): Discount
    {
        return DB::transaction(function () use ($outletId, $data) {
            $discount = Discount::query()->create([
                'outlet_id' => $outletId,
                'code' => strtoupper(trim((string) $data['code'])),
                'name' => trim((string) $data['name']),
                'applies_to' => strtoupper((string) $data['applies_to']),
                'discount_type' => strtoupper((string) $data['discount_type']),
                'discount_value' => (int) $data['discount_value'],
                'is_active' => (bool) ($data['is_active'] ?? true),
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
            ]);

            $this->syncAttachments($discount, $data);

            return $discount->load(['products', 'customers']);
        });
    }

    public function update(Discount $discount, array $data): Discount
    {
        return DB::transaction(function () use ($discount, $data) {
            $payload = [];

            foreach (['code','name','applies_to','discount_type','discount_value','is_active','starts_at','ends_at'] as $k) {
                if (array_key_exists($k, $data)) $payload[$k] = $data[$k];
            }

            if (array_key_exists('code', $payload)) {
                $payload['code'] = strtoupper(trim((string) $payload['code']));
            }
            if (array_key_exists('name', $payload)) {
                $payload['name'] = trim((string) $payload['name']);
            }
            if (array_key_exists('applies_to', $payload)) {
                $payload['applies_to'] = strtoupper((string) $payload['applies_to']);
            }
            if (array_key_exists('discount_type', $payload)) {
                $payload['discount_type'] = strtoupper((string) $payload['discount_type']);
            }
            if (array_key_exists('discount_value', $payload)) {
                $payload['discount_value'] = (int) $payload['discount_value'];
            }

            $discount->fill($payload)->save();

            $this->syncAttachments($discount, $data);

            return $discount->load(['products', 'customers']);
        });
    }

    public function delete(Discount $discount): void
    {
        $discount->delete();
    }

    private function syncAttachments(Discount $discount, array $data): void
    {
        $appliesTo = strtoupper((string) ($data['applies_to'] ?? $discount->applies_to));

        if ($appliesTo === 'PRODUCT') {
            $ids = array_values(array_unique(array_filter($data['product_ids'] ?? [])));
            if (count($ids) === 0) {
                throw ValidationException::withMessages(['product_ids' => ['product_ids is required for PRODUCT discount.']]);
            }

            $exists = Product::query()->whereIn('id', $ids)->pluck('id')->map(fn ($x) => (string) $x)->all();
            $missing = array_values(array_diff($ids, $exists));
            if (!empty($missing)) {
                throw ValidationException::withMessages(['product_ids' => ['Some products not found.']]);
            }

            $discount->products()->sync($ids);
            // clear other pivot
            $discount->customers()->sync([]);
            return;
        }

        if ($appliesTo === 'CUSTOMER') {
            $ids = array_values(array_unique(array_filter($data['customer_ids'] ?? [])));
            if (count($ids) === 0) {
                throw ValidationException::withMessages(['customer_ids' => ['customer_ids is required for CUSTOMER discount.']]);
            }

            $exists = Customer::query()
                ->where('outlet_id', $discount->outlet_id)
                ->whereIn('id', $ids)
                ->pluck('id')->map(fn ($x) => (string) $x)->all();

            $missing = array_values(array_diff($ids, $exists));
            if (!empty($missing)) {
                throw ValidationException::withMessages(['customer_ids' => ['Some customers not found for this outlet.']]);
            }

            $discount->customers()->sync($ids);
            $discount->products()->sync([]);
            return;
        }

        // GLOBAL: clear all pivots
        $discount->products()->sync([]);
        $discount->customers()->sync([]);
    }
}
