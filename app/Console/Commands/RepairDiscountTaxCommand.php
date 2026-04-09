<?php

namespace App\Console\Commands;

use App\Services\SaleDiscountTaxRepairService;
use Illuminate\Console\Command;

class RepairDiscountTaxCommand extends Command
{
    protected $signature = 'pos:repair-discount-tax
        {--sale-id= : Repair one sale id}
        {--outlet-id= : Filter by outlet id}
        {--date-from= : Filter created_at date from (YYYY-MM-DD)}
        {--date-to= : Filter created_at date to (YYYY-MM-DD)}
        {--chunk=200 : Chunk size}
        {--limit=0 : Stop after N audited rows (0 = no limit)}
        {--dry-run : Audit only, do not update rows}';

    protected $description = 'Repair stored sales so tax is calculated after discount, while keeping POS sync flow compatible.';

    public function handle(SaleDiscountTaxRepairService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));

        $stats = [
            'audited' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'payment_updates' => 0,
        ];

        $baseQuery = $service->repairScopeQuery([
            'sale_id' => $this->option('sale-id'),
            'outlet_id' => $this->option('outlet-id'),
            'date_from' => $this->option('date-from'),
            'date_to' => $this->option('date-to'),
        ]);

        $stop = false;
        $baseQuery->with('payments')->chunk($chunkSize, function ($sales) use ($service, $dryRun, $limit, &$stats, &$stop) {
            foreach ($sales as $sale) {
                if ($stop) {
                    break;
                }

                $result = $service->repairSale($sale, !$dryRun);
                $stats['audited']++;
                $stats['payment_updates'] += (int) ($result['payment_updates'] ?? 0);

                if ($result['updated']) {
                    $stats['updated']++;
                    $this->line(sprintf(
                        '[UPDATED] %s | tax %d -> %d | grand %d -> %d',
                        $result['sale_number'] ?: $result['sale_id'],
                        $result['recorded_tax_total'],
                        $result['canonical_tax_total'],
                        $result['recorded_grand_total'],
                        $result['canonical_grand_total']
                    ));
                } else {
                    $stats['unchanged']++;
                    if ($dryRun && $result['needs_repair']) {
                        $this->line(sprintf(
                            '[DRY-RUN] %s | tax %d -> %d | grand %d -> %d',
                            $result['sale_number'] ?: $result['sale_id'],
                            $result['recorded_tax_total'],
                            $result['canonical_tax_total'],
                            $result['recorded_grand_total'],
                            $result['canonical_grand_total']
                        ));
                    }
                }

                if ($limit > 0 && $stats['audited'] >= $limit) {
                    $stop = true;
                    break;
                }
            }

            return !$stop;
        });

        $this->newLine();
        $this->info($dryRun ? 'Dry-run summary' : 'Repair summary');
        $this->table(['audited', 'updated', 'unchanged', 'payment_updates'], [[
            $stats['audited'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['payment_updates'],
        ]]);

        return self::SUCCESS;
    }
}
