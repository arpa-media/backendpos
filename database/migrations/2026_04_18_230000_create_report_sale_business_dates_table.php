<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_sale_business_dates')) {
            return;
        }

        Schema::create('report_sale_business_dates', function (Blueprint $table) {
            $table->char('sale_id', 26)->primary();
            $table->char('outlet_id', 26);
            $table->string('business_timezone', 64);
            $table->date('business_date');
            $table->unsignedTinyInteger('marking')->default(0);
            $table->timestamps();

            $table->index(['outlet_id', 'business_date', 'sale_id'], 'rsbd_outlet_date_sale_idx');
            $table->index(['business_date', 'outlet_id', 'marking', 'sale_id'], 'rsbd_date_outlet_marking_sale_idx');
            $table->index(['business_date', 'sale_id'], 'rsbd_date_sale_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_sale_business_dates');
    }
};
