<?php

namespace App\Console\Commands;

use App\Services\Warehouse\Billing\WarehouseOutgoingInvoiceRepriceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WarehouseBillingUomIteration02CheckCommand extends Command
{
    protected $signature = 'warehouse:billing-uom:iteration-02-check {--reprice-drafts : Reprice seluruh draft Outgoing Invoice memakai canonical price policy} {--warehouse= : Batasi reprice ke warehouse ULID}';
    protected $description = 'Smoke check Warehouse Iterasi 02 billing UOM integrity.';

    public function handle(WarehouseOutgoingInvoiceRepriceService $reprice): int
    {
        $required = [
            'wh_price_policies_v3' => ['price_uom_id','price_uom_code_snapshot','price_conversion_factor_snapshot','price_basis','price_uom_review_required'],
            'wh_v3_outgoing_invoice_items' => ['billing_uom_id','billing_uom_code_snapshot','billing_conversion_factor_snapshot','billing_qty','unit_price_basis'],
        ];
        $missing = [];
        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) { $missing[] = $table.'.*'; continue; }
            foreach ($columns as $column) if (! Schema::hasColumn($table, $column)) $missing[] = $table.'.'.$column;
        }

        $review = Schema::hasTable('wh_price_policies_v3') && Schema::hasColumn('wh_price_policies_v3', 'price_uom_review_required')
            ? DB::table('wh_price_policies_v3')->where('price_uom_review_required', true)->count() : 0;
        $drafts = Schema::hasTable('wh_v3_outgoing_invoices') ? DB::table('wh_v3_outgoing_invoices')->where('status','draft')->count() : 0;
        $repriced = 0;
        $repriceBlocked = false;
        if ($this->option('reprice-drafts') && $missing === []) {
            if ($review > 0) {
                $repriceBlocked = true;
                $this->warn('Reprice massal diblokir: masih ada price policy yang perlu review UOM. Atur UOM Harga di UI terlebih dahulu.');
            } else {
                $repriced = $reprice->repriceAllDrafts($this->option('warehouse') ?: null, null);
            }
        }

        $this->table(['Check','Result'], [
            ['Missing schema', $missing ? implode(', ', $missing) : '-'],
            ['Price policy perlu review UOM', (string) $review],
            ['Draft Outgoing Invoice', (string) $drafts],
            ['Draft direprice sekarang', (string) $repriced],
            ['Mass reprice diblokir', $repriceBlocked ? 'YA - review UOM dulu' : 'TIDAK'],
            ['Status', $missing ? 'FAILED' : 'OK'],
        ]);
        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
