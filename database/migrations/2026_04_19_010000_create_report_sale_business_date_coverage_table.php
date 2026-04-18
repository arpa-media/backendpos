<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('report_sale_business_date_coverage')) {
            return;
        }

        Schema::create('report_sale_business_date_coverage', function (Blueprint $table) {
            $table->char('outlet_id', 26);
            $table->string('business_timezone', 64);
            $table->date('business_date');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->primary(['outlet_id', 'business_timezone', 'business_date'], 'rsbdc_primary');
            $table->index(['business_timezone', 'business_date', 'outlet_id'], 'rsbdc_tz_date_outlet_idx');
            $table->index(['business_date', 'synced_at'], 'rsbdc_date_synced_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_sale_business_date_coverage');
    }
};
