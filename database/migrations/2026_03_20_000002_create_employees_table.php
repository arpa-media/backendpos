<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('assignment_id')->nullable()->nullOnDelete();
            $table->uuid('hr_employee_id')->nullable()->unique();
            $table->string('nisj')->nullable()->index();
            $table->string('full_name')->nullable();
            $table->string('nickname')->nullable();
            $table->string('employment_status')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
