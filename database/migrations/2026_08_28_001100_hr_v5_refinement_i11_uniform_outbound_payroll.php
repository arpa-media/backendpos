<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_uniform_outbounds')) {
            Schema::create('HR_uniform_outbounds', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('document_no', 80);
                $table->string('idempotency_key', 80)->nullable();
                $table->string('outbound_type', 20); // UNIFORM | ATTRIBUTE
                $table->date('outbound_date');
                $table->ulid('employee_id')->nullable();
                $table->string('recipient_name', 180);
                $table->ulid('outlet_id')->nullable();
                $table->string('outlet_name_snapshot', 180)->nullable();
                $table->string('company_code', 8)->nullable();
                $table->string('payroll_month', 7)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('POSTED');
                $table->ulid('created_by_user_id')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();

                $table->unique('document_no', 'hr_uout_doc_uq');
                $table->unique('idempotency_key', 'hr_uout_idem_uq');
                $table->index(['outbound_type', 'outbound_date'], 'hr_uout_type_date_idx');
                $table->index(['employee_id', 'payroll_month'], 'hr_uout_emp_month_idx');
            });
        }

        if (! Schema::hasTable('HR_uniform_outbound_lines')) {
            Schema::create('HR_uniform_outbound_lines', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('outbound_id');
                $table->ulid('uniform_item_id');
                $table->unsignedInteger('quantity');
                $table->decimal('purchase_price', 15, 2)->default(0);
                $table->decimal('squad_charge', 15, 2)->default(0);
                $table->decimal('size_charge', 15, 2)->default(0);
                $table->decimal('company_charge', 15, 2)->default(0);
                $table->decimal('manual_price', 15, 2)->default(0);
                $table->string('company_code', 8);
                $table->timestamps();

                $table->unique(['outbound_id', 'uniform_item_id'], 'hr_uol_out_item_uq');
                $table->index(['uniform_item_id', 'created_at'], 'hr_uol_item_date_idx');
                $table->foreign('outbound_id', 'hr_uol_out_fk')->references('id')->on('HR_uniform_outbounds')->restrictOnDelete();
                $table->foreign('uniform_item_id', 'hr_uol_item_fk')->references('id')->on('HR_uniform_items')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('HR_uniform_payroll_deductions')) {
            Schema::create('HR_uniform_payroll_deductions', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('outbound_id');
                $table->ulid('outbound_line_id');
                $table->ulid('employee_id');
                $table->string('payroll_month', 7);
                $table->decimal('amount', 15, 2);
                $table->string('status', 20)->default('PENDING'); // PENDING | CLAIMED | SETTLED
                $table->ulid('payroll_cutoff_id')->nullable();
                $table->ulid('payroll_slip_id')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();

                $table->unique('outbound_line_id', 'hr_upd_line_uq');
                $table->index(['employee_id', 'payroll_month', 'status'], 'hr_upd_emp_month_idx');
                $table->index(['payroll_cutoff_id', 'status'], 'hr_upd_cut_status_idx');
                $table->foreign('outbound_id', 'hr_upd_out_fk')->references('id')->on('HR_uniform_outbounds')->restrictOnDelete();
                $table->foreign('outbound_line_id', 'hr_upd_line_fk')->references('id')->on('HR_uniform_outbound_lines')->restrictOnDelete();
                $table->foreign('employee_id', 'hr_upd_emp_fk')->references('id')->on('employees')->restrictOnDelete();
                $table->foreign('payroll_cutoff_id', 'hr_upd_cut_fk')->references('id')->on('HR_payroll_cutoffs')->nullOnDelete();
                $table->foreign('payroll_slip_id', 'hr_upd_slip_fk')->references('id')->on('HR_payroll_slips')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Non-destructive: outbound stock and payroll deduction records are HR audit data.
    }
};
