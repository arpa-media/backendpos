<?php

namespace App\Services\Cogs\V13;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * I24 read-only stock valuation. Inventory and warehouse batch are DISTINCT
 * representations: their valuations MUST NOT be combined into a single grand total.
 */
final class StockValuationI24Service
{
    public const INVENTORY = 'inventory';
    public const WAREHOUSE = 'warehouse';

    public function assertSchema(string $source): void
    {
        $tables = ['outlets', 'stk_skus', 'stk_uoms'];
        array_push($tables, ...($source === self::WAREHOUSE
            ? ['wh_batch_balances', 'wh_ledger_entries', 'wh_ledger_postings']
            : ['stk_inventory_balances', 'stk_inventory_movements']));
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                throw ValidationException::withMessages(['schema' => ["Tabel {$table} belum tersedia pada database ini."]]);
            }
        }
    }

    /** Scope is enforced on both listing AND detail; never trust client outlet id. */
    private function scope(Builder $query, string $column, ?string $scopeOutletId, ?string $requestedOutletId): Builder
    {
        if ($scopeOutletId && $requestedOutletId && $requestedOutletId !== $scopeOutletId) {
            throw ValidationException::withMessages(['outlet_id' => ['Outlet tidak tersedia pada scope akun.']]);
        }
        if ($scopeOutletId) $query->where($column, $scopeOutletId);
        elseif ($requestedOutletId) $query->where($column, $requestedOutletId);
        return $query;
    }

    public static function quality(float $quantity, float $averageCost, float $value): array
    {
        $flags = [];
        if ($quantity < -0.00005) $flags[] = 'negative_stock';
        if (abs($quantity) <= 0.00005 && abs($value) > 0.05) $flags[] = 'residual_value';
        if ($quantity > 0.00005 && $averageCost <= 0 && $value <= 0) $flags[] = 'missing_valuation';
        // Do not flag rounding differences as financial reconciliation failures.
        $drift = abs($value - $quantity * $averageCost);
        if ($quantity > 0.00005 && $averageCost > 0 && $drift > max(1.0, abs($value) * 0.01)) {
            $flags[] = 'cost_difference_review';
        }
        return $flags;
    }

    public function outlets(?string $scopeOutletId): array
    {
        $query = DB::table('outlets')->select(['id', 'code', 'name', 'type']);
        if ($scopeOutletId) $query->where('id', $scopeOutletId);
        return $query->orderBy('code')->limit(250)->get()->map(fn ($o) => [
            'id' => (string) $o->id, 'code' => (string) $o->code,
            'name' => (string) $o->name, 'type' => (string) $o->type,
        ])->all();
    }

    private function baseInventory(?string $scopeOutletId, ?string $outletId, string $search, bool $includeZero): Builder
    {
        $query = DB::table('stk_inventory_balances as b')
            ->join('outlets as o', 'o.id', '=', 'b.outlet_id')
            ->join('stk_skus as s', 's.id', '=', 'b.sku_id')
            ->leftJoin('stk_uoms as u', 'u.id', '=', 's.base_uom_id');
        $this->scope($query, 'b.outlet_id', $scopeOutletId, $outletId);
        if (! $includeZero) $query->where(fn ($q) => $q->where('b.on_hand_qty', '<>', 0)->orWhere('b.inventory_value', '<>', 0));
        $this->search($query, $search);
        return $query;
    }

    private function baseWarehouse(?string $scopeOutletId, ?string $outletId, string $search, bool $includeZero): Builder
    {
        $query = DB::table('wh_batch_balances as b')
            ->join('outlets as o', 'o.id', '=', 'b.warehouse_id')
            ->join('stk_skus as s', 's.id', '=', 'b.sku_id')
            ->leftJoin('stk_uoms as u', 'u.id', '=', 's.base_uom_id');
        $this->scope($query, 'b.warehouse_id', $scopeOutletId, $outletId);
        if (! $includeZero) $query->where(fn ($q) => $q->where('b.on_hand_qty', '<>', 0)->orWhere('b.inventory_value', '<>', 0));
        $this->search($query, $search);
        return $query;
    }

    private function search(Builder $query, string $search): void
    {
        if ($search === '') return;
        $like = '%'.addcslashes(trim($search), '%_\\').'%';
        $query->where(fn ($q) => $q->where('s.sku_code', 'like', $like)->orWhere('s.name', 'like', $like));
    }

    /** Returns values for one chosen ledger projection, NOT a consolidated financial balance. */
    public function overview(string $source, ?string $scopeOutletId, ?string $outletId,
        string $search, bool $includeZero, int $page, int $perPage): array
    {
        $this->assertSchema($source);
        if ($source === self::INVENTORY) {
            $base = $this->baseInventory($scopeOutletId, $outletId, $search, $includeZero);
            $totals = (clone $base)->selectRaw('COUNT(*) as item_count, COALESCE(SUM(b.inventory_value),0) as total_value')->first();
            $result = $base->select([
                'b.outlet_id','b.sku_id','o.code as outlet_code','o.name as outlet_name',
                's.sku_code','s.name as sku_name','u.symbol as uom_symbol',
                'b.on_hand_qty as qty','b.average_unit_cost as unit_cost','b.inventory_value as value',
                'b.last_movement_at',
            ])->orderBy('o.code')->orderBy('s.sku_code')->orderBy('b.sku_id')
                ->forPage($page, $perPage)->get();
        } else {
            $base = $this->baseWarehouse($scopeOutletId, $outletId, $search, $includeZero);
            $totals = (clone $base)->selectRaw(
                'COUNT(DISTINCT b.warehouse_id,b.sku_id) as item_count, COALESCE(SUM(b.inventory_value),0) as total_value'
            )->first();
            $result = $base->selectRaw(
                'b.warehouse_id as outlet_id, b.sku_id, o.code as outlet_code, o.name as outlet_name, '
                .'s.sku_code, s.name as sku_name, u.symbol as uom_symbol, '
                .'SUM(b.on_hand_qty) as qty, '
                .'CASE WHEN SUM(b.on_hand_qty) <> 0 THEN SUM(b.inventory_value)/SUM(b.on_hand_qty) ELSE 0 END as unit_cost, '
                .'SUM(b.inventory_value) as value, MAX(b.last_movement_at) as last_movement_at, '
                .'COUNT(*) as batches, SUM(b.reserved_qty) as reserved_qty, SUM(b.quarantine_qty) as quarantine_qty'
            )->groupBy('b.warehouse_id','b.sku_id','o.code','o.name','s.sku_code','s.name','u.symbol')
                ->orderBy('o.code')->orderBy('s.sku_code')->orderBy('b.sku_id')
                ->forPage($page, $perPage)->get();
        }
        // A separate, source-specific subtotal per outlet (same search/zero filters)
        // makes the global view auditable without summing different UOM quantities.
        $outletKey = $source === self::WAREHOUSE ? 'b.warehouse_id' : 'b.outlet_id';
        $subtotalQuery = $source === self::WAREHOUSE
            ? $this->baseWarehouse($scopeOutletId, $outletId, $search, $includeZero)
            : $this->baseInventory($scopeOutletId, $outletId, $search, $includeZero);
        $outletTotals = $subtotalQuery->selectRaw(
            $outletKey.' as outlet_id, o.code as outlet_code, o.name as outlet_name, '
            .'COUNT(DISTINCT b.sku_id) as sku_count, COALESCE(SUM(b.inventory_value),0) as inventory_value'
        )->groupBy($outletKey,'o.code','o.name')->orderBy('o.code')->limit(250)
            ->get()->map(fn ($r) => [
                'outlet_id' => (string) $r->outlet_id,
                'outlet_code' => (string) $r->outlet_code,
                'outlet_name' => (string) $r->outlet_name,
                'sku_count' => (int) $r->sku_count,
                'inventory_value' => (float) $r->inventory_value,
            ])->all();
        $items = $result->map(function ($row) use ($source) {
            $qty = (float) $row->qty;
            $value = (float) $row->value;
            $cost = (float) $row->unit_cost;
            return [
                'outlet_id' => (string) $row->outlet_id,
                'outlet_code' => (string) $row->outlet_code,
                'outlet_name' => (string) $row->outlet_name,
                'sku_id' => (string) $row->sku_id,
                'sku_code' => (string) $row->sku_code,
                'sku_name' => (string) $row->sku_name,
                'uom_symbol' => (string) ($row->uom_symbol ?? ''),
                'quantity' => $qty, 'average_cost' => $cost, 'inventory_value' => $value,
                'last_movement_at' => $row->last_movement_at,
                'batch_count' => $source === self::WAREHOUSE ? (int) $row->batches : null,
                'reserved_qty' => $source === self::WAREHOUSE ? (float) $row->reserved_qty : null,
                'quarantine_qty' => $source === self::WAREHOUSE ? (float) $row->quarantine_qty : null,
                'quality_flags' => self::quality($qty, $cost, $value),
            ];
        })->all();
        $total = (int) ($totals->item_count ?? 0);
        return [
            'source' => $source,
            'scope' => ['mode' => $scopeOutletId ? 'outlet' : 'global', 'outlet_id' => $scopeOutletId],
            'summary' => ['sku_outlet_rows' => $total, 'inventory_value' => (float) ($totals->total_value ?? 0),
                'notice' => 'Total valuasi hanya untuk sumber yang dipilih. Inventory dan Warehouse tidak dijumlahkan. Quantity berbeda UOM tidak ditotal.'],
            'items' => $items,
            'outlet_totals' => $outletTotals,
            'pagination' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total, 'per_page' => $perPage],
            'generated_at' => now()->toIso8601String(),
            'valuation_basis' => $source === self::WAREHOUSE ? 'warehouse_batch_balances' : 'stk_inventory_balances',
        ];
    }

    public function movements(string $source, string $outletId, string $skuId,
        ?string $scopeOutletId, ?string $from, ?string $to, int $page, int $perPage): array
    {
        $this->assertSchema($source);
        if ($scopeOutletId && $outletId !== $scopeOutletId) {
            throw ValidationException::withMessages(['outlet_id' => ['Outlet tidak tersedia pada scope akun.']]);
        }
        if ($source === self::INVENTORY) {
            $query = DB::table('stk_inventory_movements as m')
                ->where('m.outlet_id', $outletId)->where('m.sku_id', $skuId);
            if ($from) $query->where('m.business_date','>=',$from);
            if ($to) $query->where('m.business_date','<=',$to);
            $total = (clone $query)->count();
            $rows = $query->select([
                'm.id','m.business_date','m.created_at','m.movement_type','m.reference_type','m.reference_id',
                'm.reference_line_id','m.quantity','m.unit_cost','m.total_cost',
                'm.balance_qty_after','m.average_cost_after','m.inventory_value_after',
            ])->orderByDesc('m.business_date')->orderByDesc('m.created_at')->orderByDesc('m.id')
                ->forPage($page,$perPage)->get()->map(fn ($m) => [
                    'id' => (string) $m->id,'business_date' => $m->business_date,'posted_at' => $m->created_at,
                    'movement_type' => $m->movement_type,'reference_type' => $m->reference_type,
                    'reference_id' => $m->reference_id,'reference_line_id' => $m->reference_line_id,
                    'signed_quantity' => (float) $m->quantity, 'unit_cost' => (float) $m->unit_cost,
                    'total_cost' => (float) $m->total_cost,
                    'balance_qty_after' => (float) $m->balance_qty_after,
                    'average_cost_after' => (float) $m->average_cost_after,
                    'inventory_value_after' => $m->inventory_value_after === null ? null : (float) $m->inventory_value_after,
                    'batch_id' => null,'storage_id' => null,'projection_movement_id' => null,
                ])->all();
        } else {
            $query = DB::table('wh_ledger_entries as e')
                ->join('wh_ledger_postings as p','p.id','=','e.posting_id')
                ->where('e.warehouse_id',$outletId)->where('e.sku_id',$skuId);
            if ($from) $query->where('p.business_date','>=',$from);
            if ($to) $query->where('p.business_date','<=',$to);
            $total = (clone $query)->count();
            $rows = $query->select([
                'e.id','e.storage_id','e.batch_id','e.projection_movement_id','e.created_at',
                'e.direction','e.signed_quantity_base','e.unit_cost','e.total_cost',
                'e.batch_qty_after','e.average_cost_after','e.inventory_value_after',
                'p.business_date','p.movement_type','p.reference_type','p.reference_id',
            ])->orderByDesc('e.created_at')->orderByDesc('e.id')
                ->forPage($page,$perPage)->get()->map(fn ($m) => [
                    'id' => (string) $m->id,'business_date' => $m->business_date,'posted_at' => $m->created_at,
                    'movement_type' => $m->movement_type,'reference_type' => $m->reference_type,
                    'reference_id' => $m->reference_id,'reference_line_id' => null,
                    'signed_quantity' => (float) $m->signed_quantity_base, 'unit_cost' => (float) $m->unit_cost,
                    'total_cost' => (float) $m->total_cost,
                    // Warehouse batch qty after is per batch; value after is warehouse SKU aggregate.
                    'balance_qty_after' => (float) $m->batch_qty_after,
                    'average_cost_after' => (float) $m->average_cost_after,
                    'inventory_value_after' => (float) $m->inventory_value_after,
                    'batch_id' => (string) $m->batch_id,'storage_id' => (string) $m->storage_id,
                    'projection_movement_id' => $m->projection_movement_id,
                ])->all();
        }
        return [
            'source' => $source, 'outlet_id' => $outletId, 'sku_id' => $skuId,
            'items' => $rows,
            'pagination' => ['current_page'=>$page,'last_page'=>max(1,(int) ceil($total/$perPage)), 'total'=>$total,'per_page'=>$perPage],
            'note' => $source === self::WAREHOUSE
                ? 'Setiap baris merepresentasikan batch movement; saldo quantity setelah movement adalah saldo batch, bukan agregat seluruh SKU.'
                : 'Urutan adalah tanggal bisnis lalu waktu pencatatan. Saldo sesudah movement adalah snapshot pada waktu posting, bukan hasil replay historis.',
        ];
    }
}
