<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_sale_scope_cache', function (Blueprint $table) {
            $table->index(['scope_key', 'expires_at', 'sale_id'], 'report_sale_scope_cache_scope_exp_sale_idx');
        });
    }

    public function down(): void
    {
        Schema::table('report_sale_scope_cache', function (Blueprint $table) {
            $table->dropIndex('report_sale_scope_cache_scope_exp_sale_idx');
        });
    }
};
