<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\SavedBillDeleteHistory;
use App\Support\BackofficeOutletScope;
use App\Support\OutletScope;
use Illuminate\Http\Request;

class SavedBillDeleteHistoryController extends Controller
{
    private function normalizeString($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function normalizeItems($items): array
    {
        return collect(is_array($items) ? $items : [])
            ->map(fn ($item) => is_array($item) ? $item : [])
            ->values()
            ->all();
    }

    private function sumItems(array $items, string $field, string $fallbackField = null): int
    {
        return (int) collect($items)->sum(function ($item) use ($field, $fallbackField) {
            $primary = $item[$field] ?? null;
            if (($primary === null || $primary === '') && $fallbackField) {
                $primary = $item[$fallbackField] ?? 0;
            }
            return (float) ($primary ?? 0);
        });
    }

    private function resolveOutletId(Request $request, array $validated = []): ?string
    {
        $explicit = $this->normalizeString($validated['outlet_id'] ?? null);
        if ($explicit) {
            return $explicit;
        }

        $scope = OutletScope::id($request);
        if ($scope) {
            return (string) $scope;
        }

        $user = $request->user();
        return $this->normalizeString($user?->outlet_id ?? $user?->outlet?->id ?? null);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => ['nullable', 'string', 'max:64'],
            'saved_bill_id' => ['required', 'string', 'max:120'],
            'bill_name' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', 'string', 'max:80'],
            'table_label' => ['nullable', 'string', 'max:120'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'cashier_id' => ['nullable', 'string', 'max:64'],
            'cashier_name' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'bill_snapshot' => ['nullable', 'array'],
            'items_snapshot' => ['nullable', 'array'],
            'subtotal' => ['nullable', 'numeric'],
            'discount_amount' => ['nullable', 'numeric'],
            'tax_total' => ['nullable', 'numeric'],
            'grand_total' => ['nullable', 'numeric'],
            'item_count' => ['nullable', 'integer'],
            'qty_total' => ['nullable', 'numeric'],
        ]);

        $billSnapshot = is_array($validated['bill_snapshot'] ?? null) ? $validated['bill_snapshot'] : [];
        $items = $this->normalizeItems($validated['items_snapshot'] ?? ($billSnapshot['items'] ?? []));
        $user = $request->user();
        $subtotal = (int) round((float) ($validated['subtotal'] ?? $billSnapshot['subtotal'] ?? $this->sumItems($items, 'line_total', 'total')));
        $discount = (int) round((float) ($validated['discount_amount'] ?? $billSnapshot['discount_amount'] ?? $billSnapshot['discount_total'] ?? 0));
        $tax = (int) round((float) ($validated['tax_total'] ?? $billSnapshot['tax_total'] ?? $billSnapshot['tax_amount'] ?? 0));
        $grand = (int) round((float) ($validated['grand_total'] ?? $billSnapshot['grand_total'] ?? max(0, $subtotal - $discount + $tax)));
        $qtyTotal = (int) round((float) ($validated['qty_total'] ?? $this->sumItems($items, 'qty')));

        $history = SavedBillDeleteHistory::create([
            'outlet_id' => $this->resolveOutletId($request, $validated),
            'saved_bill_id' => (string) $validated['saved_bill_id'],
            'bill_name' => $this->normalizeString($validated['bill_name'] ?? $billSnapshot['bill_name'] ?? $billSnapshot['name'] ?? null),
            'channel' => $this->normalizeString($validated['channel'] ?? $billSnapshot['channel'] ?? null),
            'table_label' => $this->normalizeString($validated['table_label'] ?? $billSnapshot['table_label'] ?? $billSnapshot['table_number'] ?? null),
            'customer_name' => $this->normalizeString($validated['customer_name'] ?? $billSnapshot['customer_name'] ?? null),
            'cashier_id' => $this->normalizeString($validated['cashier_id'] ?? $billSnapshot['cashier_id'] ?? null),
            'cashier_name' => $this->normalizeString($validated['cashier_name'] ?? $billSnapshot['cashier_name'] ?? null),
            'deleted_by_user_id' => $user?->id ? (string) $user->id : null,
            'deleted_by_name' => $user?->name ?: null,
            'reason' => $this->normalizeString($validated['reason'] ?? null),
            'pin_verified_at' => now(),
            'bill_snapshot' => $billSnapshot ?: null,
            'items_snapshot' => $items ?: null,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_total' => $tax,
            'grand_total' => $grand,
            'item_count' => (int) ($validated['item_count'] ?? count($items)),
            'qty_total' => $qtyTotal,
        ]);

        return ApiResponse::ok($this->serialize($history->fresh('outlet')), 'History delete save bill tersimpan.', 201);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'outlet_filter' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $scope = BackofficeOutletScope::resolve($request, $validated['outlet_filter'] ?? null);
        $ids = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        $query = SavedBillDeleteHistory::query()
            ->with('outlet')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (count($ids) === 1) {
            $query->where('outlet_id', $ids[0]);
        } elseif (count($ids) > 1) {
            $query->whereIn('outlet_id', $ids);
        }

        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('bill_name', 'like', $like)
                    ->orWhere('saved_bill_id', 'like', $like)
                    ->orWhere('deleted_by_name', 'like', $like)
                    ->orWhere('cashier_name', 'like', $like)
                    ->orWhere('reason', 'like', $like);
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $paginator = $query->paginate($perPage);

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn ($history) => $this->serialize($history))->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    private function serialize(SavedBillDeleteHistory $history): array
    {
        return [
            'id' => (string) $history->id,
            'outlet_id' => $history->outlet_id,
            'outlet' => $history->relationLoaded('outlet') && $history->outlet ? [
                'id' => (string) $history->outlet->id,
                'name' => $history->outlet->name,
            ] : null,
            'saved_bill_id' => $history->saved_bill_id,
            'bill_name' => $history->bill_name,
            'channel' => $history->channel,
            'table_label' => $history->table_label,
            'customer_name' => $history->customer_name,
            'cashier_id' => $history->cashier_id,
            'cashier_name' => $history->cashier_name,
            'deleted_by_user_id' => $history->deleted_by_user_id,
            'deleted_by_name' => $history->deleted_by_name,
            'reason' => $history->reason,
            'pin_verified_at' => optional($history->pin_verified_at)->toISOString(),
            'created_at' => optional($history->created_at)->toISOString(),
            'subtotal' => (int) $history->subtotal,
            'discount_amount' => (int) $history->discount_amount,
            'tax_total' => (int) $history->tax_total,
            'grand_total' => (int) $history->grand_total,
            'item_count' => (int) $history->item_count,
            'qty_total' => (int) $history->qty_total,
            'bill_snapshot' => $history->bill_snapshot ?: null,
            'items_snapshot' => $history->items_snapshot ?: [],
        ];
    }
}
