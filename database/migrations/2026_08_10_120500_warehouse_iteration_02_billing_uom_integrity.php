<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['stk_skus', 'stk_uoms', 'wh_price_policies_v3', 'wh_v3_outgoing_invoices', 'wh_v3_outgoing_invoice_items'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse Iterasi 02 membutuhkan tabel {$table}. Apply baseline Warehouse v3 terlebih dahulu.");
            }
        }

        $this->extendCanonicalPricePolicies();
        $this->extendOutgoingInvoiceItems();
        $this->backfillPricePolicyUomSafely();
        $this->backfillDraftOutgoingInvoiceSnapshotsSafely();
    }

    private function extendCanonicalPricePolicies(): void
    {
        $columns = [
            'price_uom_id' => fn (Blueprint $table) => $table->ulid('price_uom_id')->nullable()->after('sku_id')->index('wh_price_v3_price_uom_idx'),
            'price_uom_code_snapshot' => fn (Blueprint $table) => $table->string('price_uom_code_snapshot', 40)->nullable()->after('price_uom_id'),
            'price_conversion_factor_snapshot' => fn (Blueprint $table) => $table->decimal('price_conversion_factor_snapshot', 24, 8)->default(1)->after('price_uom_code_snapshot'),
            'price_basis' => fn (Blueprint $table) => $table->string('price_basis', 30)->default('PER_PRICE_UOM')->after('price_conversion_factor_snapshot'),
            'price_uom_review_required' => fn (Blueprint $table) => $table->boolean('price_uom_review_required')->default(false)->after('price_basis')->index('wh_price_v3_uom_review_idx'),
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn('wh_price_policies_v3', $column)) continue;
            Schema::table('wh_price_policies_v3', function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }
    }

    private function extendOutgoingInvoiceItems(): void
    {
        if (! Schema::hasTable('wh_v3_outgoing_invoice_items')) return;

        $columns = [
            'billing_uom_id' => fn (Blueprint $table) => $table->ulid('billing_uom_id')->nullable()->after('sku_id')->index('whv3_oii_bill_uom_idx'),
            'billing_uom_code_snapshot' => fn (Blueprint $table) => $table->string('billing_uom_code_snapshot', 40)->nullable()->after('billing_uom_id'),
            'billing_conversion_factor_snapshot' => fn (Blueprint $table) => $table->decimal('billing_conversion_factor_snapshot', 24, 8)->default(1)->after('billing_uom_code_snapshot'),
            'billing_qty' => fn (Blueprint $table) => $table->decimal('billing_qty', 20, 4)->default(0)->after('billing_conversion_factor_snapshot'),
            'unit_price_basis' => fn (Blueprint $table) => $table->string('unit_price_basis', 30)->default('PER_PRICE_UOM')->after('unit_price'),
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn('wh_v3_outgoing_invoice_items', $column)) continue;
            Schema::table('wh_v3_outgoing_invoice_items', function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }
    }

    private function backfillPricePolicyUomSafely(): void
    {
        DB::table('wh_price_policies_v3')
            ->orderBy('id')
            ->chunk(250, function ($policies): void {
                foreach ($policies as $policy) {
                    $sku = DB::table('stk_skus')->where('id', $policy->sku_id)->first([
                        'base_uom_id', 'purchase_uom_id', 'purchase_conversion_factor',
                    ]);
                    if (! $sku || ! $sku->base_uom_id) continue;

                    $base = DB::table('stk_uoms')->where('id', $sku->base_uom_id)->first(['id', 'code']);
                    if (! $base) continue;

                    $purchaseAlternative = $sku->purchase_uom_id
                        && (string) $sku->purchase_uom_id !== (string) $sku->base_uom_id
                        && abs((float) $sku->purchase_conversion_factor - 1.0) > 0.00000001;
                    $mappedAlternative = Schema::hasTable('wh_sku_uoms')
                        && DB::table('wh_sku_uoms')
                            ->where('sku_id', $policy->sku_id)
                            ->where('is_active', true)
                            ->where('uom_id', '<>', $sku->base_uom_id)
                            ->where('conversion_factor', '>', 0)
                            ->whereRaw('ABS(conversion_factor - 1) > 0.00000001')
                            ->exists();
                    $needsReview = $purchaseAlternative || $mappedAlternative;

                    DB::table('wh_price_policies_v3')->where('id', $policy->id)->update([
                        'price_uom_id' => $policy->price_uom_id ?: $base->id,
                        'price_uom_code_snapshot' => $policy->price_uom_code_snapshot ?: $base->code,
                        'price_conversion_factor_snapshot' => (float) ($policy->price_conversion_factor_snapshot ?: 1),
                        'price_basis' => $policy->price_basis ?: 'PER_PRICE_UOM',
                        // Existing price had no UOM dimension. Do not guess PAX/BOX/KG automatically.
                        'price_uom_review_required' => $policy->price_uom_id ? (bool) $policy->price_uom_review_required : $needsReview,
                        'updated_at' => $policy->updated_at ?: now(),
                    ]);
                }
            });
    }

    private function backfillDraftOutgoingInvoiceSnapshotsSafely(): void
    {
        if (! Schema::hasTable('wh_v3_outgoing_invoice_items') || ! Schema::hasTable('wh_v3_outgoing_invoices')) return;

        $rows = DB::table('wh_v3_outgoing_invoice_items as item')
            ->join('wh_v3_outgoing_invoices as invoice', 'invoice.id', '=', 'item.outgoing_invoice_id')
            ->join('stk_skus as sku', 'sku.id', '=', 'item.sku_id')
            ->leftJoin('stk_uoms as uom', 'uom.id', '=', 'sku.base_uom_id')
            ->where('invoice.status', 'draft')
            ->get([
                'item.id', 'item.billed_qty_base', 'item.billing_uom_id', 'item.billing_uom_code_snapshot',
                'item.billing_conversion_factor_snapshot', 'item.billing_qty', 'item.unit_price_basis',
                'sku.base_uom_id', 'uom.code as base_uom_code',
            ]);

        foreach ($rows as $row) {
            DB::table('wh_v3_outgoing_invoice_items')->where('id', $row->id)->update([
                'billing_uom_id' => $row->billing_uom_id ?: $row->base_uom_id,
                'billing_uom_code_snapshot' => $row->billing_uom_code_snapshot ?: $row->base_uom_code,
                'billing_conversion_factor_snapshot' => (float) ($row->billing_conversion_factor_snapshot ?: 1),
                'billing_qty' => (float) ($row->billing_qty ?: $row->billed_qty_base),
                'unit_price_basis' => $row->unit_price_basis ?: 'PER_PRICE_UOM',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. Billing snapshots are audit data and must not be dropped automatically.
    }
};
