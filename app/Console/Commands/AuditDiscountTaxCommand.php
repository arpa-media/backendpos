<?php

namespace App\Console\Commands;

use App\Services\SaleDiscountTaxRepairService;
use Illuminate\Console\Command;

class AuditDiscountTaxCommand extends Command
{
    protected $signature = 'pos:audit-discount-tax
        {--sale-id= : Audit one sale id}
        {--outlet-id= : Filter by outlet id}
        {--date-from= : Filter created_at date from (YYYY-MM-DD)}
        {--date-to= : Filter created_at date to (YYYY-MM-DD)}
        {--limit=200 : Max audited rows}
        {--only-mismatch : Show only rows that need repair}
        {--json : Emit JSON lines instead of table}';

    protected $description = 'Audit recorded sales tax/discount totals against canonical rule: tax is calculated after discount.';

    public function handle(SaleDiscountTaxRepairService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $onlyMismatch = (bool) $this->option('only-mismatch');
        $useJson = (bool) $this->option('json');

        $rows = [];
        $stats = [
            'audited' => 0,
            'needs_repair' => 0,
            'matches_legacy_tax_before_discount' => 0,
        ];

        $query = $service->repairScopeQuery([
            'sale_id' => $this->option('sale-id'),
            'outlet_id' => $this->option('outlet-id'),
            'date_from' => $this->option('date-from'),
            'date_to' => $this->option('date-to'),
        ])->limit($limit);

        $query->with('payments')->get()->each(function ($sale) use ($service, $onlyMismatch, $useJson, &$rows, &$stats) {
            $audit = $service->auditSale($sale);
            $stats['audited']++;
            if ($audit['needs_repair']) {
                $stats['needs_repair']++;
            }
            if ($audit['matches_legacy_tax_before_discount']) {
                $stats['matches_legacy_tax_before_discount']++;
            }

            if ($onlyMismatch && !$audit['needs_repair']) {
                return;
            }

            if ($useJson) {
                $this->line(json_encode($audit, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }

            $rows[] = [
                $audit['sale_number'] ?: $audit['sale_id'],
                $audit['subtotal'],
                $audit['discount_total'],
                $audit['tax_percent_snapshot'],
                $audit['recorded_tax_total'],
                $audit['canonical_tax_total'],
                $audit['recorded_grand_total'],
                $audit['canonical_grand_total'],
                $audit['needs_repair'] ? 'YES' : 'NO',
                $audit['matches_legacy_tax_before_discount'] ? 'LEGACY' : '-',
            ];
        });

        if (!$useJson) {
            if (!empty($rows)) {
                $this->table([
                    'Sale', 'Subtotal', 'Discount', 'Tax %', 'Tax DB', 'Tax Canonical', 'Grand DB', 'Grand Canonical', 'Repair', 'Hint'
                ], $rows);
            } else {
                $this->info('No audit rows matched the given filters.');
            }
        }

        $this->newLine();
        $this->info('Audit summary');
        $this->table(['audited', 'needs_repair', 'legacy_tax_before_discount'], [[
            $stats['audited'],
            $stats['needs_repair'],
            $stats['matches_legacy_tax_before_discount'],
        ]]);

        return self::SUCCESS;
    }
}
