<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only operations health check for staged receipts and closed PO proof. */
class WarehouseV13I18CheckCommand extends Command
{
    protected $signature = 'warehouse:v13-i18:check {--po= : Optional Purchase Order ULID} {--max=200 : Maximum states to check}';
    protected $description = 'V13 I18 read-only health check (partial, readiness, ledger and receipt minutes).';

    public function handle(): int
    {
        $tables = ['wh_v13_i18_stock_in_states', 'wh_v13_i18_stock_in_receipts',
            'wh_v13_i18_stock_in_receipt_items', 'wh_stock_ins', 'wh_stock_in_documents'];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                $this->error("BLOCKED: Missing {$table}. Run migration in staging.");
                return self::FAILURE;
            }
        }
        $query = DB::table('wh_v13_i18_stock_in_states as state')
            ->join('wh_supplier_purchase_orders as po', 'po.id', '=', 'state.purchase_order_id')
            ->leftJoin('wh_stock_ins as si', 'si.id', '=', 'state.stock_in_id')
            ->leftJoin('wh_stock_in_documents as doc', 'doc.stock_in_id', '=', 'si.id')
            ->select('po.po_number', 'po.status as po_status', 'state.status as i18_status',
                'si.status as si_status', 'si.ledger_posting_id', 'doc.id as document_id', 'state.purchase_order_id', 'state.stock_in_id');
        if ($this->option('po')) $query->where('state.purchase_order_id', $this->option('po'));
        $rows = $query->orderBy('state.created_at')->limit(min(1000, max(1, (int) $this->option('max'))))->get();
        $issues = 0; $output = [];
        foreach ($rows as $row) {
            $problem = null;
            if ($row->i18_status === 'CLOSED' &&
                ($row->po_status !== 'completed' || !$row->ledger_posting_id || !$row->document_id)) {
                $problem = 'CLOSED without completed PO / ledger / BA';
            }
            if (in_array($row->i18_status, ['PARTIAL', 'READY'], true) &&
                ($row->po_status !== 'stock_in_prepare' || $row->ledger_posting_id || $row->document_id)) {
                $problem = 'Staged receipt has premature ledger/BA or unexpected PO status';
            }
            if (!in_array($row->i18_status, ['PARTIAL', 'READY', 'CLOSED'], true)) {
                $problem = 'Unexpected state';
            }
            $expected = DB::table('wh_stock_in_items')->where('stock_in_id', $row->stock_in_id)->get(['id', 'expected_qty_base']);
            $sums = DB::table('wh_v13_i18_stock_in_receipt_items as l')
                ->join('wh_v13_i18_stock_in_receipts as r', 'r.id', '=', 'l.receipt_id')
                ->where('r.purchase_order_id', $row->purchase_order_id)
                ->groupBy('l.stock_in_item_id')
                ->select('l.stock_in_item_id')->selectRaw('SUM(l.quantity_base) as quantity')->pluck('quantity', 'stock_in_item_id');
            foreach ($expected as $item) {
                $received = (int) round(((float) ($sums[$item->id] ?? 0)) * 10000);
                $target = (int) round(((float) $item->expected_qty_base) * 10000);
                if ($received > $target || ($row->i18_status !== 'PARTIAL' && $received !== $target)) {
                    $problem = 'Receipt quantity mismatch vs expected';
                    break;
                }
            }
            if ($problem) $issues++;
            $output[] = [$row->po_number, $row->i18_status, $row->po_status,
                $row->ledger_posting_id ? 'YES' : 'NO', $row->document_id ? 'YES' : 'NO', $problem ?: 'PASS'];
        }
        $this->table(['PO', 'I18 state', 'PO state', 'Ledger', 'BA', 'Check'], $output);
        if (!$rows->count()) $this->info('No I18 state rows (expected before first partial).');
        $this->line(sprintf('Checked %d row(s), %d issue(s). Read-only.', $rows->count(), $issues));
        return $issues ? self::FAILURE : self::SUCCESS;
    }
}
