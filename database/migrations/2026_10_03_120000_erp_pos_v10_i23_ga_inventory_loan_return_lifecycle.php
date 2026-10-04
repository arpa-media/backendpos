<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ga_inventory_movements')) {
            return;
        }

        if (! Schema::hasColumn('ga_inventory_movements', 'loan_parent_id')) {
            Schema::table('ga_inventory_movements', function (Blueprint $table): void {
                $table->foreignUlid('loan_parent_id')
                    ->nullable()
                    ->after('movement_status')
                    ->constrained('ga_inventory_movements')
                    ->restrictOnDelete();
                $table->index(['loan_parent_id', 'movement_at'], 'ga_move_loan_parent_date_idx');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Return movements are operational audit evidence.
    }
};
