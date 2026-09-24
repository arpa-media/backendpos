<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_overtimes')) {
            throw new RuntimeException('HR Backoffice Patch I07 membutuhkan I06: tabel HR_overtimes belum tersedia.');
        }
        if (! Schema::hasTable('HR_payroll_cutoffs') || ! Schema::hasTable('HR_payroll_slips')) {
            throw new RuntimeException('HR Backoffice Patch I07 membutuhkan modul Payroll Cutoff.');
        }

        if (! Schema::hasTable('HR_payroll_overtime_snapshots')) {
            Schema::create('HR_payroll_overtime_snapshots', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('cutoff_id', 26)->index();
                $table->char('slip_id', 26)->index();
                $table->char('overtime_id', 26)->index();
                $table->char('employee_id', 26)->index();
                $table->char('outlet_id', 26)->nullable()->index();
                $table->date('business_date')->index();
                $table->string('source', 32)->nullable();
                $table->unsignedInteger('overtime_minutes')->default(0);
                $table->decimal('overtime_rate_snapshot', 15, 2)->default(0);
                $table->decimal('amount_snapshot', 18, 2)->default(0);
                $table->string('sync_version', 32)->default('I07_OVERTIME_PAYROLL_V1');
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();

                $table->unique(['slip_id', 'overtime_id'], 'hr_pay_ot_snap_slip_ot_uq');
                $table->index(['cutoff_id', 'employee_id'], 'hr_pay_ot_snap_cut_emp_idx');
                $table->index(['cutoff_id', 'business_date'], 'hr_pay_ot_snap_cut_date_idx');

                $table->foreign('slip_id', 'hr_pay_ot_snap_slip_fk')
                    ->references('id')->on('HR_payroll_slips')->cascadeOnDelete();
                $table->foreign('overtime_id', 'hr_pay_ot_snap_ot_fk')
                    ->references('id')->on('HR_overtimes')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Payroll/overtime snapshots are audit data.
    }
};
