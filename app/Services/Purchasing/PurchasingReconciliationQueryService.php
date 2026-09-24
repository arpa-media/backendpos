<?php
namespace App\Services\Purchasing;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchasingReconciliationQueryService
{
    /** @return array<string,mixed> */
    public function catalogs(): array
    {
        return [
            'source_scopes'=>['ALL','STOCK','WAREHOUSE'],'overall_statuses'=>['LINKED','WARNING','CONFLICT','UNRESOLVED'],
            'severities'=>['INFO','WARNING','ERROR','CRITICAL'],
            'outlets'=>Schema::hasTable('outlets')?DB::table('outlets')->select('id','code','name','type')->where('is_active',true)->orderBy('name')->get():[],
            'runs'=>$this->runs(30),
        ];
    }
    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function paginate(array $filters): array
    {
        $runId=trim((string)($filters['run_id']??''));
        if ($runId==='') $runId=(string)(DB::table('pur_reconciliation_runs')->where('status','COMPLETED')->orderByDesc('started_at')->value('id')??'');
        $q=DB::table('pur_reconciliation_snapshots as s')->where('s.run_id',$runId);
        foreach(['source_system','overall_status','request_type','chamber_code','outlet_id'] as $field) if(!empty($filters[$field])) $q->where('s.'.$field,$filters[$field]);
        if(!empty($filters['search'])) { $term='%'.trim((string)$filters['search']).'%'; $q->where(fn($w)=>$w->where('s.source_number','like',$term)->orWhere('s.fund_request_number','like',$term)->orWhere('s.order_number','like',$term)->orWhere('s.invoice_number','like',$term)->orWhere('s.outlet_name','like',$term)); }
        $p=$q->orderByRaw("CASE s.overall_status WHEN 'CONFLICT' THEN 0 WHEN 'WARNING' THEN 1 WHEN 'UNRESOLVED' THEN 2 ELSE 3 END")->orderByDesc('s.created_at')->paginate(min(max((int)($filters['per_page']??20),1),100));
        $run=$runId?DB::table('pur_reconciliation_runs')->where('id',$runId)->first():null;
        return ['items'=>$p->items(),'run'=>$run?$this->runRow($run):null,'summary'=>$this->snapshotSummary($runId),'pagination'=>$this->pagination($p)];
    }
    /** @return array<string,mixed> */
    public function show(string $id): array
    {
        $s=DB::table('pur_reconciliation_snapshots')->where('id',$id)->first(); abort_unless($s,404);
        $links=DB::table('pur_legacy_document_links')->where(function($q)use($s){$q->where(fn($x)=>$x->where('source_table',$s->source_table)->where('source_id',$s->source_id));if($s->fund_request_id)$q->orWhere('root_fund_request_id',$s->fund_request_id);})->orderBy('canonical_type')->get();
        $issues=DB::table('pur_reconciliation_issues')->where('run_id',$s->run_id)->where(function($q)use($s){$q->where(fn($x)=>$x->where('source_table',$s->source_table)->where('source_id',$s->source_id));if($s->fund_request_id)$q->orWhere('root_fund_request_id',$s->fund_request_id);})->orderByRaw("CASE severity WHEN 'CRITICAL' THEN 0 WHEN 'ERROR' THEN 1 WHEN 'WARNING' THEN 2 ELSE 3 END")->get();
        $events=$s->fund_request_id&&Schema::hasTable('pur_document_events')?DB::table('pur_document_events')->where('root_request_id',$s->fund_request_id)->orderBy('occurred_at')->get():[];
        return ['snapshot'=>$s,'links'=>$links,'issues'=>$issues,'events'=>$events];
    }
    /** @return array<int,array<string,mixed>> */
    public function runs(int $limit=50): array { return DB::table('pur_reconciliation_runs')->orderByDesc('started_at')->limit($limit)->get()->map(fn($r)=>$this->runRow($r))->all(); }
    /** @return array<string,mixed> */
    public function resolveIssue(string $id,string $status,?string $notes,?string $userId): array
    {
        $status=strtoupper($status); if(!in_array($status,['RESOLVED','IGNORED','OPEN'],true)) throw new ConflictHttpException('Status resolusi tidak valid.');
        abort_unless(DB::table('pur_reconciliation_issues')->where('id',$id)->exists(),404);
        DB::table('pur_reconciliation_issues')->where('id',$id)->update(['resolution_status'=>$status,'resolution_notes'=>$notes,'resolved_by_user_id'=>$status==='OPEN'?null:$userId,'resolved_at'=>$status==='OPEN'?null:now(),'updated_at'=>now()]);
        return (array)DB::table('pur_reconciliation_issues')->where('id',$id)->first();
    }
    private function snapshotSummary(string $runId): array
    {
        if($runId==='') return ['total'=>0,'linked'=>0,'warning'=>0,'conflict'=>0,'unresolved'=>0];
        $base=DB::table('pur_reconciliation_snapshots')->where('run_id',$runId);
        return ['total'=>(clone $base)->count(),'linked'=>(clone $base)->where('overall_status','LINKED')->count(),'warning'=>(clone $base)->where('overall_status','WARNING')->count(),'conflict'=>(clone $base)->where('overall_status','CONFLICT')->count(),'unresolved'=>(clone $base)->where('overall_status','UNRESOLVED')->count()];
    }
    private function runRow(object $r): array { return ['id'=>$r->id,'run_number'=>$r->run_number,'mode'=>$r->mode,'source_scope'=>$r->source_scope,'status'=>$r->status,'totals'=>json_decode((string)$r->totals,true)?:[],'started_at'=>$r->started_at,'finished_at'=>$r->finished_at,'error_message'=>$r->error_message]; }
    private function pagination(LengthAwarePaginator $p): array { return ['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total(),'from'=>$p->firstItem(),'to'=>$p->lastItem()]; }
}
