<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stk_requests')) {
            Schema::table('stk_requests', function (Blueprint $table): void {
                if (! Schema::hasColumn('stk_requests', 'canonical_fund_request_id')) {
                    $table->ulid('canonical_fund_request_id')->nullable();
                    $table->index('canonical_fund_request_id', 'stk_request_fund_request_idx');
                }
                if (! Schema::hasColumn('stk_requests', 'draft_purchase_order_id')) {
                    $table->ulid('draft_purchase_order_id')->nullable();
                    $table->index('draft_purchase_order_id', 'stk_request_draft_po_idx');
                }
                if (! Schema::hasColumn('stk_requests', 'request_approval_status')) {
                    $table->string('request_approval_status', 40)->default('not_started')->index();
                }
                if (! Schema::hasColumn('stk_requests', 'request_approved_by_user_id')) {
                    $table->ulid('request_approved_by_user_id')->nullable();
                }
                if (! Schema::hasColumn('stk_requests', 'request_approved_at')) {
                    $table->timestamp('request_approved_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('pur_purchase_orders')) {
            Schema::table('pur_purchase_orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('pur_purchase_orders', 'fund_request_id')) {
                    $table->ulid('fund_request_id')->nullable();
                    $table->index('fund_request_id', 'pur_po_fund_request_idx');
                }
                if (! Schema::hasColumn('pur_purchase_orders', 'order_type')) {
                    $table->string('order_type', 30)->default('PURCHASE')->index();
                }
            });
        }

        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')
                ->where('code', 'inventory-manual-stock')
                ->update(['is_active' => false, 'updated_at' => now()]);

            DB::table('access_menus')
                ->where('code', 'purchasing-stock-request-approval')
                ->update(['is_active' => false, 'updated_at' => now()]);

            DB::table('access_menus')
                ->where('code', 'inventory-request-stock')
                ->update([
                    'name' => 'Request Stock',
                    'path' => '/stock-inventory/request-stock',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Non-destructive. Linkage and Access Matrix decisions may already be used by live documents.
    }
};
