<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fund Request belum mempunyai supplier. Iterasi 02 membuat PO Draft
        // segera setelah Request approved; supplier wajib dilengkapi saat PO
        // diedit sebelum submit ke Finance.
        if (Schema::hasTable('pur_purchase_orders')
            && Schema::hasColumn('pur_purchase_orders', 'supplier_source_id')) {
            Schema::table('pur_purchase_orders', function (Blueprint $table): void {
                $table->ulid('supplier_source_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Tidak dikembalikan ke NOT NULL karena setelah Iterasi 02 dapat terdapat
        // Draft PO sah yang supplier-nya memang belum dilengkapi.
    }
};
