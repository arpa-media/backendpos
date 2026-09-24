<?php

namespace App\Services\Warehouse\SalesCustomerV4;

use App\Services\Warehouse\Pricing\WarehouseSalesPriceResolverI06;
use App\Services\Warehouse\Support\WarehouseSkuTransactionCatalogService;
use App\Services\Warehouse\Support\WarehouseTransactionUomService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseSalesCustomerService
{
    public function __construct(
        private readonly WarehouseSkuTransactionCatalogService $catalog,
        private readonly WarehouseTransactionUomService $transactionUoms,
        private readonly WarehouseSalesPriceResolverI06 $salesPricing,
    ) {
    }

    public function options(string $warehouseId): array
    {
        $warehouse = DB::table('outlets')
            ->where('id', $warehouseId)
            ->where('is_active', true)
            ->whereRaw("LOWER(COALESCE(type,''))='warehouse'")
            ->first(['id', 'code', 'name']);

        if (! $warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => ['Warehouse aktif tidak ditemukan.']]);
        }

        $addresses = DB::table('wh_customer_addresses')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->get(['id', 'customer_id', 'label', 'recipient_name', 'phone', 'address', 'city', 'province', 'postal_code', 'is_default'])
            ->groupBy(fn (object $row): string => (string) $row->customer_id);

        $customers = DB::table('wh_customers')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'customer_type', 'contact_name', 'phone', 'email', 'tax_address', 'credit_term_days', 'currency_code'])
            ->map(function (object $row) use ($addresses): array {
                return [
                    'id' => (string) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'customer_type' => (string) $row->customer_type,
                    'contact_name' => $row->contact_name,
                    'phone' => $row->phone,
                    'email' => $row->email,
                    'tax_address' => $row->tax_address,
                    'credit_term_days' => (int) $row->credit_term_days,
                    'currency_code' => (string) ($row->currency_code ?: 'IDR'),
                    'addresses' => $addresses->get((string) $row->id, collect())->map(fn (object $address): array => [
                        'id' => (string) $address->id,
                        'label' => (string) $address->label,
                        'recipient_name' => $address->recipient_name,
                        'phone' => $address->phone,
                        'address' => (string) $address->address,
                        'city' => $address->city,
                        'province' => $address->province,
                        'postal_code' => $address->postal_code,
                        'is_default' => (bool) $address->is_default,
                    ])->values()->all(),
                ];
            })->values()->all();

        return [
            'current_warehouse' => ['id' => (string) $warehouse->id, 'code' => (string) $warehouse->code, 'name' => (string) $warehouse->name],
            'customers' => $customers,
            'skus' => $this->catalog->forWarehouse($warehouseId),
        ];
    }

    /** @return array<string,mixed> */
    public function pricePreview(string $warehouseId, string $customerId, string $skuId, string $uomId, float $qtyUom, mixed $businessDate): array
    {
        return $this->salesPricing->resolveForTransaction($warehouseId, 'customer', $customerId, $skuId, $uomId, $qtyUom, $businessDate, true);
    }

    public function list(string $warehouseId, array $filters): array
    {
        $this->syncAll($warehouseId);

        $query = DB::table('wh_v3_sales_orders as o')
            ->join('wh_customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'o.created_by_user_id')
            ->where('o.warehouse_id', $warehouseId)
            ->where('o.sales_channel', 'customer_manual')
            ->select('o.*', 'c.code as customer_code', 'c.name as customer_name', 'creator.name as requester_name')
            ->selectSub(fn ($q) => $q->from('wh_v3_sales_order_items as i')->whereColumn('i.sales_order_id', 'o.id')->selectRaw('COUNT(*)'), 'line_count')
            ->selectSub(fn ($q) => $q->from('wh_v3_sales_order_items as i')->whereColumn('i.sales_order_id', 'o.id')->selectRaw('COALESCE(SUM(i.approved_qty_base),0)'), 'approved_qty_base');

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(fn ($q) => $q
                ->where('o.sales_order_number', 'like', $term)
                ->orWhere('c.name', 'like', $term)
                ->orWhere('o.destination_recipient_snapshot', 'like', $term)
                ->orWhere('o.destination_address_snapshot', 'like', $term));
        }
        if (! empty($filters['status'])) {
            $query->where('o.status', $filters['status']);
        }

        $paginator = $query->orderByDesc('o.created_at')->paginate((int) ($filters['per_page'] ?? 30));

        return $this->paginated($paginator, fn (object $row): array => [
            'id' => (string) $row->id,
            'number' => (string) $row->sales_order_number,
            'date' => $row->order_date,
            'needed_date' => $row->needed_date,
            'status' => (string) $row->status,
            'customer' => ['id' => (string) $row->customer_id, 'code' => $row->customer_code, 'name' => $row->customer_name],
            'destination' => [
                'label' => $row->destination_label_snapshot,
                'recipient_name' => $row->destination_recipient_snapshot,
                'phone' => $row->destination_phone_snapshot,
                'address' => $row->destination_address_snapshot,
                'city' => $row->destination_city_snapshot,
                'province' => $row->destination_province_snapshot,
            ],
            'currency_code' => (string) ($row->currency_code ?: 'IDR'),
            'grand_total' => (float) $row->grand_total,
            'approved_grand_total' => (float) $row->approved_grand_total,
            'line_count' => (int) $row->line_count,
            'approved_qty_base' => (float) $row->approved_qty_base,
            'requester_name' => $row->requester_name,
            'created_at' => $row->created_at,
        ]);
    }

    public function save(string $warehouseId, array $payload, string $userId, ?string $id = null): array
    {
        $id = DB::transaction(function () use ($warehouseId, $payload, $userId, $id): string {
            $existing = $id
                ? DB::table('wh_v3_sales_orders')->where('warehouse_id', $warehouseId)->where('id', $id)->lockForUpdate()->first()
                : null;

            if ($id && ! $existing) {
                abort(404);
            }
            if ($existing && (string) $existing->sales_channel !== 'customer_manual') {
                throw ValidationException::withMessages(['sales_channel' => ['Dokumen ini bukan Sales Customer v4.']]);
            }
            if ($existing && (string) $existing->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Hanya Sales Customer Draft yang dapat diedit.']]);
            }

            $customer = DB::table('wh_customers')
                ->where('id', $payload['customer_id'])
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();
            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => ['Customer aktif tidak ditemukan.']]);
            }

            $destination = $this->destinationSnapshot($customer, $payload);
            $docId = $existing?->id ?: (string) Str::ulid();
            $now = now();

            $data = [
                'warehouse_id' => $warehouseId,
                'customer_id' => $customer->id,
                'sales_channel' => 'customer_manual',
                'ship_to_address_id' => $destination['ship_to_address_id'],
                'destination_label_snapshot' => $destination['label'],
                'destination_recipient_snapshot' => $destination['recipient_name'],
                'destination_phone_snapshot' => $destination['phone'],
                'destination_address_snapshot' => $destination['address'],
                'destination_city_snapshot' => $destination['city'],
                'destination_province_snapshot' => $destination['province'],
                'destination_postal_code_snapshot' => $destination['postal_code'],
                'order_date' => $payload['order_date'],
                'needed_date' => $payload['needed_date'] ?? null,
                'currency_code' => (string) ($customer->currency_code ?: 'IDR'),
                'subtotal' => 0,
                'discount_total' => 0,
                'grand_total' => 0,
                'approved_subtotal' => 0,
                'approved_discount_total' => 0,
                'approved_grand_total' => 0,
                'status' => 'draft',
                'notes' => $payload['notes'] ?? null,
                'updated_by_user_id' => $userId,
                'metadata' => json_encode([
                    'flow_version' => 4,
                    'warehouse_v4_iteration' => 2,
                    'sales_customer' => true,
                    'price_visible' => true,
                    'manual_price_snapshot' => true,
                    'customer_gr_override' => true,
                ]),
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('wh_v3_sales_orders')->where('id', $docId)->update($data);
            } else {
                DB::table('wh_v3_sales_orders')->insert($data + [
                    'id' => $docId,
                    'sales_order_number' => $this->number('SC'),
                    'created_by_user_id' => $userId,
                    'created_at' => $now,
                ]);
            }

            $totals = $this->replaceItems($warehouseId, (string) $customer->id, (string) $payload['order_date'], $docId, $payload['items'], (bool) $existing);
            DB::table('wh_v3_sales_orders')->where('id', $docId)->update([
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'grand_total' => $totals['grand_total'],
                'updated_at' => now(),
            ]);

            $this->event($docId, $existing ? 'draft_updated' : 'draft_created', $existing ? 'draft' : null, 'draft', $existing ? 'Sales Customer draft diperbarui.' : 'Sales Customer draft dibuat.', $userId, ['grand_total' => $totals['grand_total']]);
            return $docId;
        }, 5);

        return $this->detail($warehouseId, $id);
    }

    public function submit(string $warehouseId, string $id, string $userId): array
    {
        DB::transaction(function () use ($warehouseId, $id, $userId): void {
            $row = DB::table('wh_v3_sales_orders')
                ->where('warehouse_id', $warehouseId)
                ->where('sales_channel', 'customer_manual')
                ->where('id', $id)
                ->lockForUpdate()->first();
            if (! $row) abort(404);
            if (in_array((string) $row->status, ['submitted', 'approved'], true)) return;
            if ((string) $row->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Sales Customer tidak dapat disubmit pada status saat ini.']]);
            }
            if (! DB::table('wh_v3_sales_order_items')->where('sales_order_id', $id)->where('requested_qty_base', '>', 0)->exists()) {
                throw ValidationException::withMessages(['items' => ['Minimal satu item positif diperlukan.']]);
            }
            $this->assertStrictPricingSnapshots($id);

            DB::table('wh_v3_sales_orders')->where('id', $id)->update([
                'status' => 'submitted', 'submitted_by_user_id' => $userId, 'submitted_at' => now(),
                'updated_by_user_id' => $userId, 'updated_at' => now(),
            ]);
            DB::table('wh_v3_sales_order_items')->where('sales_order_id', $id)->update(['status' => 'submitted', 'updated_at' => now()]);
            $this->event($id, 'submitted', 'draft', 'submitted', 'Sales Customer disubmit untuk approval.', $userId);
        }, 5);

        return $this->detail($warehouseId, $id);
    }

    public function approve(string $warehouseId, string $id, array $payload, string $userId): array
    {
        DB::transaction(function () use ($warehouseId, $id, $payload, $userId): void {
            $row = DB::table('wh_v3_sales_orders')
                ->where('warehouse_id', $warehouseId)
                ->where('sales_channel', 'customer_manual')
                ->where('id', $id)
                ->lockForUpdate()->first();
            if (! $row) abort(404);
            if ((string) $row->status === 'approved' && $row->prepare_request_id) return;
            if ((string) $row->status !== 'submitted') {
                throw ValidationException::withMessages(['status' => ['Sales Customer harus Submitted sebelum Approval.']]);
            }
            $this->assertStrictPricingSnapshots($id);

            $inputs = collect($payload['items'] ?? [])->keyBy(fn (array $line): string => (string) ($line['item_id'] ?? ''));
            $items = DB::table('wh_v3_sales_order_items')->where('sales_order_id', $id)->lockForUpdate()->get();
            $positive = 0;
            $subtotal = 0.0;
            $discount = 0.0;

            foreach ($items as $item) {
                $input = $inputs->get((string) $item->id);
                if (! $input) {
                    throw ValidationException::withMessages(['items' => ['Seluruh item wajib memiliki Approved Qty.']]);
                }
                $qty = round((float) ($input['approved_qty_uom'] ?? 0), 4);
                $requested = round((float) $item->requested_qty_uom, 4);
                if ($qty < 0 || $qty > $requested + 0.0001) {
                    throw ValidationException::withMessages(['items' => ["Approved Qty item {$item->id} harus antara 0 dan Requested Qty."]]);
                }
                $base = round($qty * (float) $item->conversion_factor_snapshot, 4);
                $gross = round($qty * (float) $item->unit_price, 2);
                $lineDiscount = round($gross * ((float) $item->discount_percent / 100), 2);
                $lineTotal = round($gross - $lineDiscount, 2);
                if ($base > 0) $positive++;
                $subtotal += $gross;
                $discount += $lineDiscount;

                DB::table('wh_v3_sales_order_items')->where('id', $item->id)->update([
                    'approved_qty_uom' => $qty,
                    'approved_qty_base' => $base,
                    'approved_line_total' => $lineTotal,
                    'status' => $base > 0 ? 'approved' : 'rejected',
                    'notes' => $input['notes'] ?? $item->notes,
                    'updated_at' => now(),
                ]);
            }

            if ($positive === 0) {
                throw ValidationException::withMessages(['items' => ['Minimal satu item harus memiliki Approved Qty lebih besar dari nol.']]);
            }

            $prepareId = $this->createPrepare($row, $warehouseId, $userId);
            DB::table('wh_v3_sales_orders')->where('id', $id)->update([
                'status' => 'approved',
                'prepare_request_id' => $prepareId,
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
                'approved_subtotal' => round($subtotal, 2),
                'approved_discount_total' => round($discount, 2),
                'approved_grand_total' => round($subtotal - $discount, 2),
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);

            $this->event($id, 'approved', 'submitted', 'approved', 'Sales Customer approved dan masuk Checker Prepare Logistics.', $userId, ['prepare_request_id' => $prepareId]);
        }, 5);

        return $this->detail($warehouseId, $id);
    }

    public function detail(string $warehouseId, string $id): array
    {
        $this->syncOne($id);

        $row = DB::table('wh_v3_sales_orders as o')
            ->join('wh_customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'o.created_by_user_id')
            ->leftJoin('users as approver', 'approver.id', '=', 'o.approved_by_user_id')
            ->where('o.warehouse_id', $warehouseId)
            ->where('o.sales_channel', 'customer_manual')
            ->where('o.id', $id)
            ->first(['o.*', 'c.code as customer_code', 'c.name as customer_name', 'creator.name as requester_name', 'approver.name as approver_name']);
        if (! $row) abort(404);

        $items = DB::table('wh_v3_sales_order_items as i')
            ->join('stk_skus as s', 's.id', '=', 'i.sku_id')
            ->leftJoin('stk_uoms as u', 'u.id', '=', 'i.uom_id')
            ->leftJoin('stk_uoms as base', 'base.id', '=', 's.base_uom_id')
            ->where('i.sales_order_id', $id)
            ->orderBy('s.name')
            ->get(['i.*', 's.sku_code', 's.name as item_name', 's.base_uom_id', 'u.code as live_uom_code', 'u.name as live_uom_name', 'base.code as live_base_uom_code', 'base.name as live_base_uom_name'])
            ->map(fn (object $item): array => [
                'id' => (string) $item->id,
                'sku_id' => (string) $item->sku_id,
                'sku_code' => (string) $item->sku_code,
                'item_name' => (string) $item->item_name,
                'uom_id' => (string) $item->uom_id,
                'uom_code' => (string) ($item->uom_code_snapshot ?? $item->live_uom_code ?? 'UNIT'),
                'uom_name' => (string) ($item->uom_name_snapshot ?? $item->live_uom_name ?? 'Unit'),
                'base_uom_id' => (string) ($item->base_uom_id_snapshot ?? $item->base_uom_id ?? ''),
                'base_uom_code' => (string) ($item->base_uom_code_snapshot ?? $item->live_base_uom_code ?? 'UNIT'),
                'base_uom_name' => (string) ($item->base_uom_name_snapshot ?? $item->live_base_uom_name ?? 'Unit'),
                'conversion_factor' => (float) $item->conversion_factor_snapshot,
                'requested_qty_uom' => (float) $item->requested_qty_uom,
                'requested_qty_base' => (float) $item->requested_qty_base,
                'approved_qty_uom' => (float) $item->approved_qty_uom,
                'approved_qty_base' => (float) $item->approved_qty_base,
                'unit_price' => (float) $item->unit_price,
                'price_source' => (string) ($item->price_source ?? ''),
                'price_policy_id_snapshot' => $item->price_policy_id_snapshot ?? null,
                'price_target_type_snapshot' => $item->price_target_type_snapshot ?? null,
                'price_target_id_snapshot' => $item->price_target_id_snapshot ?? null,
                'price_business_date_snapshot' => $item->price_business_date_snapshot ?? null,
                'price_uom_id_snapshot' => $item->price_uom_id_snapshot ?? null,
                'price_uom_code_snapshot' => $item->price_uom_code_snapshot ?? null,
                'price_conversion_factor_snapshot' => $item->price_conversion_factor_snapshot !== null ? (float) $item->price_conversion_factor_snapshot : null,
                'price_master_snapshot' => $item->price_master_snapshot !== null ? (float) $item->price_master_snapshot : null,
                'price_transaction_unit_snapshot' => $item->price_transaction_unit_snapshot !== null ? (float) $item->price_transaction_unit_snapshot : null,
                'price_rule_snapshot' => $this->json($item->price_rule_snapshot ?? null),
                'discount_percent' => (float) $item->discount_percent,
                'discount_amount' => (float) $item->discount_amount,
                'line_total' => (float) $item->line_total,
                'approved_line_total' => (float) $item->approved_line_total,
                'status' => (string) $item->status,
                'notes' => $item->notes,
            ])->values()->all();

        return [
            'id' => (string) $row->id,
            'number' => (string) $row->sales_order_number,
            'date' => $row->order_date,
            'needed_date' => $row->needed_date,
            'status' => (string) $row->status,
            'notes' => $row->notes,
            'customer' => ['id' => (string) $row->customer_id, 'code' => $row->customer_code, 'name' => $row->customer_name],
            'destination' => [
                'ship_to_address_id' => $row->ship_to_address_id ? (string) $row->ship_to_address_id : null,
                'label' => $row->destination_label_snapshot,
                'recipient_name' => $row->destination_recipient_snapshot,
                'phone' => $row->destination_phone_snapshot,
                'address' => $row->destination_address_snapshot,
                'city' => $row->destination_city_snapshot,
                'province' => $row->destination_province_snapshot,
                'postal_code' => $row->destination_postal_code_snapshot,
            ],
            'currency_code' => (string) ($row->currency_code ?: 'IDR'),
            'subtotal' => (float) $row->subtotal,
            'discount_total' => (float) $row->discount_total,
            'grand_total' => (float) $row->grand_total,
            'approved_subtotal' => (float) $row->approved_subtotal,
            'approved_discount_total' => (float) $row->approved_discount_total,
            'approved_grand_total' => (float) $row->approved_grand_total,
            'requester' => ['name' => $row->requester_name, 'created_at' => $row->created_at],
            'approver' => $row->approved_by_user_id ? ['name' => $row->approver_name, 'approved_at' => $row->approved_at] : null,
            'prepare_request_id' => $row->prepare_request_id,
            'items' => $items,
            'logistics' => $this->logisticsSnapshot($row->prepare_request_id),
            'timeline' => $this->timeline($id, $row->prepare_request_id),
        ];
    }

    private function replaceItems(string $warehouseId, string $customerId, string $orderDate, string $docId, array $items, bool $existing): array
    {
        if ($existing || DB::table('wh_v3_sales_order_items')->where('sales_order_id', $docId)->exists()) {
            DB::table('wh_v3_sales_order_items')->where('sales_order_id', $docId)->delete();
        }

        $seen = [];
        $subtotal = 0.0;
        $discountTotal = 0.0;

        foreach ($items as $index => $line) {
            $sku = DB::table('stk_skus')->where('id', $line['sku_id'])->where('is_active', true)->whereNull('deleted_at')->first();
            if (! $sku) {
                throw ValidationException::withMessages(["items.{$index}.sku_id" => ['SKU aktif tidak ditemukan.']]);
            }
            if (in_array((string) $sku->id, $seen, true)) {
                throw ValidationException::withMessages(['items' => ['SKU tidak boleh duplikat dalam satu Sales Customer.']]);
            }
            $seen[] = (string) $sku->id;

            $resolved = $this->transactionUoms->resolve((string) $sku->id, (string) $line['uom_id'], true);
            $factor = (float) $resolved['conversion_factor'];
            $qty = round((float) $line['requested_qty_uom'], 4);
            $discountPercent = round((float) ($line['discount_percent'] ?? 0), 4);
            if ($qty <= 0) {
                throw ValidationException::withMessages(["items.{$index}.requested_qty_uom" => ['Qty harus lebih besar dari nol.']]);
            }
            if ($discountPercent < 0 || $discountPercent > 100) {
                throw ValidationException::withMessages(["items.{$index}.discount_percent" => ['Diskon harus antara 0 sampai 100%.']]);
            }

            // I06: browser supplied unit_price is deliberately ignored.
            // Sales Warehouse price is resolved strictly from Harga Outlet & Customer.
            $pricing = $this->salesPricing->resolveForTransaction(
                $warehouseId, 'customer', $customerId, (string) $sku->id, (string) $resolved['uom_id'], $qty, $orderDate, true
            );
            $price = round((float) $pricing['transaction_unit_price'], 6);
            $gross = round($qty * $price, 2);
            $discount = round($gross * ($discountPercent / 100), 2);
            $lineTotal = round($gross - $discount, 2);
            $subtotal += $gross;
            $discountTotal += $discount;

            DB::table('wh_v3_sales_order_items')->insert([
                'id' => (string) Str::ulid(),
                'sales_order_id' => $docId,
                'sku_id' => $sku->id,
                'uom_id' => $resolved['uom_id'],
                'uom_code_snapshot' => $resolved['uom_code'],
                'uom_name_snapshot' => $resolved['uom_name'],
                'base_uom_id_snapshot' => $resolved['base_uom_id'],
                'base_uom_code_snapshot' => $resolved['base_uom_code'],
                'base_uom_name_snapshot' => $resolved['base_uom_name'],
                'conversion_factor_snapshot' => round($factor, 8),
                'requested_qty_uom' => $qty,
                'requested_qty_base' => round($qty * $factor, 4),
                'unit_price' => $price,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discount,
                'line_total' => $lineTotal,
                'approved_qty_uom' => 0,
                'approved_qty_base' => 0,
                'approved_line_total' => 0,
                'price_source' => WarehouseSalesPriceResolverI06::SOURCE,
                'price_policy_id_snapshot' => $pricing['policy_id'],
                'price_target_type_snapshot' => 'customer',
                'price_target_id_snapshot' => $customerId,
                'price_business_date_snapshot' => $pricing['business_date'],
                'price_uom_id_snapshot' => $pricing['price_uom_id'],
                'price_uom_code_snapshot' => $pricing['price_uom_code'],
                'price_conversion_factor_snapshot' => round((float) $pricing['conversion_factor'], 8),
                'price_master_snapshot' => round((float) $pricing['unit_price'], 6),
                'price_transaction_unit_snapshot' => $price,
                'price_rule_snapshot' => json_encode($this->salesPricing->snapshot($pricing)),
                'status' => 'draft',
                'notes' => $line['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! $seen) {
            throw ValidationException::withMessages(['items' => ['Minimal satu item wajib diisi.']]);
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'grand_total' => round($subtotal - $discountTotal, 2),
        ];
    }

    private function assertStrictPricingSnapshots(string $salesOrderId): void
    {
        $invalid = DB::table('wh_v3_sales_order_items')
            ->where('sales_order_id', $salesOrderId)
            ->where(function ($q): void {
                $q->where('price_source', '!=', WarehouseSalesPriceResolverI06::SOURCE)
                    ->orWhereNull('price_policy_id_snapshot')
                    ->orWhereNull('price_business_date_snapshot')
                    ->orWhereNull('price_rule_snapshot')
                    ->orWhere('unit_price', '<=', 0);
            })->exists();
        if ($invalid) {
            throw ValidationException::withMessages([
                'warehouse_price' => ['Ada item yang belum memakai snapshot Harga Outlet & Customer. Edit dan simpan ulang Draft sebelum Submit/Approve.'],
            ]);
        }
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array) $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function destinationSnapshot(object $customer, array $payload): array
    {
        $address = null;
        $addressId = trim((string) ($payload['ship_to_address_id'] ?? ''));
        if ($addressId !== '') {
            $address = DB::table('wh_customer_addresses')
                ->where('id', $addressId)
                ->where('customer_id', $customer->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();
            if (! $address) {
                throw ValidationException::withMessages(['ship_to_address_id' => ['Alamat tujuan tidak aktif atau bukan milik customer terpilih.']]);
            }
        } else {
            $address = DB::table('wh_customer_addresses')
                ->where('customer_id', $customer->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->orderByDesc('is_default')->orderBy('created_at')->first();
            $addressId = $address ? (string) $address->id : '';
        }

        $destinationAddress = trim((string) ($payload['destination_address'] ?? ''));
        if ($destinationAddress === '') {
            $destinationAddress = trim((string) ($address?->address ?? $customer->tax_address ?? ''));
        }
        if ($destinationAddress === '') {
            throw ValidationException::withMessages(['destination_address' => ['Alamat tujuan customer wajib diisi atau tersedia pada Master Customer.']]);
        }

        return [
            'ship_to_address_id' => $addressId !== '' ? $addressId : null,
            'label' => trim((string) ($payload['destination_label'] ?? '')) ?: (string) ($address?->label ?? 'Tujuan Customer'),
            'recipient_name' => trim((string) ($payload['destination_recipient_name'] ?? '')) ?: (string) ($address?->recipient_name ?? $customer->contact_name ?? $customer->name),
            'phone' => trim((string) ($payload['destination_phone'] ?? '')) ?: (string) ($address?->phone ?? $customer->phone ?? ''),
            'address' => $destinationAddress,
            'city' => trim((string) ($payload['destination_city'] ?? '')) ?: (string) ($address?->city ?? ''),
            'province' => trim((string) ($payload['destination_province'] ?? '')) ?: (string) ($address?->province ?? ''),
            'postal_code' => trim((string) ($payload['destination_postal_code'] ?? '')) ?: (string) ($address?->postal_code ?? ''),
        ];
    }

    private function createPrepare(object $row, string $warehouseId, string $userId): string
    {
        if ($row->prepare_request_id) return (string) $row->prepare_request_id;

        $prepareId = (string) Str::ulid();
        DB::table('wh_v3_logistics_prepare_requests')->insert([
            'id' => $prepareId,
            'prepare_number' => $this->number('PREP'),
            'warehouse_id' => $warehouseId,
            'source_type' => 'sales_order',
            'source_id' => $row->id,
            'source_number' => $row->sales_order_number,
            'destination_type' => 'customer',
            'destination_id' => $row->customer_id,
            'status' => 'queued',
            'requested_delivery_date' => $row->needed_date,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
            'created_by_user_id' => $userId,
            'metadata' => json_encode(['flow_version' => 4, 'source' => 'warehouse_v4_iteration_02', 'sales_customer' => true, 'barcode_required' => false]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (DB::table('wh_v3_sales_order_items')->where('sales_order_id', $row->id)->where('approved_qty_base', '>', 0)->get() as $item) {
            DB::table('wh_v3_logistics_prepare_items')->insert([
                'id' => (string) Str::ulid(),
                'prepare_request_id' => $prepareId,
                'source_item_id' => $item->id,
                'sku_id' => $item->sku_id,
                'uom_id' => $item->uom_id,
                'requested_qty_uom' => $item->requested_qty_uom,
                'requested_qty_base' => $item->requested_qty_base,
                'approved_qty_uom' => $item->approved_qty_uom,
                'approved_qty_base' => $item->approved_qty_base,
                'ready_qty_base' => 0,
                'status' => 'queued',
                'notes' => $item->notes,
                'metadata' => json_encode(['flow_version' => 4, 'source_type' => 'sales_order', 'sales_customer' => true, 'unit_price' => (float) $item->unit_price]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $prepareId;
    }

    private function syncAll(string $warehouseId): void
    {
        DB::table('wh_v3_sales_orders')
            ->where('warehouse_id', $warehouseId)
            ->where('sales_channel', 'customer_manual')
            ->whereNotNull('prepare_request_id')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->pluck('id')->each(fn ($id) => $this->syncOne((string) $id));
    }

    private function syncOne(string $id): void
    {
        $row = DB::table('wh_v3_sales_orders')->where('id', $id)->first();
        if (! $row || ! $row->prepare_request_id) return;

        $prepare = DB::table('wh_v3_logistics_prepare_requests')->where('id', $row->prepare_request_id)->first();
        if (! $prepare) return;
        $delivery = DB::table('wh_v3_delivery_orders')->where('prepare_request_id', $prepare->id)->first();
        $gr = $delivery ? DB::table('wh_v3_goods_receipts')->where('delivery_order_id', $delivery->id)->first() : null;

        $status = (string) $row->status;
        if ($gr?->status === 'completed') $status = 'completed';
        elseif ($gr?->status === 'submitted') $status = 'goods-receipt';
        elseif ($delivery) $status = 'on-delivery';
        elseif (in_array((string) $prepare->status, ['queued', 'preparing'], true)) $status = 'approved';

        if ($status !== (string) $row->status) {
            DB::table('wh_v3_sales_orders')->where('id', $id)->update([
                'status' => $status,
                'completed_at' => $status === 'completed' ? now() : $row->completed_at,
                'updated_at' => now(),
            ]);
        }
    }

    private function logisticsSnapshot(?string $prepareId): ?array
    {
        if (! $prepareId) return null;
        $prepare = DB::table('wh_v3_logistics_prepare_requests')->where('id', $prepareId)->first();
        if (! $prepare) return null;
        $delivery = DB::table('wh_v3_delivery_orders')->where('prepare_request_id', $prepareId)->first();
        $gr = $delivery ? DB::table('wh_v3_goods_receipts')->where('delivery_order_id', $delivery->id)->first() : null;

        $grItems = [];
        if ($gr) {
            $grItems = DB::table('wh_v3_goods_receipt_items as i')
                ->join('stk_skus as s', 's.id', '=', 'i.sku_id')
                ->leftJoin('stk_uoms as u', 'u.id', '=', 'i.uom_id')
                ->where('i.goods_receipt_id', $gr->id)
                ->orderBy('s.name')
                ->get(['i.*', 's.sku_code', 's.name as item_name', 'u.code as uom_code'])
                ->map(fn (object $item): array => [
                    'item_id' => (string) $item->id,
                    'sku_code' => (string) $item->sku_code,
                    'item_name' => (string) $item->item_name,
                    'uom_code' => (string) ($item->uom_code ?: 'UNIT'),
                    'sent_qty_uom' => (float) $item->sent_qty_uom,
                    'received_qty_uom' => (float) $item->received_qty_uom,
                    'not_received_qty_uom' => (float) $item->not_received_qty_uom,
                    'notes' => $item->notes,
                ])->values()->all();
        }

        return [
            'prepare' => ['id' => (string) $prepare->id, 'number' => $prepare->prepare_number, 'status' => $prepare->status],
            'delivery_order' => $delivery ? ['id' => (string) $delivery->id, 'number' => $delivery->delivery_number, 'status' => $delivery->status] : null,
            'goods_receipt' => $gr ? [
                'id' => (string) $gr->id,
                'number' => $gr->goods_receipt_number,
                'status' => $gr->status,
                'receipt_date' => $gr->receipt_date,
                'receiver_name' => $gr->receiver_name_snapshot,
                'items' => $grItems,
                'outgoing_invoice_id' => $gr->outgoing_invoice_id,
            ] : null,
        ];
    }

    private function timeline(string $id, ?string $prepareId): array
    {
        $events = DB::table('wh_v3_sales_transfer_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.document_type', 'sales_order')->where('e.document_id', $id)
            ->get(['e.*', 'u.name as actor_name'])
            ->map(fn (object $event): array => [
                'source' => 'sales_customer', 'event_type' => $event->event_type, 'message' => $event->message,
                'from_status' => $event->from_status, 'to_status' => $event->to_status,
                'actor' => $event->actor_name, 'created_at' => $event->occurred_at,
            ])->all();

        if ($prepareId) {
            $deliveryId = DB::table('wh_v3_delivery_orders')->where('prepare_request_id', $prepareId)->value('id');
            $grId = $deliveryId ? DB::table('wh_v3_goods_receipts')->where('delivery_order_id', $deliveryId)->value('id') : null;
            $logistics = DB::table('wh_v3_logistics_events as e')
                ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
                ->where(function ($q) use ($prepareId, $deliveryId, $grId): void {
                    $q->where(fn ($x) => $x->where('e.document_type', 'prepare_request')->where('e.document_id', $prepareId));
                    if ($deliveryId) $q->orWhere(fn ($x) => $x->where('e.document_type', 'delivery_order')->where('e.document_id', $deliveryId));
                    if ($grId) $q->orWhere(fn ($x) => $x->where('e.document_type', 'goods_receipt')->where('e.document_id', $grId));
                })->get(['e.*', 'u.name as actor_name'])
                ->map(fn (object $event): array => [
                    'source' => 'logistics', 'event_type' => $event->event_type, 'message' => $event->message,
                    'from_status' => $event->from_status, 'to_status' => $event->to_status,
                    'actor' => $event->actor_name, 'created_at' => $event->occurred_at,
                ])->all();
            $events = array_merge($events, $logistics);
        }

        usort($events, fn (array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));
        return $events;
    }

    private function event(string $id, string $event, ?string $from, ?string $to, string $message, ?string $actor, array $metadata = []): void
    {
        DB::table('wh_v3_sales_transfer_events')->insert([
            'id' => (string) Str::ulid(),
            'document_type' => 'sales_order',
            'document_id' => $id,
            'event_type' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'message' => $message,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'actor_user_id' => $actor,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.now('Asia/Jakarta')->format('Ymd').'-'.Str::upper(Str::random(6));
    }

    private function paginated(LengthAwarePaginator $paginator, callable $map): array
    {
        return [
            'items' => collect($paginator->items())->map($map)->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }
}
