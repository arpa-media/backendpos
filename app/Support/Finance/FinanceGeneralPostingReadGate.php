<?php

namespace App\Support\Finance;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * ERP POS FINAL I02
 *
 * Canonical accounting read gate.
 *
 * Active accounting reports MUST only read GL journals that are the current
 * POSTED version of a General Posting. This makes General Posting the single
 * accounting source of truth: reopening/unposting the parent immediately
 * removes that economic entry from General Ledger and financial statements,
 * while the original + reversal journals remain available for audit.
 */
final class FinanceGeneralPostingReadGate
{
    public static function assertAvailable(): void
    {
        foreach (['finance_general_postings', 'finance_general_posting_journals'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("I02 Unified Accounting Read Gate membutuhkan tabel {$table}. Jalankan migration Finance Unified Posting terlebih dahulu.");
            }
        }
    }

    public static function applyActive(Builder $query, string $journalAlias = 'e'): Builder
    {
        self::assertAvailable();

        return $query->whereExists(function (Builder $sub) use ($journalAlias): void {
            $sub->selectRaw('1')
                ->from('finance_general_posting_journals as gpjl_i02')
                ->join('finance_general_postings as gp_i02', 'gp_i02.id', '=', 'gpjl_i02.general_posting_id')
                ->whereColumn('gpjl_i02.journal_entry_id', $journalAlias.'.id')
                ->whereColumn('gpjl_i02.posting_version', 'gp_i02.posting_version')
                ->where('gp_i02.status', 'POSTED')
                ->whereNull('gpjl_i02.reversal_journal_id');
        });
    }

    /**
     * Audit mode may expose historical original/reversal journals, but even
     * those rows must retain a General Posting lineage. Detached legacy GL is
     * intentionally hidden until F04 reconcile adopts/repairs it.
     */
    public static function applyAuditLinked(Builder $query, string $journalAlias = 'e'): Builder
    {
        self::assertAvailable();

        return $query->whereExists(function (Builder $sub) use ($journalAlias): void {
            $sub->selectRaw('1')
                ->from('finance_general_posting_journals as gpjl_i02')
                ->where(function (Builder $linked) use ($journalAlias): void {
                    $linked->whereColumn('gpjl_i02.journal_entry_id', $journalAlias.'.id')
                        ->orWhereColumn('gpjl_i02.reversal_journal_id', $journalAlias.'.id');
                });
        });
    }
}
