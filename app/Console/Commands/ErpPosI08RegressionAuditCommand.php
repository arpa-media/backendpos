<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * I08: bounded, read-only integration health check. Never posts/mutates stock,
 * never auto-heals, and never recalculates financial history.
 */
final class ErpPosI08RegressionAuditCommand extends Command
{
    protected $signature = 'erp-pos:i08-audit {--outlet= : Outlet code or ID; empty means sample across outlets} {--limit=30 : Recent rows per section; 1..100} {--json : Output machine-readable JSON}';
    protected $description = 'I08 read-only regression audit: partial DO/GR, UOM production, transfer-price valuation gap.';

    /** @var list<array<string,mixed>> */
    private array $checks = [];

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $outletInput = trim((string) ($this->option('outlet') ?? ''));
        $outletId = null;
        try {
            if ($outletInput !== '') {
                $outletId = DB::table('outlets')->where('id', $outletInput)
                    ->orWhere('code', $outletInput)->value('id');
                if (!$outletId) {
                    $this->add('scope', 'FAIL', 'Outlet tidak ditemukan', ['outlet'=>$outletInput]);
                    return $this->finish($limit, $outletInput);
                }
            }
            $this->schemaCheck();
            $this->dispatchCheck($limit, $outletId);
            $this->receiptCheck($limit, $outletId);
            $this->productionCheck($limit, $outletId);
            $this->valuationGapCheck($limit, $outletId);
        } catch (Throwable $e) {
            $this->add('exception', 'FAIL', 'Audit read-only gagal: '.$e->getMessage());
        }
        return $this->finish($limit, $outletInput);
    }

    private function add(string $name, string $status, string $message, array $details = []): void
    {
        $this->checks[] = compact('name', 'status', 'message', 'details');
    }

    private function tables(array $names): bool
    {
        foreach ($names as $name) {
            if (!Schema::hasTable($name)) return false;
        }
        return true;
    }

    private function schemaCheck(): void
    {
        $required = [
            'wh_v3_delivery_orders', 'wh_v3_delivery_order_items', 'wh_v3_logistics_prepare_items',
            'wh_v3_goods_receipts', 'wh_v3_goods_receipt_items', 'wh_v3_partial_receipt_batches',
            'wh_v3_partial_receipt_items', 'stk_inventory_movements', 'stk_inventory_balances',
            'wh_v3_production_results', 'wh_v3_production_result_items', 'wh_price_policies_v3',
            'wh_chain_supplies',
        ];
        $missing = array_values(array_filter($required, fn ($name) => !Schema::hasTable($name)));
        $requiredColumns = [
            ['wh_v3_delivery_orders', 'dispatch_request_key'],
            ['wh_v3_partial_receipt_batches', 'payload_hash'],
            ['wh_v3_production_result_items', 'conversion_factor_snapshot'],
            ['wh_price_policies_v3', 'price_uom_id'],
            ['wh_price_policies_v3', 'price_conversion_factor_snapshot'],
        ];
        foreach ($requiredColumns as [$table, $column]) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, $column)) $missing[] = "$table.$column";
        }
        $this->add('i01_i07_schema', $missing ? 'FAIL' : 'PASS',
            $missing ? 'Komponen I04-I07 belum lengkap. Jangan jalankan modul transaksi baru.' : 'Tabel dan kolom kunci I04-I07 tersedia.',
            ['missing'=>$missing]);
    }

    private function dispatchCheck(int $limit, ?string $outletId): void
    {
        if (!$this->tables(['wh_v3_logistics_prepare_items', 'wh_v3_logistics_prepare_requests', 'wh_v3_delivery_order_items', 'wh_v3_delivery_orders'])) {
            $this->add('i04_partial_do', 'SKIP', 'Tabel I04 tidak lengkap.'); return;
        }
        $q = DB::table('wh_v3_logistics_prepare_items as pi')
            ->join('wh_v3_logistics_prepare_requests as p', 'p.id', '=', 'pi.prepare_request_id')
            ->where('p.source_type', 'stock_request');
        if ($outletId) $q->where('p.destination_id', $outletId);
        $samples = $q->orderByDesc('pi.created_at')->limit($limit)
            ->get(['pi.id', 'pi.approved_qty_base']);
        $issues = [];
        foreach ($samples as $item) {
            $sent = (float) DB::table('wh_v3_delivery_order_items as di')
                ->join('wh_v3_delivery_orders as d', 'd.id', '=', 'di.delivery_order_id')
                ->where('di.prepare_item_id', $item->id)->sum('di.sent_qty_base');
            if ($sent > (float)$item->approved_qty_base + 0.0001 || $sent < -0.0001) {
                $issues[] = ['prepare_item_id'=>(string)$item->id, 'approved'=>(float)$item->approved_qty_base, 'sent'=>round($sent,4)];
            }
        }
        $this->add('i04_partial_do', $samples->isEmpty() ? 'SKIP' : ($issues ? 'FAIL' : 'PASS'),
            $issues ? 'Kuantitas terkirim melebihi approved pada sampel.' : 'Tidak ada over-dispatch pada sampel.',
            ['sampled'=>$samples->count(),'examples'=>array_slice($issues,0,10)]);
    }

    private function receiptCheck(int $limit, ?string $outletId): void
    {
        $required = ['wh_v3_goods_receipt_items', 'wh_v3_goods_receipts', 'wh_v3_partial_receipt_items',
            'wh_v3_partial_receipt_batches', 'stk_inventory_movements'];
        if (!$this->tables($required)) { $this->add('i05_partial_gr', 'SKIP', 'Tabel I05 tidak lengkap.'); return; }
        $q = DB::table('wh_v3_goods_receipt_items as gi')
            ->join('wh_v3_goods_receipts as gr','gr.id','=','gi.goods_receipt_id');
        if ($outletId) $q->where('gr.destination_type','outlet')->where('gr.destination_id',$outletId);
        $items = $q->orderByDesc('gi.created_at')->limit($limit)
            ->get(['gi.id','gi.goods_receipt_id','gi.sent_qty_base','gi.received_qty_base',
                'gi.not_received_qty_base','gr.status as gr_status']);
        $issues = []; $batchCount = 0;
        foreach ($items as $item) {
            $sent=(float)$item->sent_qty_base; $received=(float)$item->received_qty_base; $not=(float)$item->not_received_qty_base;
            if ($received < -0.0001 || $not < -0.0001 || $received+$not > $sent+0.0001 ||
                (in_array((string)$item->gr_status,['submitted','completed'],true) && abs($received+$not-$sent)>0.0001)) {
                $issues[]=['receipt_item_id'=>(string)$item->id,'issue'=>'invalid_received_not_received_balance'];
            }
            $batches = DB::table('wh_v3_partial_receipt_items as bi')
                ->join('wh_v3_partial_receipt_batches as b','b.id','=','bi.batch_id')
                ->where('bi.goods_receipt_item_id',$item->id)
                ->where('b.goods_receipt_id',$item->goods_receipt_id)
                ->get(['bi.id','bi.movement_reference_line_id','bi.received_qty_base']);
            if ($batches->isEmpty()) continue;
            $batchCount += $batches->count();
            if (abs((float)$batches->sum('received_qty_base') - $received)>0.0001) {
                $issues[]=['receipt_item_id'=>(string)$item->id,'issue'=>'batch_sum_differs_from_gr'];
            }
            foreach ($batches as $batch) {
                $movements = DB::table('stk_inventory_movements')->where('movement_type','goods_receipt')
                    ->where('reference_type','wh_v3_goods_receipt')
                    ->where('reference_line_id',$batch->movement_reference_line_id)
                    ->get(['quantity']);
                if ($movements->count() !== 1 || abs((float)($movements->first()?->quantity ?? 0) - (float)$batch->received_qty_base)>0.0001) {
                    $issues[]=['receipt_item_id'=>(string)$item->id,'batch_id'=>(string)$batch->id,
                        'issue'=>'movement_missing_duplicate_or_qty_mismatch'];
                }
            }
        }
        $this->add('i05_partial_gr', $items->isEmpty() ? 'SKIP' : ($issues ? 'FAIL' : 'PASS'),
            $issues ? 'Mismatch batch/GR/movement ditemukan; tahan posting lanjutan dan lakukan rekonsiliasi.'
                : 'Saldo received dan movement batch sesuai pada sampel.',
            ['sampled_gr_items'=>$items->count(),'sampled_batches'=>$batchCount,'examples'=>array_slice($issues,0,12)]);
    }

    private function productionCheck(int $limit, ?string $outletId): void
    {
        if (!$this->tables(['wh_v3_production_results','wh_v3_production_result_items'])) {
            $this->add('i06_output_uom', 'SKIP', 'Tabel produksi I06 tidak lengkap.'); return;
        }
        $q = DB::table('wh_v3_production_result_items as ri')
            ->join('wh_v3_production_results as r','r.id','=','ri.result_id');
        if ($outletId) $q->where('r.warehouse_id',$outletId);
        $rows = $q->orderByDesc('ri.created_at')->limit($limit)
            ->get(['ri.id','ri.qty_uom','ri.qty_base','ri.conversion_factor_snapshot']);
        $issues=[];
        foreach ($rows as $r) {
            $expected = round((float)$r->qty_uom*(float)$r->conversion_factor_snapshot,4);
            if ((float)$r->conversion_factor_snapshot<=0 || abs($expected-(float)$r->qty_base)>0.0001) {
                $issues[]=['result_item_id'=>(string)$r->id,'expected_base'=>$expected,'stored_base'=>(float)$r->qty_base];
            }
        }
        $this->add('i06_output_uom',$rows->isEmpty()?'SKIP':($issues?'FAIL':'PASS'),
            $issues?'Hasil produksi memiliki mismatch Qty Base / faktor konversi.':'Konversi hasil produksi sesuai pada sampel.',
            ['sampled'=>$rows->count(),'examples'=>array_slice($issues,0,10)]);
    }

    private function valuationGapCheck(int $limit, ?string $outletId): void
    {
        if (!$this->tables(['wh_price_policies_v3','stk_inventory_balances'])) {
            $this->add('i07_price_vs_actual', 'SKIP', 'Tabel harga atau saldo tidak tersedia.'); return;
        }
        $required = ['price_conversion_factor_snapshot','price_uom_review_required','price_uom_id'];
        foreach ($required as $column) if (!Schema::hasColumn('wh_price_policies_v3',$column)) {
            $this->add('i07_price_vs_actual','SKIP',"Kolom harga $column belum tersedia."); return;
        }
        $q=DB::table('wh_price_policies_v3 as p')
            ->join('stk_inventory_balances as b',function($join) {
                $join->on('b.sku_id','=','p.sku_id')->on('b.outlet_id','=','p.target_id');
            })
            ->where('p.target_type','outlet')->where('p.is_active',true)
            ->where('p.price_uom_review_required',false)
            ->where('p.price','>',0)->where('p.price_conversion_factor_snapshot','>',0);
        if ($outletId) $q->where('p.target_id',$outletId);
        $rows=$q->orderByDesc('p.updated_at')->limit($limit)
            ->get(['p.id','p.sku_id','p.target_id','p.price','p.price_conversion_factor_snapshot','b.average_unit_cost']);
        $gaps=[];
        foreach ($rows as $r) {
            $transfer=(float)$r->price/(float)$r->price_conversion_factor_snapshot;
            $balance=(float)$r->average_unit_cost;
            if (abs($transfer-$balance) > max(0.01,abs($transfer)*0.01)) {
                $gaps[]=['outlet_id'=>(string)$r->target_id,'sku_id'=>(string)$r->sku_id,
                    'transfer_price_per_base'=>round($transfer,4),'outlet_actual_avg_per_base'=>round($balance,4)];
            }
        }
        $this->add('i07_price_vs_actual',$rows->isEmpty()?'SKIP':($gaps?'WARN':'PASS'),
            $gaps?'Harga transfer master dan average actual berbeda. I07 hanya preview: nilai actual tidak dikoreksi.':'Tidak ada perbedaan harga signifikan pada sampel.',
            ['sampled'=>$rows->count(),'gaps'=>count($gaps),'examples'=>array_slice($gaps,0,10),
             'interpretation'=>'DIAGNOSTIC ONLY: perbedaan tidak otomatis salah karena stok historis/lot/pembelian langsung.']);
    }

    private function finish(int $limit, string $outlet): int
    {
        $failures = count(array_filter($this->checks,fn($r)=>$r['status']==='FAIL'));
        $warnings = count(array_filter($this->checks,fn($r)=>$r['status']==='WARN'));
        $summary=['version'=>'I08','read_only'=>true,'outlet'=>$outlet ?: 'all',
            'limit_per_section'=>$limit,'failed_checks'=>$failures,'warning_checks'=>$warnings,
            'checks'=>$this->checks];
        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            $this->info('ERP POS I08 | READ-ONLY regression audit | No data changes');
            $this->line('Scope: '.($outlet ?: 'ALL sampled').' | Limit per section: '.$limit);
            foreach ($this->checks as $check) {
                $this->line(sprintf('[%s] %s — %s', $check['status'], $check['name'], $check['message']));
                if ($check['status'] !== 'PASS') $this->line(json_encode($check['details'], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
            }
            $this->line("Result: {$failures} FAIL, {$warnings} WARN. PASS hanya untuk sampel, bukan bukti seluruh database aman.");
        }
        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
