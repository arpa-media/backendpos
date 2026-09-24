<?php

use Database\Seeders\HrUniformI10Seeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createItems();
        $this->createBalances();
        $this->createInbounds();
        $this->createInboundLines();
        $this->createMovements();
        $this->createStockAlerts();

        // Canonical catalog is seeded as part of the migration so a copy-paste
        // deployment is immediately usable. The seeder itself is idempotent and
        // can also be run manually when needed.
        app(HrUniformI10Seeder::class)->run();
    }

    public function down(): void
    {
        // Non-destructive by design. Uniform ledger and balances are HR audit data.
    }

    private function createItems(): void
    {
        if (Schema::hasTable('HR_uniform_items')) return;

        Schema::create('HR_uniform_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 80);
            $table->string('code_key', 80);
            $table->string('name', 180);
            $table->string('company_code', 8);
            $table->string('item_kind', 20)->default('UNIFORM');
            $table->string('size', 12)->nullable();
            $table->unsignedSmallInteger('low_stock_threshold')->default(10);
            $table->unsignedSmallInteger('source_row')->nullable();
            $table->string('source_template', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('code_key', 'hr_ui_code_key_uq');
            $table->index(['company_code', 'item_kind', 'is_active'], 'hr_ui_company_kind_idx');
            $table->index(['name', 'is_active'], 'hr_ui_name_idx');
        });
    }

    private function createBalances(): void
    {
        if (Schema::hasTable('HR_uniform_stock_balances')) return;

        Schema::create('HR_uniform_stock_balances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('uniform_item_id');
            $table->unsignedInteger('opening_qty')->default(0);
            $table->unsignedInteger('inbound_qty')->default(0);
            $table->unsignedInteger('outbound_qty')->default(0);
            $table->integer('current_qty')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique('uniform_item_id', 'hr_usb_item_uq');
            $table->foreign('uniform_item_id', 'hr_usb_item_fk')->references('id')->on('HR_uniform_items')->restrictOnDelete();
        });
    }

    private function createInbounds(): void
    {
        if (Schema::hasTable('HR_uniform_inbounds')) return;

        Schema::create('HR_uniform_inbounds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('document_no', 80);
            $table->string('idempotency_key', 80)->nullable();
            $table->date('inbound_date');
            $table->string('receiver_name', 120);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('POSTED');
            $table->ulid('created_by_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique('document_no', 'hr_uin_doc_uq');
            $table->unique('idempotency_key', 'hr_uin_idem_uq');
            $table->index(['inbound_date', 'status'], 'hr_uin_date_status_idx');
            $table->index('created_by_user_id', 'hr_uin_user_idx');
        });
    }

    private function createInboundLines(): void
    {
        if (Schema::hasTable('HR_uniform_inbound_lines')) return;

        Schema::create('HR_uniform_inbound_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('inbound_id');
            $table->ulid('uniform_item_id');
            $table->unsignedInteger('quantity');
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('squad_charge', 15, 2)->default(0);
            $table->decimal('company_charge', 15, 2)->default(0);
            $table->string('company_code', 8);
            $table->timestamps();

            $table->unique(['inbound_id', 'uniform_item_id'], 'hr_uil_in_item_uq');
            $table->index(['uniform_item_id', 'created_at'], 'hr_uil_item_date_idx');
            $table->foreign('inbound_id', 'hr_uil_in_fk')->references('id')->on('HR_uniform_inbounds')->restrictOnDelete();
            $table->foreign('uniform_item_id', 'hr_uil_item_fk')->references('id')->on('HR_uniform_items')->restrictOnDelete();
        });
    }

    private function createMovements(): void
    {
        if (Schema::hasTable('HR_uniform_movements')) return;

        Schema::create('HR_uniform_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('uniform_item_id');
            $table->date('movement_date');
            $table->string('movement_type', 32);
            $table->integer('quantity_delta');
            $table->integer('balance_before');
            $table->integer('balance_after');
            $table->string('source_type', 32);
            $table->ulid('source_id')->nullable();
            $table->ulid('source_line_id')->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('squad_charge', 15, 2)->default(0);
            $table->decimal('company_charge', 15, 2)->default(0);
            $table->ulid('actor_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['source_type', 'source_line_id'], 'hr_um_src_line_uq');
            $table->index(['uniform_item_id', 'movement_date'], 'hr_um_item_date_idx');
            $table->index(['movement_type', 'movement_date'], 'hr_um_type_date_idx');
            $table->foreign('uniform_item_id', 'hr_um_item_fk')->references('id')->on('HR_uniform_items')->restrictOnDelete();
        });
    }

    private function createStockAlerts(): void
    {
        if (Schema::hasTable('HR_uniform_stock_alerts')) return;

        Schema::create('HR_uniform_stock_alerts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('uniform_item_id');
            $table->string('status', 16)->default('OPEN');
            $table->unsignedSmallInteger('threshold')->default(10);
            $table->integer('current_qty')->default(0);
            $table->ulid('last_movement_id')->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique('uniform_item_id', 'hr_usa_item_uq');
            $table->index(['status', 'current_qty'], 'hr_usa_status_qty_idx');
            $table->foreign('uniform_item_id', 'hr_usa_item_fk')->references('id')->on('HR_uniform_items')->restrictOnDelete();
        });
    }
};
