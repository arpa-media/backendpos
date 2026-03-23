<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outlets')) {
            return;
        }

        Schema::table('outlets', function (Blueprint $table) {
            if (! Schema::hasColumn('outlets', 'ig_1')) {
                $table->string('ig_1', 150)->nullable()->after('phone');
            }

            if (! Schema::hasColumn('outlets', 'ig_2')) {
                $table->string('ig_2', 150)->nullable()->after('ig_1');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('outlets')) {
            return;
        }

        Schema::table('outlets', function (Blueprint $table) {
            if (Schema::hasColumn('outlets', 'ig_2')) {
                $table->dropColumn('ig_2');
            }

            if (Schema::hasColumn('outlets', 'ig_1')) {
                $table->dropColumn('ig_1');
            }
        });
    }
};
