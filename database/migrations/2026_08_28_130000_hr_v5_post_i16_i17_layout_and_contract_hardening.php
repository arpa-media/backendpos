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
        $this->repairVerbalWarningSoftDeletes();
        $this->seedLifecycleContractTemplates();
        $this->seedLifecycleContractSigners();
    }

    public function down(): void
    {
        // Non-destructive by design. I17 may be applied after users have edited
        // lifecycle templates/signers or created verbal-warning audit history.
    }

    private function repairVerbalWarningSoftDeletes(): void
    {
        if (! Schema::hasTable('HR_verbal_warnings') || Schema::hasColumn('HR_verbal_warnings', 'deleted_at')) return;
        Schema::table('HR_verbal_warnings', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    private function seedLifecycleContractTemplates(): void
    {
        if (! Schema::hasTable('HR_contract_document_templates')) return;

        $source = DB::table('HR_contract_document_templates')
            ->where('document_type', 'contract')
            ->orderByDesc('is_active')
            ->orderByDesc('version')
            ->first();
        if (! $source) return;

        $stages = [
            'contract_spt' => ['SPT', 'SK Kontrak Kerja - SPT'],
            'contract_pkwt1' => ['PKWT1', 'SK Kontrak Kerja - PKWT1'],
            'contract_pkwt2' => ['PKWT2', 'SK Kontrak Kerja - PKWT2'],
            'contract_pkwt3' => ['PKWT3', 'SK Kontrak Kerja - PKWT3'],
            'contract_pkwt4' => ['PKWT4', 'SK Kontrak Kerja - PKWT4'],
            'contract_pkwt5' => ['PKWT5', 'SK Kontrak Kerja - PKWT5'],
            'contract_pkwtt' => ['PKWTT', 'SK Kontrak Kerja - PKWTT'],
        ];
        $now = now();

        foreach ($stages as $key => [$stage, $name]) {
            $existing = DB::table('HR_contract_document_templates')->where('document_type', $key)->orderByDesc('version')->first();
            if ($existing) {
                if (! DB::table('HR_contract_document_templates')->where('document_type', $key)->where('is_active', true)->exists()) {
                    DB::table('HR_contract_document_templates')->where('id', $existing->id)->update(['is_active' => true, 'updated_at' => $now]);
                }
                continue;
            }
            $title = trim((string) ($source->title_template ?? 'SK Kontrak Kerja — {{full_name}}'));
            if (! str_contains(strtoupper($title), $stage)) $title .= ' · '.$stage;

            DB::table('HR_contract_document_templates')->insert([
                'id' => (string) Str::ulid(),
                'document_type' => $key,
                'name' => $name,
                'version' => 1,
                'title_template' => $title,
                'body_template' => (string) $source->body_template,
                'is_active' => true,
                'created_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedLifecycleContractSigners(): void
    {
        if (! Schema::hasTable('HR_document_signers')) return;
        $keys = ['contract_spt','contract_pkwt1','contract_pkwt2','contract_pkwt3','contract_pkwt4','contract_pkwt5','contract_pkwtt'];
        $now = now();

        foreach (['BKJB','MDMF'] as $companyCode) {
            $source = DB::table('HR_document_signers')
                ->where('template_key', 'contract')
                ->where('company_code', $companyCode)
                ->first();
            if (! $source) continue;

            foreach ($keys as $key) {
                if (DB::table('HR_document_signers')->where('template_key', $key)->where('company_code', $companyCode)->exists()) continue;
                DB::table('HR_document_signers')->insert([
                    'id' => (string) Str::ulid(),
                    'template_key' => $key,
                    'company_code' => $companyCode,
                    'source_type' => $source->source_type ?? 'default',
                    'source_user_id' => $source->source_user_id ?? null,
                    'source_employee_id' => $source->source_employee_id ?? null,
                    'signer_name' => $source->signer_name,
                    'signer_role' => $source->signer_role,
                    'signature_path' => $source->signature_path ?? null,
                    'signature_mime' => $source->signature_mime ?? null,
                    'signature_sha256' => $source->signature_sha256 ?? null,
                    'is_active' => (bool) ($source->is_active ?? true),
                    'created_by_user_id' => null,
                    'updated_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
