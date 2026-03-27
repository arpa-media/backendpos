<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('sales', 'queue_no')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->string('queue_no', 20)->nullable()->after('sale_number')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('sales', 'queue_no')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['queue_no']);
            $table->dropColumn('queue_no');
        });
    }
};
