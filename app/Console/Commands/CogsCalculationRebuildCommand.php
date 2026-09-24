<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Services\Cogs\CogsCalculationService;
use Illuminate\Console\Command;

class CogsCalculationRebuildCommand extends Command
{
    protected $signature = 'cogs:calculation-rebuild
        {--outlet= : Outlet ULID. Kosong berarti seluruh outlet aktif}
        {--date-from= : Tanggal awal periode Y-m-d}
        {--date-to= : Tanggal akhir periode Y-m-d}
        {--reconcile : Jalankan reconciliation gate setelah calculate}
        {--close : Tutup dan kunci periode setelah reconciliation}
        {--confirm-attention : Konfirmasi blocking check secara eksplisit}
        {--notes= : Catatan penutupan bila attention masih ada}';

    protected $description = 'Calculate, reconcile, and optionally close final COGS for one period.';

    public function handle(CogsCalculationService $engine): int
    {
        $from = trim((string) $this->option('date-from'));
        $to = trim((string) $this->option('date-to'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            $this->error('date-from dan date-to wajib format Y-m-d serta date-to tidak boleh lebih kecil.');
            return self::FAILURE;
        }

        $outletOption = trim((string) $this->option('outlet'));
        $outlets = Outlet::query()
            ->when($outletOption !== '', fn ($query) => $query->whereKey($outletOption))
            ->when($outletOption === '', fn ($query) => $query->where('is_active', true))
            ->orderBy('code')->get(['id', 'code', 'name']);
        if ($outlets->isEmpty()) {
            $this->error('Outlet tidak ditemukan.');
            return self::FAILURE;
        }

        $failed = false;
        foreach ($outlets as $outlet) {
            try {
                $run = $engine->calculate((string) $outlet->id, $from, $to, null, 'artisan_rebuild');
                if ($this->option('reconcile') || $this->option('close')) {
                    $run = $engine->reconcile((string) $run->id, null, (bool) $this->option('confirm-attention'));
                }
                if ($this->option('close')) {
                    $run = $engine->close(
                        (string) $run->id,
                        null,
                        (bool) $this->option('confirm-attention'),
                        trim((string) $this->option('notes')) ?: null,
                    );
                }
                $this->line(sprintf(
                    '%s | %s | %s..%s | final=%s | bridge=%s | difference=%s | attention=%d',
                    (string) ($outlet->code ?? $outlet->name),
                    $run->status,
                    $from,
                    $to,
                    (string) $run->final_cogs_value,
                    (string) $run->inventory_bridge_cogs_value,
                    (string) $run->reconciliation_difference,
                    (int) $run->attention_count,
                ));
            } catch (\Throwable $exception) {
                $failed = true;
                $this->error((string) ($outlet->code ?? $outlet->name).': '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
