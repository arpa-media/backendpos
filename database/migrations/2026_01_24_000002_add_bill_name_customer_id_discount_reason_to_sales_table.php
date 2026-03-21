<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Existing rows will receive default 'GUEST'
            $table->string('bill_name', 120)->default('GUEST')->after('status');

            $table->ulid('customer_id')->nullable()->index()->after('bill_name');

            // Prepare for discount patch (member|promo|squad)
            $table->string('discount_reason', 30)->nullable()->after('discount_total');

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn(['bill_name', 'customer_id', 'discount_reason']);
        });
    }
};
