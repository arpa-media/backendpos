<?php

namespace App\Console\Commands;

use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpFinanceV8I02ReportQueryCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i02-report-query-check
        {--days=370 : Rolling window to inspect (max 400)}
        {--outlet= : Optional outlet id. Defaults to first active outlet}';

    protected $description = 'Read-only V8 I02 health gate for Report Center and Sales Collected 1-year query hardening.';

    public function handle(): int
    {
        $days = max(1, min(400, (int) $this->option('days')));
        $timezone = TransactionDate::appTimezone();
        $to = CarbonImmutable::parse(TransactionDate::businessTodayDateString($timezone), $timezone);
        $from = $to->subDays($days - 1);
        $failed = false;

        $this->info(sprintf(
            'ERP Finance V8 I02 query check %s .. %s (%d days)',
            $from->toDateString(),
            $to->toDateString(),
            $days,
        ));

        $requiredTables = [
            'sales',
            'sale_payments',
            'payment_methods',
            'report_sale_business_dates',
            'report_sale_business_date_coverage',
            'report_daily_summary_coverage',
            'report_daily_sales_summaries',
            'report_daily_payment_summaries',
            'report_daily_channel_summaries',
        ];

        foreach ($requiredTables as $table) {
            $ok = Schema::hasTable($table);
            $this->line(sprintf('[%s] table %s', $ok ? 'OK' : 'MISSING', $table));
            $failed = $failed || ! $ok;
        }

        $requiredIndexes = [
            ['report_sale_business_dates', 'rsbd_outlet_date_sale_idx'],
            ['sale_payments', 'sale_payments_sale_id_payment_method_id_index'],
            ['sale_items', 'sale_items_sale_channel_voided_idx'],
            ['report_daily_sales_summaries', 'report_daily_sales_summaries_date_outlet_idx'],
            ['report_daily_payment_summaries', 'rdps_outlet_date_method_idx'],
            ['report_daily_channel_summaries', 'rdcs_outlet_date_channel_idx'],
        ];

        foreach ($requiredIndexes as [$table, $index]) {
            $ok = Schema::hasTable($table) && $this->indexExists($table, $index);
            $this->line(sprintf('[%s] index %s.%s', $ok ? 'OK' : 'MISSING', $table, $index));
            $failed = $failed || ! $ok;
        }

        $outletId = trim((string) $this->option('outlet'));
        if ($outletId === '' && Schema::hasTable('outlets')) {
            $query = DB::table('outlets')->whereRaw("LOWER(COALESCE(type, 'outlet')) = 'outlet'");
            if (Schema::hasColumn('outlets', 'is_active')) {
                $query->where('is_active', true);
            }
            $outletId = (string) ($query->orderBy('id')->value('id') ?? '');
        }

        if ($outletId === '') {
            $this->warn('No outlet found. EXPLAIN and coverage checks skipped.');
        } else {
            $this->line('Probe outlet: '.$outletId);
            $this->checkCoverage($outletId, $from->toDateString(), $to->toDateString(), $days);
            $this->explainCanonicalPage($outletId, $from->toDateString(), $to->toDateString());
            $this->explainFilteredPayment($outletId, $from->toDateString(), $to->toDateString());
            $this->explainSummaryFacts($outletId, $from->toDateString(), $to->toDateString());
        }

        $this->newLine();
        $this->line('Runtime observability gate: append ?__trace=1&__explain=1 to Report Center/Sales Collected requests.');
        $this->line('Expected headers: X-Report-Query-Count, X-Report-Db-Time-Ms, X-Report-Slowest-Query-Ms.');
        $this->line('I02 rule: no page query should contain a period-wide sale_payments GROUP BY / sale_items GROUP BY solely for row decoration.');

        if ($failed) {
            $this->error('ERP Finance V8 I02 infrastructure gate failed. Apply previous migrations/patches first.');
            return self::FAILURE;
        }

        $this->info('ERP Finance V8 I02 schema/query gate passed. Review EXPLAIN keys/rows above on production-like data.');
        return self::SUCCESS;
    }

    private function checkCoverage(string $outletId, string $from, string $to, int $days): void
    {
        if (!Schema::hasTable('report_sale_business_date_coverage') || !Schema::hasTable('report_daily_summary_coverage')) {
            return;
        }

        $businessDateCoverage = DB::table('report_sale_business_date_coverage')
            ->where('outlet_id', $outletId)
            ->whereBetween('business_date', [$from, $to])
            ->distinct()
            ->count('business_date');

        $dailyCoverage = DB::table('report_daily_summary_coverage')
            ->where('outlet_id', $outletId)
            ->whereBetween('business_date', [$from, $to])
            ->distinct()
            ->count('business_date');

        $this->line("Canonical business-date coverage: {$businessDateCoverage}/{$days} dates");
        $this->line("Daily summary coverage: {$dailyCoverage}/{$days} dates");

        if ($businessDateCoverage < $days || $dailyCoverage < $days) {
            $this->warn('Coverage belum penuh. Jalankan I01 warm command sebelum menguji performa 1 tahun.');
        }
    }

    private function explainCanonicalPage(string $outletId, string $from, string $to): void
    {
        $sql = <<<'SQL'
SELECT s.id, s.sale_number, s.created_at, s.grand_total
FROM report_sale_business_dates rsbd
INNER JOIN sales s ON s.id = rsbd.sale_id
WHERE rsbd.outlet_id = ?
  AND rsbd.business_date BETWEEN ? AND ?
  AND s.status = 'PAID'
  AND s.deleted_at IS NULL
ORDER BY s.created_at DESC, s.id DESC
LIMIT 20
SQL;

        $this->printExplain('Canonical page', $sql, [$outletId, $from, $to]);
    }

    private function explainFilteredPayment(string $outletId, string $from, string $to): void
    {
        $method = Schema::hasTable('payment_methods')
            ? (string) (DB::table('payment_methods')->orderBy('name')->value('name') ?? '')
            : '';

        if ($method === '') {
            $this->line('Payment filter EXPLAIN skipped: no payment method row.');
            return;
        }

        $sql = <<<'SQL'
SELECT s.id, s.sale_number
FROM report_sale_business_dates rsbd
INNER JOIN sales s ON s.id = rsbd.sale_id
WHERE rsbd.outlet_id = ?
  AND rsbd.business_date BETWEEN ? AND ?
  AND s.status = 'PAID'
  AND s.deleted_at IS NULL
  AND (
      s.payment_method_name = ?
      OR EXISTS (
          SELECT 1
          FROM sale_payments sp
          INNER JOIN payment_methods pm ON pm.id = sp.payment_method_id
          WHERE sp.sale_id = s.id AND pm.name = ?
      )
  )
ORDER BY s.created_at DESC, s.id DESC
LIMIT 20
SQL;

        $this->printExplain('Payment-filter page', $sql, [$outletId, $from, $to, $method, $method]);
    }

    private function explainSummaryFacts(string $outletId, string $from, string $to): void
    {
        $sql = <<<'SQL'
SELECT
  SUM(trx_count) trx_count,
  SUM(subtotal_sales) subtotal_sales,
  SUM(discount_total) discount_total,
  SUM(tax_total) tax_total,
  SUM(grand_sales) grand_sales
FROM report_daily_sales_summaries
WHERE outlet_id = ? AND business_date BETWEEN ? AND ?
SQL;

        $this->printExplain('Daily summary', $sql, [$outletId, $from, $to]);
    }

    private function printExplain(string $label, string $sql, array $bindings): void
    {
        try {
            $rows = DB::select('EXPLAIN '.$sql, $bindings);
            if ($rows === []) {
                $this->warn($label.' EXPLAIN returned no rows.');
                return;
            }

            $this->line($label.' EXPLAIN:');
            foreach ($rows as $row) {
                $data = (array) $row;
                $this->line(sprintf(
                    '  table=%s type=%s key=%s rows=%s extra=%s',
                    (string) ($data['table'] ?? '-'),
                    (string) ($data['type'] ?? '-'),
                    (string) ($data['key'] ?? '-'),
                    (string) ($data['rows'] ?? '-'),
                    (string) ($data['Extra'] ?? $data['extra'] ?? '-'),
                ));
            }
        } catch (Throwable $e) {
            $this->warn($label.' EXPLAIN skipped: '.$e->getMessage());
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        } catch (Throwable $e) {
            return false;
        }
    }
}
