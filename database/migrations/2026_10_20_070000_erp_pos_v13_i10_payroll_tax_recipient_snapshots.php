<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('HR_payroll_slips')) {
            foreach (['bank_name_snapshot','bank_account_snapshot','email_snapshot'] as $column) {
                if (! Schema::hasColumn('HR_payroll_slips', $column)) {
                    Schema::table('HR_payroll_slips', function (Blueprint $table) use ($column) {
                        $table->string($column, $column === 'email_snapshot' ? 190 : 120)->nullable();
                    });
                }
            }
        }
        if (! Schema::hasTable('finance_payroll_pph21_lines')) {
            Schema::create('finance_payroll_pph21_lines', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->char('posting_id',26)->index();
                $t->char('payroll_slip_id',26);
                $t->decimal('tax_amount',18,2)->default(0);
                $t->char('actor_user_id',26)->nullable();
                $t->timestamps();
                $t->unique(['posting_id','payroll_slip_id'],'fin_pph21_line_uq');
                $t->foreign('posting_id','fin_pph21_post_fk')->references('id')->on('finance_payroll_posting_inbox')->cascadeOnDelete();
                $t->foreign('payroll_slip_id','fin_pph21_slip_fk')->references('id')->on('HR_payroll_slips')->restrictOnDelete();
            });
        }
        if (! Schema::hasTable('finance_payroll_pph21_import_batches')) {
            Schema::create('finance_payroll_pph21_import_batches', function (Blueprint $t) {
                $t->ulid('id')->primary();$t->char('posting_id',26)->index();
                $t->char('actor_user_id',26)->nullable();$t->string('filename',240);
                $t->char('sha256',64);$t->decimal('old_total',18,2);$t->decimal('new_total',18,2);
                $t->unsignedInteger('line_count');$t->timestamps();
                $t->foreign('posting_id','fin_pph21_batch_fk')->references('id')->on('finance_payroll_posting_inbox')->cascadeOnDelete();
            });
        }
        if (! Schema::hasTable('finance_payroll_recipient_snapshots')) {
            Schema::create('finance_payroll_recipient_snapshots', function (Blueprint $t) {
                $t->ulid('id')->primary();
                $t->char('posting_id',26)->index();
                $t->string('source_type',16);
                $t->char('source_line_id',26);
                $t->string('nisj',80)->nullable();
                $t->string('full_name',180)->nullable();
                $t->string('bank_name',120)->nullable();
                $t->string('bank_account',120)->nullable();
                $t->string('email',190)->nullable();
                $t->decimal('gross_amount',18,2)->default(0);
                $t->decimal('bpjs_amount',18,2)->default(0);
                $t->decimal('net_amount',18,2)->default(0);
                $t->string('snapshot_hash',64);
                $t->timestamps();
                $t->unique(['posting_id','source_type','source_line_id'],'fin_pay_recipient_uq');
                $t->foreign('posting_id','fin_pay_recip_post_fk')->references('id')->on('finance_payroll_posting_inbox')->cascadeOnDelete();
            });
        }
    }
    // Financial snapshots are immutable accounting evidence; do not DROP in down().
    public function down(): void {}
};
