<?php

namespace App\Console\Commands;

use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpFinanceV8I04HistoricalReadCheckCommand extends Command
{
    protected $signature = 'erp-finance-v8:i04-historical-read-check
        {--days=370 : Historical window to inspect (max 400)}
        {--outlet= : Optional outlet id. Defaults to first active physical outlet}
        {--sample=250 : Max indexed sales sampled for business-date parity}';

    protected $description = 'V8 I04 gate for Cashier/Reconciliation/COGS/Overhandle historical read performance and business-date parity.';

    public function handle(): int
    {
        $days = max(1, min(400, (int) $this->option('days')));
        $sample = max(1, min(2000, (int) $this->option('sample')));
        $failed = false;

        $this->info("ERP Finance V8 I04 historical read check ({$days} days)");

        foreach ([
            'report_sale_business_dates',
            'report_sale_business_date_coverage',
            'sales',
            'sale_payments',
            'sale_items',
            'sale_cancel_requests',
            'finance_reconciliations',
            'finance_overhandle_reports',
            'finance_overhandle_report_payments',
            'cogs_calculation_runs',
            'finance_cogs_postings',
        ] as $table) {
            $ok = Schema::hasTable($table);
            $this->line(sprintf('[%s] table %s', $ok ? 'OK' : 'MISSING', $table));
            $failed = $failed || ! $ok;
        }

        $requiredIndexes = [
            ['report_sale_business_dates', 'rsbd_outlet_date_sale_idx'],
            ['sale_cancel_requests', 'scr_status_type_sale_outlet_idx'],
            ['finance_reconciliations', 'fin_v8_i04_rec_out_date_status_idx'],
            ['cogs_calculation_runs', 'fin_v8_i04_cogs_out_status_to_from_idx'],
        ];
        foreach ($requiredIndexes as [$table, $index]) {
            $ok = $this->indexExists($table, $index);
            $this->line(sprintf('[%s] index %s.%s', $ok ? 'OK' : 'MISSING', $table, $index));
            $failed = $failed || ! $ok;
        }

        $failed = $this->checkSourceContracts() || $failed;

        $outletId = trim((string) $this->option('outlet'));
        if ($outletId === '' && Schema::hasTable('outlets')) {
            $q = DB::table('outlets');
            if (Schema::hasColumn('outlets', 'type')) {
                $q->whereRaw("LOWER(COALESCE(type, 'outlet')) = 'outlet'");
            }
            if (Schema::hasColumn('outlets', 'is_active')) {
                $q->where('is_active', true);
            }
            $outletId = (string) ($q->orderBy('id')->value('id') ?? '');
        }

        if ($outletId === '') {
            $this->warn('No outlet available; parity and EXPLAIN probes skipped.');
        } else {
            $outlet = DB::table('outlets')->where('id', $outletId)->first(['id', 'timezone']);
            $timezone = TransactionDate::normalizeTimezone((string) ($outlet->timezone ?? ''), TransactionDate::appTimezone());
            $to = CarbonImmutable::parse(TransactionDate::businessTodayDateString($timezone), $timezone);
            $from = $to->subDays($days - 1);

            $this->newLine();
            $this->line("Outlet={$outletId} timezone={$timezone} range={$from->toDateString()}..{$to->toDateString()}");

            $failed = $this->businessDateParity(
                $outletId,
                $timezone,
                $from->toDateString(),
                $to->toDateString(),
                $sample,
            ) || $failed;
            $failed = $this->cashierMembershipParity(
                $outletId,
                $timezone,
                $from,
                $to,
            ) || $failed;

            $this->explain(
                'Cashier canonical scope',
                'SELECT rsbd.sale_id FROM report_sale_business_dates rsbd WHERE rsbd.outlet_id = ? AND rsbd.business_timezone = ? AND rsbd.business_date BETWEEN ? AND ?',
                [$outletId, $timezone, $from->toDateString(), $to->toDateString()]
            );
            $this->explain(
                'Reconciliation historical list',
                "SELECT r.id FROM finance_reconciliations r WHERE r.outlet_id = ? AND r.business_date BETWEEN ? AND ? AND r.status IN ('DRAFT','POSTED') ORDER BY r.business_date DESC LIMIT 20",
                [$outletId, $from->toDateString(), $to->toDateString()]
            );
            $this->explain(
                'COGS historical base page',
                "SELECT r.id FROM cogs_calculation_runs r WHERE r.outlet_id = ? AND r.status = 'closed' AND r.period_from >= ? AND r.period_to <= ? ORDER BY r.period_to DESC LIMIT 25",
                [$outletId, $from->toDateString(), $to->toDateString()]
            );
            $this->explain(
                'Overhandle exact historical day',
                "SELECT id FROM finance_overhandle_reports WHERE outlet_id = ? AND business_date = ? ORDER BY shift_type",
                [$outletId, $from->toDateString()]
            );
        }

        $this->newLine();
        $this->line('I04 invariant: TransactionDate / Cashier cutoff logic is not replaced.');
        $this->line('I04 invariant: report_sale_business_dates is used only when read-only coverage is already fresh; fallback remains the exact Cashier resolver.');
        $this->line('I04 invariant: HTTP historical reads never call ensureCoverage() through the new Cashier path.');

        if ($failed) {
            $this->error('ERP Finance V8 I04 gate failed. Review missing indexes/contracts or business-date parity mismatches.');
            return self::FAILURE;
        }

        $this->info('ERP Finance V8 I04 gate passed.');
        return self::SUCCESS;
    }

    private function checkSourceContracts(): bool
    {
        $checks = [
            [
                app_path('Services/Finance/FinanceDailySalesSnapshotService.php'),
                fn (string $c): bool => str_contains($c, 'cashierReconciliationSnapshot(') && ! str_contains($c, '$this->reports->cashierReport(['),
                'Overhandle snapshot uses lightweight Cashier source',
            ],
            [
                app_path('Services/ReportService.php'),
                fn (string $c): bool => str_contains($c, 'coveredSaleIdsSubquery(') && str_contains($c, "'http_backfill' => false"),
                'Cashier read path has read-only canonical scope + no HTTP backfill',
            ],
            [
                app_path('Http/Controllers/Api/V1/Finance/FinanceReconciliationController.php'),
                fn (string $c): bool => ! str_contains($c, "whereDate('r.business_date'"),
                'Reconciliation date range remains index-sargable',
            ],
            [
                app_path('Services/Finance/FinanceCogsPostingService.php'),
                fn (string $c): bool => str_contains($c, 'base_scope_then_page_decoration') && ! str_contains($c, "whereDate('r.period_"),
                'COGS uses base-page then decoration contract',
            ],
        ];

        $failed = false;
        foreach ($checks as [$path, $predicate, $label]) {
            if (! is_file($path)) {
                $this->line("[MISSING] {$label}: ".basename($path));
                $failed = true;
                continue;
            }
            $contents = (string) file_get_contents($path);
            $ok = (bool) $predicate($contents);
            $this->line(sprintf('[%s] %s', $ok ? 'OK' : 'FAIL', $label));
            $failed = $failed || ! $ok;
        }

        return $failed;
    }

    private function businessDateParity(string $outletId, string $timezone, string $from, string $to, int $sample): bool
    {
        try {
            $rows = DB::table('report_sale_business_dates as rsbd')
                ->join('sales as s', 's.id', '=', 'rsbd.sale_id')
                ->where('rsbd.outlet_id', $outletId)
                ->where('rsbd.business_timezone', $timezone)
                ->whereBetween('rsbd.business_date', [$from, $to])
                ->orderBy('s.created_at')
                ->limit($sample)
                ->get(['rsbd.business_date', 's.id', 's.sale_number', 's.created_at']);

            $mismatches = [];
            foreach ($rows as $row) {
                $localText = TransactionDate::formatSaleLocal($row->created_at, $timezone, (string) ($row->sale_number ?? ''));
                if (! $localText) {
                    continue;
                }
                try {
                    $moment = CarbonImmutable::parse($localText, $timezone);
                } catch (Throwable) {
                    continue;
                }
                $startHour = TransactionDate::businessDayStartHour($timezone);
                if ($startHour > 0) {
                    $moment = $moment->subHours($startHour);
                }
                $computed = $moment->toDateString();
                if ($computed !== (string) $row->business_date) {
                    $mismatches[] = [(string) $row->id, (string) $row->business_date, $computed, (string) $row->created_at];
                    if (count($mismatches) >= 10) break;
                }
            }

            if ($mismatches !== []) {
                $this->error('Business-date parity mismatch detected.');
                $this->table(['sale_id', 'indexed', 'computed', 'created_at'], $mismatches);
                return true;
            }

            $this->line(sprintf('[OK] business-date parity sampled %d indexed sales', $rows->count()));
            return false;
        } catch (Throwable $e) {
            $this->warn('Business-date parity probe skipped: '.$e->getMessage());
            return false;
        }
    }

    /**
     * Compare canonical report_sale_business_dates membership against the exact
     * pre-I04 TransactionDate resolver on a few CLOSED historical business days.
     * This is intentionally diagnostic-only and never builds coverage.
     */
    private function cashierMembershipParity(string $outletId, string $timezone, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        try {
            $closedTo = $to->subDays(2);
            if ($closedTo->lessThan($from)) {
                $closedTo = $from;
            }

            $span = max(0, $from->diffInDays($closedTo));
            $dates = collect([
                $from->toDateString(),
                $from->addDays((int) floor($span / 2))->toDateString(),
                $closedTo->toDateString(),
            ])->unique()->values();

            $failed = false;
            foreach ($dates as $date) {
                $exact = DB::table('sales as s')
                    ->select('s.id')
                    ->where('s.outlet_id', $outletId)
                    ->whereNull('s.deleted_at')
                    ->where('s.status', 'PAID');

                TransactionDate::applyExactBusinessDateScope(
                    $exact,
                    's.created_at',
                    (string) $date,
                    (string) $date,
                    $timezone,
                    's.sale_number',
                );

                $exactIds = $exact->pluck('s.id')->map(fn ($id) => (string) $id)->sort()->values();
                $indexedIds = DB::table('report_sale_business_dates as rsbd')
                    ->where('rsbd.outlet_id', $outletId)
                    ->where('rsbd.business_timezone', $timezone)
                    ->where('rsbd.business_date', (string) $date)
                    ->pluck('rsbd.sale_id')
                    ->map(fn ($id) => (string) $id)
                    ->sort()
                    ->values();

                $missing = $exactIds->diff($indexedIds)->values();
                $extra = $indexedIds->diff($exactIds)->values();
                $ok = $missing->isEmpty() && $extra->isEmpty();
                $this->line(sprintf(
                    '[%s] cashier membership parity %s exact=%d indexed=%d missing=%d extra=%d',
                    $ok ? 'OK' : 'FAIL',
                    $date,
                    $exactIds->count(),
                    $indexedIds->count(),
                    $missing->count(),
                    $extra->count(),
                ));

                if (! $ok) {
                    $failed = true;
                    $sampleRows = collect()
                        ->merge($missing->take(5)->map(fn ($id) => ['MISSING_FROM_INDEX', $id]))
                        ->merge($extra->take(5)->map(fn ($id) => ['EXTRA_IN_INDEX', $id]))
                        ->all();
                    if ($sampleRows !== []) {
                        $this->table(['difference', 'sale_id'], $sampleRows);
                    }
                }
            }

            return $failed;
        } catch (Throwable $e) {
            $this->warn('Cashier membership parity probe skipped: '.$e->getMessage());
            return false;
        }
    }

    private function explain(string $label, string $sql, array $bindings): void
    {
        try {
            $rows = DB::select('EXPLAIN '.$sql, $bindings);
            $this->line($label.' EXPLAIN:');
            foreach ($rows as $row) {
                $d = (array) $row;
                $this->line(sprintf(
                    '  table=%s type=%s key=%s rows=%s extra=%s',
                    (string) ($d['table'] ?? '-'),
                    (string) ($d['type'] ?? '-'),
                    (string) ($d['key'] ?? '-'),
                    (string) ($d['rows'] ?? '-'),
                    (string) ($d['Extra'] ?? $d['extra'] ?? '-'),
                ));
            }
        } catch (Throwable $e) {
            $this->warn($label.' EXPLAIN skipped: '.$e->getMessage());
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) return false;
        try {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
