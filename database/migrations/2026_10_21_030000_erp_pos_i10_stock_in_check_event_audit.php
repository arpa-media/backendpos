<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('wh_stock_in_check_events')) return;
        Schema::create('wh_stock_in_check_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('purchase_order_id');
            $table->ulid('stock_in_id');
            $table->string('event_type', 24); // FINISH_FULL, FINISH_SHORT, REOPEN
            $table->json('payload');
            $table->ulid('created_by_user_id');
            $table->timestamp('created_at');
            $table->index(['purchase_order_id','created_at'], 'wh_stock_in_check_events_po_time_idx');
        });
    }

    public function down(): void
    {
        // Audit evidence cannot be deleted by automatic rollback.
        if (Schema::hasTable('wh_stock_in_check_events') && DB::table('wh_stock_in_check_events')->exists()) {
            throw new \RuntimeException('Riwayat pengecekan Stock In sudah terisi. Rollback struktur diblokir untuk melindungi audit.');
        }
        Schema::dropIfExists('wh_stock_in_check_events');
    }
};
