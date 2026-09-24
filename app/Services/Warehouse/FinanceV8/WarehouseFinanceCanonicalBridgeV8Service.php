<?php

namespace App\Services\Warehouse\FinanceV8;

use App\Services\Finance\FinanceGeneralPostingService;
use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class WarehouseFinanceCanonicalBridgeV8Service
{
    private const KNOWN = [
        '1010'=>'1-10001', '1020'=>'1-10002', '1100'=>'1-10100', '1210'=>'1-10200',
        '1300'=>'1-10500', '2100'=>'2-20100', '2200'=>'2-20500',
        '4100'=>'4-40000', '4200'=>'4-40000',
        '5100'=>'5-50000', '5200'=>'5-50500', '5300'=>'8-80100', '6100'=>'6-60100',
    ];

    public function __construct(
        private readonly FinanceGeneralPostingService $globalPosting,
        private readonly FinanceScopeResolver $scopeResolver,
    ) {}

    public function ensureMappings(): array
    {
        if (! Schema::hasTable('wh_v8_finance_coa_mappings')) return ['mapped'=>0,'created'=>0,'skipped'=>'migration_not_ready'];

        $mapped=0; $created=0;
        $accounts=DB::table('wh_v4_finance_coa')->where('is_active',true)->where('is_postable',true)->orderBy('code')->get();
        foreach ($accounts as $account) {
            if (DB::table('wh_v8_finance_coa_mappings')->where('warehouse_coa_id',$account->id)->exists()) continue;
            [$finance,$method,$wasCreated]=$this->resolveCanonical($account);
            if (! $finance) continue;
            $created += $wasCreated ? 1 : 0;
            DB::table('wh_v8_finance_coa_mappings')->insertOrIgnore([
                'id'=>(string)Str::ulid(),
                'warehouse_coa_id'=>$account->id,
                'finance_coa_id'=>$finance->id,
                'mapping_method'=>$method,
                'mapping_note'=>"Warehouse {$account->code} → Finance {$finance->code}",
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $mapped++;
        }
        return ['mapped'=>$mapped,'created'=>$created];
    }

    public function syncPosting(string $warehousePostingId, ?string $userId=null, bool $throw=false): array
    {
        if (! Schema::hasTable('wh_v8_finance_posting_bridges')) return ['status'=>'SKIPPED','reason'=>'migration_not_ready'];
        $this->ensureMappings();

        try {
            return DB::transaction(function () use ($warehousePostingId,$userId): array {
                $posting=DB::table('wh_v4_finance_general_postings')->where('id',$warehousePostingId)->lockForUpdate()->first();
                if (! $posting) throw new \InvalidArgumentException('General Posting Warehouse tidak ditemukan.');
                if (! in_array((string)$posting->status,['POSTED','REVERSED'],true)) return ['status'=>'SKIPPED','reason'=>'warehouse_posting_not_posted'];

                $company=$this->scopeResolver->companyForOutlet((string)$posting->warehouse_id);
                if (! $company) {
                    return $this->recordFailure($warehousePostingId,'PENDING_SCOPE','Warehouse belum dipetakan ke PT pada Finance → Mapping PT Outlet.');
                }

                $lines=DB::table('wh_v4_finance_general_posting_lines as l')
                    ->join('wh_v8_finance_coa_mappings as m','m.warehouse_coa_id','=','l.account_id')
                    ->join('finance_chart_of_accounts as f','f.id','=','m.finance_coa_id')
                    ->where('l.general_posting_id',$warehousePostingId)
                    ->orderBy('l.line_no')
                    ->get(['l.*','f.id as finance_account_id','f.code as finance_account_code','f.name as finance_account_name']);
                if ($lines->isEmpty()) return $this->recordFailure($warehousePostingId,'FAILED','Tidak ada canonical CoA mapping untuk line posting Warehouse.');

                $grouped=[];
                foreach ($lines as $line) {
                    $id=(string)$line->finance_account_id;
                    if (! isset($grouped[$id])) $grouped[$id]=['account_id'=>$id,'debit'=>0.0,'credit'=>0.0,'descriptions'=>[]];
                    $grouped[$id]['debit'] += (float)$line->debit;
                    $grouped[$id]['credit'] += (float)$line->credit;
                    if (trim((string)$line->description)!=='') $grouped[$id]['descriptions'][]=(string)$line->description;
                }

                $canonical=[];
                foreach ($grouped as $row) {
                    $net=round($row['debit']-$row['credit'],2);
                    if (abs($net)<=0.009) continue;
                    $canonical[]=[
                        'account_id'=>$row['account_id'],
                        'debit'=>$net>0?$net:0,
                        'credit'=>$net<0?abs($net):0,
                        'description'=>implode(' / ',array_slice(array_unique($row['descriptions']),0,3)) ?: (string)$posting->description,
                    ];
                }

                $debit=round((float)collect($canonical)->sum('debit'),2);
                $credit=round((float)collect($canonical)->sum('credit'),2);
                if (count($canonical)<2 || abs($debit-$credit)>0.01) {
                    return $this->recordFailure($warehousePostingId,'FAILED','Canonical projection tidak balance atau kurang dari 2 line setelah aggregation.');
                }

                $fingerprint=hash('sha256',json_encode([
                    'posting'=>$warehousePostingId,'source_fingerprint'=>$posting->source_fingerprint,'company'=>$company,'warehouse'=>$posting->warehouse_id,'lines'=>$canonical,
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));

                $bridge=DB::table('wh_v8_finance_posting_bridges')->where('warehouse_general_posting_id',$warehousePostingId)->lockForUpdate()->first();
                if ($bridge && $bridge->status==='SYNCED' && hash_equals((string)$bridge->source_fingerprint,$fingerprint)) {
                    return ['status'=>'SYNCED','idempotent'=>true,'finance_general_posting_id'=>$bridge->finance_general_posting_id,'finance_journal_entry_id'=>$bridge->finance_journal_entry_id];
                }

                $result=$this->globalPosting->stageSystem([
                    'source_key'=>'WAREHOUSE-V8:'.$warehousePostingId,
                    'source_code'=>'WAREHOUSE_V4',
                    'source_module'=>'WAREHOUSE_FINANCE_V8',
                    'source_identity'=>$warehousePostingId,
                    'company_code'=>$company,
                    'outlet_id'=>(string)$posting->warehouse_id,
                    'marking'=>'MARKING',
                    'reference_no'=>$posting->reference_no ?: $posting->posting_no,
                    'business_date'=>(string)$posting->business_date,
                    'journal_date'=>(string)$posting->journal_date,
                    'description'=>'Warehouse '.$posting->posting_no.' · '.(string)$posting->description,
                    'metadata'=>[
                        'warehouse_general_posting_id'=>$warehousePostingId,
                        'warehouse_posting_no'=>(string)$posting->posting_no,
                        'warehouse_source_type'=>(string)$posting->source_type,
                        'warehouse_id'=>(string)$posting->warehouse_id,
                        'canonical_bridge_version'=>8,
                    ],
                ],$canonical,$userId,true);

                $globalId=(string)($result['general_posting_id'] ?? '');
                $journalId=(string)($result['journal_entry_id'] ?? '');
                DB::table('wh_v8_finance_posting_bridges')->updateOrInsert(
                    ['warehouse_general_posting_id'=>$warehousePostingId],
                    [
                        'id'=>$bridge?->id ?: (string)Str::ulid(),
                        'finance_general_posting_id'=>$globalId ?: null,
                        'finance_journal_entry_id'=>$journalId ?: null,
                        'status'=>'SYNCED','source_fingerprint'=>$fingerprint,'last_error'=>null,
                        'last_attempted_at'=>now(),'synced_at'=>now(),
                        'created_at'=>$bridge?->created_at ?: now(),'updated_at'=>now(),
                    ]
                );
                return ['status'=>'SYNCED','idempotent'=>false,'finance_general_posting_id'=>$globalId,'finance_journal_entry_id'=>$journalId];
            },3);
        } catch (Throwable $e) {
            $this->recordFailure($warehousePostingId,'FAILED',$e->getMessage());
            if ($throw) throw $e;
            return ['status'=>'FAILED','error'=>$e->getMessage()];
        }
    }

    public function syncAll(?string $userId=null): array
    {
        $this->ensureMappings();
        $summary=['synced'=>0,'pending_scope'=>0,'failed'=>0,'skipped'=>0];
        $ids=DB::table('wh_v4_finance_general_postings')->whereIn('status',['POSTED','REVERSED'])->orderBy('business_date')->orderBy('created_at')->pluck('id');
        foreach ($ids as $id) {
            $result=$this->syncPosting((string)$id,$userId,false);
            $key=match($result['status'] ?? ''){'SYNCED'=>'synced','PENDING_SCOPE'=>'pending_scope','FAILED'=>'failed',default=>'skipped'};
            $summary[$key]++;
        }
        return $summary;
    }

    public function status(): array
    {
        $this->ensureMappings();
        $postable=(int)DB::table('wh_v4_finance_coa')->where('is_active',true)->where('is_postable',true)->count();
        $mapped=(int)DB::table('wh_v8_finance_coa_mappings')->count();
        $bridge=DB::table('wh_v8_finance_posting_bridges')->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total','status')->map(fn($v)=>(int)$v)->all();
        $warehouses=DB::table('outlets as w')->leftJoin('finance_outlet_company_mappings as m',function($j):void{$j->on('m.outlet_id','=','w.id')->where('m.is_active',true);})
            ->where('w.is_active',true)->whereRaw("LOWER(COALESCE(w.type,''))='warehouse'")
            ->orderBy('w.name')->get(['w.id','w.code','w.name','m.company_code'])
            ->map(fn($r)=>['id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name,'company_code'=>$r->company_code,'finance_scope_ready'=>(bool)$r->company_code])->all();
        return ['postable_warehouse_coa'=>$postable,'mapped_warehouse_coa'=>$mapped,'all_coa_mapped'=>$postable===$mapped,'bridge_status'=>$bridge,'warehouses'=>$warehouses];
    }

    private function resolveCanonical(object $account): array
    {
        $code=(string)$account->code;
        $target=self::KNOWN[$code] ?? null;
        if (str_starts_with($code,'1010.')) $target='1-10001';
        if (str_starts_with($code,'1020.')) $target='1-10002';

        if ($target) {
            $finance=DB::table('finance_chart_of_accounts')->where('code',$target)->where('is_active',true)->where('is_postable',true)->first();
            if ($finance) return [$finance,'KNOWN',false];
        }

        // Same code/name is preferred when Finance already has an equivalent account.
        $finance=DB::table('finance_chart_of_accounts')->where('code',$code)->where('is_active',true)->where('is_postable',true)->first();
        if ($finance) return [$finance,'EXACT_CODE',false];
        $finance=DB::table('finance_chart_of_accounts')->whereRaw('LOWER(name)=?', [mb_strtolower((string)$account->name)])->where('is_active',true)->where('is_postable',true)->first();
        if ($finance) return [$finance,'EXACT_NAME',false];

        $canonicalCode=$this->canonicalCode($code);
        $existing=DB::table('finance_chart_of_accounts')->where('code',$canonicalCode)->first();
        if ($existing) return [$existing,'WAREHOUSE_CANONICAL',false];

        $id=(string)Str::ulid();
        try {
            DB::table('finance_chart_of_accounts')->insert([
                'id'=>$id,'code'=>$canonicalCode,'name'=>'Warehouse · '.$account->name,
                'account_type'=>$this->globalType((string)$account->account_type),
                'normal_balance'=>(string)$account->normal_balance,'parent_id'=>null,'level_no'=>1,
                'is_header'=>false,'is_postable'=>true,'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
            ]);
        } catch (Throwable) {
            // Concurrent migration/sync may create the same canonical code first.
        }
        $finance=DB::table('finance_chart_of_accounts')->where('code',$canonicalCode)->first();
        return [$finance,'WAREHOUSE_CANONICAL',true];
    }

    private function canonicalCode(string $code): string
    {
        $safe=preg_replace('/[^A-Z0-9._-]+/','-',strtoupper($code)) ?: 'ACCOUNT';
        $candidate='WH-'.substr($safe,0,29);
        if (strlen($candidate)<=32) return $candidate;
        return 'WH-'.substr(hash('sha256',$code),0,12);
    }

    private function globalType(string $type): string
    {
        $type=strtoupper($type);
        return in_array($type,['ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE','COGS','OTHER_INCOME','OTHER_EXPENSE','TAX'],true) ? $type : 'EXPENSE';
    }

    private function recordFailure(string $postingId,string $status,string $message): array
    {
        if (Schema::hasTable('wh_v8_finance_posting_bridges')) {
            $existing=DB::table('wh_v8_finance_posting_bridges')->where('warehouse_general_posting_id',$postingId)->first();
            DB::table('wh_v8_finance_posting_bridges')->updateOrInsert(
                ['warehouse_general_posting_id'=>$postingId],
                ['id'=>$existing?->id ?: (string)Str::ulid(),'status'=>$status,'last_error'=>mb_substr($message,0,4000),'last_attempted_at'=>now(),'created_at'=>$existing?->created_at ?: now(),'updated_at'=>now()]
            );
        }
        return ['status'=>$status,'error'=>$message];
    }
}
