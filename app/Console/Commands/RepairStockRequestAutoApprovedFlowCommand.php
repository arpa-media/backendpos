<?php

namespace App\Console\Commands;

use App\Models\Purchasing\FundRequest;
use App\Models\StockInventory\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairStockRequestAutoApprovedFlowCommand extends Command
{
    protected $signature = 'purchasing:repair-stock-request-auto-approved {--execute : Terapkan perbaikan data existing}';
    protected $description = 'Audit/backfill PR dan PO Stock Request agar auto-approved dan snapshot item lengkap.';

    public function handle(): int
    {
        $required = ['stk_requests', 'stk_request_items', 'pur_fund_requests', 'pur_purchase_orders', 'pur_purchase_order_items'];
        $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            $this->error('Missing tables: ' . implode(', ', $missing));
            return self::FAILURE;
        }

        $fundRequests = DB::table('pur_fund_requests')
            ->where('source_type', 'STOCK_INVENTORY_REQUEST')
            ->count();
        $stockOrders = DB::table('pur_purchase_orders')
            ->where('order_type', 'STOCK')
            ->count();
        $blankItems = DB::table('pur_purchase_order_items as poi')
            ->join('pur_purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('po.order_type', 'STOCK')
            ->where(function ($query): void {
                if (Schema::hasColumn('pur_purchase_order_items', 'item_name')) {
                    $query->whereNull('poi.item_name')->orWhere('poi.item_name', '');
                } else {
                    $query->whereRaw('1 = 1');
                }
            })->count();

        $this->table(['Check', 'Result'], [
            ['Stock PR', (string) $fundRequests],
            ['Stock PO', (string) $stockOrders],
            ['PO item blank', (string) $blankItems],
            ['Mode', $this->option('execute') ? 'EXECUTE' : 'DRY-RUN'],
        ]);

        if (! $this->option('execute')) {
            $this->warn('Dry-run. Tambahkan --execute untuk memperbaiki data existing.');
            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            $now = now();

            $fundUpdate = [
                'status' => FundRequest::STATUS_APPROVED,
                'updated_at' => $now,
            ];
            DB::table('pur_fund_requests')
                ->where('source_type', 'STOCK_INVENTORY_REQUEST')
                ->whereNotIn('status', [FundRequest::STATUS_REJECTED])
                ->update($fundUpdate);

            $poUpdate = [
                'status' => PurchaseOrder::STATUS_APPROVED,
                'updated_at' => $now,
            ];
            foreach (['submitted_at', 'finance_approved_1_at', 'finance_approved_2_at', 'approved_at'] as $column) {
                if (Schema::hasColumn('pur_purchase_orders', $column)) {
                    $poUpdate[$column] = $now;
                }
            }
            DB::table('pur_purchase_orders')
                ->where('order_type', 'STOCK')
                ->update($poUpdate);

            $rows = DB::table('pur_purchase_order_items as poi')
                ->join('pur_purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
                ->leftJoin('stk_request_items as sri', 'sri.id', '=', 'poi.stock_request_item_id')
                ->leftJoin('stk_skus as sku', 'sku.id', '=', 'poi.sku_id')
                ->where('po.order_type', 'STOCK')
                ->select([
                    'poi.id',
                    'poi.sku_id',
                    'sri.id as request_item_id',
                    'sri.notes as request_notes',
                    'sri.base_uom_code_snapshot',
                    'sri.request_uom_code_snapshot',
                    'sku.sku_code',
                    'sku.name as sku_name',
                ])->get();

            foreach ($rows as $row) {
                $update = [];
                if (Schema::hasColumn('pur_purchase_order_items', 'item_name')) {
                    $update['item_name'] = trim((string) ($row->sku_name ?: $row->sku_code ?: $row->sku_id));
                }
                if (Schema::hasColumn('pur_purchase_order_items', 'uom_text')) {
                    $update['uom_text'] = trim((string) ($row->base_uom_code_snapshot ?: $row->request_uom_code_snapshot ?: 'UNIT'));
                }
                if (Schema::hasColumn('pur_purchase_order_items', 'source_line_key')) {
                    $update['source_line_key'] = (string) ($row->request_item_id ?: '');
                }
                if (Schema::hasColumn('pur_purchase_order_items', 'notes')) {
                    $update['notes'] = $row->request_notes;
                }
                if ($update !== []) {
                    $update['updated_at'] = $now;
                    DB::table('pur_purchase_order_items')->where('id', $row->id)->update($update);
                }
            }

            DB::table('stk_requests')
                ->whereNotNull('canonical_fund_request_id')
                ->whereNotNull('draft_purchase_order_id')
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->update([
                    'status' => 'requested',
                    'request_approval_status' => 'approved1',
                    'purchasing_handoff_status' => 'approved',
                    'updated_at' => $now,
                ]);
        }, 3);

        $this->info('Data Stock Request, PR, dan PO existing berhasil diselaraskan.');
        return self::SUCCESS;
    }
}
