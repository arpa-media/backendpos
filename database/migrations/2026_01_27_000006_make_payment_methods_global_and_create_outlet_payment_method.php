<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('outlet_payment_method', function (Blueprint $table) {
            $table->ulid('outlet_id');
            $table->ulid('payment_method_id');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['outlet_id', 'payment_method_id'], 'uq_outlet_payment_method');
            $table->index(['outlet_id', 'is_active'], 'idx_outlet_payment_method_outlet_active');

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->cascadeOnDelete();
        });

        if (Schema::hasColumn('payment_methods', 'outlet_id')) {
            DB::statement('INSERT INTO outlet_payment_method (outlet_id, payment_method_id, is_active, created_at, updated_at) SELECT outlet_id, id, is_active, NOW(), NOW() FROM payment_methods');
        }

        Schema::table('payment_methods', function (Blueprint $table) {
            if (Schema::hasColumn('payment_methods', 'outlet_id')) {
                try { $table->dropForeign(['outlet_id']); } catch (\Throwable $e) {}
                try { $table->dropUnique(['outlet_id', 'name']); } catch (\Throwable $e) {}
                try { $table->dropIndex(['outlet_id', 'type']); } catch (\Throwable $e) {}
                try { $table->dropIndex(['outlet_id', 'is_active']); } catch (\Throwable $e) {}
                try { $table->dropIndex(['outlet_id']); } catch (\Throwable $e) {}
                $table->dropColumn('outlet_id');
            }
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            // unique name globally (practical)
            try { $table->unique(['name']); } catch (\Throwable $e) {}
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        // best-effort rollback
        Schema::table('payment_methods', function (Blueprint $table) {
            if (!Schema::hasColumn('payment_methods', 'outlet_id')) {
                $table->ulid('outlet_id')->nullable()->index()->after('id');
            }
        });

        Schema::dropIfExists('outlet_payment_method');
    }
};
