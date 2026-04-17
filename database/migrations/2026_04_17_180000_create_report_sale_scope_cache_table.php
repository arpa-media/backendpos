<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_sale_scope_cache', function (Blueprint $table) {
            $table->id();
            $table->string('scope_key', 96)->index();
            $table->string('sale_id', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['scope_key', 'sale_id'], 'report_sale_scope_cache_scope_sale_unique');
            $table->index(['scope_key', 'expires_at'], 'report_sale_scope_cache_scope_exp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_sale_scope_cache');
    }
};
