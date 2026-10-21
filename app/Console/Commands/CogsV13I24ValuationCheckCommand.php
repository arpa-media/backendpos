<?php

namespace App\Console\Commands;

use App\Services\Cogs\V13\StockValuationI24Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** A read-only, bounded sampling check; it is not a GL reconciliation. */
final class CogsV13I24ValuationCheckCommand extends Command
{
    protected $signature = 'cogs:v13-i24:check {--source=inventory : inventory or warehouse} {--outlet= : Outlet CODE or ID} {--limit=50 : Max sample rows, 5..50}';
    protected $description = 'Read-only sample of valuation and basic anomaly indicators (V13 I24).';

    public function handle(StockValuationI24Service $service): int
    {
        $source = (string) $this->option('source');
        if (! in_array($source, [StockValuationI24Service::INVENTORY, StockValuationI24Service::WAREHOUSE], true)) {
            $this->error('Source hanya inventory atau warehouse.');
            return self::FAILURE;
        }
        $outletInput = trim((string) ($this->option('outlet') ?? ''));
        $outletId = null;
        if ($outletInput !== '') {
            $outletId = DB::table('outlets')->where('code', $outletInput)->orWhere('id',$outletInput)->value('id');
            if (! $outletId) { $this->error('Outlet tidak ditemukan.'); return self::FAILURE; }
        }
        $limit = max(5, min(50,(int) $this->option('limit')));
        try {
            $result = $service->overview($source, null, $outletId, '', true, 1, $limit);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info('V13 I24 READ-ONLY valuation sample');
        $this->line('Source          : '.$source);
        $this->line('Outlet          : '.($outletInput !== '' ? $outletInput : 'ALL'));
        $this->line('Outlet-SKU rows : '.$result['summary']['sku_outlet_rows']);
        $this->line('Projection value: '.number_format($result['summary']['inventory_value'],2,'.',','));
        $this->line('Sample size     : '.count($result['items']).' / max '.$limit);
        $sampleFlags = 0;
        foreach ($result['items'] as $item) {
            if (!$item['quality_flags']) continue;
            $sampleFlags++;
            $this->warn($item['outlet_code'].' | '.$item['sku_code'].' | '.implode(', ', $item['quality_flags']));
        }
        $this->line('Sample flagged  : '.$sampleFlags);
        $this->comment('Pemeriksaan hanya atas sampel halaman pertama; bukan bukti semua saldo/GL sudah rekonsiliasi.');
        $this->comment('Tidak ada perubahan data.');
        return self::SUCCESS;
    }
}
