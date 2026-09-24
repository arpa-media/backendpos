<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_document_signers')) {
            Schema::create('HR_document_signers', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('template_key', 80);
                $table->string('company_code', 16)->default('GLOBAL');
                $table->string('source_type', 20)->default('custom');
                $table->foreignUlid('source_user_id')->nullable();
                $table->foreignUlid('source_employee_id')->nullable();
                $table->string('signer_name', 180);
                $table->string('signer_role', 180);
                $table->string('signature_path', 255)->nullable();
                $table->string('signature_mime', 80)->nullable();
                $table->char('signature_sha256', 64)->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignUlid('created_by_user_id')->nullable();
                $table->foreignUlid('updated_by_user_id')->nullable();
                $table->timestamps();

                $table->unique(['template_key', 'company_code'], 'hr_doc_signer_tpl_company_uq');
                $table->index(['company_code', 'is_active'], 'hr_doc_signer_company_idx');
                $table->foreign('source_user_id', 'hr_doc_signer_user_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('source_employee_id', 'hr_doc_signer_emp_fk')->references('id')->on('employees')->nullOnDelete();
                $table->foreign('created_by_user_id', 'hr_doc_signer_creator_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by_user_id', 'hr_doc_signer_updater_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_verbal_warnings')) {
            Schema::create('HR_verbal_warnings', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('employee_id');
                $table->unsignedBigInteger('squad_id')->nullable();
                $table->foreignUlid('outlet_id')->nullable();
                $table->string('template_key', 80)->default('verbal_warning');
                $table->string('company_code', 16);
                $table->string('letter_code', 16)->default('TL');
                $table->string('letter_no', 100)->unique('hr_verbal_warning_no_uq');
                $table->date('issue_date');
                $table->string('title', 250)->default('FORM TEGURAN LISAN');
                $table->text('reason');
                $table->longText('body_snapshot');
                $table->json('employee_snapshot')->nullable();
                $table->json('branding_snapshot')->nullable();
                $table->string('status', 24)->default('issued');
                $table->foreignUlid('created_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['outlet_id', 'issue_date'], 'hr_verbal_warning_outlet_date_idx');
                $table->index(['employee_id', 'issue_date'], 'hr_verbal_warning_emp_date_idx');
                $table->foreign('employee_id', 'hr_verbal_warning_emp_fk')->references('id')->on('employees')->cascadeOnDelete();
                $table->foreign('squad_id', 'hr_verbal_warning_squad_fk')->references('id')->on('HR_squads')->nullOnDelete();
                $table->foreign('outlet_id', 'hr_verbal_warning_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
                $table->foreign('created_by_user_id', 'hr_verbal_warning_creator_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        $this->seedDefaultSigners();
    }

    public function down(): void
    {
        Schema::dropIfExists('HR_verbal_warnings');
        Schema::dropIfExists('HR_document_signers');
    }

    private function seedDefaultSigners(): void
    {
        if (! Schema::hasTable('HR_document_signers')) return;
        $keys = [
            ['contract', 'BKJB'], ['contract', 'MDMF'],
            ['extension', 'BKJB'], ['extension', 'MDMF'],
            ['termination', 'BKJB'], ['termination', 'MDMF'],
            ['promotion_bkjb', 'BKJB'], ['promotion_mdmf', 'MDMF'],
            ['transfer_bkjb', 'BKJB'], ['transfer_mdmf', 'MDMF'],
            ['demotion_bkjb', 'BKJB'], ['demotion_mdmf', 'MDMF'],
            ['warning_bkjb', 'BKJB'], ['warning_mdmf', 'MDMF'],
            ['verbal_warning', 'BKJB'], ['verbal_warning', 'MDMF'],
        ];
        $now = now();
        foreach ($keys as [$templateKey, $companyCode]) {
            if (DB::table('HR_document_signers')->where('template_key', $templateKey)->where('company_code', $companyCode)->exists()) continue;
            DB::table('HR_document_signers')->insert([
                'id' => (string) Str::ulid(), 'template_key' => $templateKey, 'company_code' => $companyCode,
                'source_type' => 'default', 'source_user_id' => null, 'source_employee_id' => null,
                'signer_name' => 'Ray Fanany Muhammad', 'signer_role' => 'Human Resource Development',
                'signature_path' => 'hr/branding/signature-ray.jpg', 'signature_mime' => 'image/jpeg',
                'signature_sha256' => is_file(storage_path('app/hr/branding/signature-ray.jpg')) ? hash_file('sha256', storage_path('app/hr/branding/signature-ray.jpg')) : null,
                'is_active' => true, 'created_by_user_id' => null, 'updated_by_user_id' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
