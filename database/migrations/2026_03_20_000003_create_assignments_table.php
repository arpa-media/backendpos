<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->uuid('hr_assignment_id')->nullable()->unique();
            $table->string('role_title')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'is_primary']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('assignment_id')->references('id')->on('assignments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['assignment_id']);
        });
        Schema::dropIfExists('assignments');
    }
};
