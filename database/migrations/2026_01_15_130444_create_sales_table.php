<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->ulid('outlet_id')->index();
            $table->ulid('cashier_id')->index(); // user id

            $table->string('sale_number', 40)->index(); // receipt number
            $table->string('channel', 20)->index(); // DINE_IN/TAKEAWAY/DELIVERY (SalesChannels)
            $table->string('status', 20)->default('PAID')->index(); // SaleStatuses

            // money fields stored as integer Rupiah
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0); // PHASE2: discount engine
            $table->unsignedBigInteger('tax_total')->default(0);      // PHASE2: tax rules
            $table->unsignedBigInteger('service_charge_total')->default(0); // PHASE2
            $table->unsignedBigInteger('grand_total')->default(0);

            $table->unsignedBigInteger('paid_total')->default(0);
            $table->unsignedBigInteger('change_total')->default(0);

            // PHASE2: dine-in table, queue number, customer/member, notes
            // $table->string('queue_number', 20)->nullable()->index();
            // $table->ulid('customer_id')->nullable()->index();
            $table->string('note', 500)->nullable();

            // PHASE2: sync fields
            // $table->string('sync_status', 20)->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('cashier_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['outlet_id', 'sale_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
