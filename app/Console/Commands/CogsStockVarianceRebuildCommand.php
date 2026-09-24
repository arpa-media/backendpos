<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Models\StockInventory\StockOpname;
use App\Services\Cogs\StockVarianceService;
use Illuminate\Console\Command;

class CogsStockVarianceRebuildCommand extends Command
{
    protected $signature = 'cogs:stock-variance-rebuild
        {--outlet= : Outlet ULID. Kosong berarti seluruh outlet aktif}
        {--date-from= : Tanggal Stock Opname mulai, format Y-m-d}
        {--date-to= : Tanggal Stock Opname selesai, format Y-m-d}
        {--submit : Submit dan kunci hasil setelah perhitungan}
        {--confirm-attention : Izinkan submit meskipun terdapat exception, harga nol, opening kosong, atau movement SKU tidak terhitung}';

    protected $description = 'Calculate Stock Variance from submitted Stock Opname and optionally submit the immutable result.';

    public function handle(StockVarianceService $engine): int
    {
        $dateFrom = trim((string) $this->option('date-from')) ?: now()->toDateString();
        $dateTo = trim((string) $this->option('date-to')) ?: $dateFrom;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || $dateTo < $dateFrom) {
            $this->error('Gunakan date-from/date-to format Y-m-d dan date-to tidak boleh lebih kecil.');
            return self::FAILURE;
        }

        $outlets = Outlet::query()
            ->when(trim((string) $this->option('outlet')) !== '', fn ($query) => $query->whereKey(trim((string) $this->option('outlet'))))
            ->when(trim((string) $this->option('outlet')) === '', fn ($query) => $query->where('is_active', true))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        if ($outlets->isEmpty()) {
            $this->error('Outlet tidak ditemukan.');
            return self::FAILURE;
        }

        $failed = false;
        foreach ($outlets as $outlet) {
            $opnames = StockOpname::query()
                ->where('outlet_id', $outlet->id)
                ->where('status', 'submitted')
                ->whereBetween('opname_date', [$dateFrom, $dateTo])
                ->orderBy('opname_date')
                ->get();

            $this->newLine();
            $this->info(sprintf('%s — %s (%d opname)', (string) ($outlet->code ?? '-'), (string) $outlet->name, $opnames->count()));

            foreach ($opnames as $opname) {
                try {
                    $variance = $engine->calculateForOpname($opname, null, 'artisan_rebuild');
                    if ($this->option('submit') && $variance->status !== 'submitted') {
                        $variance = $engine->submit(
                            (string) $variance->id,
                            null,
                            (bool) $this->option('confirm-attention'),
                        );
                    }
                    $this->line(sprintf(
                        '%s | %s | shortage=%s | surplus=%s | attention=%d',
                        $opname->opname_date?->format('Y-m-d'),
                        $variance->status,
                        (string) $variance->shortage_value,
                        (string) $variance->surplus_value,
                        (int) $variance->open_exception_count
                            + (int) $variance->zero_cost_sku_count
                            + (int) $variance->missing_opening_sku_count
                            + (int) $variance->uncounted_movement_sku_count,
                    ));
                } catch (\Throwable $exception) {
                    $failed = true;
                    $this->error($opname->opname_date?->format('Y-m-d').': '.$exception->getMessage());
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
