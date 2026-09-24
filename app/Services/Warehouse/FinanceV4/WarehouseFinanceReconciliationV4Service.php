<?php

namespace App\Services\Warehouse\FinanceV4;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseFinanceReconciliationV4Service
{
    private const CONTROLS = [
        'INVENTORY' => '1210',
        'AR' => '1100',
        'AP' => '2100',
        'PO_CLEARING' => '1240',
        'WIP' => '1230',
        'TRANSIT' => '1220',
    ];

    private const LEDGER_TEMPLATE = [
        'purchase_in' => 'PURCHASE_STOCK_RECEIPT',
        'production_out' => 'PRODUCTION_MATERIAL_OUT',
        'production_in' => 'PRODUCTION_FINISHED_IN',
        'transfer_out' => 'TRANSFER_DISPATCH',
        'transfer_in' => 'TRANSFER_RECEIVE',
        'adjustment_in' => 'STOCK_ADJUSTMENT_IN',
        'adjustment_out' => 'STOCK_ADJUSTMENT_OUT',
    ];

    private const LEDGER_SALE_MOVEMENTS = ['request_out', 'customer_sale_out'];
    private const ISSUE_PERSIST_CAP = 5000;
    private const ISSUE_RESPONSE_CAP = 250;
    private const TOLERANCE = 0.01;

    public function overview(array $warehouseIds, string $scopeMode, string $from, string $to): array
    {
        $warehouseIds = $this->cleanIds($warehouseIds);
        return [
            'scope_mode' => $scopeMode,
            'warehouses' => $this->warehouses($warehouseIds),
            'date_from' => $from,
            'date_to' => $to,
            'baselines' => $this->baselineRows($warehouseIds),
            'baseline_complete' => $this->baselineComplete($warehouseIds),
            'finance_snapshot' => $this->financeSnapshot($warehouseIds, $from, $to),
            'latest_runs' => $this->latestRuns($warehouseIds, $scopeMode),
            'latest_operational_runs' => $this->latestOperationalRows($warehouseIds),
            'generated_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    public function captureBaseline(array $warehouseIds, string $userId, bool $force = false): array
    {
        $warehouseIds = $this->cleanIds($warehouseIds);
        if ($warehouseIds === []) throw ValidationException::withMessages(['warehouse'=>['Pilih Warehouse terlebih dahulu.']]);

        return DB::transaction(function () use ($warehouseIds, $userId, $force): array {
            foreach ($warehouseIds as $warehouseId) {
                if (! $force && DB::table('wh_v4_finance_recon_baselines')->where('warehouse_id',$warehouseId)->exists()) {
                    throw ValidationException::withMessages(['baseline'=>['Baseline Warehouse sudah terkunci. Gunakan CLI --force-baseline hanya untuk maintenance/test cut-over.']]);
                }

                $operational = $this->latestPassingOperationalRun($warehouseId, CarbonImmutable::now()->subHours(24));
                if (! $operational) {
                    throw ValidationException::withMessages(['baseline'=>['Operational Reconciliation zero-issue dalam 24 jam terakhir wajib dijalankan sebelum capture baseline.']]);
                }

                $values = $this->controlValues($warehouseId);
                $now = now();
                foreach (self::CONTROLS as $key => $code) {
                    $operationalValue = round((float) ($values[$key] ?? 0), 2);
                    $financeValue = round($this->financeAccountBalance([$warehouseId], $code), 2);
                    $payload = [
                        'operational_value'=>$operationalValue,
                        'finance_value'=>$financeValue,
                        'offset_value'=>round($operationalValue-$financeValue,2),
                        'operational_run_id'=>(string) $operational->id,
                        'captured_at'=>$now,
                        'captured_by_user_id'=>$userId ?: null,
                        'metadata'=>json_encode(['iteration'=>7,'locked'=>true,'force'=>$force]),
                        'updated_at'=>$now,
                    ];
                    if ($force) {
                        DB::table('wh_v4_finance_recon_baselines')->updateOrInsert(
                            ['warehouse_id'=>$warehouseId,'control_key'=>$key],
                            array_merge($payload,['id'=>(string) (DB::table('wh_v4_finance_recon_baselines')->where('warehouse_id',$warehouseId)->where('control_key',$key)->value('id') ?: Str::ulid()),'account_code'=>$code,'created_at'=>$now])
                        );
                    } else {
                        DB::table('wh_v4_finance_recon_baselines')->insert(array_merge($payload,[
                            'id'=>(string) Str::ulid(),'warehouse_id'=>$warehouseId,'control_key'=>$key,'account_code'=>$code,'created_at'=>$now,
                        ]));
                    }
                }
            }
            return ['baselines'=>$this->baselineRows($warehouseIds),'baseline_complete'=>$this->baselineComplete($warehouseIds)];
        });
    }

    public function run(array $warehouseIds, string $scopeMode, string $from, string $to, ?string $userId = null): array
    {
        $warehouseIds = $this->cleanIds($warehouseIds);
        if ($warehouseIds === []) throw ValidationException::withMessages(['warehouse'=>['Scope Warehouse kosong.']]);
        $runId = (string) Str::ulid();
        $started = now();
        DB::table('wh_v4_finance_recon_runs')->insert([
            'id'=>$runId,'scope_mode'=>$scopeMode,'warehouse_id'=>$scopeMode==='warehouse' && count($warehouseIds)===1?$warehouseIds[0]:null,
            'date_from'=>$from,'date_to'=>$to,'status'=>'RUNNING','checked_rule_count'=>0,'critical_count'=>0,'warning_count'=>0,'issue_count'=>0,
            'executed_by_user_id'=>$userId,'started_at'=>$started,'created_at'=>$started,'updated_at'=>$started,
        ]);

        $issues = [];
        $checked = 0;
        $add = function (string $severity, string $rule, ?string $warehouseId, string $message, array $extra = []) use (&$issues): void {
            $issues[] = array_merge([
                'severity'=>$severity,'rule_key'=>$rule,'warehouse_id'=>$warehouseId,'message'=>$message,
                'reference_type'=>null,'reference_id'=>null,'operational_value'=>null,'finance_value'=>null,'variance'=>null,'metadata'=>[],
            ], $extra);
        };

        try {
            $checked++; $this->checkBaseline($warehouseIds, $add);
            $checked++; $this->checkGeneralPostingBalance($warehouseIds, $from, $to, $add);
            $checked++; $this->checkGeneralPostingDuplicates($warehouseIds, $from, $to, $add);
            $checked++; $this->checkDraftQueue($warehouseIds, $add);
            $checked++; $this->checkTreasuryPending($warehouseIds, $add);
            $checked++; $this->checkPurchaseCoverage($warehouseIds, $add);
            $checked++; $this->checkLedgerCoverage($warehouseIds, $add);
            $checked++; $this->checkProductionCoverage($warehouseIds, $add);
            $checked++; $this->checkSalesCoverage($warehouseIds, $add);
            $checked++; $this->checkPaymentCoverage($warehouseIds, $add);
            $checked++; $this->checkTreasuryLinks($warehouseIds, $add);
            $checked++; $this->checkOperationalReconciliation($warehouseIds, $add);
            $checked++; $controls = $this->checkControlAccounts($warehouseIds, $add);

            $critical = count(array_filter($issues, fn ($i) => $i['severity']==='CRITICAL'));
            $warning = count(array_filter($issues, fn ($i) => $i['severity']==='WARNING'));
            $status = $critical > 0 ? 'BLOCKED' : ($warning > 0 ? 'READY_WITH_WARNINGS' : 'READY');
            $snapshot = $this->financeSnapshot($warehouseIds, $from, $to);
            $summary = [
                'status'=>$status,'go_live_ready'=>$critical===0,'critical_count'=>$critical,'warning_count'=>$warning,'issue_count'=>count($issues),
                'checked_rule_count'=>$checked,'controls'=>$controls,'finance_snapshot'=>$snapshot,'baseline_complete'=>$this->baselineComplete($warehouseIds),
            ];

            foreach (array_slice($issues,0,self::ISSUE_PERSIST_CAP) as $issue) {
                DB::table('wh_v4_finance_recon_issues')->insert([
                    'id'=>(string) Str::ulid(),'run_id'=>$runId,'warehouse_id'=>$issue['warehouse_id'],'severity'=>$issue['severity'],'rule_key'=>$issue['rule_key'],
                    'reference_type'=>$issue['reference_type'],'reference_id'=>$issue['reference_id'],'message'=>$issue['message'],
                    'operational_value'=>$issue['operational_value'],'finance_value'=>$issue['finance_value'],'variance'=>$issue['variance'],
                    'metadata'=>json_encode($issue['metadata'] ?: []),'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            DB::table('wh_v4_finance_recon_runs')->where('id',$runId)->update([
                'status'=>$status,'checked_rule_count'=>$checked,'critical_count'=>$critical,'warning_count'=>$warning,'issue_count'=>count($issues),
                'summary'=>json_encode($summary),'completed_at'=>now(),'updated_at'=>now(),
            ]);

            return array_merge($summary,[
                'run_id'=>$runId,'date_from'=>$from,'date_to'=>$to,'scope_mode'=>$scopeMode,
                'issues'=>array_slice($issues,0,self::ISSUE_RESPONSE_CAP),'issues_truncated'=>count($issues)>self::ISSUE_RESPONSE_CAP,
                'warehouses'=>$this->warehouses($warehouseIds),'generated_at'=>now('Asia/Jakarta')->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            DB::table('wh_v4_finance_recon_runs')->where('id',$runId)->update(['status'=>'ERROR','completed_at'=>now(),'summary'=>json_encode(['error'=>$e->getMessage()]),'updated_at'=>now()]);
            throw $e;
        }
    }

    public function latestRunDetail(string $runId, array $allowedWarehouseIds): array
    {
        $run = DB::table('wh_v4_finance_recon_runs')->where('id',$runId)->first();
        if (! $run) abort(404,'Finance Reconciliation run tidak ditemukan.');
        if ($run->warehouse_id && ! in_array((string)$run->warehouse_id,$this->cleanIds($allowedWarehouseIds),true)) abort(403,'Warehouse tidak diizinkan.');
        $issues = DB::table('wh_v4_finance_recon_issues')->where('run_id',$runId)->orderByRaw("FIELD(severity,'CRITICAL','WARNING')")->orderBy('rule_key')->limit(self::ISSUE_RESPONSE_CAP)->get();
        return ['run'=>$this->runRow($run),'issues'=>$issues->map(fn($x)=>$this->issueRow($x))->all()];
    }

    private function checkBaseline(array $warehouseIds, callable $add): void
    {
        foreach ($warehouseIds as $wh) {
            $keys = DB::table('wh_v4_finance_recon_baselines')->where('warehouse_id',$wh)->pluck('control_key')->all();
            foreach (array_keys(self::CONTROLS) as $key) if (! in_array($key,$keys,true)) {
                $add('CRITICAL','baseline.cutover',$wh,"Baseline {$key} belum tersedia.",['reference_type'=>'BASELINE','reference_id'=>$key]);
            }
        }
    }

    private function checkGeneralPostingBalance(array $warehouseIds, string $from, string $to, callable $add): void
    {
        $rows = DB::table('wh_v4_finance_general_postings as p')
            ->leftJoin('wh_v4_finance_general_posting_lines as l','l.general_posting_id','=','p.id')
            ->whereIn('p.warehouse_id',$warehouseIds)->whereBetween('p.business_date',[$from,$to])
            ->groupBy('p.id','p.warehouse_id','p.posting_no','p.total_debit','p.total_credit')
            ->get(['p.id','p.warehouse_id','p.posting_no','p.total_debit','p.total_credit',DB::raw('COALESCE(SUM(l.debit),0) line_debit'),DB::raw('COALESCE(SUM(l.credit),0) line_credit')]);
        foreach ($rows as $r) {
            $bad = abs((float)$r->total_debit-(float)$r->total_credit)>self::TOLERANCE || abs((float)$r->total_debit-(float)$r->line_debit)>self::TOLERANCE || abs((float)$r->total_credit-(float)$r->line_credit)>self::TOLERANCE;
            if ($bad) $add('CRITICAL','general_posting.balance',(string)$r->warehouse_id,"General Posting {$r->posting_no} tidak balance/consistent dengan detail line.",['reference_type'=>'GENERAL_POSTING','reference_id'=>(string)$r->id,'finance_value'=>(float)$r->total_debit,'variance'=>round((float)$r->line_debit-(float)$r->line_credit,2)]);
        }
    }

    private function checkGeneralPostingDuplicates(array $warehouseIds, string $from, string $to, callable $add): void
    {
        $groups = DB::table('wh_v4_finance_general_postings')
            ->whereIn('warehouse_id',$warehouseIds)->whereBetween('business_date',[$from,$to])->whereIn('status',['POSTED','REVERSED'])
            ->whereNotIn('source_type',['MANUAL','REVERSAL'])->whereNull('reversal_of_posting_id')
            ->groupBy('warehouse_id','source_type','source_id','template_id','total_debit','total_credit')
            ->havingRaw('COUNT(*) > 1')->get(['warehouse_id','source_type','source_id','template_id','total_debit','total_credit',DB::raw('COUNT(*) duplicate_count')]);
        foreach ($groups as $g) $add('CRITICAL','general_posting.duplicate',(string)$g->warehouse_id,"Duplicate economic General Posting terdeteksi ({$g->duplicate_count} posting).",['reference_type'=>(string)$g->source_type,'reference_id'=>(string)$g->source_id,'metadata'=>['template_id'=>$g->template_id,'duplicate_count'=>(int)$g->duplicate_count]]);
    }

    private function checkDraftQueue(array $warehouseIds, callable $add): void
    {
        $q = DB::table('wh_v4_finance_general_postings')->whereIn('warehouse_id',$warehouseIds)->where('status','DRAFT');
        foreach ($q->get(['id','warehouse_id','posting_no','source_type','source_key']) as $r) {
            $manual = strtoupper((string)$r->source_type)==='MANUAL' || ! $r->source_key;
            $add($manual?'WARNING':'CRITICAL','general_posting.draft_queue',(string)$r->warehouse_id,"General Posting {$r->posting_no} masih DRAFT.",['reference_type'=>'GENERAL_POSTING','reference_id'=>(string)$r->id]);
        }
    }

    private function checkTreasuryPending(array $warehouseIds, callable $add): void
    {
        foreach (DB::table('wh_v4_treasury_transactions')->whereIn('warehouse_id',$warehouseIds)->where('auto_generated',false)->whereIn('status',['DRAFT','SUBMITTED'])->get(['id','warehouse_id','treasury_number','status']) as $r) {
            $add('WARNING','treasury.pending_approval',(string)$r->warehouse_id,"Treasury {$r->treasury_number} masih {$r->status}.",['reference_type'=>'TREASURY','reference_id'=>(string)$r->id]);
        }
    }

    private function checkPurchaseCoverage(array $warehouseIds, callable $add): void
    {
        if (! Schema::hasTable('wh_supplier_purchase_orders')) return;
        foreach ($warehouseIds as $wh) {
            $cut = $this->cutoff($wh); if (! $cut) continue;
            $pos = DB::table('wh_supplier_purchase_orders')->where('warehouse_id',$wh)->where('created_at','>=',$cut)->get(['id','po_number','estimated_total','actual_total']);
            $keys = $this->sourceKeySet($pos->flatMap(fn($p)=>['WHV4:PO:APPROVED:'.$p->id,'WHV4:PO:REVALUE:'.$p->id])->all());
            foreach ($pos as $p) {
                $base='WHV4:PO:APPROVED:'.$p->id;
                if (! isset($keys[$base])) $add('CRITICAL','purchase.finance_coverage',$wh,"PO {$p->po_number} belum memiliki jurnal hutang approved.",['reference_type'=>'PURCHASE_ORDER','reference_id'=>(string)$p->id]);
                if (abs((float)$p->actual_total-(float)$p->estimated_total)>self::TOLERANCE && (float)$p->actual_total>0) {
                    $rk='WHV4:PO:REVALUE:'.$p->id;
                    if (! isset($keys[$rk])) $add('CRITICAL','purchase.finance_coverage',$wh,"PO {$p->po_number} berubah dari estimate ke actual tetapi jurnal revaluation belum ada.",['reference_type'=>'PURCHASE_ORDER','reference_id'=>(string)$p->id]);
                }
            }
        }
    }

    private function checkLedgerCoverage(array $warehouseIds, callable $add): void
    {
        foreach ($warehouseIds as $wh) {
            $cut=$this->cutoff($wh); if (! $cut) continue;
            $rows=DB::table('wh_ledger_postings')->where('warehouse_id',$wh)->where('status','posted')->where('created_at','>=',$cut)->get(['id','movement_type','reference_type','reference_id']);
            $required=[];
            foreach ($rows as $r) if (isset(self::LEDGER_TEMPLATE[(string)$r->movement_type])) $required['WHV4:LEDGER:'.$r->id.':'.self::LEDGER_TEMPLATE[(string)$r->movement_type]]=$r;
            $existing=$this->sourceKeySet(array_keys($required));
            foreach ($required as $key=>$r) if (! isset($existing[$key])) $add('CRITICAL','stock_ledger.finance_coverage',$wh,"Stock movement {$r->movement_type} belum masuk General Posting v4.",['reference_type'=>'WAREHOUSE_LEDGER','reference_id'=>(string)$r->id,'metadata'=>['source_key'=>$key]]);

            $known=array_merge(array_keys(self::LEDGER_TEMPLATE),self::LEDGER_SALE_MOVEMENTS,['reversal']);
            foreach ($rows as $r) if (! in_array((string)$r->movement_type,$known,true)) {
                $add('CRITICAL','stock_ledger.finance_coverage',$wh,"Movement type baru '{$r->movement_type}' belum memiliki mapping Finance v4.",['reference_type'=>'WAREHOUSE_LEDGER','reference_id'=>(string)$r->id]);
            }
        }
    }

    private function checkProductionCoverage(array $warehouseIds, callable $add): void
    {
        if (! Schema::hasTable('wh_productions')) return;
        foreach ($warehouseIds as $wh) {
            $cut=$this->cutoff($wh); if (! $cut) continue;
            $rows=DB::table('wh_productions')->where('warehouse_id',$wh)->where('created_at','>=',$cut)->get(['id','production_number','labor_cost','overhead_cost','actual_input_value','actual_output_value','status','finished_at']);
            $need=[];
            foreach($rows as $p){
                if(((float)$p->labor_cost+(float)$p->overhead_cost)>self::TOLERANCE)$need[]='WHV4:PRODUCTION:COST:'.$p->id;
                $finished=$p->finished_at || in_array(strtolower((string)$p->status),['finished','completed'],true);
                $variance=(float)$p->actual_input_value+(float)$p->labor_cost+(float)$p->overhead_cost-(float)$p->actual_output_value;
                if($finished && abs($variance)>self::TOLERANCE)$need[]='WHV4:PRODUCTION:VARIANCE:'.$p->id;
            }
            $keys=$this->sourceKeySet($need);
            foreach($rows as $p){
                $cost='WHV4:PRODUCTION:COST:'.$p->id;
                if(((float)$p->labor_cost+(float)$p->overhead_cost)>self::TOLERANCE && !isset($keys[$cost]))$add('CRITICAL','production.finance_coverage',$wh,"Production {$p->production_number} belum memiliki jurnal labor/overhead.",['reference_type'=>'PRODUCTION','reference_id'=>(string)$p->id]);
                $finished=$p->finished_at || in_array(strtolower((string)$p->status),['finished','completed'],true);
                $variance=(float)$p->actual_input_value+(float)$p->labor_cost+(float)$p->overhead_cost-(float)$p->actual_output_value;
                $vk='WHV4:PRODUCTION:VARIANCE:'.$p->id;
                if($finished && abs($variance)>self::TOLERANCE && !isset($keys[$vk]))$add('CRITICAL','production.finance_coverage',$wh,"Production {$p->production_number} mempunyai residual variance tetapi jurnal variance belum ada.",['reference_type'=>'PRODUCTION','reference_id'=>(string)$p->id,'variance'=>round($variance,2)]);
            }
        }
    }

    private function checkSalesCoverage(array $warehouseIds, callable $add): void
    {
        if (! Schema::hasTable('wh_v3_outgoing_invoices')) return;
        foreach($warehouseIds as $wh){$cut=$this->cutoff($wh);if(!$cut)continue;
            $rows=DB::table('wh_v3_outgoing_invoices')->where('warehouse_id',$wh)->where('created_at','>=',$cut)->whereNotIn('status',['draft','cancelled','rejected'])->get(['id','invoice_number']);
            $keys=$this->sourceKeySet($rows->map(fn($x)=>'WHV4:SALE:INVOICE:'.$x->id)->all());
            foreach($rows as $r){$k='WHV4:SALE:INVOICE:'.$r->id;if(!isset($keys[$k]))$add('CRITICAL','sales.finance_coverage',$wh,"Outgoing Invoice {$r->invoice_number} belum memiliki Revenue + HPP General Posting.",['reference_type'=>'OUTGOING_INVOICE','reference_id'=>(string)$r->id]);}
        }
    }

    private function checkPaymentCoverage(array $warehouseIds, callable $add): void
    {
        if(!Schema::hasTable('wh_v3_invoice_payments'))return;
        foreach($warehouseIds as $wh){$cut=$this->cutoff($wh);if(!$cut)continue;
            $rows=DB::table('wh_v3_invoice_payments')->where('warehouse_id',$wh)->where('status','posted')->where('created_at','>=',$cut)->get(['id','payment_number']);
            $keys=$this->sourceKeySet($rows->map(fn($x)=>'WHV4:PAYMENT:'.$x->id)->all());
            $counts=DB::table('wh_v4_treasury_transactions')->where('warehouse_id',$wh)->where('source_type','INVOICE_PAYMENT')->whereIn('source_id',$rows->pluck('id')->all())->groupBy('source_id')->pluck(DB::raw('COUNT(*)'),'source_id');
            foreach($rows as $r){$k='WHV4:PAYMENT:'.$r->id;if(!isset($keys[$k]))$add('CRITICAL','payment.finance_coverage',$wh,"Payment {$r->payment_number} belum memiliki General Posting v4.",['reference_type'=>'INVOICE_PAYMENT','reference_id'=>(string)$r->id]);$c=(int)($counts[(string)$r->id]??0);if($c!==1)$add('CRITICAL','payment.finance_coverage',$wh,"Payment {$r->payment_number} harus memiliki tepat satu dokumen Treasury; ditemukan {$c}.",['reference_type'=>'INVOICE_PAYMENT','reference_id'=>(string)$r->id,'metadata'=>['treasury_count'=>$c]]);}
        }
    }

    private function checkTreasuryLinks(array $warehouseIds, callable $add): void
    {
        foreach(DB::table('wh_v4_treasury_transactions as t')->leftJoin('wh_v4_finance_general_postings as p','p.id','=','t.general_posting_id')->whereIn('t.warehouse_id',$warehouseIds)->where('t.status','APPROVED')->get(['t.id','t.warehouse_id','t.treasury_number','t.general_posting_id','p.status as posting_status']) as $r){
            if(!$r->general_posting_id || !in_array((string)$r->posting_status,['POSTED','REVERSED'],true))$add('CRITICAL','treasury.general_posting_link',(string)$r->warehouse_id,"Treasury {$r->treasury_number} APPROVED tetapi General Posting valid tidak ditemukan.",['reference_type'=>'TREASURY','reference_id'=>(string)$r->id]);
        }
    }

    private function checkOperationalReconciliation(array $warehouseIds, callable $add): void
    {
        foreach($warehouseIds as $wh){$cut=$this->cutoff($wh);if(!$cut)continue;$row=$this->latestOperationalRun($wh);if(!$row || (string)$row->status!=='passed' || (int)$row->issue_count!==0 || !$row->completed_at || CarbonImmutable::parse($row->completed_at)->lt(CarbonImmutable::parse($cut))){$add('CRITICAL','operational_reconciliation.latest',$wh,'Operational Reconciliation terbaru harus passed, zero issue, dan dijalankan setelah cut-over baseline.',['reference_type'=>'OPERATIONAL_RECON','reference_id'=>$row?(string)$row->id:null]);}}
    }

    private function checkControlAccounts(array $warehouseIds, callable $add): array
    {
        $rows=[];
        foreach($warehouseIds as $wh){$values=$this->controlValues($wh);foreach(self::CONTROLS as $key=>$code){$base=DB::table('wh_v4_finance_recon_baselines')->where('warehouse_id',$wh)->where('control_key',$key)->first();$op=round((float)($values[$key]??0),2);$fin=round($this->financeAccountBalance([$wh],$code),2);$offset=round((float)($base->offset_value??0),2);$expected=round($fin+$offset,2);$variance=round($op-$expected,2);$rows[]=['warehouse_id'=>$wh,'control_key'=>$key,'account_code'=>$code,'operational_value'=>$op,'finance_value'=>$fin,'baseline_offset'=>$offset,'finance_plus_baseline'=>$expected,'variance'=>$variance,'status'=>abs($variance)<=self::TOLERANCE?'OK':'VARIANCE'];if($base && abs($variance)>self::TOLERANCE)$add('CRITICAL','control_accounts.current_balance',$wh,"Control {$key} tidak reconcile dengan Finance + baseline.",['reference_type'=>'CONTROL_ACCOUNT','reference_id'=>$key,'operational_value'=>$op,'finance_value'=>$expected,'variance'=>$variance,'metadata'=>['account_code'=>$code,'raw_finance'=>$fin,'baseline_offset'=>$offset]]);}}
        return $rows;
    }

    private function controlValues(string $warehouseId): array
    {
        $inventory = Schema::hasTable('wh_batch_balances') ? (float) DB::table('wh_batch_balances')->where('warehouse_id',$warehouseId)->sum('inventory_value') : 0;
        $ar=0.0;if(Schema::hasTable('wh_v3_outgoing_invoices')&&Schema::hasColumn('wh_v3_outgoing_invoices','balance_due'))$ar+=(float)DB::table('wh_v3_outgoing_invoices')->where('warehouse_id',$warehouseId)->where('balance_due','>',0)->whereNotIn('status',['draft','cancelled','rejected'])->sum('balance_due');
        if(Schema::hasTable('wh_v3_manual_invoices')&&Schema::hasColumn('wh_v3_manual_invoices','balance_due'))$ar+=(float)DB::table('wh_v3_manual_invoices')->where('warehouse_id',$warehouseId)->where('direction','outgoing')->where('balance_due','>',0)->whereNotIn('status',['draft','cancelled','rejected'])->sum('balance_due');

        $ap=0.0;if(Schema::hasTable('wh_supplier_invoices'))$ap+=(float)DB::table('wh_supplier_invoices')->where('warehouse_id',$warehouseId)->where('balance_due','>',0)->whereNotIn('status',['cancelled','void'])->sum('balance_due');
        if(Schema::hasTable('wh_v3_manual_invoices')&&Schema::hasColumn('wh_v3_manual_invoices','balance_due'))$ap+=(float)DB::table('wh_v3_manual_invoices')->where('warehouse_id',$warehouseId)->where('direction','incoming')->where('balance_due','>',0)->whereNotIn('status',['draft','cancelled','rejected'])->sum('balance_due');
        if(Schema::hasTable('wh_supplier_purchase_orders')&&Schema::hasTable('wh_supplier_invoices')){
            $uninvoiced=DB::table('wh_supplier_purchase_orders as p')->leftJoin('wh_supplier_invoices as i','i.purchase_order_id','=','p.id')->where('p.warehouse_id',$warehouseId)->whereNull('i.id')->whereNotNull('p.purchase_approved_at')->get(['p.estimated_total','p.actual_total']);
            foreach($uninvoiced as $p)$ap+=(float)$p->actual_total>0?(float)$p->actual_total:(float)$p->estimated_total;
        }

        $poClearing=0.0;if(Schema::hasTable('wh_supplier_purchase_orders')){$pos=DB::table('wh_supplier_purchase_orders')->where('warehouse_id',$warehouseId)->whereNotNull('purchase_approved_at')->get(['id','estimated_total','actual_total']);$ledgerIds=Schema::hasTable('wh_stock_ins')?DB::table('wh_stock_ins')->where('warehouse_id',$warehouseId)->whereIn('purchase_order_id',$pos->pluck('id')->all())->pluck('ledger_posting_id','purchase_order_id'):collect();$costs=[];$ids=$ledgerIds->filter()->values()->all();if($ids!==[]){$costs=DB::table('wh_ledger_entries')->whereIn('posting_id',$ids)->groupBy('posting_id')->pluck(DB::raw('SUM(total_cost)'),'posting_id')->all();}foreach($pos as $p){$liability=(float)$p->actual_total>0?(float)$p->actual_total:(float)$p->estimated_total;$lp=(string)($ledgerIds[(string)$p->id]??'');$poClearing+=$liability-(float)($costs[$lp]??0);}}

        $wip=0.0;if(Schema::hasTable('wh_productions')){$ps=DB::table('wh_productions')->where('warehouse_id',$warehouseId)->whereNotIn('status',['completed','finished','cancelled','rejected'])->get(['id','actual_input_value','labor_cost','overhead_cost']);$resultValues=Schema::hasTable('wh_v3_production_results')?DB::table('wh_v3_production_results')->whereIn('production_id',$ps->pluck('id')->all())->where('status','approved')->groupBy('production_id')->pluck(DB::raw('SUM(inventory_value)'),'production_id'):collect();foreach($ps as $p)$wip+=(float)$p->actual_input_value+(float)$p->labor_cost+(float)$p->overhead_cost-(float)($resultValues[(string)$p->id]??0);}

        $transit=0.0;if(Schema::hasTable('wh_ledger_postings')){$trs=DB::table('wh_ledger_postings as p')->join('wh_ledger_entries as e','e.posting_id','=','p.id')->where('p.warehouse_id',$warehouseId)->where('p.status','posted')->whereIn('p.movement_type',['transfer_out','transfer_in'])->groupBy('p.id','p.movement_type')->get(['p.movement_type',DB::raw('SUM(e.total_cost) value')]);foreach($trs as $r)$transit+=(string)$r->movement_type==='transfer_out'?(float)$r->value:-(float)$r->value;}
        return ['INVENTORY'=>round($inventory,2),'AR'=>round($ar,2),'AP'=>round($ap,2),'PO_CLEARING'=>round($poClearing,2),'WIP'=>round($wip,2),'TRANSIT'=>round($transit,2)];
    }

    private function financeAccountBalance(array $warehouseIds, string $accountCode): float
    {
        $q=DB::table('wh_v4_finance_general_posting_lines as l')->join('wh_v4_finance_general_postings as p','p.id','=','l.general_posting_id')->whereIn('p.warehouse_id',$warehouseIds)->whereIn('p.status',['POSTED','REVERSED'])->where('l.account_code',$accountCode);
        $debit=(float)(clone $q)->sum('l.debit');$credit=(float)(clone $q)->sum('l.credit');
        return $accountCode==='2100' ? $credit-$debit : $debit-$credit;
    }

    private function financeSnapshot(array $warehouseIds, string $from, string $to): array
    {
        $base=DB::table('wh_v4_finance_general_posting_lines as l')->join('wh_v4_finance_general_postings as p','p.id','=','l.general_posting_id')->whereIn('p.warehouse_id',$warehouseIds)->whereIn('p.status',['POSTED','REVERSED']);
        $period=(clone $base)->whereBetween('p.business_date',[$from,$to]);
        $sum=function($q,array $codes,bool $creditNormal=false):float{$q->whereIn('l.account_code',$codes);$d=(float)(clone $q)->sum('l.debit');$c=(float)(clone $q)->sum('l.credit');return round($creditNormal?$c-$d:$d-$c,2);};
        $revenue=$sum(clone $period,['4100'],true);$otherRevenue=$sum(clone $period,['4200'],true);$hpp=$sum(clone $period,['5100']);
        $expenseCodes=DB::table('wh_v4_finance_coa')->where('account_type','EXPENSE')->whereNotIn('code',['5100'])->pluck('code')->all();$otherExpense=$expenseCodes? $sum(clone $period,$expenseCodes):0.0;
        $cashCodes=DB::table('wh_v4_finance_coa')->where(fn($q)=>$q->where('code','1010')->orWhere('code','like','1010.%'))->pluck('code')->all();
        $bankCodes=DB::table('wh_v4_finance_coa')->where(fn($q)=>$q->where('code','1020')->orWhere('code','like','1020.%'))->pluck('code')->all();
        $cash=$cashCodes?$sum(clone $base,$cashCodes):0.0;$bank=$bankCodes?$sum(clone $base,$bankCodes):0.0;
        return ['revenue'=>$revenue,'other_revenue'=>$otherRevenue,'hpp'=>$hpp,'gross_profit'=>round($revenue-$hpp,2),'other_expense'=>$otherExpense,'net_operating_result'=>round($revenue+$otherRevenue-$hpp-$otherExpense,2),'cash_balance'=>$cash,'bank_balance'=>$bank];
    }

    private function sourceKeySet(array $keys): array
    {
        $keys=array_values(array_unique(array_filter($keys)));$out=[];foreach(array_chunk($keys,500) as $chunk){foreach(DB::table('wh_v4_finance_general_postings')->whereIn('source_key',$chunk)->whereIn('status',['POSTED','REVERSED'])->pluck('source_key')->all() as $k)$out[(string)$k]=true;}return $out;
    }

    private function cutoff(string $warehouseId): ?string
    {
        $v=DB::table('wh_v4_finance_recon_baselines')->where('warehouse_id',$warehouseId)->max('captured_at');return $v?CarbonImmutable::parse($v)->format('Y-m-d H:i:s'):null;
    }

    private function baselineRows(array $warehouseIds): array
    {
        return DB::table('wh_v4_finance_recon_baselines as b')->leftJoin('outlets as o','o.id','=','b.warehouse_id')->whereIn('b.warehouse_id',$warehouseIds)->orderBy('o.code')->orderBy('b.control_key')->get(['b.*','o.code as warehouse_code','o.name as warehouse_name'])->map(fn($r)=>['id'=>(string)$r->id,'warehouse_id'=>(string)$r->warehouse_id,'warehouse_code'=>$r->warehouse_code,'warehouse_name'=>$r->warehouse_name,'control_key'=>$r->control_key,'account_code'=>$r->account_code,'operational_value'=>(float)$r->operational_value,'finance_value'=>(float)$r->finance_value,'offset_value'=>(float)$r->offset_value,'captured_at'=>$r->captured_at])->all();
    }
    private function baselineComplete(array $warehouseIds): bool { foreach($warehouseIds as $wh) if(DB::table('wh_v4_finance_recon_baselines')->where('warehouse_id',$wh)->distinct()->count('control_key')<count(self::CONTROLS)) return false; return $warehouseIds!==[]; }
    private function latestOperationalRun(string $warehouseId): ?object { return DB::table('wh_operational_reconciliation_runs')->where(fn($q)=>$q->where('warehouse_id',$warehouseId)->orWhereNull('warehouse_id'))->orderByDesc('completed_at')->orderByDesc('created_at')->first(); }
    private function latestPassingOperationalRun(string $warehouseId, CarbonImmutable $since): ?object { return DB::table('wh_operational_reconciliation_runs')->where(fn($q)=>$q->where('warehouse_id',$warehouseId)->orWhereNull('warehouse_id'))->where('status','passed')->where('issue_count',0)->whereNotNull('completed_at')->where('completed_at','>=',$since)->orderByDesc('completed_at')->first(); }
    private function latestOperationalRows(array $warehouseIds): array { $out=[];foreach($warehouseIds as $wh){$r=$this->latestOperationalRun($wh);$out[]=['warehouse_id'=>$wh,'run_id'=>$r?(string)$r->id:null,'status'=>$r?->status,'issue_count'=>$r?(int)$r->issue_count:null,'completed_at'=>$r?->completed_at];}return $out; }
    private function warehouses(array $ids): array { return DB::table('outlets')->whereIn('id',$ids)->orderBy('name')->get(['id','code','name'])->map(fn($r)=>['id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name])->all(); }
    private function cleanIds(array $ids): array { return collect($ids)->filter()->map(fn($x)=>(string)$x)->unique()->values()->all(); }
    private function latestRuns(array $ids,string $mode): array { $q=DB::table('wh_v4_finance_recon_runs');if($mode==='warehouse'&&count($ids)===1)$q->where('warehouse_id',$ids[0]);else $q->where(fn($x)=>$x->whereNull('warehouse_id')->orWhereIn('warehouse_id',$ids));return $q->orderByDesc('created_at')->limit(20)->get()->map(fn($r)=>$this->runRow($r))->all(); }
    private function runRow(object $r): array { return ['id'=>(string)$r->id,'scope_mode'=>$r->scope_mode,'warehouse_id'=>$r->warehouse_id,'date_from'=>$r->date_from,'date_to'=>$r->date_to,'status'=>$r->status,'checked_rule_count'=>(int)$r->checked_rule_count,'critical_count'=>(int)$r->critical_count,'warning_count'=>(int)$r->warning_count,'issue_count'=>(int)$r->issue_count,'started_at'=>$r->started_at,'completed_at'=>$r->completed_at,'summary'=>$this->json($r->summary)]; }
    private function issueRow(object $r): array { return ['id'=>(string)$r->id,'warehouse_id'=>$r->warehouse_id,'severity'=>$r->severity,'rule_key'=>$r->rule_key,'reference_type'=>$r->reference_type,'reference_id'=>$r->reference_id,'message'=>$r->message,'operational_value'=>$r->operational_value!==null?(float)$r->operational_value:null,'finance_value'=>$r->finance_value!==null?(float)$r->finance_value:null,'variance'=>$r->variance!==null?(float)$r->variance:null,'metadata'=>$this->json($r->metadata)]; }
    private function json(mixed $v): array { if(is_array($v))return $v;if(!is_string($v)||trim($v)==='')return[];$d=json_decode($v,true);return is_array($d)?$d:[]; }
}
