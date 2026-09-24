<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Services\Cogs\SaleConsumptionService;
use App\Support\TransactionDate;
use Illuminate\Console\Command;

class CogsItemSoldRebuildCommand extends Command
{
    protected $signature = 'cogs:item-sold-rebuild
        {--outlet= : Outlet ULID. Kosong berarti seluruh outlet aktif}
        {--date-from= : Business date mulai, format Y-m-d}
        {--date-to= : Business date selesai, format Y-m-d}';

    protected $description = 'Reconcile paid sale items, recipe consumption, and void/cancel reversals idempotently.';

    public function handle(SaleConsumptionService $engine): int
    {
        $dateFrom = trim((string) $this->option('date-from'));
        $dateTo = trim((string) $this->option('date-to'));
        if ($dateFrom === '') {
            $dateFrom = TransactionDate::businessTodayDateString();
        }
        if ($dateTo === '') {
            $dateTo = $dateFrom;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || $dateTo < $dateFrom) {
            $this->error('Gunakan date-from/date-to format Y-m-d dan date-to tidak boleh lebih kecil.');
            return self::FAILURE;
        }

        $query = Outlet::query()->orderBy('code')->orderBy('name');
        $outletId = trim((string) $this->option('outlet'));
        if ($outletId !== '') {
            $query->whereKey($outletId);
        } else {
            $query->where('is_active', true);
        }
        $outlets = $query->get(['id', 'code', 'name']);
        if ($outlets->isEmpty()) {
            $this->error('Outlet tidak ditemukan.');
            return self::FAILURE;
        }

        $failed = false;
        foreach ($outlets as $outlet) {
            $this->newLine();
            $this->info(sprintf('%s — %s', (string) ($outlet->code ?? '-'), (string) $outlet->name));
            $result = $engine->rebuildRange((string) $outlet->id, $dateFrom, $dateTo, null, 'artisan_rebuild');
            $this->table(['Metric', 'Value'], [
                ['Scanned', (string) ($result['scanned'] ?? $result['processed'])],
                ['Processed', (string) $result['processed']],
                ['Posted', (string) $result['posted']],
                ['Reversed', (string) $result['reversed']],
                ['Exceptions', (string) $result['exceptions']],
                ['Skipped', (string) $result['skipped']],
                ['Failed', (string) ($result['failed'] ?? count($result['errors']))],
                ['Duration', number_format(((int) ($result['duration_ms'] ?? 0)) / 1000, 1).' detik'],
            ]);
            if ($result['errors'] !== []) {
                foreach (array_slice($result['errors'], 0, 20) as $error) {
                    $this->warn(($error['sale_item_id'] ?? '-').': '.($error['message'] ?? 'Unknown error'));
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
