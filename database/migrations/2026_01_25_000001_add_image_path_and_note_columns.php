<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('sort_order');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('description');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->text('note')->nullable()->after('variant_name');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
