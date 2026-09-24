<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV7I02StockOpnameCheckCommand extends Command
{
    protected $signature = 'erp-finance-v7:i02-stock-opname-check {--outlet= : Optional outlet ULID}';

    protected $description = 'Audit immutable Stock Opname snapshots introduced by ERP Finance V7 I02.';

    public function handle(): int
    {
        foreach (['stk_stock_opnames', 'stk_stock_opname_items', 'stk_inventory_movements'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Table {$table} tidak tersedia.");
                return self::FAILURE;
            }
        }

        $query = DB::table('stk_stock_opnames as o')
            ->join('stk_stock_opname_items as i', 'i.stock_opname_id', '=', 'o.id')
            ->leftJoin('stk_inventory_movements as m', function ($join): void {
                $join->on('m.reference_line_id', '=', 'i.id')
                    ->where('m.reference_type', '=', 'stk_stock_opname')
                    ->where('m.movement_type', '=', 'stock_opname_adjustment');
            })
            ->where('o.status', 'submitted');

        $outlet = trim((string) $this->option('outlet'));
        if ($outlet !== '') {
            $query->where('o.outlet_id', $outlet);
        }

        $rows = $query->orderBy('o.opname_date')->orderBy('o.submitted_at')->get([
            'o.id as opname_id', 'o.outlet_id', 'o.opname_date', 'o.submitted_at',
            'i.id as item_id', 'i.sku_id', 'i.actual_qty',
            'm.id as movement_id', 'm.quantity', 'm.balance_qty_after', 'm.metadata',
        ]);

        $missing = 0;
        $mismatch = 0;
        foreach ($rows as $row) {
            if (! $row->movement_id) {
                $missing++;
                $this->warn(sprintf(
                    'LEGACY_NO_SNAPSHOT opname=%s item=%s outlet=%s sku=%s date=%s submitted=%s',
                    $row->opname_id, $row->item_id, $row->outlet_id, $row->sku_id, $row->opname_date, $row->submitted_at
                ));
                continue;
            }

            $metadata = is_string($row->metadata) ? (json_decode($row->metadata, true) ?: []) : (array) $row->metadata;
            $after = round((float) $row->balance_qty_after, 4);
            $actual = round((float) $row->actual_qty, 4);
            $delta = round((float) $row->quantity, 4);
            $before = array_key_exists('qty_before_authoritative', $metadata)
                ? round((float) $metadata['qty_before_authoritative'], 4)
                : round($after - $delta, 4);

            if (abs($after - $actual) > 0.0001 || abs(($after - $before) - $delta) > 0.0001) {
                $mismatch++;
                $this->error(sprintf(
                    'SNAPSHOT_MISMATCH opname=%s item=%s sku=%s before=%.4f delta=%.4f after=%.4f actual=%.4f',
                    $row->opname_id, $row->item_id, $row->sku_id, $before, $delta, $after, $actual
                ));
            }
        }

        $snapshots = $rows->count() - $missing;
        $this->newLine();
        $this->table(
            ['Submitted Items', 'Persisted Snapshots', 'Legacy Fallback', 'Mismatch'],
            [[$rows->count(), $snapshots, $missing, $mismatch]]
        );

        if ($mismatch > 0) {
            $this->error('Ditemukan snapshot mismatch. Jangan melakukan backfill otomatis sebelum data sumber diverifikasi.');
            return self::FAILURE;
        }

        $this->info($missing > 0
            ? 'PASS dengan legacy fallback: item lama tanpa snapshot akan direkonstruksi pada timestamp submit oleh I02.'
            : 'PASS: seluruh submitted Stock Opname memiliki snapshot konsisten.');

        return self::SUCCESS;
    }
}
