<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_provision_controls')) {
            return;
        }

        Schema::create('pos_provision_controls', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->boolean('allow_provision')->default(true);
            $table->text('notes')->nullable();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'outlet_id'], 'pos_provision_controls_user_outlet_unique');
            $table->index(['outlet_id', 'allow_provision'], 'pos_provision_controls_outlet_allow_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_provision_controls');
    }
};
