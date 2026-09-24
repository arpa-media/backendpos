<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addGenerationColumns();
        $this->rebuildDimensionMonthlyFacts();
    }

    public function down(): void
    {
        // Derived reporting facts are intentionally not rolled back destructively.
        // The previous application version can still read these tables because all
        // original report columns are preserved; only identity/indexing is hardened.
    }

    private function addGenerationColumns(): void
    {
        if (Schema::hasTable('report_daily_summary_coverage')
            && ! Schema::hasColumn('report_daily_summary_coverage', 'generation_ulid')) {
            Schema::table('report_daily_summary_coverage', function (Blueprint $table): void {
                $table->char('generation_ulid', 26)->nullable()->after('synced_at');
                $table->index(['generation_ulid'], 'rdsc_generation_idx');
            });
        }

        if (Schema::hasTable('report_monthly_summary_coverage')
            && ! Schema::hasColumn('report_monthly_summary_coverage', 'source_daily_generation_ulid')) {
            Schema::table('report_monthly_summary_coverage', function (Blueprint $table): void {
                $table->char('source_daily_generation_ulid', 26)->nullable()->after('source_daily_max_synced_at');
                $table->index(['source_daily_generation_ulid'], 'rmscov_generation_idx');
            });
        }
    }

    private function rebuildDimensionMonthlyFacts(): void
    {
        if (Schema::hasTable('report_monthly_summary_coverage')) {
            // Monthly facts are fully derived from Daily Facts. Invalidate only the
            // monthly coverage so Control Center rebuilds them with the corrected
            // dimensional identity; Daily/Hourly facts are untouched.
            DB::table('report_monthly_summary_coverage')->delete();
        }

        Schema::dropIfExists('report_monthly_variant_summaries');
        Schema::dropIfExists('report_monthly_product_summaries');
        Schema::dropIfExists('report_monthly_category_summaries');

        Schema::create('report_monthly_category_summaries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('outlet_id', 26);
            $table->date('business_month');
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
            $table->index(['business_month', 'outlet_id'], 'rmcat_month_out_idx');
            $table->index(['outlet_id', 'business_month', 'category_id'], 'rmcat_dim_idx');
        });

        Schema::create('report_monthly_product_summaries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('outlet_id', 26);
            $table->date('business_month');
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
            $table->index(['business_month', 'outlet_id'], 'rmprod_month_out_idx');
            $table->index(['outlet_id', 'business_month', 'product_id'], 'rmprod_dim_idx');
            $table->index(['outlet_id', 'business_month', 'category_id'], 'rmprod_cat_idx');
        });

        Schema::create('report_monthly_variant_summaries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('outlet_id', 26);
            $table->date('business_month');
            $table->string('business_timezone', 64)->nullable();
            $table->char('product_id', 26)->default('');
            $table->char('variant_id', 26)->default('');
            $table->string('product_name', 191)->default('-');
            $table->string('variant_name', 191)->default('');
            $table->char('category_id', 26)->default('');
            $table->string('category_name', 191)->default('Uncategorized');
            $table->string('category_kind', 30)->default('');
            foreach (['line_count', 'marked_line_count', 'unit_price_sum', 'marked_unit_price_sum', 'item_sold', 'marked_item_sold', 'gross_sales', 'marked_gross_sales'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->decimal('discount_basis', 20, 6)->default(0);
            $table->decimal('marked_discount_basis', 20, 6)->default(0);
            $table->timestamps();
            $table->index(['business_month', 'outlet_id'], 'rmvar_month_out_idx');
            $table->index(['outlet_id', 'business_month', 'product_id', 'variant_id'], 'rmvar_dim_idx');
            $table->index(['outlet_id', 'business_month', 'category_id'], 'rmvar_cat_idx');
        });
    }
};
