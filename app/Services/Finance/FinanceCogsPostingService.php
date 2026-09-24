<?php

namespace App\Services\Finance;

use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinanceCogsPostingService
{
    public function __construct(
        private readonly FinancePostingTemplateEngine $templateEngine,
        private readonly FinanceGeneralPostingService $generalPosting,
        private readonly FinanceScopeResolver $scopeResolver,
    ) {}

    public function options(array $allowedOutletIds): array
    {
        $ids = array_values(array_filter(array_map('strval', $allowedOutletIds)));
        $outlets = DB::table('outlets as o')
            ->leftJoin('finance_outlet_company_mappings as m', function ($join): void {
                $join->on('m.outlet_id','=','o.id')->where('m.is_active', true);
            })
            ->when($ids, fn ($q) => $q->whereIn('o.id',$ids), fn ($q) => $q->whereRaw('1=0'))
            ->where('o.is_active', true)->orderBy('o.name')
            ->get(['o.id','o.code','o.name','m.company_code'])
            ->map(fn ($r) => ['id'=>(string)$r->id,'code'=>(string)($r->code ?? ''),'name'=>(string)$r->name,'company_code'=>$r->company_code ? strtoupper((string)$r->company_code) : null])
            ->all();

        $accounts = DB::table('finance_chart_of_accounts')->where('is_active',true)->where('is_postable',true)->orderBy('code')
            ->get(['id','code','name','account_type','normal_balance'])
            ->map(fn ($r) => (array)$r)->all();
        $template = DB::table('finance_posting_templates')->whereNull('deleted_at')->where('is_active',true)
            ->where(function($q): void { $q->where('system_key','COGS_VALUATION')->orWhere('code','SYS-COGS-VALUATION'); })
            ->first(['id','code','name']);

        return [
            'outlets'=>$outlets,
            'accounts'=>$accounts,
            'template'=>$template ? (array)$template : null,
            'posting_statuses'=>['UNPOSTED','DRAFT','POSTED','NEEDS_DAILY_REBUILD'],
        ];
    }

    public function sources(array $filters, array $allowedOutletIds): array
    {
        // V8 I04: paginate the narrow COGS run scope first. The previous query
        // joined outlet-company mapping + posting tables before paginator COUNT,
        // so a 1-year filter made both the count and page query carry every
        // decoration join. Posting status is now expressed as EXISTS predicates;
        // only the current page IDs are decorated afterwards.
        $ids = array_values(array_filter(array_map('strval', $allowedOutletIds)));
        $base = DB::table('cogs_calculation_runs as r')
            ->join('outlets as o', 'o.id', '=', 'r.outlet_id')
            ->where('r.status', 'closed')
            ->where('r.period_from', '>=', $filters['date_from'])
            ->where('r.period_to', '<=', $filters['date_to'])
            ->when($ids, fn ($q) => $q->whereIn('r.outlet_id', $ids), fn ($q) => $q->whereRaw('1=0'));

        if (! empty($filters['outlet_id'])) {
            $base->where('r.outlet_id', $filters['outlet_id']);
        }

        $status = strtoupper((string) ($filters['posting_status'] ?? ''));
        if ($status === 'UNPOSTED') {
            $base->whereNotExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('finance_cogs_postings as fp')
                    ->whereColumn('fp.cogs_calculation_run_id', 'r.id');
            });
        } elseif ($status === 'DRAFT' || $status === 'POSTED') {
            $base->whereExists(function ($q) use ($status): void {
                $q->selectRaw('1')
                    ->from('finance_cogs_postings as fp')
                    ->whereColumn('fp.cogs_calculation_run_id', 'r.id')
                    ->where('fp.status', $status)
                    ->whereColumn('fp.source_fingerprint', 'r.source_fingerprint');
            });
        } elseif ($status === 'NEEDS_DAILY_REBUILD') {
            $base->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('finance_cogs_postings as fp')
                    ->whereColumn('fp.cogs_calculation_run_id', 'r.id')
                    ->whereColumn('fp.source_fingerprint', '<>', 'r.source_fingerprint');
            });
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 25)));
        $total = (int) (clone $base)->count('r.id');

        $pageIds = (clone $base)
            ->orderByDesc('r.period_to')
            ->orderBy('o.name')
            ->orderBy('r.id')
            ->forPage($page, $perPage)
            ->pluck('r.id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        $items = [];
        if ($pageIds !== []) {
            $order = array_flip($pageIds);
            $rows = DB::table('cogs_calculation_runs as r')
                ->join('outlets as o', 'o.id', '=', 'r.outlet_id')
                ->leftJoin('finance_outlet_company_mappings as m', function ($join): void {
                    $join->on('m.outlet_id', '=', 'r.outlet_id')->where('m.is_active', true);
                })
                ->leftJoin('finance_cogs_postings as p', 'p.cogs_calculation_run_id', '=', 'r.id')
                ->whereIn('r.id', $pageIds)
                ->select([
                    'r.id as cogs_calculation_run_id','r.outlet_id','o.code as outlet_code','o.name as outlet_name','m.company_code',
                    'r.period_from','r.period_to','r.status as cogs_status','r.source_fingerprint','r.final_cogs_value','r.inventory_bridge_cogs_value',
                    'r.reconciliation_difference','r.data_quality_score','r.attention_count','r.closed_at','p.id as posting_id','p.posting_no',
                    'p.status as posting_status','p.posting_version','p.source_fingerprint as posting_fingerprint','p.marking_percent','p.unmarking_percent','p.posted_at',
                ])
                ->get()
                ->sortBy(fn ($row) => $order[(string) $row->cogs_calculation_run_id] ?? PHP_INT_MAX)
                ->values();

            $items = $rows->map(function ($r): array {
                $changed = $r->posting_id && (string) $r->posting_fingerprint !== (string) $r->source_fingerprint;
                return [
                    'cogs_calculation_run_id'=>(string)$r->cogs_calculation_run_id,'outlet_id'=>(string)$r->outlet_id,
                    'outlet_code'=>(string)($r->outlet_code ?? ''),'outlet_name'=>(string)$r->outlet_name,
                    'company_code'=>$r->company_code ? strtoupper((string)$r->company_code) : null,
                    'period_from'=>(string)$r->period_from,'period_to'=>(string)$r->period_to,'cogs_status'=>strtoupper((string)$r->cogs_status),
                    'final_cogs_value'=>round((float)$r->final_cogs_value,2),'inventory_bridge_cogs_value'=>round((float)$r->inventory_bridge_cogs_value,2),
                    'reconciliation_difference'=>round((float)$r->reconciliation_difference,2),'data_quality_score'=>(int)$r->data_quality_score,
                    'attention_count'=>(int)$r->attention_count,'closed_at'=>$r->closed_at ? (string)$r->closed_at : null,
                    'posting_id'=>$r->posting_id ? (string)$r->posting_id : null,'posting_no'=>$r->posting_no ? (string)$r->posting_no : null,
                    'posting_status'=>$changed ? 'NEEDS_DAILY_REBUILD' : ($r->posting_status ? strtoupper((string)$r->posting_status) : 'UNPOSTED'),
                    'posting_version'=>(int)($r->posting_version ?? 0),'marking_percent'=>(float)($r->marking_percent ?? 100),
                    'unmarking_percent'=>(float)($r->unmarking_percent ?? 0),'source_changed'=>(bool)$changed,'posted_at'=>$r->posted_at ? (string)$r->posted_at : null,
                ];
            })->all();
        }

        return ['items'=>$items,'pagination'=>[
            'current_page'=>$page,
            'last_page'=>max(1, (int) ceil($total / max(1, $perPage))),
            'per_page'=>$perPage,
            'total'=>$total,
        ], 'meta'=>[
            'contract'=>'erp_finance_v8_i04',
            'query_strategy'=>'base_scope_then_page_decoration',
        ]];
    }


    public function mappings(array $allowedOutletIds): array
    {
        $ids = array_values(array_filter(array_map('strval',$allowedOutletIds)));
        return DB::table('finance_cogs_account_mappings as m')
            ->leftJoin('outlets as o','o.id','=','m.outlet_id')
            ->join('finance_chart_of_accounts as c','c.id','=','m.cogs_account_id')
            ->join('finance_chart_of_accounts as i','i.id','=','m.inventory_account_id')
            ->join('finance_chart_of_accounts as v','v.id','=','m.variance_account_id')
            ->where(function($q) use($ids): void { $q->whereNull('m.outlet_id'); if($ids)$q->orWhereIn('m.outlet_id',$ids); })
            ->orderBy('m.company_code')->orderByRaw('m.outlet_id IS NULL DESC')->orderBy('o.name')
            ->get(['m.*','o.name as outlet_name','c.code as cogs_account_code','c.name as cogs_account_name','i.code as inventory_account_code','i.name as inventory_account_name','v.code as variance_account_code','v.name as variance_account_name'])
            ->map(fn($r)=>(array)$r)->all();
    }

    public function saveMapping(array $data, ?string $userId, ?string $id = null): string
    {
        $company = strtoupper((string)$data['company_code']);
        $outlet = $this->nullable($data['outlet_id'] ?? null);
        if ($outlet) {
            $resolved = $this->scopeResolver->resolve($company,$outlet);
            $company = $resolved['company_code'];
        }
        $this->assertAccount((string)$data['cogs_account_id'], ['COGS','EXPENSE'], 'COGS');
        $this->assertAccount((string)$data['inventory_account_id'], ['ASSET'], 'Persediaan');
        $this->assertAccount((string)$data['variance_account_id'], ['COGS','EXPENSE','OTHER_EXPENSE'], 'Variance');

        return DB::transaction(function() use($data,$userId,$id,$company,$outlet): string {
            $existing = $id ? DB::table('finance_cogs_account_mappings')->where('id',$id)->lockForUpdate()->first() : null;
            $duplicate = DB::table('finance_cogs_account_mappings')->where('company_code',$company)
                ->when($outlet,fn($q)=>$q->where('outlet_id',$outlet),fn($q)=>$q->whereNull('outlet_id'))
                ->when($id,fn($q)=>$q->where('id','<>',$id))->exists();
            if($duplicate) throw new InvalidArgumentException('Mapping COGS untuk scope tersebut sudah ada.');
            $mappingId = (string)($existing->id ?? Str::ulid());
            $payload = [
                'company_code'=>$company,'outlet_id'=>$outlet,'cogs_account_id'=>$data['cogs_account_id'],'inventory_account_id'=>$data['inventory_account_id'],
                'variance_account_id'=>$data['variance_account_id'],'is_active'=>(bool)$data['is_active'],'notes'=>$this->nullable($data['notes'] ?? null),
                'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ];
            if($existing) DB::table('finance_cogs_account_mappings')->where('id',$mappingId)->update($payload);
            else DB::table('finance_cogs_account_mappings')->insert($payload + ['id'=>$mappingId,'created_by_user_id'=>$userId,'created_at'=>now()]);
            return $mappingId;
        });
    }

    public function deleteMapping(string $id): void
    {
        $mapping = DB::table('finance_cogs_account_mappings')->where('id',$id)->first();
        if(!$mapping) return;
        if(DB::table('finance_cogs_postings')->where('mapping_id',$id)->where('status','POSTED')->exists()) {
            throw new InvalidArgumentException('Mapping pernah digunakan oleh COGS Posting POSTED. Nonaktifkan mapping, jangan hapus audit mapping.');
        }
        DB::table('finance_cogs_account_mappings')->where('id',$id)->delete();
    }

    public function createDraft(string $runId, array $data, ?string $userId): string
    {
        return DB::transaction(function() use($runId,$data,$userId): string {
            $run = DB::table('cogs_calculation_runs')->where('id',$runId)->lockForUpdate()->first();
            $this->assertClosedRun($run);
            $existing = DB::table('finance_cogs_postings')->where('cogs_calculation_run_id',$runId)->lockForUpdate()->first();
            if($existing) return (string)$existing->id;
            $company = $this->scopeResolver->companyForOutlet((string)$run->outlet_id);
            if(!$company) throw new InvalidArgumentException('Outlet COGS belum dipetakan ke PT Finance.');
            [$marking,$unmarking] = $this->allocation($data['marking_percent'] ?? 100,$data['unmarking_percent'] ?? 0);
            $template = $this->cogsTemplate();
            $mapping = $this->resolveMapping($company,(string)$run->outlet_id);
            $id = (string)Str::ulid();
            DB::table('finance_cogs_postings')->insert([
                'id'=>$id,'posting_no'=>$this->postingNo((string)$run->period_to,$id),'cogs_calculation_run_id'=>$runId,'company_code'=>$company,'outlet_id'=>(string)$run->outlet_id,
                'period_from'=>(string)$run->period_from,'period_to'=>(string)$run->period_to,'journal_date'=>(string)$run->period_to,
                'source_fingerprint'=>(string)$run->source_fingerprint,'final_cogs_value'=>round((float)$run->final_cogs_value,2),
                'inventory_bridge_cogs_value'=>round((float)$run->inventory_bridge_cogs_value,2),'reconciliation_difference'=>round((float)$run->reconciliation_difference,2),
                'marking_percent'=>$marking,'unmarking_percent'=>$unmarking,'template_id'=>(string)$template->id,'mapping_id'=>$mapping?->id,
                'status'=>'DRAFT','posting_version'=>1,'note'=>$this->nullable($data['note'] ?? null),'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            return $id;
        });
    }

    public function refreshDraft(string $id, array $data, ?string $userId): void
    {
        DB::transaction(function() use($id,$data,$userId): void {
            $posting = DB::table('finance_cogs_postings')->where('id',$id)->lockForUpdate()->first();
            if(!$posting) throw new InvalidArgumentException('COGS Posting tidak ditemukan.');
            if($posting->status !== 'DRAFT') throw new InvalidArgumentException('Hanya COGS Posting DRAFT yang dapat direfresh.');
            $run = DB::table('cogs_calculation_runs')->where('id',$posting->cogs_calculation_run_id)->first();
            $this->assertClosedRun($run);
            [$marking,$unmarking] = $this->allocation($data['marking_percent'] ?? $posting->marking_percent,$data['unmarking_percent'] ?? $posting->unmarking_percent);
            $mapping = $this->resolveMapping((string)$posting->company_code,(string)$posting->outlet_id);
            DB::table('finance_cogs_postings')->where('id',$id)->update([
                'source_fingerprint'=>(string)$run->source_fingerprint,'final_cogs_value'=>round((float)$run->final_cogs_value,2),
                'inventory_bridge_cogs_value'=>round((float)$run->inventory_bridge_cogs_value,2),'reconciliation_difference'=>round((float)$run->reconciliation_difference,2),
                'marking_percent'=>$marking,'unmarking_percent'=>$unmarking,'mapping_id'=>$mapping?->id,'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ]);
        });
    }

    public function preview(string $id): array
    {
        $posting = DB::table('finance_cogs_postings')->where('id',$id)->first();
        if(!$posting) throw new InvalidArgumentException('COGS Posting tidak ditemukan.');
        $run = DB::table('cogs_calculation_runs')->where('id',$posting->cogs_calculation_run_id)->first();
        $this->assertSourceFresh($posting,$run);
        if((float)$posting->final_cogs_value <= 0) throw new InvalidArgumentException('Final COGS bernilai 0. Tidak ada nilai yang dapat diposting.');
        return ['posting'=>$this->postingArray($posting),'allocations'=>$this->buildAllocations($posting),'source'=>$this->sourceArray($run)];
    }

    public function post(string $id, ?string $userId): array
    {
        DB::transaction(function() use($id,$userId): void {
            $posting = DB::table('finance_cogs_postings')->where('id',$id)->lockForUpdate()->first();
            if(!$posting) throw new InvalidArgumentException('COGS Posting tidak ditemukan.');
            if($posting->status === 'POSTED') return;
            if($posting->status !== 'DRAFT') throw new InvalidArgumentException('Hanya COGS Posting DRAFT yang dapat diposting.');
            $run = DB::table('cogs_calculation_runs')->where('id',$posting->cogs_calculation_run_id)->first();
            $this->assertSourceFresh($posting,$run);
            if((float)$posting->final_cogs_value <= 0) throw new InvalidArgumentException('Final COGS bernilai 0. Posting dibatalkan.');

            foreach($this->buildAllocations($posting) as $allocation) {
                $sourceKey = 'COGS:'.$posting->cogs_calculation_run_id.':V'.$posting->posting_version.':'.$allocation['marking'];
                $staged = $this->generalPosting->stageSystem([
                    'source_key'=>$sourceKey,
                    'source_code'=>'COGS',
                    'source_module'=>'COGS',
                    'source_identity'=>(string)$posting->id,
                    'reference_no'=>(string)$posting->posting_no,
                    'company_code'=>(string)$posting->company_code,
                    'outlet_id'=>(string)$posting->outlet_id,
                    'marking'=>$allocation['marking'],
                    'business_date'=>(string)$posting->period_to,
                    'journal_date'=>(string)$posting->journal_date,
                    'description'=>'COGS Valuation '.$posting->period_from.' s/d '.$posting->period_to,
                    'subtotal'=>$allocation['amount'],
                    'payable'=>$allocation['amount'],
                    'metadata'=>[
                        'cogs_posting_id'=>(string)$posting->id,
                        'cogs_calculation_run_id'=>(string)$posting->cogs_calculation_run_id,
                        'period_from'=>(string)$posting->period_from,
                        'period_to'=>(string)$posting->period_to,
                        'allocation_percent'=>$allocation['percent'],
                        'allocation_marking'=>$allocation['marking'],
                        'unified_posting_version'=>'F02',
                    ],
                ],$allocation['lines'],$userId,true);
                $journalId = (string)($staged['journal_entry_id'] ?? '');
                if($journalId==='') throw new InvalidArgumentException('General Posting AUTO COGS tidak menghasilkan journal entry.');
                $journalNo = (string)($staged['journal_no'] ?? DB::table('finance_journal_entries')->where('id',$journalId)->value('journal_no'));
                DB::table('finance_cogs_posting_journals')->updateOrInsert([
                    'cogs_posting_id'=>$posting->id,'posting_version'=>$posting->posting_version,'marking'=>$allocation['marking'],
                ],[
                    'id'=>(string)(DB::table('finance_cogs_posting_journals')->where('cogs_posting_id',$posting->id)->where('posting_version',$posting->posting_version)->where('marking',$allocation['marking'])->value('id') ?: Str::ulid()),
                    'allocation_percent'=>$allocation['percent'],'amount'=>$allocation['amount'],'journal_entry_id'=>$journalId,'journal_no'=>$journalNo,
                    'posted_at'=>now(),'updated_at'=>now(),'created_at'=>now(),
                ]);
            }
            DB::table('finance_cogs_postings')->where('id',$id)->update(['status'=>'POSTED','posted_at'=>now(),'posted_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'updated_at'=>now()]);
        });
        return $this->show($id);
    }

    public function reopen(string $id, string $reason, ?string $userId): void
    {
        DB::transaction(function() use($id,$reason,$userId): void {
            $posting = DB::table('finance_cogs_postings')->where('id',$id)->lockForUpdate()->first();
            if(!$posting) throw new InvalidArgumentException('COGS Posting tidak ditemukan.');
            if($posting->status !== 'POSTED') throw new InvalidArgumentException('Hanya COGS Posting POSTED yang dapat direopen.');
            $rows = DB::table('finance_cogs_posting_journals')->where('cogs_posting_id',$id)->where('posting_version',$posting->posting_version)->lockForUpdate()->get();
            foreach($rows as $row) {
                if($row->reversal_journal_id) continue;
                $general = $this->generalPosting->generalPostingByJournal((string)$row->journal_entry_id);
                if(!$general) throw new InvalidArgumentException('Journal COGS belum mempunyai parent General Posting. Jalankan reconcile F02 terlebih dahulu.');
                if((string)$general->status==='POSTED') $this->generalPosting->reopen((string)$general->id,$reason,$userId);
                $link = DB::table('finance_general_posting_journals')->where('general_posting_id',$general->id)->where('journal_entry_id',$row->journal_entry_id)->first();
                if($link?->reversal_journal_id) {
                    DB::table('finance_cogs_posting_journals')->where('id',$row->id)->update([
                        'reversal_journal_id'=>$link->reversal_journal_id,
                        'reversal_journal_no'=>$link->reversal_journal_no,
                        'reversed_at'=>$link->reversed_at ?: now(),
                        'updated_at'=>now(),
                    ]);
                }
            }
            DB::table('finance_cogs_postings')->where('id',$id)->update([
                'status'=>'DRAFT','posting_version'=>(int)$posting->posting_version+1,'journal_date'=>now()->toDateString(),
                'posted_at'=>null,'posted_by_user_id'=>null,'reopened_at'=>now(),'reopened_by_user_id'=>$userId,'reopen_reason'=>$reason,'updated_by_user_id'=>$userId,'updated_at'=>now(),
            ]);
        });
    }

    public function destroyDraft(string $id): void
    {
        DB::transaction(function() use($id): void {
            $posting = DB::table('finance_cogs_postings')->where('id',$id)->lockForUpdate()->first();
            if(!$posting) return;
            if($posting->status !== 'DRAFT') throw new InvalidArgumentException('Hanya draft yang dapat dihapus.');
            if(DB::table('finance_cogs_posting_journals')->where('cogs_posting_id',$id)->exists()) throw new InvalidArgumentException('Draft memiliki histori posting/reversal dan tidak boleh dihapus.');
            DB::table('finance_cogs_postings')->where('id',$id)->delete();
        });
    }

    public function show(string $id): array
    {
        $posting = DB::table('finance_cogs_postings as p')->join('outlets as o','o.id','=','p.outlet_id')
            ->where('p.id',$id)->first(['p.*','o.code as outlet_code','o.name as outlet_name']);
        if(!$posting) throw new InvalidArgumentException('COGS Posting tidak ditemukan.');
        $run = DB::table('cogs_calculation_runs')->where('id',$posting->cogs_calculation_run_id)->first();
        $journals = DB::table('finance_cogs_posting_journals')->where('cogs_posting_id',$id)->orderByDesc('posting_version')->orderBy('marking')->get()->map(fn($r)=>(array)$r)->all();
        $mapping = $posting->mapping_id ? DB::table('finance_cogs_account_mappings')->where('id',$posting->mapping_id)->first() : null;
        return $this->postingArray($posting) + ['source'=>$this->sourceArray($run),'journals'=>$journals,'mapping'=>$mapping ? (array)$mapping : null,'source_changed'=>$run ? (string)$run->source_fingerprint !== (string)$posting->source_fingerprint : true];
    }

    private function buildAllocations(object $posting): array
    {
        $total = round((float)$posting->final_cogs_value,2);
        $markAmount = round($total * ((float)$posting->marking_percent / 100),2);
        $unmarkAmount = round($total - $markAmount,2);
        $mapping = $posting->mapping_id ? DB::table('finance_cogs_account_mappings')->where('id',$posting->mapping_id)->where('is_active',true)->first() : null;
        $rows = [];
        foreach([['MARKING',(float)$posting->marking_percent,$markAmount],['UNMARKING',(float)$posting->unmarking_percent,$unmarkAmount]] as [$marking,$percent,$amount]) {
            if($percent <= 0 || $amount <= 0) continue;
            $context = ['amount'=>$amount,'company_code'=>$posting->company_code,'outlet_id'=>$posting->outlet_id,'marking'=>$marking,'reference_no'=>$posting->posting_no,'description'=>'COGS Valuation'];
            $preview = $this->templateEngine->preview((string)$posting->template_id,'COGS',$context);
            $lines = $preview['lines'];
            if($mapping) $lines = $this->applyMapping($lines,$mapping);
            $rows[] = ['marking'=>$marking,'percent'=>$percent,'amount'=>$amount,'lines'=>$lines,'total_debit'=>round(array_sum(array_column($lines,'debit')),2),'total_credit'=>round(array_sum(array_column($lines,'credit')),2)];
        }
        if(!$rows) throw new InvalidArgumentException('Alokasi COGS tidak menghasilkan jurnal.');
        return $rows;
    }

    private function applyMapping(array $lines, object $mapping): array
    {
        $cogs = $this->account((string)$mapping->cogs_account_id);
        $inventory = $this->account((string)$mapping->inventory_account_id);
        foreach($lines as &$line) {
            if((float)$line['debit'] > 0) $line = array_replace($line,$this->accountLine($cogs));
            elseif((float)$line['credit'] > 0) $line = array_replace($line,$this->accountLine($inventory));
        }
        unset($line);
        return $lines;
    }

    private function resolveMapping(string $company,string $outletId): ?object
    {
        return DB::table('finance_cogs_account_mappings')->where('company_code',$company)->where('is_active',true)
            ->where(function($q) use($outletId): void { $q->where('outlet_id',$outletId)->orWhereNull('outlet_id'); })
            ->orderByRaw('CASE WHEN outlet_id IS NULL THEN 1 ELSE 0 END')->first();
    }

    private function cogsTemplate(): object
    {
        $template = DB::table('finance_posting_templates')->whereNull('deleted_at')->where('is_active',true)
            ->where(function($q): void { $q->where('system_key','COGS_VALUATION')->orWhere('code','SYS-COGS-VALUATION'); })->first();
        if(!$template) throw new InvalidArgumentException('Template SYS-COGS-VALUATION belum tersedia. Apply Iterasi 05 terlebih dahulu.');
        return $template;
    }

    private function assertClosedRun(?object $run): void
    {
        if(!$run) throw new InvalidArgumentException('COGS Calculation tidak ditemukan.');
        if(strtolower((string)$run->status) !== 'closed') throw new InvalidArgumentException('COGS Calculation harus CLOSED sebelum dapat diposting ke Finance.');
    }

    private function assertSourceFresh(object $posting, ?object $run): void
    {
        $this->assertClosedRun($run);
        if((string)$posting->source_fingerprint !== (string)$run->source_fingerprint) throw new InvalidArgumentException('Source COGS berubah. Refresh draft sebelum posting ulang.');
    }

    private function allocation(mixed $marking,mixed $unmarking): array
    {
        $m = round((float)$marking,4); $u = round((float)$unmarking,4);
        if($m < 0 || $u < 0 || $m > 100 || $u > 100 || abs(($m+$u)-100) > 0.0001) throw new InvalidArgumentException('Marking + Unmarking harus tepat 100%.');
        return [$m,$u];
    }

    private function assertAccount(string $id,array $types,string $label): void
    {
        $a = $this->account($id);
        if(!$a->is_active || !$a->is_postable) throw new InvalidArgumentException("COA {$label} tidak aktif/postable.");
        if(!in_array(strtoupper((string)$a->account_type),$types,true)) throw new InvalidArgumentException("Tipe COA {$label} tidak sesuai.");
    }

    private function account(string $id): object
    {
        $a = DB::table('finance_chart_of_accounts')->where('id',$id)->first(['id','code','name','account_type','normal_balance','is_active','is_postable']);
        if(!$a) throw new InvalidArgumentException('COA Finance tidak ditemukan.');
        return $a;
    }

    private function accountLine(object $a): array
    {
        return ['account_id'=>(string)$a->id,'account_code'=>(string)$a->code,'account_name'=>(string)$a->name,'account_type'=>(string)$a->account_type,'normal_balance'=>(string)$a->normal_balance];
    }

    private function postingArray(object $p): array
    {
        return [
            'id'=>(string)$p->id,'posting_no'=>(string)$p->posting_no,'cogs_calculation_run_id'=>(string)$p->cogs_calculation_run_id,
            'company_code'=>(string)$p->company_code,'outlet_id'=>(string)$p->outlet_id,'outlet_code'=>(string)($p->outlet_code ?? ''),'outlet_name'=>(string)($p->outlet_name ?? ''),
            'period_from'=>(string)$p->period_from,'period_to'=>(string)$p->period_to,'journal_date'=>(string)$p->journal_date,
            'final_cogs_value'=>round((float)$p->final_cogs_value,2),'inventory_bridge_cogs_value'=>round((float)$p->inventory_bridge_cogs_value,2),
            'reconciliation_difference'=>round((float)$p->reconciliation_difference,2),'marking_percent'=>(float)$p->marking_percent,'unmarking_percent'=>(float)$p->unmarking_percent,
            'template_id'=>$p->template_id ? (string)$p->template_id : null,'mapping_id'=>$p->mapping_id ? (string)$p->mapping_id : null,
            'status'=>strtoupper((string)$p->status),'posting_version'=>(int)$p->posting_version,'note'=>$p->note ? (string)$p->note : null,
            'posted_at'=>$p->posted_at ? (string)$p->posted_at : null,'reopened_at'=>$p->reopened_at ? (string)$p->reopened_at : null,'reopen_reason'=>$p->reopen_reason ? (string)$p->reopen_reason : null,
        ];
    }

    private function sourceArray(?object $r): ?array
    {
        if(!$r) return null;
        return ['id'=>(string)$r->id,'status'=>strtoupper((string)$r->status),'period_from'=>(string)$r->period_from,'period_to'=>(string)$r->period_to,
            'source_fingerprint'=>(string)$r->source_fingerprint,'final_cogs_value'=>round((float)$r->final_cogs_value,2),'inventory_bridge_cogs_value'=>round((float)$r->inventory_bridge_cogs_value,2),
            'reconciliation_difference'=>round((float)$r->reconciliation_difference,2),'data_quality_score'=>(int)$r->data_quality_score,'attention_count'=>(int)$r->attention_count];
    }

    private function postingNo(string $date, string $id): string
    {
        // ULID suffix makes posting numbers deterministic and concurrency-safe without a counter race.
        return 'COGS-'.str_replace('-','',$date).'-'.strtoupper(substr($id, -8));
    }

    private function nullable(mixed $v): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v === '' ? null : $v;
    }
}
