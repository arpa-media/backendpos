<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StockV13I13CheckCommand extends Command
{
    protected $signature = 'stock:v13-i13:check {--outlet= : Kode outlet (opsional)}';
    protected $description = 'Read-only verification of I13 SKU type and opening ledger';

    public function handle(): int
    {
        foreach (['stk_skus', 'stk_opening_stocks', 'stk_inventory_movements', 'stk_inventory_balances',
            'stk_v13_i13_opening_batches', 'stk_v13_i13_opening_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("MISSING TABLE {$table}; migrate terlebih dahulu.");
                return self::FAILURE;
            }
        }
        if (! Schema::hasColumn('stk_skus', 'sku_type')) {
            $this->error('MISSING stk_skus.sku_type');
            return self::FAILURE;
        }
        $outlet = null;
        if (filled($this->option('outlet'))) {
            $outlet = DB::table('outlets')->where('code', strtoupper(trim((string) $this->option('outlet'))))->first(['id', 'code']);
            if (! $outlet) { $this->error('Outlet tidak ditemukan.'); return self::FAILURE; }
        }
        $q = DB::table('stk_opening_stocks as o');
        if ($outlet) $q->where('o.outlet_id', $outlet->id);
        $all = (clone $q)->count();
        $posted = (clone $q)->whereExists(fn ($sub) => $sub->selectRaw('1')
            ->from('stk_inventory_movements as m')
            ->whereColumn('m.outlet_id', 'o.outlet_id')
            ->whereColumn('m.sku_id', 'o.sku_id')
            ->where('m.movement_type', 'opening_stock'))->count();
        $legacy = $all - $posted;
        $batches = DB::table('stk_v13_i13_opening_batches')
            ->when($outlet, fn ($b) => $b->where('outlet_id', $outlet->id))->count();
        $this->table(['Check', 'Result'], [
            ['SKU type', 'READY'], ['Opening records', $all],
            ['Opening with ledger', $posted], ['Legacy without ledger', $legacy],
            ['I13 audit batches', $batches],
        ]);
        if ($legacy > 0) $this->warn('Opening legacy tidak otomatis dikonversi. Rekonsiliasi manual diperlukan sebelum Reopen.');
        $this->info('READ ONLY: Tidak ada saldo/ledger yang diubah.');
        return self::SUCCESS;
    }
}
