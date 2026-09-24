<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosFinalI04StockInventoryCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i04-stock-inventory-check {--strict-due : Fail when Stock Request due for 06:00 auto approval remains pending}';

    protected $description = 'Verify ERP POS FINAL I04 Stock Inventory receiving window, opening stock, and 06:00 auto approval invariants.';

    public function handle(): int
    {
        $failures = [];
        $warnings = [];

        foreach (['stk_opening_stocks', 'stk_goods_receipts', 'stk_inventory_balances', 'stk_inventory_movements', 'stk_requests'] as $table) {
            if (! Schema::hasTable($table)) {
                $failures[] = "Missing table: {$table}";
            }
        }

        foreach ([
            'delivery_not_before_at',
            'delivery_reference_type',
            'delivery_reference_id',
            'delivery_reference_number',
            'received_at_input_by_user_id',
            'received_at_input_at',
        ] as $column) {
            if (Schema::hasTable('stk_goods_receipts') && ! Schema::hasColumn('stk_goods_receipts', $column)) {
                $failures[] = "Missing stk_goods_receipts.{$column}";
            }
        }

        $openingCount = Schema::hasTable('stk_opening_stocks') ? (int) DB::table('stk_opening_stocks')->count() : 0;
        $openingValue = Schema::hasTable('stk_opening_stocks') ? (float) DB::table('stk_opening_stocks')->sum('inventory_value') : 0.0;
        $this->line(sprintf('Opening Stock rows       : %d', $openingCount));
        $this->line(sprintf('Opening inventory value  : %.2f', $openingValue));

        $openingMovementRows = 0;
        if (Schema::hasTable('stk_inventory_movements')) {
            $openingMovementRows = (int) DB::table('stk_inventory_movements')
                ->where(function ($query): void {
                    $query->where('reference_type', 'stk_opening_stock')
                        ->orWhere('movement_type', 'opening_stock');
                })
                ->count();
            if ($openingMovementRows > 0) {
                $failures[] = "Opening Stock leaked into stk_inventory_movements ({$openingMovementRows} row(s)).";
            }
        }
        $this->line("Opening movement rows     : {$openingMovementRows} (expected 0)");

        $invalidReceiving = 0;
        if (Schema::hasTable('stk_goods_receipts') && Schema::hasColumn('stk_goods_receipts', 'delivery_not_before_at')) {
            $invalidReceiving = (int) DB::table('stk_goods_receipts')
                ->where('receipt_type', 'warehouse')
                ->whereNotNull('received_at')
                ->whereNotNull('delivery_not_before_at')
                ->whereColumn('received_at', '<', 'delivery_not_before_at')
                ->count();
            if ($invalidReceiving > 0) {
                $failures[] = "Warehouse Goods Receipt before delivery lower bound: {$invalidReceiving}.";
            }
        }
        $this->line("Invalid receive timestamps: {$invalidReceiving} (expected 0)");

        $duePending = 0;
        if (Schema::hasTable('stk_requests')) {
            $cutoff = Carbon::now('Asia/Jakarta')->startOfDay()->utc();
            $duePending = (int) DB::table('stk_requests')
                ->where('request_channel', 'warehouse_operations')
                ->where('status', 'submitted')
                ->where('request_approval_status', 'awaiting_approval1')
                ->whereNotNull('submitted_at')
                ->where('submitted_at', '<', $cutoff)
                ->count();

            if ($duePending > 0) {
                $message = "Stock Request from prior business day still pending SPV: {$duePending}. Run warehouse:stock-request-auto-approve or verify scheduler.";
                if ($this->option('strict-due') && Carbon::now('Asia/Jakarta')->format('H:i') >= '06:05') {
                    $failures[] = $message;
                } else {
                    $warnings[] = $message;
                }
            }
        }
        $this->line("Due pending auto-approve  : {$duePending}");

        $actor = Schema::hasTable('users')
            ? DB::table('users')->where('email', 'system-auto-approval@pos.local.invalid')->first(['id', 'name', 'is_active'])
            : null;
        if ($actor) {
            $this->line(sprintf('System approval actor     : %s / active=%s', (string) $actor->name, (int) $actor->is_active));
            if ((string) $actor->name !== 'SYSTEM_AUTO_APPROVAL' || (int) $actor->is_active !== 0) {
                $failures[] = 'SYSTEM_AUTO_APPROVAL actor must remain disabled and explicitly named.';
            }
        } else {
            $warnings[] = 'SYSTEM_AUTO_APPROVAL user has not been created yet; it is created lazily on the first non-dry auto-approval run.';
            $this->line('System approval actor     : not created yet (lazy)');
        }

        foreach ($warnings as $warning) {
            $this->warn('WARN: '.$warning);
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error('FAIL: '.$failure);
            }
            $this->newLine();
            $this->error('ERP POS FINAL I04 validation FAILED.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS FINAL I04 validation PASS.');
        return self::SUCCESS;
    }
}
