<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_hourly_sales_summaries')) {
            Schema::create('report_hourly_sales_summaries', function (Blueprint $table): void {
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->string('business_timezone', 64);
                $table->unsignedTinyInteger('business_hour');
                $table->unsignedInteger('trx_count')->default(0);
                $table->unsignedInteger('marked_trx_count')->default(0);
                $table->unsignedBigInteger('gross_amount_sales')->default(0);
                $table->unsignedBigInteger('marked_gross_amount_sales')->default(0);
                $table->timestamps();

                $table->primary(['outlet_id', 'business_date', 'business_hour'], 'rhss_pk');
                $table->index(['business_date', 'outlet_id', 'business_hour'], 'rhss_date_out_hour_idx');
            });
        }

        if (! Schema::hasTable('report_hourly_product_summaries')) {
            Schema::create('report_hourly_product_summaries', function (Blueprint $table): void {
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->string('business_timezone', 64);
                $table->unsignedTinyInteger('business_hour');
                $table->char('product_id', 26);
                $table->string('product_name', 180);
                $table->char('category_id', 26)->default('');
                $table->string('category_name', 180)->default('Uncategorized');
                $table->string('category_kind', 40)->default('');
                $table->decimal('item_sold', 18, 3)->default(0);
                $table->decimal('marked_item_sold', 18, 3)->default(0);
                $table->unsignedBigInteger('gross_sales')->default(0);
                $table->unsignedBigInteger('marked_gross_sales')->default(0);
                $table->timestamps();

                $table->primary(['outlet_id', 'business_date', 'business_hour', 'product_id', 'category_id'], 'rhps_pk');
                $table->index(['business_date', 'outlet_id', 'business_hour', 'category_id'], 'rhps_date_out_hour_cat_idx');
                $table->index(['category_name', 'business_date', 'business_hour'], 'rhps_cat_date_hour_idx');
            });
        }

        if (! Schema::hasTable('report_hourly_summary_coverage')) {
            Schema::create('report_hourly_summary_coverage', function (Blueprint $table): void {
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->dateTime('source_daily_synced_at')->nullable();
                $table->dateTime('synced_at');
                $table->timestamps();

                $table->primary(['outlet_id', 'business_date'], 'rhcov_pk');
                $table->index(['business_date', 'outlet_id', 'source_daily_synced_at'], 'rhcov_date_out_sync_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_hourly_summary_coverage');
        Schema::dropIfExists('report_hourly_product_summaries');
        Schema::dropIfExists('report_hourly_sales_summaries');
    }
};
