<?php

namespace App\Services\Warehouse\FinalV3;

use App\Services\Warehouse\FinanceV3\WarehouseFinanceV3Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseV3FinalService
{
    private const REQUIRED_TABLES = [
        'outlets','stk_skus','wh_storages','stk_inventory_balances','wh_batch_balances','wh_ledger_postings','wh_ledger_entries',
        'access_menus','wh_purchase_requests','wh_purchase_request_items','wh_supplier_purchase_orders','wh_supplier_purchase_order_items','wh_stock_ins','wh_stock_in_items','wh_stock_in_documents',
        'wh_v3_stock_request_reviews','wh_v3_production_material_requests','wh_v3_production_material_request_items',
        'wh_v3_logistics_prepare_requests','wh_v3_delivery_orders','wh_v3_delivery_order_items','wh_v3_goods_receipts','wh_v3_goods_receipt_items',
        'wh_v3_sales_orders','wh_v3_sales_order_items','wh_v3_transfer_orders','wh_v3_transfer_order_items','wh_productions','wh_production_inputs','wh_production_outputs','wh_v3_production_results','wh_v3_production_result_items',
        'wh_supplier_invoices','wh_supplier_invoice_items','wh_v3_outgoing_invoices','wh_v3_outgoing_invoice_items','wh_v3_manual_invoices','wh_v3_manual_invoice_items','wh_v3_invoice_approvals',
    ];

    public function __construct(private readonly WarehouseFinanceV3Service $finance) {}

    public function dashboard(Request $request): array
    {
        $warehouseId = $this->warehouseId($request);
        [$from, $to] = $this->dateRange($request);
        $stock = $this->stockSummary($warehouseId);
        $reconciliation = $this->reconciliation($warehouseId, 15);
        $incoming = $this->finance->invoices('incoming', $warehouseId, ['date_from'=>$from,'date_to'=>$to,'per_page'=>10]);
        $outgoing = $this->finance->invoices('outgoing', $warehouseId, ['date_from'=>$from,'date_to'=>$to,'per_page'=>10]);

        $procurement = [
            'pr_pending' => $this->countStatus('wh_purchase_requests', 'warehouse_id', $warehouseId, ['submitted','review'], ['flow_version'=>3]),
            'po_open' => $this->countStatus('wh_supplier_purchase_orders', 'warehouse_id', $warehouseId, ['generated','draft','ordered','receiving'], ['flow_version'=>3]),
            'stock_in_pending' => $this->countStatus('wh_stock_ins', 'warehouse_id', $warehouseId, ['draft','receiving'], ['flow_version'=>3]),
            'stock_in_completed' => $this->countStatus('wh_stock_ins', 'warehouse_id', $warehouseId, ['approved'], ['flow_version'=>3]),
        ];

        $sales = [
            'stock_request_pending' => $this->countStatus('wh_v3_stock_request_reviews', 'warehouse_id', $warehouseId, ['pending']),
            'production_request_pending' => $this->countStatus('wh_v3_production_material_requests', 'warehouse_id', $warehouseId, ['pending']),
            'sales_order_open' => $this->countStatus('wh_v3_sales_orders', 'warehouse_id', $warehouseId, ['draft','submitted','approved','checker_prepare','dispatched']),
            'transfer_open' => $this->countTransferOpen($warehouseId),
        ];

        $production = [
            'awaiting_material' => $this->countStatus('wh_productions', 'warehouse_id', $warehouseId, ['awaiting_material'], ['flow_version'=>3]),
            'ongoing' => $this->countStatus('wh_productions', 'warehouse_id', $warehouseId, ['on_progress'], ['flow_version'=>3]),
            'result_draft' => $this->countStatus('wh_v3_production_results', 'warehouse_id', $warehouseId, ['draft']),
            'finished' => $this->countStatus('wh_productions', 'warehouse_id', $warehouseId, ['completed'], ['flow_version'=>3]),
        ];

        $logistics = [
            'checker_prepare' => $this->countStatus('wh_v3_logistics_prepare_requests', 'warehouse_id', $warehouseId, ['queued','preparing']),
            'delivery_open' => $this->countStatus('wh_v3_delivery_orders', 'warehouse_id', $warehouseId, ['dispatched','in_transit']),
            'gr_waiting_receipt' => $this->countStatus('wh_v3_goods_receipts', 'warehouse_id', $warehouseId, ['draft']),
            'gr_waiting_complete' => $this->countStatus('wh_v3_goods_receipts', 'warehouse_id', $warehouseId, ['submitted']),
        ];

        $invoice = [
            'incoming_draft' => $this->invoiceDraftCount('incoming', $warehouseId),
            'outgoing_draft' => $this->invoiceDraftCount('outgoing', $warehouseId),
            'incoming_due' => $this->invoiceDueCount('incoming', $warehouseId),
            'outgoing_due' => $this->invoiceDueCount('outgoing', $warehouseId),
            'incoming_approved_value' => (float)($incoming['metrics']['approved_invoice_value'] ?? 0),
            'outgoing_approved_value' => (float)($outgoing['metrics']['approved_invoice_value'] ?? 0),
        ];

        return [
            'warehouse' => $this->warehouseSnapshot($warehouseId),
            'filters' => ['date_from'=>$from,'date_to'=>$to],
            'stock' => $stock,
            'procurement' => $procurement,
            'sales' => $sales,
            'production' => $production,
            'logistics' => $logistics,
            'invoice' => $invoice,
            'notifications' => [
                'sales' => $sales['stock_request_pending'] + $sales['production_request_pending'] + $sales['sales_order_open'] + $sales['transfer_open'],
                'logistics' => $logistics['checker_prepare'] + $logistics['gr_waiting_complete'],
                'invoice' => $invoice['incoming_draft'] + $invoice['outgoing_draft'] + $invoice['incoming_due'] + $invoice['outgoing_due'],
                'production' => $production['awaiting_material'] + $production['ongoing'] + $production['result_draft'],
                'reconciliation' => (int)($reconciliation['summary']['failed_checks'] ?? 0),
            ],
            'finance' => ['incoming'=>$incoming['metrics'] ?? [], 'outgoing'=>$outgoing['metrics'] ?? []],
            'reconciliation' => $reconciliation['summary'],
            'recent_documents' => $this->recentDocuments($warehouseId, 18),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function reconciliation(string $warehouseId, int $rowLimit = 50): array
    {
        $checks = [];
        $checks[] = $this->checkAggregateVsBatch($warehouseId, $rowLimit);
        $checks[] = $this->checkPurchaseStockIn($warehouseId, $rowLimit);
        $checks[] = $this->checkGoodsReceipts($warehouseId, $rowLimit);
        $checks[] = $this->checkTransferQuantity($warehouseId, $rowLimit);
        $checks[] = $this->checkTransferValuation($warehouseId, $rowLimit);
        $checks[] = $this->checkProductionMaterials($warehouseId, $rowLimit);
        $checks[] = $this->checkProductionOutputs($warehouseId, $rowLimit);
        $checks[] = $this->checkOutgoingInvoiceValuation($warehouseId, $rowLimit);
        $checks[] = $this->checkIncomingInvoiceValuation($warehouseId, $rowLimit);

        return [
            'warehouse' => $this->warehouseSnapshot($warehouseId),
            'summary' => [
                'total_checks' => count($checks),
                'passed_checks' => collect($checks)->where('status','passed')->count(),
                'failed_checks' => collect($checks)->where('status','failed')->count(),
                'critical_failures' => collect($checks)->where('status','failed')->where('severity','critical')->count(),
                'warning_failures' => collect($checks)->where('status','failed')->where('severity','warning')->count(),
            ],
            'checks' => $checks,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function readiness(string $warehouseId): array
    {
        $reconciliation = $this->reconciliation($warehouseId, 10);
        $checks = [];
        $missing = collect(self::REQUIRED_TABLES)->reject(fn(string $table) => Schema::hasTable($table))->values()->all();
        $checks[] = $this->simpleCheck('required_tables','Schema Warehouse v3 lengkap', $missing === [], 'critical', $missing ? 'Missing: '.implode(', ', $missing) : 'Seluruh tabel inti tersedia.');

        $warehouseActive = Schema::hasTable('outlets') && DB::table('outlets')->where('id',$warehouseId)->where('is_active',true)->whereRaw('LOWER(type)=?',['warehouse'])->exists();
        $checks[] = $this->simpleCheck('warehouse_active','Warehouse scope aktif dan valid', $warehouseActive, 'critical', $warehouseActive ? 'Warehouse aktif tersedia.' : 'Warehouse ID tidak aktif atau bukan outlet type Warehouse.');

        $uncategorized = Schema::hasTable('wh_storages')
            ? DB::table('wh_storages')->where('warehouse_id',$warehouseId)->where('code','UNCATEGORIZED')->where('is_active',true)->whereNull('deleted_at')->exists()
            : false;
        $checks[] = $this->simpleCheck('uncategorized_storage','Storage UNCATEGORIZED tersedia', $uncategorized, 'critical', $uncategorized ? 'Fallback storage siap.' : 'Storage fallback belum tersedia.');

        $negative = $this->negativeBalanceCount($warehouseId);
        $checks[] = $this->simpleCheck('negative_stock','Tidak ada saldo negatif', $negative === 0, 'critical', $negative." balance negatif.");

        $processing = Schema::hasTable('wh_ledger_postings') ? DB::table('wh_ledger_postings')->where('warehouse_id',$warehouseId)->where('status','processing')->count() : 0;
        $checks[] = $this->simpleCheck('ledger_processing','Tidak ada posting ledger menggantung', $processing === 0, 'critical', $processing." posting masih processing.");

        $legacyActive = $this->legacyMenuActiveCount();
        $checks[] = $this->simpleCheck('legacy_navigation_hidden','Barcode / checker-keeper / fulfillment tidak terekspos', $legacyActive === 0, 'warning', $legacyActive." menu legacy masih aktif pada Access Matrix.");

        $routes = ['warehouse.v3-final.dashboard','warehouse.v3-final.reconciliation','warehouse.v3-final.readiness'];
        $missingRoutes = collect($routes)->reject(fn(string $name) => Route::has($name))->values()->all();
        $checks[] = $this->simpleCheck('final_routes','Route final Warehouse v3 tersedia', $missingRoutes === [], 'critical', $missingRoutes ? 'Missing route: '.implode(', ', $missingRoutes) : 'Route final tersedia.');

        $checks[] = $this->simpleCheck(
            'cross_module_reconciliation','Cross-module reconciliation bersih',
            (int)($reconciliation['summary']['critical_failures'] ?? 0) === 0,
            'critical',
            ($reconciliation['summary']['failed_checks'] ?? 0).' check gagal; '.($reconciliation['summary']['critical_failures'] ?? 0).' critical.'
        );

        $criticalFailed = collect($checks)->where('status','failed')->where('severity','critical')->count();
        return [
            'warehouse'=>$this->warehouseSnapshot($warehouseId),
            'status'=>$criticalFailed === 0 ? 'ready' : 'blocked',
            'summary'=>[
                'total_checks'=>count($checks),
                'passed'=>collect($checks)->where('status','passed')->count(),
                'failed'=>collect($checks)->where('status','failed')->count(),
                'critical_failed'=>$criticalFailed,
                'warning_failed'=>collect($checks)->where('status','failed')->where('severity','warning')->count(),
            ],
            'checks'=>$checks,
            'reconciliation'=>$reconciliation['summary'],
            'generated_at'=>now()->toIso8601String(),
        ];
    }

    public function smoke(?string $warehouseId = null): array
    {
        $ids = $warehouseId ? collect([$warehouseId]) : $this->activeWarehouseIds();
        if ($ids->isEmpty()) {
            return ['status'=>'failed','warehouses'=>[],'message'=>'Tidak ada Warehouse aktif yang dapat diperiksa.','generated_at'=>now()->toIso8601String()];
        }
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = $this->readiness((string)$id);
        }
        return [
            'status' => collect($rows)->contains(fn(array $row) => $row['status'] === 'blocked') ? 'failed' : 'passed',
            'warehouses' => $rows,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function checkAggregateVsBatch(string $warehouseId, int $limit): array
    {
        if (!Schema::hasTable('stk_skus') || !Schema::hasTable('stk_inventory_balances') || !Schema::hasTable('wh_batch_balances')) return $this->unavailable('stock_balance_vs_batch','Aggregate stock vs batch balance');
        $batch = DB::table('wh_batch_balances')->where('warehouse_id',$warehouseId)->groupBy('sku_id')
            ->selectRaw('sku_id, COALESCE(SUM(on_hand_qty),0) qty, COALESCE(SUM(inventory_value),0) value');
        $rows = DB::table('stk_skus as sku')
            ->leftJoin('stk_inventory_balances as a', fn($j) => $j->on('a.sku_id','=','sku.id')->where('a.outlet_id','=',$warehouseId))
            ->leftJoinSub($batch,'b',fn($j)=>$j->on('b.sku_id','=','sku.id'))
            ->where(fn($q)=>$q->whereNotNull('a.id')->orWhereNotNull('b.sku_id'))
            ->whereRaw('ABS(COALESCE(a.on_hand_qty,0)-COALESCE(b.qty,0)) > 0.0001 OR ABS(COALESCE(a.inventory_value,0)-COALESCE(b.value,0)) > 0.01')
            ->selectRaw('sku.id, sku.sku_code, sku.name, COALESCE(a.on_hand_qty,0) aggregate_qty, COALESCE(b.qty,0) batch_qty, COALESCE(a.inventory_value,0) aggregate_value, COALESCE(b.value,0) batch_value')
            ->limit($limit)->get();
        return $this->checkResult('stock_balance_vs_batch','Aggregate stock = batch balance','critical',$rows->count(),$rows->map(fn($r)=>[
            'document'=>$r->sku_code,'description'=>$r->name,'expected'=>(float)$r->batch_qty,'actual'=>(float)$r->aggregate_qty,'variance'=>round((float)$r->aggregate_qty-(float)$r->batch_qty,4),
            'value_variance'=>round((float)$r->aggregate_value-(float)$r->batch_value,2),
        ])->all());
    }

    private function checkPurchaseStockIn(string $warehouseId, int $limit): array
    {
        if (!Schema::hasTable('wh_stock_ins') || !Schema::hasTable('wh_stock_in_items') || !Schema::hasTable('wh_ledger_entries')) return $this->unavailable('purchase_stock_in_ledger','Stock In document vs ledger');
        $rows = DB::table('wh_stock_ins')->where('warehouse_id',$warehouseId)->where('flow_version',3)->where('status','approved')->orderByDesc('created_at')->limit(1000)->get();
        $bad=[];
        foreach($rows as $row){
            $doc=(float)DB::table('wh_stock_in_items')->where('stock_in_id',$row->id)->sum('accepted_qty_base');
            $ledger=$row->ledger_posting_id ? (float)DB::table('wh_ledger_entries')->where('posting_id',$row->ledger_posting_id)->sum('quantity_base') : 0.0;
            if(!$row->ledger_posting_id || abs($doc-$ledger)>0.0001){$bad[]=['document'=>$row->stock_in_number,'expected'=>round($doc,4),'actual'=>round($ledger,4),'variance'=>round($ledger-$doc,4),'message'=>$row->ledger_posting_id?'Qty ledger berbeda.':'Ledger posting belum ada.'];if(count($bad)>=$limit)break;}
        }
        return $this->checkResult('purchase_stock_in_ledger','Completed Stock In = ledger purchase_in','critical',count($bad),$bad);
    }

    private function checkGoodsReceipts(string $warehouseId, int $limit): array
    {
        if (!Schema::hasTable('wh_v3_goods_receipts') || !Schema::hasTable('wh_v3_goods_receipt_items') || !Schema::hasTable('wh_ledger_entries')) return $this->unavailable('goods_receipt_ledger','Goods Receipt vs outbound ledger');
        $rows=DB::table('wh_v3_goods_receipts')->where('warehouse_id',$warehouseId)->where('status','completed')->orderByDesc('completed_at')->limit(1200)->get();$bad=[];
        foreach($rows as $row){
            $sent=(float)DB::table('wh_v3_goods_receipt_items')->where('goods_receipt_id',$row->id)->sum('sent_qty_base');
            $ledger=$row->ledger_posting_id?(float)DB::table('wh_ledger_entries')->where('posting_id',$row->ledger_posting_id)->sum('quantity_base'):0.0;
            if(!$row->ledger_posting_id||abs($sent-$ledger)>0.0001){$bad[]=['document'=>$row->goods_receipt_number,'expected'=>round($sent,4),'actual'=>round($ledger,4),'variance'=>round($ledger-$sent,4),'message'=>'Qty dikirim harus sama dengan ledger OUT origin.'];if(count($bad)>=$limit)break;}
        }
        return $this->checkResult('goods_receipt_ledger','GR completed = outbound ledger','critical',count($bad),$bad);
    }

    private function transferRows(string $warehouseId)
    {
        if (!Schema::hasColumn('wh_v3_goods_receipts','destination_ledger_posting_id')) return collect();
        return DB::table('wh_v3_goods_receipts')
            ->where('destination_type','warehouse')
            ->where('status','completed')
            ->where(function ($query) use ($warehouseId): void {
                $query->where('warehouse_id',$warehouseId)->orWhere('destination_id',$warehouseId);
            })
            ->orderByDesc('completed_at')->limit(1000)->get();
    }

    private function checkTransferQuantity(string $warehouseId, int $limit): array
    {
        if (!Schema::hasTable('wh_v3_goods_receipts') || !Schema::hasTable('wh_v3_goods_receipt_items') || !Schema::hasTable('wh_ledger_entries') || !Schema::hasColumn('wh_v3_goods_receipts','destination_ledger_posting_id')) return $this->unavailable('transfer_pair','Transfer origin vs destination');
        $bad=[];
        foreach($this->transferRows($warehouseId) as $row){
            $items=DB::table('wh_v3_goods_receipt_items')->where('goods_receipt_id',$row->id)
                ->groupBy('sku_id')->selectRaw('sku_id, SUM(sent_qty_base) sent, SUM(received_qty_base) received, SUM(not_received_qty_base) not_received')->get();
            $origin=collect($row->ledger_posting_id ? DB::table('wh_ledger_entries')->where('posting_id',$row->ledger_posting_id)->groupBy('sku_id')->selectRaw('sku_id,SUM(quantity_base) qty')->get() : [])->keyBy('sku_id');
            $destination=collect($row->destination_ledger_posting_id ? DB::table('wh_ledger_entries')->where('posting_id',$row->destination_ledger_posting_id)->groupBy('sku_id')->selectRaw('sku_id,SUM(quantity_base) qty')->get() : [])->keyBy('sku_id');
            foreach($items as $item){
                $sent=(float)$item->sent;$received=(float)$item->received;$notReceived=(float)$item->not_received;
                $originQty=(float)($origin->get($item->sku_id)->qty??0);$destQty=(float)($destination->get($item->sku_id)->qty??0);
                if(!$row->ledger_posting_id||!$row->destination_ledger_posting_id||abs($sent-$originQty)>0.0001||abs($received-$destQty)>0.0001||abs($sent-($received+$notReceived))>0.0001){
                    $bad[]=['document'=>$row->goods_receipt_number,'expected'=>round($received,4),'actual'=>round($destQty,4),'variance'=>round($destQty-$received,4),'message'=>'Transfer harus memenuhi sent = received + not received; origin ledger = sent; destination ledger = received.'];
                    if(count($bad)>=$limit)break 2;
                }
            }
        }
        return $this->checkResult('transfer_pair','Transfer origin/destination quantity conservation','critical',count($bad),$bad);
    }

    private function checkTransferValuation(string $warehouseId, int $limit): array
    {
        if (!Schema::hasTable('wh_v3_goods_receipts') || !Schema::hasTable('wh_v3_goods_receipt_items') || !Schema::hasTable('wh_ledger_entries') || !Schema::hasColumn('wh_v3_goods_receipts','destination_ledger_posting_id')) return $this->unavailable('transfer_valuation','Transfer cost basis');
        $bad=[];
        foreach($this->transferRows($warehouseId) as $row){
            if(!$row->ledger_posting_id||!$row->destination_ledger_posting_id) continue;
            $items=DB::table('wh_v3_goods_receipt_items')->where('goods_receipt_id',$row->id)
                ->groupBy('sku_id')->selectRaw('sku_id, SUM(sent_qty_base) sent, SUM(received_qty_base) received')->get();
            $origin=DB::table('wh_ledger_entries')->where('posting_id',$row->ledger_posting_id)->groupBy('sku_id')->selectRaw('sku_id,SUM(quantity_base) qty,SUM(total_cost) value')->get()->keyBy('sku_id');
            $destination=DB::table('wh_ledger_entries')->where('posting_id',$row->destination_ledger_posting_id)->groupBy('sku_id')->selectRaw('sku_id,SUM(quantity_base) qty,SUM(total_cost) value')->get()->keyBy('sku_id');
            foreach($items as $item){
                $originLine=$origin->get($item->sku_id);$destLine=$destination->get($item->sku_id);
                $sent=(float)$item->sent;$received=(float)$item->received;
                if(!$originLine||!$destLine||$sent<=0||$received<=0) continue;
                $expected=((float)$originLine->value/(float)$originLine->qty)*$received;
                $actual=(float)$destLine->value;
                if(abs($expected-$actual)>0.01){$bad[]=['document'=>$row->goods_receipt_number,'expected'=>round($expected,2),'actual'=>round($actual,2),'variance'=>round($actual-$expected,2),'message'=>'Cost basis transfer-in berbeda dari weighted unit cost origin untuk qty yang diterima.'];if(count($bad)>=$limit)break 2;}
            }
        }
        return $this->checkResult('transfer_valuation','Transfer destination keeps origin cost basis','warning',count($bad),$bad);
    }

    private function checkProductionMaterials(string $warehouseId, int $limit): array
    {
        if (!Schema::hasTable('wh_v3_production_material_requests') || !Schema::hasTable('wh_v3_production_material_request_items') || !Schema::hasTable('wh_ledger_entries')) return $this->unavailable('production_material_ledger','Production material vs ledger');
        $rows=DB::table('wh_v3_production_material_requests')->where('warehouse_id',$warehouseId)->where('status','approved')->orderByDesc('approved_at')->limit(1000)->get();$bad=[];
        foreach($rows as $row){
            $approved=(float)DB::table('wh_v3_production_material_request_items')->where('production_request_id',$row->id)->sum('approved_qty_base');
            $ledger=$row->material_ledger_posting_id?(float)DB::table('wh_ledger_entries')->where('posting_id',$row->material_ledger_posting_id)->sum('quantity_base'):0.0;
            if(!$row->material_ledger_posting_id||abs($approved-$ledger)>0.0001){$bad[]=['document'=>$row->request_number,'expected'=>round($approved,4),'actual'=>round($ledger,4),'variance'=>round($ledger-$approved,4),'message'=>'Approved material harus sama dengan production_out.'];if(count($bad)>=$limit)break;}
        }
        return $this->checkResult('production_material_ledger','Production Request = material ledger OUT','critical',count($bad),$bad);
    }

    private function checkProductionOutputs(string $warehouseId, int $limit): array
    {
        if (!Schema::hasTable('wh_v3_production_results') || !Schema::hasTable('wh_v3_production_result_items') || !Schema::hasTable('wh_ledger_entries')) return $this->unavailable('production_output_ledger','Production result vs ledger');
        $rows=DB::table('wh_v3_production_results')->where('warehouse_id',$warehouseId)->where('status','approved')->orderByDesc('approved_at')->limit(1000)->get();$bad=[];
        foreach($rows as $row){
            $qty=(float)DB::table('wh_v3_production_result_items')->where('result_id',$row->id)->sum('qty_base');
            $ledger=$row->ledger_posting_id?(float)DB::table('wh_ledger_entries')->where('posting_id',$row->ledger_posting_id)->sum('quantity_base'):0.0;
            if(!$row->ledger_posting_id||abs($qty-$ledger)>0.0001){$bad[]=['document'=>$row->result_number,'expected'=>round($qty,4),'actual'=>round($ledger,4),'variance'=>round($ledger-$qty,4),'message'=>'Approved output harus sama dengan production_in.'];if(count($bad)>=$limit)break;}
        }
        return $this->checkResult('production_output_ledger','Production Result = ledger IN','critical',count($bad),$bad);
    }

    private function checkOutgoingInvoiceValuation(string $warehouseId, int $limit): array
    {
        if (!Schema::hasTable('wh_v3_outgoing_invoices') || !Schema::hasTable('wh_v3_goods_receipts') || !Schema::hasTable('wh_ledger_entries')) return $this->unavailable('outgoing_invoice_valuation','Outgoing invoice vs stock valuation');
        $rows=DB::table('wh_v3_outgoing_invoices as i')->join('wh_v3_goods_receipts as g','g.id','=','i.goods_receipt_id')->where('i.warehouse_id',$warehouseId)->whereNotIn('i.status',['void','cancelled'])->select('i.*','g.ledger_posting_id')->orderByDesc('i.invoice_date')->limit(1000)->get();$bad=[];
        foreach($rows as $row){
            $ledger=$row->ledger_posting_id?(float)DB::table('wh_ledger_entries')->where('posting_id',$row->ledger_posting_id)->sum('total_cost'):0.0;
            $expected=(float)$row->stock_valuation_total;
            if(!$row->ledger_posting_id||abs($expected-$ledger)>0.01){$bad[]=['document'=>$row->invoice_number,'expected'=>round($ledger,2),'actual'=>round($expected,2),'variance'=>round($expected-$ledger,2),'message'=>'Stock valuation invoice harus sama dengan cost ledger GR.'];if(count($bad)>=$limit)break;}
        }
        return $this->checkResult('outgoing_invoice_valuation','Outgoing invoice valuation = ledger cost','warning',count($bad),$bad);
    }

    private function checkIncomingInvoiceValuation(string $warehouseId, int $limit): array
    {
        if (!Schema::hasTable('wh_supplier_invoices') || !Schema::hasTable('wh_stock_ins') || !Schema::hasTable('wh_ledger_entries')) return $this->unavailable('incoming_invoice_valuation','Incoming invoice vs stock valuation');
        $rows=DB::table('wh_supplier_invoices as i')->join('wh_stock_ins as s','s.id','=','i.stock_in_id')->where('i.warehouse_id',$warehouseId)->where('s.flow_version',3)->whereNotIn('i.status',['void','cancelled'])->select('i.*','s.ledger_posting_id','s.stock_in_number')->orderByDesc('i.invoice_date')->limit(1000)->get();$bad=[];
        foreach($rows as $row){
            $ledger=$row->ledger_posting_id?(float)DB::table('wh_ledger_entries')->where('posting_id',$row->ledger_posting_id)->sum('total_cost'):0.0;
            $value=(float)$row->grand_total;
            if(!$row->ledger_posting_id||abs($value-$ledger)>0.01){$bad[]=['document'=>$row->invoice_number,'expected'=>round($ledger,2),'actual'=>round($value,2),'variance'=>round($value-$ledger,2),'message'=>'Nilai invoice supplier berbeda dari stock valuation; review jika ada biaya non-stock/tax.'];if(count($bad)>=$limit)break;}
        }
        return $this->checkResult('incoming_invoice_valuation','Incoming invoice value vs stock valuation','warning',count($bad),$bad);
    }

    private function recentDocuments(string $warehouseId, int $limit): array
    {
        $rows=[];
        $push=function(string $type,string $id,string $number,string $status,$date,?string $source=null) use (&$rows):void{$rows[]=['type'=>$type,'id'=>$id,'number'=>$number,'status'=>$status,'date'=>(string)$date,'source'=>$source];};
        if(Schema::hasTable('wh_purchase_requests')) foreach(DB::table('wh_purchase_requests')->where('warehouse_id',$warehouseId)->where('flow_version',3)->latest('created_at')->limit(6)->get(['id','pr_number','status','request_date']) as $r)$push('purchase-request',(string)$r->id,(string)$r->pr_number,(string)$r->status,$r->request_date);
        if(Schema::hasTable('wh_supplier_purchase_orders')) foreach(DB::table('wh_supplier_purchase_orders')->where('warehouse_id',$warehouseId)->where('flow_version',3)->latest('created_at')->limit(6)->get(['id','po_number','status','created_at']) as $r)$push('purchase-order',(string)$r->id,(string)$r->po_number,(string)$r->status,$r->created_at);
        if(Schema::hasTable('wh_v3_production_material_requests')) foreach(DB::table('wh_v3_production_material_requests')->where('warehouse_id',$warehouseId)->latest('created_at')->limit(5)->get(['id','request_number','status','created_at']) as $r)$push('production-request',(string)$r->id,(string)$r->request_number,(string)$r->status,$r->created_at);
        if(Schema::hasTable('wh_productions')) foreach(DB::table('wh_productions')->where('warehouse_id',$warehouseId)->where('flow_version',3)->latest('created_at')->limit(6)->get(['id','production_number','status','production_date']) as $r)$push('production-order',(string)$r->id,(string)$r->production_number,(string)$r->status,$r->production_date);
        if(Schema::hasTable('wh_v3_delivery_orders')) foreach(DB::table('wh_v3_delivery_orders')->where('warehouse_id',$warehouseId)->latest('created_at')->limit(6)->get(['id','delivery_number','status','created_at']) as $r)$push('delivery-order',(string)$r->id,(string)$r->delivery_number,(string)$r->status,$r->created_at);
        if(Schema::hasTable('wh_v3_goods_receipts')) foreach(DB::table('wh_v3_goods_receipts')->where('warehouse_id',$warehouseId)->latest('created_at')->limit(6)->get(['id','goods_receipt_number','status','created_at']) as $r)$push('goods-receipt',(string)$r->id,(string)$r->goods_receipt_number,(string)$r->status,$r->created_at);
        if(Schema::hasTable('wh_supplier_invoices')) foreach(DB::table('wh_supplier_invoices')->where('warehouse_id',$warehouseId)->latest('created_at')->limit(5)->get(['id','invoice_number','status','invoice_date']) as $r)$push('incoming-invoice',(string)$r->id,(string)$r->invoice_number,(string)$r->status,$r->invoice_date,'auto_incoming');
        if(Schema::hasTable('wh_v3_outgoing_invoices')) foreach(DB::table('wh_v3_outgoing_invoices')->where('warehouse_id',$warehouseId)->latest('created_at')->limit(5)->get(['id','invoice_number','status','invoice_date']) as $r)$push('outgoing-invoice',(string)$r->id,(string)$r->invoice_number,(string)$r->status,$r->invoice_date,'auto_outgoing');
        if(Schema::hasTable('wh_v3_manual_invoices')) foreach(DB::table('wh_v3_manual_invoices')->where('warehouse_id',$warehouseId)->latest('created_at')->limit(8)->get(['id','invoice_number','direction','status','invoice_date']) as $r)$push($r->direction==='incoming'?'incoming-invoice':'outgoing-invoice',(string)$r->id,(string)$r->invoice_number,(string)$r->status,$r->invoice_date,'manual');
        if(Schema::hasTable('wh_sales_invoices')) foreach(DB::table('wh_sales_invoices')->where('warehouse_id',$warehouseId)->latest('created_at')->limit(5)->get(['id','invoice_number','status','invoice_date']) as $r)$push('outgoing-invoice',(string)$r->id,(string)$r->invoice_number,(string)$r->status,$r->invoice_date,'legacy_outgoing');
        usort($rows,fn($a,$b)=>strcmp((string)$b['date'],(string)$a['date']));return array_slice($rows,0,$limit);
    }

    private function countStatus(string $table,string $warehouseColumn,string $warehouseId,array $statuses,array $equals=[]):int
    {if(!Schema::hasTable($table))return 0;$q=DB::table($table)->where($warehouseColumn,$warehouseId)->whereIn('status',$statuses);foreach($equals as $c=>$v)if(Schema::hasColumn($table,$c))$q->where($c,$v);return $q->count();}
    private function countTransferOpen(string $warehouseId):int
    {if(!Schema::hasTable('wh_v3_transfer_orders'))return 0;return DB::table('wh_v3_transfer_orders')->where(fn($q)=>$q->where('origin_warehouse_id',$warehouseId)->orWhere('destination_warehouse_id',$warehouseId))->whereNotIn('status',['completed','rejected','cancelled'])->count();}
    private function invoiceDraftCount(string $direction,string $warehouseId):int
    {if($direction==='incoming'){return (Schema::hasTable('wh_supplier_invoices')?DB::table('wh_supplier_invoices')->where('warehouse_id',$warehouseId)->where('status','draft')->count():0)+(Schema::hasTable('wh_v3_manual_invoices')?DB::table('wh_v3_manual_invoices')->where('warehouse_id',$warehouseId)->where('direction','incoming')->where('status','draft')->count():0);}return (Schema::hasTable('wh_v3_outgoing_invoices')?DB::table('wh_v3_outgoing_invoices')->where('warehouse_id',$warehouseId)->where('status','draft')->whereIn('destination_type',['outlet','customer'])->count():0)+(Schema::hasTable('wh_v3_manual_invoices')?DB::table('wh_v3_manual_invoices')->where('warehouse_id',$warehouseId)->where('direction','outgoing')->where('status','draft')->count():0);}
    private function invoiceDueCount(string $direction,string $warehouseId):int
    {if(!Schema::hasTable('wh_v3_invoice_approvals'))return 0;return DB::table('wh_v3_invoice_approvals')->where('warehouse_id',$warehouseId)->where('direction',$direction)->where('due_date','<',now()->toDateString())->count();}
    private function negativeBalanceCount(string $warehouseId):int
    {$a=Schema::hasTable('stk_inventory_balances')?DB::table('stk_inventory_balances')->where('outlet_id',$warehouseId)->where('on_hand_qty','<',-0.0001)->count():0;$b=Schema::hasTable('wh_batch_balances')?DB::table('wh_batch_balances')->where('warehouse_id',$warehouseId)->where('on_hand_qty','<',-0.0001)->count():0;return $a+$b;}
    private function legacyMenuActiveCount():int
    {if(!Schema::hasTable('access_menus'))return 0;return DB::table('access_menus')->where('is_active',true)->where(fn($q)=>$q->whereIn('code',['warehouse-stock-request-fulfillment','warehouse-checker-keeper-tasks','warehouse-inventory-barcodes','warehouse-inventory-package-barcodes'])->orWhereIn('path',['/warehouse/stock-requests/fulfillment','/warehouse/tasks/checker-keeper','/warehouse/inventory/barcodes','/warehouse/inventory/package-barcodes']))->count();}
    private function stockSummary(string $warehouseId):array
    {$a=Schema::hasTable('stk_inventory_balances')?DB::table('stk_inventory_balances')->where('outlet_id',$warehouseId)->selectRaw('COALESCE(SUM(on_hand_qty),0) qty,COALESCE(SUM(inventory_value),0) value,COUNT(*) sku')->first():null;$b=Schema::hasTable('wh_batch_balances')?DB::table('wh_batch_balances')->where('warehouse_id',$warehouseId)->selectRaw('COALESCE(SUM(on_hand_qty),0) qty,COALESCE(SUM(inventory_value),0) value,COUNT(DISTINCT sku_id) sku')->first():null;return ['on_hand_qty'=>round((float)($a->qty??0),4),'inventory_value'=>round((float)($a->value??0),2),'sku_count'=>(int)($a->sku??0),'batch_qty'=>round((float)($b->qty??0),4),'batch_value'=>round((float)($b->value??0),2),'negative_balance_count'=>$this->negativeBalanceCount($warehouseId)];}
    private function warehouseSnapshot(string $id):array
    {$r=DB::table('outlets')->where('id',$id)->first(['id','code','name','type']);if(!$r)return ['id'=>$id,'code'=>null,'name'=>'Warehouse'];return ['id'=>(string)$r->id,'code'=>$r->code,'name'=>$r->name,'type'=>$r->type];}
    private function activeWarehouseIds(){return Schema::hasTable('outlets')?DB::table('outlets')->where('is_active',true)->whereRaw('LOWER(type)=?',['warehouse'])->orderBy('name')->pluck('id'):collect();}
    private function warehouseId(Request $request):string
    {foreach(['warehouse_id','warehouse_scope_id'] as $key){$v=$request->attributes->get($key);if(is_string($v)&&$v!=='')return $v;}$scope=(array)$request->attributes->get('warehouse_scope',[]);if(isset($scope['selected']->id))return(string)$scope['selected']->id;foreach(['warehouse_scope_ids','warehouse_ids'] as $key){$v=collect((array)$request->attributes->get($key,[]))->filter()->first();if($v)return(string)$v;}abort(422,'Warehouse aktif tidak ditemukan.');}
    private function dateRange(Request $request):array
    {$from=(string)$request->query('date_from',now()->startOfMonth()->toDateString());$to=(string)$request->query('date_to',now()->toDateString());return[$from,$to];}
    private function checkResult(string $code,string $label,string $severity,int $failures,array $rows):array
    {return ['code'=>$code,'label'=>$label,'severity'=>$severity,'status'=>$failures===0?'passed':'failed','failure_count'=>$failures,'rows'=>$rows];}
    private function simpleCheck(string $code,string $label,bool $ok,string $severity,string $message):array
    {return ['code'=>$code,'label'=>$label,'severity'=>$severity,'status'=>$ok?'passed':'failed','failure_count'=>$ok?0:1,'message'=>$message,'rows'=>[]];}
    private function unavailable(string $code,string $label):array
    {return ['code'=>$code,'label'=>$label,'severity'=>'critical','status'=>'failed','failure_count'=>1,'message'=>'Tabel/kolom dependency belum tersedia.','rows'=>[]];}
}
