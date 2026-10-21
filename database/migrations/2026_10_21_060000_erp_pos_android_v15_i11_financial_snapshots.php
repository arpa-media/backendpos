<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sale_cancel_requests') && ! Schema::hasColumn('sale_cancel_requests', 'financial_snapshot')) {
            Schema::table('sale_cancel_requests', function (Blueprint $table) {
                $table->json('financial_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: financial snapshots are audit evidence.
        // Never drop historical snapshots as a side effect of application rollback.
    }
};
