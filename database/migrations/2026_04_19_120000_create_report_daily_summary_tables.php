<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_daily_summary_coverage')) {
            Schema::create('report_daily_summary_coverage', function (Blueprint $table) {
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->primary(['outlet_id', 'business_date'], 'report_daily_summary_coverage_pk');
                $table->index(['business_date', 'outlet_id'], 'report_daily_summary_coverage_date_outlet_idx');
            });
        }

        if (! Schema::hasTable('report_daily_sales_summaries')) {
            Schema::create('report_daily_sales_summaries', function (Blueprint $table) {
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->string('business_timezone', 64)->nullable();
                $table->unsignedInteger('trx_count')->default(0);
                $table->unsignedInteger('marked_trx_count')->default(0);
                $table->unsignedBigInteger('subtotal_sales')->default(0);
                $table->unsignedBigInteger('marked_subtotal_sales')->default(0);
                $table->unsignedBigInteger('grand_sales')->default(0);
                $table->unsignedBigInteger('marked_grand_sales')->default(0);
                $table->unsignedBigInteger('discount_total')->default(0);
                $table->unsignedBigInteger('marked_discount_total')->default(0);
                $table->unsignedBigInteger('tax_total')->default(0);
                $table->unsignedBigInteger('marked_tax_total')->default(0);
                $table->unsignedBigInteger('service_charge_total')->default(0);
                $table->unsignedBigInteger('marked_service_charge_total')->default(0);
                $table->bigInteger('rounding_total')->default(0);
                $table->bigInteger('marked_rounding_total')->default(0);
                $table->unsignedBigInteger('item_qty_sold')->default(0);
                $table->unsignedBigInteger('marked_item_qty_sold')->default(0);
                $table->timestamps();

                $table->primary(['outlet_id', 'business_date'], 'report_daily_sales_summaries_pk');
                $table->index(['business_date', 'outlet_id'], 'report_daily_sales_summaries_date_outlet_idx');
            });
        }

        if (! Schema::hasTable('report_daily_payment_summaries')) {
            Schema::create('report_daily_payment_summaries', function (Blueprint $table) {
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->string('business_timezone', 64)->nullable();
                $table->string('payment_method_name', 120);
                $table->string('payment_method_type', 60)->default('');
                $table->unsignedInteger('trx_count')->default(0);
                $table->unsignedInteger('marked_trx_count')->default(0);
                $table->unsignedBigInteger('gross_sales')->default(0);
                $table->unsignedBigInteger('marked_gross_sales')->default(0);
                $table->timestamps();

                $table->primary(['outlet_id', 'business_date', 'payment_method_name', 'payment_method_type'], 'report_daily_payment_summaries_pk');
                $table->index(['business_date', 'outlet_id'], 'report_daily_payment_summaries_date_outlet_idx');
                $table->index(['payment_method_name', 'business_date'], 'report_daily_payment_summaries_name_date_idx');
            });
        }

        if (! Schema::hasTable('report_daily_channel_summaries')) {
            Schema::create('report_daily_channel_summaries', function (Blueprint $table) {
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->string('business_timezone', 64)->nullable();
                $table->string('display_channel', 120);
                $table->unsignedInteger('trx_count')->default(0);
                $table->unsignedInteger('marked_trx_count')->default(0);
                $table->unsignedBigInteger('gross_sales')->default(0);
                $table->unsignedBigInteger('marked_gross_sales')->default(0);
                $table->timestamps();

                $table->primary(['outlet_id', 'business_date', 'display_channel'], 'report_daily_channel_summaries_pk');
                $table->index(['business_date', 'outlet_id'], 'report_daily_channel_summaries_date_outlet_idx');
            });
        }

        if (! Schema::hasTable('report_daily_category_summaries')) {
            Schema::create('report_daily_category_summaries', function (Blueprint $table) {
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->string('business_timezone', 64)->nullable();
                $table->char('category_id', 26)->default('');
                $table->string('category_name', 191)->default('Uncategorized');
                $table->string('category_kind', 30)->default('');
                $table->unsignedBigInteger('item_sold')->default(0);
                $table->unsignedBigInteger('marked_item_sold')->default(0);
                $table->unsignedBigInteger('gross_sales')->default(0);
                $table->unsignedBigInteger('marked_gross_sales')->default(0);
                $table->decimal('discount_basis', 20, 6)->default(0);
                $table->decimal('marked_discount_basis', 20, 6)->default(0);
                $table->timestamps();

                $table->primary(['outlet_id', 'business_date', 'category_id', 'category_name'], 'report_daily_category_summaries_pk');
                $table->index(['business_date', 'outlet_id'], 'report_daily_category_summaries_date_outlet_idx');
                $table->index(['category_name', 'business_date'], 'report_daily_category_summaries_name_date_idx');
            });
        }

        if (! Schema::hasTable('report_daily_product_summaries')) {
            Schema::create('report_daily_product_summaries', function (Blueprint $table) {
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->string('business_timezone', 64)->nullable();
                $table->char('product_id', 26)->default('');
                $table->string('product_name', 191)->default('-');
                $table->char('category_id', 26)->default('');
                $table->string('category_name', 191)->default('Uncategorized');
                $table->string('category_kind', 30)->default('');
                $table->unsignedBigInteger('item_sold')->default(0);
                $table->unsignedBigInteger('marked_item_sold')->default(0);
                $table->unsignedBigInteger('gross_sales')->default(0);
                $table->unsignedBigInteger('marked_gross_sales')->default(0);
                $table->decimal('discount_basis', 20, 6)->default(0);
                $table->decimal('marked_discount_basis', 20, 6)->default(0);
                $table->timestamps();

                $table->primary(['outlet_id', 'business_date', 'product_id', 'product_name'], 'report_daily_product_summaries_pk');
                $table->index(['business_date', 'outlet_id'], 'report_daily_product_summaries_date_outlet_idx');
                $table->index(['product_name', 'business_date'], 'report_daily_product_summaries_name_date_idx');
            });
        }

        if (! Schema::hasTable('report_daily_variant_summaries')) {
            Schema::create('report_daily_variant_summaries', function (Blueprint $table) {
                $table->char('outlet_id', 26);
                $table->date('business_date');
                $table->string('business_timezone', 64)->nullable();
                $table->char('product_id', 26)->default('');
                $table->char('variant_id', 26)->default('');
                $table->string('product_name', 191)->default('-');
                $table->string('variant_name', 191)->default('');
                $table->char('category_id', 26)->default('');
                $table->string('category_name', 191)->default('Uncategorized');
                $table->string('category_kind', 30)->default('');
                $table->unsignedBigInteger('line_count')->default(0);
                $table->unsignedBigInteger('marked_line_count')->default(0);
                $table->unsignedBigInteger('unit_price_sum')->default(0);
                $table->unsignedBigInteger('marked_unit_price_sum')->default(0);
                $table->unsignedBigInteger('item_sold')->default(0);
                $table->unsignedBigInteger('marked_item_sold')->default(0);
                $table->unsignedBigInteger('gross_sales')->default(0);
                $table->unsignedBigInteger('marked_gross_sales')->default(0);
                $table->decimal('discount_basis', 20, 6)->default(0);
                $table->decimal('marked_discount_basis', 20, 6)->default(0);
                $table->timestamps();

                $table->primary(['outlet_id', 'business_date', 'product_id', 'variant_id', 'product_name', 'variant_name'], 'report_daily_variant_summaries_pk');
                $table->index(['business_date', 'outlet_id'], 'report_daily_variant_summaries_date_outlet_idx');
                $table->index(['product_name', 'variant_name', 'business_date'], 'report_daily_variant_summaries_name_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_daily_variant_summaries');
        Schema::dropIfExists('report_daily_product_summaries');
        Schema::dropIfExists('report_daily_category_summaries');
        Schema::dropIfExists('report_daily_channel_summaries');
        Schema::dropIfExists('report_daily_payment_summaries');
        Schema::dropIfExists('report_daily_sales_summaries');
        Schema::dropIfExists('report_daily_summary_coverage');
    }
};
