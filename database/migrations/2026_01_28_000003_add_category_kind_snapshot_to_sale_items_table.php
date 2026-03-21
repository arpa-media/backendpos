<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_items', 'category_kind_snapshot')) {
                $table->string('category_kind_snapshot', 20)->nullable()->after('variant_name')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_items', 'category_kind_snapshot')) {
                try { $table->dropIndex(['category_kind_snapshot']); } catch (\Throwable $e) {}
                $table->dropColumn('category_kind_snapshot');
            }
        });
    }
};
