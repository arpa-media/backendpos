<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wh_chain_supplies')) {
            Schema::table('wh_chain_supplies', function (Blueprint $table): void {
                if (! Schema::hasColumn('wh_chain_supplies', 'request_handoff_mode')) {
                    $table->string('request_handoff_mode', 30)->default('direct_internal_po')->after('effective_from')->index();
                }
                if (! Schema::hasColumn('wh_chain_supplies', 'request_lead_days')) {
                    $table->unsignedSmallInteger('request_lead_days')->default(1)->after('request_handoff_mode');
                }
            });
        }

        if (Schema::hasTable('stk_requests')) {
            Schema::table('stk_requests', function (Blueprint $table): void {
                if (! Schema::hasColumn('stk_requests', 'destination_warehouse_id')) {
                    $table->foreignUlid('destination_warehouse_id')->nullable()->after('outlet_id')->constrained('outlets')->nullOnDelete();
                }
                if (! Schema::hasColumn('stk_requests', 'chain_supply_id')) {
                    $table->foreignUlid('chain_supply_id')->nullable()->after('destination_warehouse_id')->constrained('wh_chain_supplies')->nullOnDelete();
                }
                if (! Schema::hasColumn('stk_requests', 'origin_assignment_id')) {
                    $table->foreignUlid('origin_assignment_id')->nullable()->after('chain_supply_id')->constrained('assignments')->nullOnDelete();
                }
                if (! Schema::hasColumn('stk_requests', 'request_channel')) {
                    $table->string('request_channel', 40)->default('legacy')->after('source_opname_id')->index();
                }
                if (! Schema::hasColumn('stk_requests', 'purchasing_handoff_status')) {
                    $table->string('purchasing_handoff_status', 30)->default('not_started')->after('status')->index();
                }
                if (! Schema::hasColumn('stk_requests', 'warehouse_locked_at')) {
                    $table->timestamp('warehouse_locked_at')->nullable()->after('submitted_at');
                }
                if (! Schema::hasColumn('stk_requests', 'accepted_by_user_id')) {
                    $table->foreignUlid('accepted_by_user_id')->nullable()->after('warehouse_locked_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('stk_requests', 'accepted_at')) {
                    $table->timestamp('accepted_at')->nullable()->after('accepted_by_user_id');
                }
                if (! Schema::hasColumn('stk_requests', 'destination_snapshot')) {
                    $table->json('destination_snapshot')->nullable()->after('accepted_at');
                }
                if (! Schema::hasColumn('stk_requests', 'request_policy_snapshot')) {
                    $table->json('request_policy_snapshot')->nullable()->after('destination_snapshot');
                }
            });

            Schema::table('stk_requests', function (Blueprint $table): void {
                $table->index(['destination_warehouse_id', 'status', 'needed_date'], 'stk_request_warehouse_status_needed_idx');
                $table->index(['outlet_id', 'request_channel', 'status'], 'stk_request_origin_channel_status_idx');
            });
        }

        if (Schema::hasTable('stk_request_items')) {
            Schema::table('stk_request_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('stk_request_items', 'request_uom_id')) {
                    $table->foreignUlid('request_uom_id')->nullable()->after('sku_id')->constrained('stk_uoms')->nullOnDelete();
                }
                if (! Schema::hasColumn('stk_request_items', 'base_uom_id_snapshot')) {
                    $table->foreignUlid('base_uom_id_snapshot')->nullable()->after('request_uom_id')->constrained('stk_uoms')->nullOnDelete();
                }
                if (! Schema::hasColumn('stk_request_items', 'requested_qty_uom')) {
                    $table->decimal('requested_qty_uom', 18, 4)->nullable()->after('recommended_qty_snapshot');
                }
                if (! Schema::hasColumn('stk_request_items', 'conversion_factor_snapshot')) {
                    $table->decimal('conversion_factor_snapshot', 18, 8)->nullable()->after('requested_qty_uom');
                }
                if (! Schema::hasColumn('stk_request_items', 'requested_qty_base')) {
                    $table->decimal('requested_qty_base', 18, 4)->nullable()->after('conversion_factor_snapshot');
                }
                if (! Schema::hasColumn('stk_request_items', 'request_uom_code_snapshot')) {
                    $table->string('request_uom_code_snapshot', 30)->nullable()->after('requested_qty_base');
                }
                if (! Schema::hasColumn('stk_request_items', 'request_uom_name_snapshot')) {
                    $table->string('request_uom_name_snapshot', 100)->nullable()->after('request_uom_code_snapshot');
                }
                if (! Schema::hasColumn('stk_request_items', 'base_uom_code_snapshot')) {
                    $table->string('base_uom_code_snapshot', 30)->nullable()->after('request_uom_name_snapshot');
                }
                if (! Schema::hasColumn('stk_request_items', 'warehouse_available_qty_snapshot')) {
                    $table->decimal('warehouse_available_qty_snapshot', 18, 4)->nullable()->after('base_uom_code_snapshot');
                }
                if (! Schema::hasColumn('stk_request_items', 'fulfillment_status')) {
                    $table->string('fulfillment_status', 30)->default('pending')->after('status')->index();
                }
            });
        }

        if (! Schema::hasTable('wh_stock_request_handoffs')) {
            Schema::create('wh_stock_request_handoffs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('stock_request_id')->unique()->constrained('stk_requests')->cascadeOnDelete();
                $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
                $table->foreignUlid('chain_supply_id')->nullable()->constrained('wh_chain_supplies')->nullOnDelete();
                $table->string('handoff_mode', 30)->default('direct_internal_po')->index();
                $table->string('status', 30)->default('queued')->index();
                $table->foreignUlid('purchase_order_id')->nullable()->constrained('pur_purchase_orders')->nullOnDelete();
                $table->string('idempotency_key', 120)->unique();
                $table->char('payload_fingerprint', 64);
                $table->text('error_message')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignUlid('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('generated_at')->nullable();
                $table->timestamps();
                $table->index(['warehouse_id', 'status', 'created_at'], 'wh_request_handoff_warehouse_status_idx');
                $table->index(['purchase_order_id', 'status'], 'wh_request_handoff_po_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wh_stock_request_handoffs');

        if (Schema::hasTable('stk_request_items')) {
            Schema::table('stk_request_items', function (Blueprint $table): void {
                foreach (['request_uom_id', 'base_uom_id_snapshot'] as $column) {
                    if (Schema::hasColumn('stk_request_items', $column)) {
                        $table->dropConstrainedForeignId($column);
                    }
                }
                foreach ([
                    'requested_qty_uom', 'conversion_factor_snapshot', 'requested_qty_base',
                    'request_uom_code_snapshot', 'request_uom_name_snapshot', 'base_uom_code_snapshot',
                    'warehouse_available_qty_snapshot', 'fulfillment_status',
                ] as $column) {
                    if (Schema::hasColumn('stk_request_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('stk_requests')) {
            Schema::table('stk_requests', function (Blueprint $table): void {
                foreach (['destination_warehouse_id', 'chain_supply_id', 'origin_assignment_id', 'accepted_by_user_id'] as $column) {
                    if (Schema::hasColumn('stk_requests', $column)) {
                        $table->dropConstrainedForeignId($column);
                    }
                }
                foreach ([
                    'request_channel', 'purchasing_handoff_status', 'warehouse_locked_at', 'accepted_at',
                    'destination_snapshot', 'request_policy_snapshot',
                ] as $column) {
                    if (Schema::hasColumn('stk_requests', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('wh_chain_supplies')) {
            Schema::table('wh_chain_supplies', function (Blueprint $table): void {
                foreach (['request_handoff_mode', 'request_lead_days'] as $column) {
                    if (Schema::hasColumn('wh_chain_supplies', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
