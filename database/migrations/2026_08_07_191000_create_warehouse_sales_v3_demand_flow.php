<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['outlets','users','stk_requests','stk_request_items','stk_skus','stk_uoms','wh_productions','wh_production_inputs','wh_ledger_postings'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse v3 Iterasi 04 membutuhkan tabel {$table}. Apply baseline + Iterasi 01-03 terlebih dahulu.");
            }
        }

        $this->createLogisticsPrepareRequests();
        $this->createLogisticsPrepareItems();
        $this->createStockRequestReviews();
        $this->createStockRequestReviewItems();
        $this->createProductionMaterialRequests();
        $this->createProductionMaterialRequestItems();
        $this->createDemandEvents();
    }

    private function createLogisticsPrepareRequests(): void
    {
        if (Schema::hasTable('wh_v3_logistics_prepare_requests')) return;

        Schema::create('wh_v3_logistics_prepare_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('prepare_number', 70)->unique();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
            $table->string('source_type', 40)->index();
            $table->string('source_id', 80)->index();
            $table->string('source_number', 100)->nullable()->index();
            $table->string('destination_type', 30)->nullable()->index();
            $table->string('destination_id', 80)->nullable()->index();
            $table->string('status', 30)->default('queued')->index();
            $table->date('requested_delivery_date')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_type','source_id'], 'wh_v3_prepare_source_uq');
            $table->index(['warehouse_id','status','created_at'], 'wh_v3_prepare_wh_status_idx');
        });
    }

    private function createLogisticsPrepareItems(): void
    {
        if (Schema::hasTable('wh_v3_logistics_prepare_items')) return;

        Schema::create('wh_v3_logistics_prepare_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('prepare_request_id')->constrained('wh_v3_logistics_prepare_requests')->cascadeOnDelete();
            $table->string('source_item_id', 80)->nullable()->index();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
            $table->foreignUlid('uom_id')->nullable()->constrained('stk_uoms')->nullOnDelete();
            $table->decimal('requested_qty_uom', 18, 4)->default(0);
            $table->decimal('requested_qty_base', 18, 4)->default(0);
            $table->decimal('approved_qty_uom', 18, 4)->default(0);
            $table->decimal('approved_qty_base', 18, 4)->default(0);
            $table->decimal('ready_qty_base', 18, 4)->default(0);
            $table->string('status', 30)->default('queued')->index();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['prepare_request_id','sku_id'], 'wh_v3_prepare_item_sku_idx');
        });
    }

    private function createStockRequestReviews(): void
    {
        if (Schema::hasTable('wh_v3_stock_request_reviews')) return;

        Schema::create('wh_v3_stock_request_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('stock_request_id')->unique()->constrained('stk_requests')->cascadeOnDelete();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignUlid('logistics_prepare_request_id')->nullable()->unique()->constrained('wh_v3_logistics_prepare_requests')->nullOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->text('approval_notes')->nullable();
            $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id','status','created_at'], 'wh_v3_stock_review_wh_status_idx');
        });
    }

    private function createStockRequestReviewItems(): void
    {
        if (Schema::hasTable('wh_v3_stock_request_review_items')) return;

        Schema::create('wh_v3_stock_request_review_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('review_id')->constrained('wh_v3_stock_request_reviews')->cascadeOnDelete();
            $table->foreignUlid('stock_request_item_id')->unique()->constrained('stk_request_items')->cascadeOnDelete();
            $table->decimal('requested_qty_uom', 18, 4)->default(0);
            $table->decimal('requested_qty_base', 18, 4)->default(0);
            $table->decimal('approved_qty_uom', 18, 4)->default(0);
            $table->decimal('approved_qty_base', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    private function createProductionMaterialRequests(): void
    {
        if (Schema::hasTable('wh_v3_production_material_requests')) return;

        Schema::create('wh_v3_production_material_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('request_number', 70)->unique();
            $table->foreignUlid('production_id')->unique()->constrained('wh_productions')->cascadeOnDelete();
            $table->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('material_ledger_posting_id')->nullable();
            $table->unique('material_ledger_posting_id', 'wh_v3_prod_req_ledger_uq');
            $table->foreign('material_ledger_posting_id', 'wh_v3_prod_req_ledger_fk')->references('id')->on('wh_ledger_postings')->nullOnDelete();
            $table->text('approval_notes')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id','status','created_at'], 'wh_v3_prod_req_wh_status_idx');
        });
    }

    private function createProductionMaterialRequestItems(): void
    {
        if (Schema::hasTable('wh_v3_production_material_request_items')) return;

        Schema::create('wh_v3_production_material_request_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('production_request_id');
            $table->foreign('production_request_id', 'wh_v3_prod_req_item_req_fk')->references('id')->on('wh_v3_production_material_requests')->cascadeOnDelete();
            $table->foreignUlid('production_input_id');
            $table->unique('production_input_id', 'wh_v3_prod_req_input_uq');
            $table->foreign('production_input_id', 'wh_v3_prod_req_item_input_fk')->references('id')->on('wh_production_inputs')->cascadeOnDelete();
            $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
            $table->foreignUlid('request_uom_id')->nullable()->constrained('stk_uoms')->nullOnDelete();
            $table->decimal('requested_qty_uom', 18, 4)->default(0);
            $table->decimal('requested_qty_base', 18, 4)->default(0);
            $table->decimal('approved_qty_uom', 18, 4)->default(0);
            $table->decimal('approved_qty_base', 18, 4)->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['production_request_id','sku_id'], 'wh_v3_prod_req_item_sku_idx');
        });
    }

    private function createDemandEvents(): void
    {
        if (Schema::hasTable('wh_v3_sales_demand_events')) return;

        Schema::create('wh_v3_sales_demand_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('document_type', 40)->index();
            $table->string('document_id', 80)->index();
            $table->string('event_type', 60)->index();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
            $table->index(['document_type','document_id','occurred_at'], 'wh_v3_demand_doc_event_idx');
        });
    }

    public function down(): void
    {
        // Non-destructive by design: demand approvals, logistics handoff, and material ledger audit are retained.
    }
};
