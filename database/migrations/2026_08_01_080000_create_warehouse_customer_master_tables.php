<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wh_customer_groups')) {
            Schema::create('wh_customer_groups', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 40)->unique();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->ulid('created_by_user_id')->nullable();
                $table->ulid('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('wh_customer_price_tiers')) {
            Schema::create('wh_customer_price_tiers', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 40)->unique();
                $table->string('name', 120);
                $table->decimal('discount_percent', 8, 4)->default(0);
                $table->boolean('is_active')->default(true)->index();
                $table->ulid('created_by_user_id')->nullable();
                $table->ulid('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('wh_customers')) {
            Schema::create('wh_customers', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 60)->unique();
                $table->string('name', 180)->index();
                $table->enum('customer_type', ['chain', 'external'])->default('external')->index();
                $table->ulid('outlet_id')->nullable()->index();
                $table->ulid('customer_group_id')->nullable()->index();
                $table->ulid('price_tier_id')->nullable()->index();
                $table->string('contact_name', 150)->nullable();
                $table->string('phone', 80)->nullable();
                $table->string('email', 180)->nullable();
                $table->string('tax_number', 80)->nullable();
                $table->string('tax_name', 180)->nullable();
                $table->text('tax_address')->nullable();
                $table->unsignedInteger('credit_term_days')->default(0);
                $table->decimal('credit_limit', 20, 4)->default(0);
                $table->string('currency_code', 3)->default('IDR');
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->ulid('created_by_user_id')->nullable();
                $table->ulid('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['customer_type', 'outlet_id'], 'wh_customers_type_outlet_unique');
            });
        }

        if (! Schema::hasTable('wh_customer_addresses')) {
            Schema::create('wh_customer_addresses', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('customer_id')->index();
                $table->string('label', 100)->default('Utama');
                $table->string('recipient_name', 150)->nullable();
                $table->string('phone', 80)->nullable();
                $table->text('address');
                $table->string('city', 120)->nullable();
                $table->string('province', 120)->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->decimal('latitude', 11, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->boolean('is_default')->default(false)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
                $table->softDeletes();
                $table->foreign('customer_id')->references('id')->on('wh_customers')->cascadeOnDelete();
            });
        }

        $now = now();
        foreach ([
            ['code' => 'CHAIN', 'name' => 'Outlet Chain Supply', 'description' => 'Customer outlet yang berada dalam chain supply.'],
            ['code' => 'RETAIL', 'name' => 'Retail / External', 'description' => 'Customer di luar chain supply.'],
        ] as $row) {
            if (! DB::table('wh_customer_groups')->where('code', $row['code'])->exists()) {
                DB::table('wh_customer_groups')->insert(['id' => (string) Str::ulid(), ...$row, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach ([
            ['code' => 'REGULAR', 'name' => 'Regular', 'discount_percent' => 0],
            ['code' => 'WHOLESALE', 'name' => 'Wholesale', 'discount_percent' => 0],
        ] as $row) {
            if (! DB::table('wh_customer_price_tiers')->where('code', $row['code'])->exists()) {
                DB::table('wh_customer_price_tiers')->insert(['id' => (string) Str::ulid(), ...$row, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

    }

    public function down(): void
    {
        // Additive and non-destructive. Customer master may already be referenced by business documents.
    }
};
