<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('HR_shift_schedules')) {
            return;
        }

        Schema::create('HR_shift_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->ulid('shift_id')->nullable();
            $table->date('work_date');
            $table->string('schedule_type', 20)->default('shift'); // shift | off

            // Snapshot untuk menjaga histori payroll/report walau master shift/outlet berubah.
            $table->string('outlet_code_snapshot', 50)->nullable();
            $table->string('outlet_name_snapshot', 150)->nullable();
            $table->string('outlet_timezone_snapshot', 80)->default('Asia/Jakarta');
            $table->string('shift_name_snapshot', 100)->nullable();
            $table->time('start_time_snapshot')->nullable();
            $table->time('end_time_snapshot')->nullable();
            $table->boolean('is_overnight_snapshot')->default(false);

            $table->foreignUlid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mapping_source', 30)->default('manual');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date'], 'hr_sched_emp_date_uq');
            $table->index(['outlet_id', 'work_date'], 'hr_sched_outlet_date_idx');
            $table->index(['work_date', 'schedule_type'], 'hr_sched_date_type_idx');
            $table->index(['shift_id'], 'hr_sched_shift_idx');
        });

        Schema::table('HR_shift_schedules', function (Blueprint $table) {
            $table->foreign('shift_id', 'hr_sched_shift_fk')
                ->references('id')->on('HR_shifts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('HR_shift_schedules');
    }
};
