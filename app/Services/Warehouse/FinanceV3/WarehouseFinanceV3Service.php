<?php

namespace App\Services\Warehouse\FinanceV3;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseFinanceV3Service
{
    private const DRAFT_STATUS = 'draft';

    public function options(string $direction, string $warehouseId): array
    {
        $this->assertDirection($direction);

        $supplierQuery = DB::table('pur_supplier_sources')->where('is_active', true)->orderBy('name');
        $customerQuery = DB::table('wh_customers')->where('is_active', true)->orderBy('name');
        $outletQuery = DB::table('outlets')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('type')->orWhereRaw('LOWER(type) <> ?', ['warehouse']))
            ->orderBy('name');
        $skuQuery = DB::table('stk_skus')->where('is_active', true)->orderBy('name');

        if (Schema::hasColumn('pur_supplier_sources', 'deleted_at')) $supplierQuery->whereNull('deleted_at');
        if (Schema::hasColumn('wh_customers', 'deleted_at')) $customerQuery->whereNull('deleted_at');
        if (Schema::hasColumn('outlets', 'deleted_at')) $outletQuery->whereNull('deleted_at');
        if (Schema::hasColumn('stk_skus', 'deleted_at')) $skuQuery->whereNull('deleted_at');

        return [
            'direction' => $direction,
            'suppliers' => $direction === 'incoming'
                ? $supplierQuery->get(['id','code','name'])->map(fn ($x) => ['id'=>(string)$x->id,'code'=>$x->code,'name'=>$x->name])->values()->all()
                : [],
            'outlets' => $direction === 'outgoing'
                ? $outletQuery->get(['id','code','name'])->map(fn ($x) => ['id'=>(string)$x->id,'code'=>$x->code,'name'=>$x->name])->values()->all()
                : [],
            'customers' => $direction === 'outgoing'
                ? $customerQuery->get(['id','code','name'])->map(fn ($x) => ['id'=>(string)$x->id,'code'=>$x->code,'name'=>$x->name])->values()->all()
                : [],
            'skus' => $skuQuery->get(['id','sku_code','name'])->map(fn ($x) => ['id'=>(string)$x->id,'code'=>$x->sku_code,'name'=>$x->name])->values()->all(),
            'payment_accounts' => $this->paymentAccountOptions($warehouseId),
        ];
    }

    public function invoices(string $direction, string $warehouseId, array $filters): array
    {
        $this->assertDirection($direction);
        $union = $direction === 'incoming'
            ? $this->incomingUnion($warehouseId)
            : $this->outgoingUnion($warehouseId);

        $q = DB::query()->fromSub($union, 'invoice_union');
        $this->applyFilters($q, $filters);

        $metricQuery = clone $q;
        $metrics = $metricQuery
            ->whereNotIn('status', ['draft','void','cancelled'])
            ->selectRaw('COALESCE(SUM(grand_total),0) AS approved_invoice_value')
            ->selectRaw('COALESCE(SUM(total_stock_movement_qty),0) AS total_stock_movement_qty')
            ->selectRaw('COALESCE(SUM(stock_valuation_total),0) AS stock_valuation_total')
            ->selectRaw('COUNT(*) AS approved_invoice_count')
            ->first();

        $perPage = max(10, min(100, (int)($filters['per_page'] ?? 25)));
        $page = max(1, (int)($filters['page'] ?? 1));
        /** @var LengthAwarePaginator $p */
        $p = $q->orderByDesc('invoice_date')->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($p->items())->map(fn ($r) => $this->summaryRow($r))->values()->all(),
            'pagination' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ],
            'metrics' => [
                'approved_invoice_value' => round((float)($metrics->approved_invoice_value ?? 0), 2),
                'total_stock_movement_qty' => round((float)($metrics->total_stock_movement_qty ?? 0), 4),
                'stock_valuation_total' => round((float)($metrics->stock_valuation_total ?? 0), 2),
                'approved_invoice_count' => (int)($metrics->approved_invoice_count ?? 0),
            ],
        ];
    }

    public function detail(string $direction, string $source, string $id, string $warehouseId): array
    {
        $this->assertDirection($direction);

        return match ($source) {
            'auto_incoming' => $this->detailAutoIncoming($direction, $id, $warehouseId),
            'auto_outgoing' => $this->detailAutoOutgoing($direction, $id, $warehouseId),
            'legacy_outgoing' => $this->detailLegacyOutgoing($direction, $id, $warehouseId),
            'manual' => $this->detailManual($direction, $id, $warehouseId),
            default => abort(404),
        };
    }

    public function createManual(string $direction, string $warehouseId, array $payload, string $userId): array
    {
        $this->assertDirection($direction);

        return DB::transaction(function () use ($direction, $warehouseId, $payload, $userId): array {
            $party = $this->partySnapshot($direction, (string)$payload['party_type'], (string)$payload['party_id']);
            $invoiceId = (string) Str::ulid();
            $number = $this->number($direction === 'incoming' ? 'WIN-MAN' : 'WOUT-MAN');

            DB::table('wh_v3_manual_invoices')->insert([
                'id' => $invoiceId,
                'invoice_number' => $number,
                'direction' => $direction,
                'warehouse_id' => $warehouseId,
                'party_type' => $party['type'],
                'party_id' => $party['id'],
                'party_code_snapshot' => $party['code'],
                'party_name_snapshot' => $party['name'],
                'invoice_date' => $payload['invoice_date'],
                'due_date' => null,
                'currency_code' => 'IDR',
                'external_reference' => $payload['external_reference'] ?? null,
                'total_stock_movement_qty' => 0,
                'stock_valuation_total' => 0,
                'subtotal' => 0,
                'grand_total' => 0,
                'status' => 'draft',
                'notes' => $payload['notes'] ?? null,
                'metadata' => json_encode(['flow_version'=>3,'manual'=>true]),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $subtotal = 0.0;
            $stockQty = 0.0;
            $stockValue = 0.0;

            foreach ($payload['items'] as $index => $line) {
                $skuQuery = DB::table('stk_skus')->where('id', $line['sku_id'])->where('is_active', true);
                if (Schema::hasColumn('stk_skus', 'deleted_at')) $skuQuery->whereNull('deleted_at');
                $sku = $skuQuery->first(['id','sku_code','name']);
                if (! $sku) {
                    throw ValidationException::withMessages(["items.{$index}.sku_id" => ['SKU aktif tidak ditemukan.']]);
                }

                $qty = round((float)$line['quantity_base'], 4);
                $unitPrice = round((float)$line['unit_price'], 6);
                $unitCost = array_key_exists('inventory_unit_cost', $line) && $line['inventory_unit_cost'] !== null
                    ? round((float)$line['inventory_unit_cost'], 6)
                    : ($direction === 'incoming' ? $unitPrice : $this->warehouseAverageCost($warehouseId, (string)$sku->id));

                $lineTotal = round($qty * $unitPrice, 2);
                $inventoryTotal = round($qty * $unitCost, 2);

                DB::table('wh_v3_manual_invoice_items')->insert([
                    'id' => (string) Str::ulid(),
                    'manual_invoice_id' => $invoiceId,
                    'sku_id' => $sku->id,
                    'description_snapshot' => trim((string)($line['description'] ?? '')) ?: ($sku->sku_code.' - '.$sku->name),
                    'quantity_base' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'inventory_unit_cost' => $unitCost,
                    'inventory_cost_total' => $inventoryTotal,
                    'metadata' => json_encode(['flow_version'=>3]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $subtotal += $lineTotal;
                $stockQty += $qty;
                $stockValue += $inventoryTotal;
            }

            DB::table('wh_v3_manual_invoices')->where('id', $invoiceId)->update([
                'total_stock_movement_qty' => round($stockQty, 4),
                'stock_valuation_total' => round($stockValue, 2),
                'subtotal' => round($subtotal, 2),
                'grand_total' => round($subtotal, 2),
                'updated_at' => now(),
            ]);

            $this->event('manual', $invoiceId, $direction, 'created', null, 'draft', 'Manual invoice dibuat.', $userId, [
                'party_type' => $party['type'],
                'party_id' => $party['id'],
            ]);

            return $this->detailManual($direction, $invoiceId, $warehouseId);
        }, 5);
    }

    public function approve(string $direction, string $source, string $id, string $warehouseId, array $payload, string $userId): array
    {
        $this->assertDirection($direction);

        return DB::transaction(function () use ($direction, $source, $id, $warehouseId, $payload, $userId): array {
            if ($source === 'legacy_outgoing') {
                throw ValidationException::withMessages(['invoice' => ['Invoice legacy sudah memakai status issued dan tidak melalui approval Warehouse v3.']]);
            }

            // Lock the source invoice row first. This is the concurrency boundary for
            // approval, so two Finance users cannot approve the same draft with
            // different payment metadata at the same time.
            $lockedStatus = $this->lockInvoiceForApproval($direction, $source, $id, $warehouseId);
            $detail = $this->detail($direction, $source, $id, $warehouseId);
            if ($lockedStatus !== 'draft') {
                if (DB::table('wh_v3_invoice_approvals')->where('document_source', $source)->where('document_id', $id)->exists()) {
                    return $detail;
                }
                throw ValidationException::withMessages(['status' => ['Hanya invoice Draft yang dapat di-approve.']]);
            }

            if ((string)$payload['due_date'] < (string)$detail['invoice_date']) {
                throw ValidationException::withMessages(['due_date' => ['Due Date tidak boleh lebih awal dari Invoice Date.']]);
            }
            if ((string)$payload['estimate_payment_date'] < (string)$detail['invoice_date']) {
                throw ValidationException::withMessages(['estimate_payment_date' => ['Estimate Pay Date tidak boleh lebih awal dari Invoice Date.']]);
            }

            $account = DB::table('wh_v3_payment_accounts')
                ->where('id', $payload['payment_account_id'])
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('warehouse_id', $warehouseId)->orWhereNull('warehouse_id'))
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw ValidationException::withMessages(['payment_account_id' => ['Rekening pembayaran/penerimaan tidak aktif atau tidak tersedia untuk Warehouse ini.']]);
            }

            $accountSnapshot = [
                'id' => (string)$account->id,
                'code' => $account->code,
                'name' => $account->name,
                'bank_name' => $account->bank_name,
                'account_name' => $account->account_name,
                'account_number' => $account->account_number,
            ];

            $actor = DB::table('users')->where('id', $userId)->first(['id','name','nisj']);
            $approvalSnapshot = [
                'invoice_number' => $detail['invoice_number'],
                'direction' => $direction,
                'document_source' => $source,
                'party' => $detail['party'],
                'grand_total' => $detail['grand_total'],
                'total_stock_movement_qty' => $detail['total_stock_movement_qty'],
                'stock_valuation_total' => $detail['stock_valuation_total'],
                'due_date' => $payload['due_date'],
                'payment_term_detail' => trim((string)$payload['payment_term_detail']),
                'estimate_payment_date' => $payload['estimate_payment_date'],
                'payment_account' => $accountSnapshot,
                'approved_by' => $actor ? ['id'=>(string)$actor->id,'name'=>$actor->name,'nisj'=>$actor->nisj] : ['id'=>$userId],
                'approved_at' => now()->toIso8601String(),
            ];

            DB::table('wh_v3_invoice_approvals')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'document_source' => $source,
                'document_id' => $id,
                'direction' => $direction,
                'warehouse_id' => $warehouseId,
                'due_date' => $payload['due_date'],
                'payment_term_detail' => trim((string)$payload['payment_term_detail']),
                'estimate_payment_date' => $payload['estimate_payment_date'],
                'payment_account_id' => $account->id,
                'payment_account_snapshot' => json_encode($accountSnapshot),
                'approval_snapshot' => json_encode($approvalSnapshot),
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
                'notes' => $payload['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            match ($source) {
                'auto_incoming' => DB::table('wh_supplier_invoices')->where('warehouse_id', $warehouseId)->where('id', $id)->where('status', 'draft')->update([
                    'status' => 'issued',
                    'due_date' => $payload['due_date'],
                    'issued_by_user_id' => $userId,
                    'issued_at' => now(),
                    'updated_at' => now(),
                ]),
                'auto_outgoing' => DB::table('wh_v3_outgoing_invoices')->where('warehouse_id', $warehouseId)->where('id', $id)->where('status', 'draft')->update([
                    'status' => 'approved',
                    'due_date' => $payload['due_date'],
                    'updated_at' => now(),
                ]),
                'manual' => DB::table('wh_v3_manual_invoices')->where('warehouse_id', $warehouseId)->where('direction', $direction)->where('id', $id)->where('status', 'draft')->update([
                    'status' => 'approved',
                    'due_date' => $payload['due_date'],
                    'updated_by_user_id' => $userId,
                    'updated_at' => now(),
                ]),
                default => throw ValidationException::withMessages(['invoice' => ['Sumber invoice tidak dapat di-approve.']]),
            };

            $this->event($source, $id, $direction, 'approved', 'draft', $source === 'auto_incoming' ? 'issued' : 'approved', 'Invoice di-approve Finance Warehouse.', $userId, [
                'due_date' => $payload['due_date'],
                'estimate_payment_date' => $payload['estimate_payment_date'],
                'payment_account' => $accountSnapshot,
            ]);

            return $this->detail($direction, $source, $id, $warehouseId);
        }, 5);
    }

    public function createPaymentAccount(string $warehouseId, array $payload, string $userId): array
    {
        return DB::transaction(function () use ($warehouseId, $payload, $userId): array {
            $baseCode = Str::upper(trim((string)($payload['code'] ?? '')));
            if ($baseCode === '') $baseCode = 'ACC-'.Str::upper(Str::random(6));
            $code = $baseCode;
            $suffix = 1;
            while (DB::table('wh_v3_payment_accounts')->where('warehouse_id', $warehouseId)->where('code', $code)->exists()) {
                $code = Str::limit($baseCode, 52, '').'-'.(++$suffix);
            }

            $id = (string) Str::ulid();
            DB::table('wh_v3_payment_accounts')->insert([
                'id' => $id,
                'warehouse_id' => $warehouseId,
                'code' => $code,
                'name' => trim((string)$payload['name']),
                'bank_name' => $payload['bank_name'] ?? null,
                'account_name' => $payload['account_name'] ?? null,
                'account_number' => $payload['account_number'] ?? null,
                'is_active' => true,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('wh_v3_payment_accounts')->where('id', $id)->first();
            return $this->paymentAccountRow($row);
        }, 5);
    }

    private function incomingUnion(string $warehouseId)
    {
        $auto = DB::table('wh_supplier_invoices as i')
            ->leftJoin('pur_supplier_sources as p', 'p.id', '=', 'i.supplier_source_id')
            ->leftJoin('wh_supplier_purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')
            ->where('i.warehouse_id', $warehouseId)
            ->selectRaw("'auto_incoming' AS document_source")
            ->selectRaw("i.id, i.invoice_number, i.warehouse_id, 'supplier' AS party_type, i.supplier_source_id AS party_id")
            ->selectRaw("p.code AS party_code, p.name AS party_name, i.invoice_date, i.due_date, i.grand_total, i.status")
            ->selectRaw("COALESCE((SELECT SUM(COALESCE(si.accepted_qty_base, ii.quantity_base)) FROM wh_supplier_invoice_items ii LEFT JOIN wh_stock_in_items si ON si.id=ii.stock_in_item_id WHERE ii.supplier_invoice_id=i.id),0) AS total_stock_movement_qty")
            ->selectRaw("COALESCE((SELECT SUM(ii.inventory_cost_total) FROM wh_supplier_invoice_items ii WHERE ii.supplier_invoice_id=i.id),0) AS stock_valuation_total")
            ->selectRaw("po.po_number AS source_number, NULL AS external_reference, i.created_at");

        $manual = DB::table('wh_v3_manual_invoices as m')
            ->where('m.warehouse_id', $warehouseId)
            ->where('m.direction', 'incoming')
            ->selectRaw("'manual' AS document_source")
            ->selectRaw("m.id, m.invoice_number, m.warehouse_id, m.party_type, m.party_id")
            ->selectRaw("m.party_code_snapshot AS party_code, m.party_name_snapshot AS party_name, m.invoice_date, m.due_date, m.grand_total, m.status")
            ->selectRaw("m.total_stock_movement_qty, m.stock_valuation_total, NULL AS source_number, m.external_reference, m.created_at");

        return $auto->unionAll($manual);
    }

    private function outgoingUnion(string $warehouseId)
    {
        $auto = DB::table('wh_v3_outgoing_invoices as i')
            ->where('i.warehouse_id', $warehouseId)
            ->whereIn('i.destination_type', ['outlet','customer'])
            ->selectRaw("'auto_outgoing' AS document_source")
            ->selectRaw("i.id, i.invoice_number, i.warehouse_id, i.destination_type AS party_type, i.destination_id AS party_id")
            ->selectRaw("i.destination_code_snapshot AS party_code, i.destination_name_snapshot AS party_name, i.invoice_date, i.due_date, i.grand_total, i.status")
            ->selectRaw("i.total_stock_movement_qty, i.stock_valuation_total, i.source_number, NULL AS external_reference, i.created_at");

        $legacy = DB::table('wh_sales_invoices as i')
            ->leftJoin('wh_customers as c', 'c.id', '=', 'i.customer_id')
            ->where('i.warehouse_id', $warehouseId)
            ->selectRaw("'legacy_outgoing' AS document_source")
            ->selectRaw("i.id, i.invoice_number, i.warehouse_id, 'customer' AS party_type, i.customer_id AS party_id")
            ->selectRaw("c.code AS party_code, c.name AS party_name, i.invoice_date, i.due_date, i.grand_total, i.status")
            ->selectRaw("COALESCE((SELECT SUM(x.quantity_base) FROM wh_sales_invoice_items x WHERE x.sales_invoice_id=i.id),0) AS total_stock_movement_qty")
            ->selectRaw("i.cogs_total AS stock_valuation_total, NULL AS source_number, NULL AS external_reference, i.created_at");

        $manual = DB::table('wh_v3_manual_invoices as m')
            ->where('m.warehouse_id', $warehouseId)
            ->where('m.direction', 'outgoing')
            ->selectRaw("'manual' AS document_source")
            ->selectRaw("m.id, m.invoice_number, m.warehouse_id, m.party_type, m.party_id")
            ->selectRaw("m.party_code_snapshot AS party_code, m.party_name_snapshot AS party_name, m.invoice_date, m.due_date, m.grand_total, m.status")
            ->selectRaw("m.total_stock_movement_qty, m.stock_valuation_total, NULL AS source_number, m.external_reference, m.created_at");

        return $auto->unionAll($legacy)->unionAll($manual);
    }

    private function applyFilters($q, array $filters): void
    {
        if (! empty($filters['date_from'])) $q->where('invoice_date', '>=', $filters['date_from']);
        if (! empty($filters['date_to'])) $q->where('invoice_date', '<=', $filters['date_to']);
        if (! empty($filters['status'])) $q->where('status', $filters['status']);
        if (! empty($filters['party_type']) && $filters['party_type'] !== 'all') $q->where('party_type', $filters['party_type']);
        if (! empty($filters['party_id'])) $q->where('party_id', $filters['party_id']);
        if (! empty($filters['q'])) {
            $term = '%'.trim((string)$filters['q']).'%';
            $q->where(fn ($x) => $x
                ->where('invoice_number', 'like', $term)
                ->orWhere('party_name', 'like', $term)
                ->orWhere('party_code', 'like', $term)
                ->orWhere('source_number', 'like', $term)
                ->orWhere('external_reference', 'like', $term));
        }
    }

    private function detailAutoIncoming(string $direction, string $id, string $warehouseId): array
    {
        if ($direction !== 'incoming') abort(404);
        $r = DB::table('wh_supplier_invoices as i')
            ->leftJoin('pur_supplier_sources as p', 'p.id', '=', 'i.supplier_source_id')
            ->leftJoin('wh_supplier_purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')
            ->leftJoin('wh_stock_ins as si', 'si.id', '=', 'i.stock_in_id')
            ->where('i.warehouse_id', $warehouseId)->where('i.id', $id)
            ->first(['i.*','p.code as party_code','p.name as party_name','po.po_number','si.stock_in_number']);
        if (! $r) abort(404);

        $items = DB::table('wh_supplier_invoice_items as x')
            ->leftJoin('stk_skus as s', 's.id', '=', 'x.sku_id')
            ->leftJoin('wh_stock_in_items as si', 'si.id', '=', 'x.stock_in_item_id')
            ->where('x.supplier_invoice_id', $id)
            ->orderBy('s.name')
            ->get(['x.*','s.sku_code','s.name as sku_name','si.accepted_qty_base'])
            ->map(fn ($x) => [
                'id'=>(string)$x->id,'sku_id'=>(string)$x->sku_id,'sku_code'=>$x->sku_code,'sku_name'=>$x->sku_name,
                'quantity_base'=>(float)($x->accepted_qty_base ?? $x->quantity_base),'billed_qty_base'=>(float)$x->quantity_base,
                'unit_price'=>(float)$x->unit_price,'line_total'=>(float)$x->line_subtotal,
                'inventory_unit_cost'=>(float)$x->effective_unit_cost,'inventory_cost_total'=>(float)$x->inventory_cost_total,
            ])->values()->all();

        return $this->detailPayload('incoming','auto_incoming',$r->id,$r->invoice_number,$r->warehouse_id,'supplier',$r->supplier_source_id,$r->party_code,$r->party_name,$r->invoice_date,$r->due_date,$r->grand_total,$r->status,
            collect($items)->sum('quantity_base'),collect($items)->sum('inventory_cost_total'),$r->po_number,$r->stock_in_number,$items,$r->notes ?? null,$r->created_at);
    }

    private function detailAutoOutgoing(string $direction, string $id, string $warehouseId): array
    {
        if ($direction !== 'outgoing') abort(404);
        $r = DB::table('wh_v3_outgoing_invoices')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('destination_type', ['outlet','customer'])
            ->where('id', $id)
            ->first();
        if (! $r) abort(404);

        $items = DB::table('wh_v3_outgoing_invoice_items as x')
            ->leftJoin('stk_skus as s', 's.id', '=', 'x.sku_id')
            ->leftJoin('stk_uoms as base_uom', 'base_uom.id', '=', 's.base_uom_id')
            ->leftJoin('stk_uoms as billing_uom', 'billing_uom.id', '=', 'x.billing_uom_id')
            ->where('x.outgoing_invoice_id', $id)
            ->orderBy('s.name')
            ->get(['x.*','s.sku_code','s.name as sku_name','base_uom.code as base_uom_code','billing_uom.code as joined_billing_uom_code'])
            ->map(fn ($x) => [
                'id'=>(string)$x->id,'sku_id'=>(string)$x->sku_id,'sku_code'=>$x->sku_code,'sku_name'=>$x->sku_name,
                'quantity_base'=>(float)$x->stock_movement_qty_base,'stock_uom_code'=>$x->base_uom_code ?: 'BASE',
                'billed_qty_base'=>(float)$x->billed_qty_base,
                'billing_qty'=>(float)($x->billing_qty ?: $x->billed_qty_base),
                'billing_uom_id'=>$x->billing_uom_id ? (string)$x->billing_uom_id : null,
                'billing_uom_code'=>$x->billing_uom_code_snapshot ?: $x->joined_billing_uom_code ?: $x->base_uom_code ?: 'BASE',
                'billing_conversion_factor'=>(float)($x->billing_conversion_factor_snapshot ?: 1),
                'unit_price'=>(float)$x->unit_price,'unit_price_basis'=>$x->unit_price_basis ?: 'PER_PRICE_UOM','line_total'=>(float)$x->line_total,
                'inventory_unit_cost'=>(float)$x->inventory_unit_cost,'inventory_cost_total'=>(float)$x->inventory_cost_total,
            ])->values()->all();

        return $this->detailPayload('outgoing','auto_outgoing',$r->id,$r->invoice_number,$r->warehouse_id,$r->destination_type,$r->destination_id,$r->destination_code_snapshot,$r->destination_name_snapshot,$r->invoice_date,$r->due_date,$r->grand_total,$r->status,
            $r->total_stock_movement_qty,$r->stock_valuation_total,$r->source_number,null,$items,$r->notes,$r->created_at);
    }

    private function detailLegacyOutgoing(string $direction, string $id, string $warehouseId): array
    {
        if ($direction !== 'outgoing') abort(404);
        $r = DB::table('wh_sales_invoices as i')
            ->leftJoin('wh_customers as c', 'c.id', '=', 'i.customer_id')
            ->where('i.warehouse_id', $warehouseId)->where('i.id', $id)
            ->first(['i.*','c.code as party_code','c.name as party_name']);
        if (! $r) abort(404);

        $items = DB::table('wh_sales_invoice_items as x')
            ->leftJoin('stk_skus as s', 's.id', '=', 'x.sku_id')
            ->where('x.sales_invoice_id', $id)
            ->orderBy('s.name')
            ->get(['x.*','s.sku_code','s.name as sku_name'])
            ->map(fn ($x) => [
                'id'=>(string)$x->id,'sku_id'=>(string)$x->sku_id,'sku_code'=>$x->sku_code,'sku_name'=>$x->sku_name,
                'quantity_base'=>(float)$x->quantity_base,'billed_qty_base'=>(float)$x->quantity_base,
                'unit_price'=>(float)$x->unit_price,'line_total'=>(float)$x->line_total,
                'inventory_unit_cost'=>(float)$x->unit_cost,'inventory_cost_total'=>(float)$x->cogs_total,
            ])->values()->all();

        return $this->detailPayload('outgoing','legacy_outgoing',$r->id,$r->invoice_number,$r->warehouse_id,'customer',$r->customer_id,$r->party_code,$r->party_name,$r->invoice_date,$r->due_date,$r->grand_total,$r->status,
            collect($items)->sum('quantity_base'),$r->cogs_total,null,null,$items,$r->notes,$r->created_at);
    }

    private function detailManual(string $direction, string $id, string $warehouseId): array
    {
        $r = DB::table('wh_v3_manual_invoices')->where('warehouse_id', $warehouseId)->where('direction', $direction)->where('id', $id)->first();
        if (! $r) abort(404);

        $items = DB::table('wh_v3_manual_invoice_items as x')
            ->leftJoin('stk_skus as s', 's.id', '=', 'x.sku_id')
            ->where('x.manual_invoice_id', $id)
            ->orderBy('s.name')
            ->get(['x.*','s.sku_code','s.name as sku_name'])
            ->map(fn ($x) => [
                'id'=>(string)$x->id,'sku_id'=>(string)$x->sku_id,'sku_code'=>$x->sku_code,'sku_name'=>$x->sku_name,
                'description'=>$x->description_snapshot,'quantity_base'=>(float)$x->quantity_base,'billed_qty_base'=>(float)$x->quantity_base,
                'unit_price'=>(float)$x->unit_price,'line_total'=>(float)$x->line_total,
                'inventory_unit_cost'=>(float)$x->inventory_unit_cost,'inventory_cost_total'=>(float)$x->inventory_cost_total,
            ])->values()->all();

        return $this->detailPayload($direction,'manual',$r->id,$r->invoice_number,$r->warehouse_id,$r->party_type,$r->party_id,$r->party_code_snapshot,$r->party_name_snapshot,$r->invoice_date,$r->due_date,$r->grand_total,$r->status,
            $r->total_stock_movement_qty,$r->stock_valuation_total,null,$r->external_reference,$items,$r->notes,$r->created_at);
    }

    private function detailPayload(
        string $direction,string $source,string $id,string $number,string $warehouseId,string $partyType,string $partyId,?string $partyCode,?string $partyName,
        $invoiceDate,$dueDate,$grandTotal,string $status,$stockQty,$stockValue,?string $sourceNumber,?string $externalReference,array $items,?string $notes,$createdAt
    ): array {
        $approval = DB::table('wh_v3_invoice_approvals as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.approved_by_user_id')
            ->where('a.document_source', $source)->where('a.document_id', $id)
            ->first(['a.*','u.name as approver_name','u.nisj as approver_nisj']);

        return [
            'id'=>(string)$id,'direction'=>$direction,'document_source'=>$source,'invoice_number'=>$number,'warehouse_id'=>(string)$warehouseId,
            'party'=>['type'=>$partyType,'id'=>(string)$partyId,'code'=>$partyCode,'name'=>$partyName],
            'invoice_date'=>(string)$invoiceDate,'due_date'=>$dueDate ? (string)$dueDate : null,'grand_total'=>(float)$grandTotal,'status'=>$status,
            'total_stock_movement_qty'=>(float)$stockQty,'stock_valuation_total'=>(float)$stockValue,'source_number'=>$sourceNumber,'external_reference'=>$externalReference,
            'notes'=>$notes,'created_at'=>(string)$createdAt,'items'=>$items,
            'approval'=>$approval ? [
                'due_date'=>(string)$approval->due_date,
                'payment_term_detail'=>$approval->payment_term_detail,
                'estimate_payment_date'=>(string)$approval->estimate_payment_date,
                'payment_account'=>$this->decodeJson($approval->payment_account_snapshot),
                'approved_by'=>['name'=>$approval->approver_name,'nisj'=>$approval->approver_nisj],
                'approved_at'=>(string)$approval->approved_at,
                'notes'=>$approval->notes,
            ] : null,
            'timeline'=>$this->events($source,$id,$direction),
            'can_approve'=>$status==='draft' && $source!=='legacy_outgoing',
        ];
    }

    private function summaryRow(object $r): array
    {
        return [
            'id'=>(string)$r->id,'document_source'=>(string)$r->document_source,'invoice_number'=>(string)$r->invoice_number,
            'party'=>['type'=>(string)$r->party_type,'id'=>(string)$r->party_id,'code'=>$r->party_code,'name'=>$r->party_name],
            'invoice_date'=>(string)$r->invoice_date,'due_date'=>$r->due_date ? (string)$r->due_date : null,
            'grand_total'=>(float)$r->grand_total,'total_stock_movement_qty'=>(float)$r->total_stock_movement_qty,
            'stock_valuation_total'=>(float)$r->stock_valuation_total,'status'=>(string)$r->status,
            'source_number'=>$r->source_number,'external_reference'=>$r->external_reference,
            'is_manual'=>$r->document_source==='manual','is_draft'=>$r->status==='draft',
        ];
    }

    private function lockInvoiceForApproval(string $direction, string $source, string $id, string $warehouseId): string
    {
        $row = match ($source) {
            'auto_incoming' => $direction === 'incoming'
                ? DB::table('wh_supplier_invoices')->where('warehouse_id', $warehouseId)->where('id', $id)->lockForUpdate()->first(['id','status'])
                : null,
            'auto_outgoing' => $direction === 'outgoing'
                ? DB::table('wh_v3_outgoing_invoices')->where('warehouse_id', $warehouseId)->whereIn('destination_type', ['outlet','customer'])->where('id', $id)->lockForUpdate()->first(['id','status'])
                : null,
            'manual' => DB::table('wh_v3_manual_invoices')->where('warehouse_id', $warehouseId)->where('direction', $direction)->where('id', $id)->lockForUpdate()->first(['id','status']),
            default => null,
        };

        if (! $row) {
            throw ValidationException::withMessages(['invoice' => ['Invoice tidak ditemukan pada Warehouse/direction aktif.']]);
        }

        return (string) $row->status;
    }

    private function partySnapshot(string $direction, string $partyType, string $partyId): array
    {
        if ($direction === 'incoming' && $partyType !== 'supplier') {
            throw ValidationException::withMessages(['party_type' => ['Incoming Invoice manual hanya dapat menggunakan Supplier.']]);
        }
        if ($direction === 'outgoing' && ! in_array($partyType, ['outlet','customer'], true)) {
            throw ValidationException::withMessages(['party_type' => ['Outgoing Invoice manual harus menggunakan Outlet atau Customer.']]);
        }

        if ($partyType === 'supplier') {
            $q = DB::table('pur_supplier_sources')->where('id', $partyId)->where('is_active', true);
            if (Schema::hasColumn('pur_supplier_sources', 'deleted_at')) $q->whereNull('deleted_at');
            $r = $q->first(['id','code','name']);
        } elseif ($partyType === 'customer') {
            $q = DB::table('wh_customers')->where('id', $partyId)->where('is_active', true);
            if (Schema::hasColumn('wh_customers', 'deleted_at')) $q->whereNull('deleted_at');
            $r = $q->first(['id','code','name']);
        } else {
            $q = DB::table('outlets')->where('id', $partyId)->where('is_active', true)
                ->where(fn ($x) => $x->whereNull('type')->orWhereRaw('LOWER(type) <> ?', ['warehouse']));
            if (Schema::hasColumn('outlets', 'deleted_at')) $q->whereNull('deleted_at');
            $r = $q->first(['id','code','name']);
        }

        if (! $r) throw ValidationException::withMessages(['party_id' => ['Supplier/Outlet/Customer aktif tidak ditemukan.']]);

        return ['type'=>$partyType,'id'=>(string)$r->id,'code'=>$r->code,'name'=>$r->name];
    }

    private function paymentAccountOptions(string $warehouseId): array
    {
        return DB::table('wh_v3_payment_accounts')
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('warehouse_id', $warehouseId)->orWhereNull('warehouse_id'))
            ->orderBy('name')
            ->get()
            ->map(fn ($x) => $this->paymentAccountRow($x))
            ->values()->all();
    }

    private function paymentAccountRow(object $x): array
    {
        return [
            'id'=>(string)$x->id,'warehouse_id'=>$x->warehouse_id ? (string)$x->warehouse_id : null,
            'code'=>$x->code,'name'=>$x->name,'bank_name'=>$x->bank_name,'account_name'=>$x->account_name,'account_number'=>$x->account_number,
        ];
    }

    private function warehouseAverageCost(string $warehouseId, string $skuId): float
    {
        if (! Schema::hasTable('wh_batch_balances')) return 0.0;
        return round((float)(DB::table('wh_batch_balances')->where('warehouse_id',$warehouseId)->where('sku_id',$skuId)
            ->selectRaw('COALESCE(SUM(inventory_value)/NULLIF(SUM(on_hand_qty),0),0) AS unit_cost')->value('unit_cost') ?? 0), 6);
    }

    private function events(string $source, string $id, string $direction): array
    {
        if (! Schema::hasTable('wh_v3_finance_events')) return [];
        return DB::table('wh_v3_finance_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.document_source', $source)->where('e.document_id', $id)->where('e.direction', $direction)
            ->orderBy('e.occurred_at')
            ->get(['e.*','u.name as actor_name'])
            ->map(fn ($e) => [
                'event_type'=>$e->event_type,'from_status'=>$e->from_status,'to_status'=>$e->to_status,
                'message'=>$e->message,'actor'=>$e->actor_name,'occurred_at'=>(string)$e->occurred_at,
            ])->values()->all();
    }

    private function event(string $source,string $id,string $direction,string $event,?string $from,?string $to,string $message,?string $actor,array $metadata=[]): void
    {
        DB::table('wh_v3_finance_events')->insert([
            'id'=>(string)Str::ulid(),'document_source'=>$source,'document_id'=>$id,'direction'=>$direction,
            'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'message'=>$message,
            'metadata'=>$metadata ? json_encode($metadata) : null,'actor_user_id'=>$actor,'occurred_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function assertDirection(string $direction): void
    {
        if (! in_array($direction, ['incoming','outgoing'], true)) abort(404);
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.now('Asia/Jakarta')->format('Ymd').'-'.Str::upper(Str::random(6));
    }
}
