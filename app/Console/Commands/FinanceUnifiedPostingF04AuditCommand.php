<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceUnifiedPostingF04AuditCommand extends Command
{
    protected $signature = 'erp-v5:finance-unified-posting-f04-audit {--limit=200}';
    protected $description = 'Audit GL coverage: every effective non-reversal journal must have a General Posting parent and every reversal must be linked to a GP history row.';

    public function handle(): int
    {
        foreach(['finance_journal_entries','finance_general_postings','finance_general_posting_journals'] as $table){
            if(!Schema::hasTable($table)){
                $this->error("Missing table {$table}.");
                return self::FAILURE;
            }
        }

        $limit=max(1,min(2000,(int)$this->option('limit')));

        $effective=DB::table('finance_journal_entries')->whereIn('status',['POSTED','REVERSED'])->count();
        $reversals=DB::table('finance_journal_entries')->whereNotNull('reversal_of_journal_id')->where('status','POSTED')->count();

        $orphans=DB::table('finance_journal_entries as j')
            ->leftJoin('finance_general_posting_journals as g','g.journal_entry_id','=','j.id')
            ->whereIn('j.status',['POSTED','REVERSED'])
            ->whereNull('j.reversal_of_journal_id')->whereNull('g.id')
            ->orderBy('j.journal_date')->limit($limit)
            ->get(['j.journal_no','j.journal_date','j.status','j.source_type','j.source_key','j.reference_no','j.total_debit']);

        $unlinkedReversals=DB::table('finance_journal_entries as j')
            ->leftJoin('finance_general_posting_journals as g','g.reversal_journal_id','=','j.id')
            ->whereNotNull('j.reversal_of_journal_id')->where('j.status','POSTED')->whereNull('g.id')
            ->orderBy('j.journal_date')->limit($limit)
            ->get(['j.journal_no','j.journal_date','j.source_type','j.source_key','j.reference_no','j.total_debit']);

        $postedGpWithoutJournal=DB::table('finance_general_postings as gp')
            ->leftJoin('finance_general_posting_journals as l',function($join){
                $join->on('l.general_posting_id','=','gp.id')->on('l.posting_version','=','gp.posting_version');
            })
            ->where('gp.status','POSTED')->whereNull('l.id')
            ->count();

        $this->table(['Metric','Count'],[
            ['Effective journal rows (POSTED+REVERSED originals/reversals)',$effective],
            ['Reversal journals',$reversals],
            ['Orphan original journals',$orphans->count()],
            ['Unlinked reversal journals',$unlinkedReversals->count()],
            ['POSTED General Posting without active journal link',$postedGpWithoutJournal],
        ]);

        if($orphans->isNotEmpty()){
            $this->warn('Orphan originals:');
            $this->table(['Journal','Date','Status','Source','Source Key','Reference','Amount'],$orphans->map(fn($r)=>[
                $r->journal_no,$r->journal_date,$r->status,$r->source_type,$r->source_key?:'-',$r->reference_no?:'-',number_format((float)$r->total_debit,2,'.',',')
            ])->all());
        }
        if($unlinkedReversals->isNotEmpty()){
            $this->warn('Unlinked reversals:');
            $this->table(['Journal','Date','Source','Reference','Amount'],$unlinkedReversals->map(fn($r)=>[
                $r->journal_no,$r->journal_date,$r->source_type,$r->reference_no?:'-',number_format((float)$r->total_debit,2,'.',',')
            ])->all());
        }

        $ok=$orphans->isEmpty()&&$unlinkedReversals->isEmpty()&&$postedGpWithoutJournal===0;
        $this->line('Status: '.($ok?'PASSED':'FAILED'));
        if(!$ok)$this->warn('Jalankan erp-v5:finance-unified-posting-f04-reconcile lalu audit ulang.');
        return $ok?self::SUCCESS:self::FAILURE;
    }
}
