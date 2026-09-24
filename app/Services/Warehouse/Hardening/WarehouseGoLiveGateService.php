<?php

namespace App\Services\Warehouse\Hardening;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WarehouseGoLiveGateService
{
    private const REQUIRED_TABLES = [
        'wh_batch_balances','wh_ledger_entries','wh_stock_units','wh_customers','wh_sales_orders',
        'wh_sales_delivery_orders','wh_sales_goods_receipts','wh_sales_invoices','wh_customer_receipts',
        'wh_supplier_invoices','wh_supplier_payments','wh_productions','wh_reconciliation_exceptions',
        'wh_go_live_runs','wh_go_live_check_results','wh_reversal_requests'
    ];

    public function dashboard(): array
    {
        $latest = Schema::hasTable('wh_go_live_runs') ? DB::table('wh_go_live_runs')->latest('created_at')->first() : null;
        return [
            'latest_run' => $latest,
            'open_critical_exceptions' => $this->countWhere('wh_reconciliation_exceptions', ['status' => 'open', 'severity' => 'critical']),
            'negative_batches' => Schema::hasTable('wh_batch_balances') ? DB::table('wh_batch_balances')->where('on_hand_qty', '<', 0)->count() : null,
            'missing_sales_invoices' => $this->missingSalesInvoices(),
            'missing_purchase_invoices' => $this->missingPurchaseInvoices(),
            'active_operation_locks' => Schema::hasTable('wh_operation_locks') ? DB::table('wh_operation_locks')->where('expires_at', '>', now())->count() : null,
            'pending_reversals' => Schema::hasTable('wh_reversal_requests') ? DB::table('wh_reversal_requests')->whereIn('status', ['submitted','approved'])->count() : null,
        ];
    }

    public function run(?string $userId = null, string $environment = 'production'): array
    {
        return DB::transaction(function () use ($userId, $environment): array {
            $runId = (string) Str::ulid();
            $runNumber = 'WH-GL-'.now()->format('Ymd-His').'-'.strtoupper(Str::random(4));
            DB::table('wh_go_live_runs')->insert([
                'id'=>$runId,'run_number'=>$runNumber,'environment'=>$environment,'status'=>'running',
                'started_by_user_id'=>$userId,'started_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            $checks = $this->checks();
            foreach ($checks as $check) DB::table('wh_go_live_check_results')->insert(array_merge($check, [
                'id'=>(string)Str::ulid(),'go_live_run_id'=>$runId,'created_at'=>now(),'updated_at'=>now(),
            ]));
            $failed = collect($checks)->where('status','failed');
            $critical = $failed->where('severity','critical')->count();
            $summary = ['critical_failed'=>$critical,'warning_failed'=>$failed->where('severity','warning')->count()];
            DB::table('wh_go_live_runs')->where('id',$runId)->update([
                'status'=>$critical > 0 ? 'failed' : 'passed','total_checks'=>count($checks),
                'passed_checks'=>collect($checks)->where('status','passed')->count(),'failed_checks'=>$failed->count(),
                'warning_checks'=>$failed->where('severity','warning')->count(),'summary'=>json_encode($summary),
                'completed_at'=>now(),'updated_at'=>now(),
            ]);
            return $this->runDetail($runId);
        });
    }

    public function waive(string $resultId, string $reason, ?string $userId): array
    {
        DB::transaction(function () use ($resultId,$reason,$userId): void {
            $result = DB::table('wh_go_live_check_results')->lockForUpdate()->where('id',$resultId)->first();
            abort_unless($result, 404, 'Go-live check tidak ditemukan.');
            abort_if($result->status === 'passed', 422, 'Check yang sudah lulus tidak memerlukan waiver.');
            DB::table('wh_go_live_check_results')->where('id',$resultId)->update([
                'status'=>'waived','waiver_reason'=>$reason,'waived_by_user_id'=>$userId,'waived_at'=>now(),'updated_at'=>now(),
            ]);
            $remaining = DB::table('wh_go_live_check_results')->where('go_live_run_id',$result->go_live_run_id)
                ->where('severity','critical')->where('status','failed')->count();
            if ($remaining === 0) DB::table('wh_go_live_runs')->where('id',$result->go_live_run_id)->update([
                'status'=>'passed_with_waiver','updated_at'=>now(),
            ]);
        });
        return ['ok'=>true];
    }

    public function runDetail(string $runId): array
    {
        return [
            'run'=>DB::table('wh_go_live_runs')->where('id',$runId)->first(),
            'checks'=>DB::table('wh_go_live_check_results')->where('go_live_run_id',$runId)->orderBy('category')->orderBy('check_code')->get(),
        ];
    }

    private function checks(): array
    {
        $missingTables = array_values(array_filter(self::REQUIRED_TABLES, fn(string $t): bool => !Schema::hasTable($t)));
        $checks = [];
        $checks[] = $this->check('schema_complete','schema','critical',count($missingTables)===0,'Seluruh tabel Warehouse v2 tersedia.',count(self::REQUIRED_TABLES)-count($missingTables),count(self::REQUIRED_TABLES),['missing'=>$missingTables]);
        $checks[] = $this->zeroCheck('negative_inventory','inventory','critical',Schema::hasTable('wh_batch_balances') ? DB::table('wh_batch_balances')->where('on_hand_qty','<',0)->count() : 1,'Tidak ada saldo batch negatif.');
        $checks[] = $this->zeroCheck('sales_gr_without_invoice','finance','critical',$this->missingSalesInvoices(),'Setiap GR completed mempunyai Sales Invoice.');
        $checks[] = $this->zeroCheck('stock_in_without_invoice','purchasing','critical',$this->missingPurchaseInvoices(),'Setiap Stock In approved mempunyai Purchase Invoice.');
        $checks[] = $this->zeroCheck('ar_balance_mismatch','finance','critical',$this->balanceMismatch('wh_sales_invoices'),'Saldo AR seimbang: grand total - paid = balance due.');
        $checks[] = $this->zeroCheck('ap_balance_mismatch','purchasing','critical',$this->balanceMismatch('wh_supplier_invoices'),'Saldo AP seimbang: grand total - paid = balance due.');
        foreach ([
            ['wh_sales_invoices','invoice_number','duplicate_sales_invoice_number'],
            ['wh_supplier_invoices','invoice_number','duplicate_purchase_invoice_number'],
            ['wh_customer_receipts','idempotency_key','duplicate_customer_receipt_key'],
            ['wh_supplier_payments','idempotency_key','duplicate_supplier_payment_key'],
            ['wh_sales_delivery_orders','idempotency_key','duplicate_delivery_key'],
            ['wh_sales_goods_receipts','idempotency_key','duplicate_goods_receipt_key'],
        ] as [$table,$column,$code]) $checks[] = $this->zeroCheck($code,'idempotency','critical',$this->duplicateCount($table,$column),'Tidak ada duplicate key pada '.$table.'.');
        $checks[] = $this->zeroCheck('open_critical_reconciliation','control','critical',$this->countWhere('wh_reconciliation_exceptions',['status'=>'open','severity'=>'critical']),'Tidak ada exception rekonsiliasi kritis terbuka.');
        $checks[] = $this->zeroCheck('expired_operation_locks','concurrency','warning',Schema::hasTable('wh_operation_locks') ? DB::table('wh_operation_locks')->where('expires_at','<',now())->count() : 0,'Tidak ada operation lock kedaluwarsa yang belum dibersihkan.');
        $checks[] = $this->zeroCheck('pending_reversal_requests','reversal','warning',Schema::hasTable('wh_reversal_requests') ? DB::table('wh_reversal_requests')->whereIn('status',['submitted','approved'])->count() : 0,'Tidak ada reversal tertunda menjelang go-live.');
        $menuCount = Schema::hasTable('access_menus') ? DB::table('access_menus')->where('code','like','warehouse-%')->where('is_active',true)->count() : 0;
        $checks[] = $this->check('access_matrix_registered','security','critical',$menuCount>0,'Menu Warehouse aktif di Access Matrix.',$menuCount,1,['active_menu_count'=>$menuCount]);
        return $checks;
    }

    private function check(string $code,string $category,string $severity,bool $passed,string $message,$actual=null,$expected=null,array $evidence=[]): array
    {
        return ['check_code'=>$code,'category'=>$category,'severity'=>$severity,'status'=>$passed?'passed':'failed','message'=>$message,'actual_value'=>$actual,'expected_value'=>$expected,'evidence'=>json_encode($evidence)];
    }
    private function zeroCheck(string $code,string $category,string $severity,int $actual,string $message): array { return $this->check($code,$category,$severity,$actual===0,$message,$actual,0); }
    private function countWhere(string $table,array $where): int { if(!Schema::hasTable($table)) return 0; $q=DB::table($table); foreach($where as $k=>$v)$q->where($k,$v); return $q->count(); }
    private function missingSalesInvoices(): int { if(!Schema::hasTable('wh_sales_goods_receipts')||!Schema::hasTable('wh_sales_invoices'))return 0; return DB::table('wh_sales_goods_receipts as g')->leftJoin('wh_sales_invoices as i','i.goods_receipt_id','=','g.id')->where('g.status','completed')->whereNull('i.id')->count(); }
    private function missingPurchaseInvoices(): int { if(!Schema::hasTable('wh_stock_ins')||!Schema::hasTable('wh_supplier_invoices'))return 0; return DB::table('wh_stock_ins as s')->leftJoin('wh_supplier_invoices as i','i.stock_in_id','=','s.id')->where('s.status','approved')->whereNull('i.id')->count(); }
    private function balanceMismatch(string $table): int { if(!Schema::hasTable($table))return 0; return DB::table($table)->whereRaw('ABS((grand_total - paid_total) - balance_due) > 0.01')->count(); }
    private function duplicateCount(string $table,string $column): int { if(!Schema::hasTable($table)||!Schema::hasColumn($table,$column))return 0; return DB::query()->fromSub(DB::table($table)->select($column)->whereNotNull($column)->groupBy($column)->havingRaw('COUNT(*) > 1'),'d')->count(); }
}
