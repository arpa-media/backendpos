<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'bill_name')) {
                $table->string('bill_name', 120)->default('GUEST')->after('status');
            }

            if (!Schema::hasColumn('sales', 'customer_id')) {
                $table->ulid('customer_id')->nullable()->index()->after('bill_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'customer_id')) {
                $table->dropColumn('customer_id');
            }
            if (Schema::hasColumn('sales', 'bill_name')) {
                $table->dropColumn('bill_name');
            }
        });
    }
};
