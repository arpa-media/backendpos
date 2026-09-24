<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cogs_recipes', function (Blueprint $table): void {
            if (! Schema::hasColumn('cogs_recipes', 'recipe_mode')) {
                $table->string('recipe_mode', 20)->default('direct')->after('variant_name')->index();
            }
            if (! Schema::hasColumn('cogs_recipes', 'parent_recipe_id')) {
                $table->ulid('parent_recipe_id')->nullable()->after('recipe_mode')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cogs_recipes', function (Blueprint $table): void {
            if (Schema::hasColumn('cogs_recipes', 'parent_recipe_id')) $table->dropColumn('parent_recipe_id');
            if (Schema::hasColumn('cogs_recipes', 'recipe_mode')) $table->dropColumn('recipe_mode');
        });
    }
};
