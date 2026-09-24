<?php

namespace App\Console\Commands;

use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class I07FinanceReportPerformanceCheckCommand extends Command
{
    protected $signature = 'erp-finance-v7:i07-performance-check {--days=90 : Coverage/query window to inspect (30-90 recommended)}';

    protected $description = 'Read-only health check for I07 Finance & Report 30/60/90-day performance hardening.';

    public function handle(): int
    {
        $days = max(1, min(365, (int) $this->option('days')));
        $timezone = TransactionDate::appTimezone();
        $to = CarbonImmutable::parse(TransactionDate::businessTodayDateString($timezone), $timezone);
        $from = $to->subDays($days - 1);
        $failed = false;

        $this->info("I07 Finance/Report performance check {$from->toDateString()} .. {$to->toDateString()} ({$timezone})");

        $requiredColumns = [
            'discounted_trx_count',
            'rounding_trx_count',
            'rounding_up_total',
            'rounding_down_total',
            'marked_discounted_trx_count',
            'marked_rounding_trx_count',
            'marked_rounding_up_total',
            'marked_rounding_down_total',
        ];
        foreach ($requiredColumns as $column) {
            $ok = Schema::hasTable('report_daily_sales_summaries')
                && Schema::hasColumn('report_daily_sales_summaries', $column);
            $this->line(sprintf('[%s] report_daily_sales_summaries.%s', $ok ? 'OK' : 'MISSING', $column));
            $failed = $failed || ! $ok;
        }

        $requiredIndexes = [
            ['report_daily_sales_summaries', 'report_daily_sales_summaries_date_outlet_idx'],
            ['report_daily_product_summaries', 'report_daily_product_summaries_date_outlet_idx'],
            ['report_daily_variant_summaries', 'report_daily_variant_summaries_date_outlet_idx'],
            ['finance_journal_entries', 'fin_i07_outlet_jdate_status_mark_idx'],
            ['finance_journal_entries', 'fin_i07_outlet_bdate_status_mark_idx'],
            ['finance_journal_entries', 'fin_i07_company_jdate_status_mark_idx'],
            ['finance_journal_entries', 'fin_i07_company_bdate_status_mark_idx'],
            ['finance_journal_entry_lines', 'fin_gl_account_entry_idx'],
            ['finance_financial_statement_rules', 'fin_i07_stmt_bs_account_idx'],
            ['finance_financial_statement_rules', 'fin_i07_stmt_pl_account_idx'],
            ['finance_financial_statement_rules', 'fin_i07_stmt_cf_cash_account_idx'],
        ];

        foreach ($requiredIndexes as [$table, $index]) {
            $ok = Schema::hasTable($table) && $this->indexExists($table, $index);
            $this->line(sprintf('[%s] %s.%s', $ok ? 'OK' : 'MISSING', $table, $index));
            $failed = $failed || ! $ok;
        }

        if (Schema::hasTable('report_daily_summary_coverage') && Schema::hasTable('outlets')) {
            $outletIds = DB::table('outlets')
                ->where('is_active', true)
                ->whereRaw("LOWER(COALESCE(type, 'outlet')) = 'outlet'")
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            if ($outletIds !== []) {
                $coverage = DB::table('report_daily_summary_coverage')
                    ->whereIn('outlet_id', $outletIds)
                    ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
                    ->selectRaw('business_date, COUNT(DISTINCT outlet_id) as outlet_count')
                    ->groupBy('business_date')
                    ->pluck('outlet_count', 'business_date');

                $complete = 0;
                for ($cursor = $from; $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addDay()) {
                    if ((int) ($coverage[$cursor->toDateString()] ?? 0) >= count($outletIds)) {
                        $complete++;
                    }
                }
                $this->line("Daily summary coverage: {$complete}/{$days} dates complete for ".count($outletIds).' active outlets');
                if ($complete < $days) {
                    $this->warn('Coverage belum penuh. Jalankan report-daily-summaries:warm-common sebelum peak hour.');
                }
            }
        }

        $this->explainLedgerProbe($from->toDateString(), $to->toDateString());

        if ($failed) {
            $this->error('I07 infrastructure belum lengkap. Jalankan php artisan migrate lalu ulangi check.');
            return self::FAILURE;
        }

        $this->info('I07 static/schema performance gate passed.');
        return self::SUCCESS;
    }

    private function explainLedgerProbe(string $from, string $to): void
    {
        if (! Schema::hasTable('finance_journal_entries') || ! Schema::hasTable('finance_journal_entry_lines')) {
            return;
        }

        try {
            $rows = DB::select(
                "EXPLAIN SELECT l.account_id, SUM(l.debit) debit, SUM(l.credit) credit
                 FROM finance_journal_entries e
                 INNER JOIN finance_journal_entry_lines l ON l.journal_entry_id = e.id
                 WHERE e.status = 'POSTED'
                   AND e.journal_date >= ?
                   AND e.journal_date <= ?
                 GROUP BY l.account_id",
                [$from, $to]
            );
            $first = $rows[0] ?? null;
            if ($first) {
                $key = (string) ($first->key ?? $first->possible_keys ?? '-');
                $estimate = (string) ($first->rows ?? '-');
                $this->line("GL EXPLAIN first step: key={$key}; estimated_rows={$estimate}");
            }
        } catch (Throwable $e) {
            $this->warn('GL EXPLAIN skipped: '.$e->getMessage());
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
}
