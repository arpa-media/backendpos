<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stk_request_items')) {
            throw new RuntimeException('ERP V5 Iteration 12 membutuhkan tabel stk_request_items.');
        }

        if (! Schema::hasColumn('stk_request_items', 'commercial_price_snapshot')) {
            Schema::table('stk_request_items', function (Blueprint $table): void {
                $table->json('commercial_price_snapshot')->nullable()->after('line_total_snapshot');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Snapshot harga komersial adalah audit trail.
    }
};
