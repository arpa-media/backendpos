<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrDocumentTemplateCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrV5RefinementIteration02CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i02-check';
    protected $description = 'Validate HR v5 refinement iteration 02 document foundation, canonical templates, branding, and payroll slip logo.';

    public function handle(HrDocumentTemplateCatalog $catalog): int
    {
        $frontendRoot = base_path('../frontend - Backoffice');
        $contractPage = $this->read($frontendRoot.'/src/pages/human-resource/HumanResourceContractPage.vue');
        $punishmentPage = $this->read($frontendRoot.'/src/pages/human-resource/HumanResourcePunishmentPage.vue');
        $approvalPage = $this->read($frontendRoot.'/src/pages/human-resource/HumanResourceSpApprovalPage.vue');
        $printLib = $this->read($frontendRoot.'/src/lib/humanResourceDocumentPrint.js');
        $payrollLib = $this->read($frontendRoot.'/src/lib/payrollSlipPrint.js');
        $contractService = $this->read(app_path('Services/HumanResource/HrContractDocumentService.php'));
        $punishmentService = $this->read(app_path('Services/HumanResource/HrPunishmentService.php'));

        $canonicalKeys = $catalog->keys();
        $templateRowsOk = false;
        if (Schema::hasTable('HR_contract_document_templates')) {
            $active = DB::table('HR_contract_document_templates')
                ->whereIn('document_type', $canonicalKeys)
                ->where('is_active', true)
                ->pluck('document_type')
                ->unique()
                ->values()
                ->all();
            $templateRowsOk = count(array_intersect($canonicalKeys, $active)) === count($canonicalKeys);
        }

        $contractColumnsOk = Schema::hasTable('HR_contract_documents')
            && $this->hasColumns('HR_contract_documents', ['template_key', 'company_code', 'letter_code', 'branding_snapshot']);
        $warningColumnsOk = Schema::hasTable('HR_warning_letters')
            && $this->hasColumns('HR_warning_letters', ['template_key', 'company_code', 'letter_code', 'branding_snapshot']);

        $sourceDir = storage_path('app/hr/templates/source');
        $sourceFilesOk = collect($catalog->definitions())->every(
            fn (array $definition) => is_file($sourceDir.'/'.$definition['source_name'])
        );

        $checks = [
            'Sequence nomor dokumen HR tersedia' => Schema::hasTable('HR_document_number_sequences'),
            'HR_contract_documents memiliki snapshot branding canonical' => $contractColumnsOk,
            'HR_warning_letters memiliki snapshot branding canonical' => $warningColumnsOk,
            'Tepat 9 canonical template aktif tersedia' => count($canonicalKeys) === 9 && $templateRowsOk,
            '9 source DOCX canonical tersedia di storage' => $sourceFilesOk,
            'Logo HR backend tersedia' => is_file(storage_path('app/hr/branding/logo-hr.png')),
            'Signature HR backend tersedia' => is_file(storage_path('app/hr/branding/signature-ray.jpg')),
            'Logo HR frontend tersedia' => is_file($frontendRoot.'/public/hr/logo-hr.png'),
            'Signature HR frontend tersedia' => is_file($frontendRoot.'/public/hr/signature-ray.jpg'),
            'Nomor canonical SPN/SPMK/SKD digunakan pada Contract' => str_contains($contractService, '$this->numbers->allocate')
                && str_contains($contractService, "['promotion', 'transfer', 'demotion']"),
            'Surat Peringatan menggunakan nomor canonical SP' => str_contains($punishmentService, "\$this->numbers->allocate('SP'")
                && str_contains($punishmentService, "keyFor('warning'"),
            'Preview Contract memakai HR document renderer' => str_contains($contractPage, 'openHrDocumentPrint')
                && str_contains($contractPage, 'previewHrTemplate'),
            'Preview Punishment memakai HR document renderer' => str_contains($punishmentPage, 'printHrWarningLetter'),
            'Preview Approval SP memakai HR document renderer' => str_contains($approvalPage, 'printHrWarningLetter'),
            'Renderer mendukung Teguran Lisan 4-up' => str_contains($printLib, 'verbal_warning_4up')
                && str_contains($printLib, 'verbal-grid'),
            'Slip gaji memakai Logo HR canonical' => str_contains($payrollLib, 'hrBrandingForCompany')
                && str_contains($payrollLib, 'brand.logo_asset'),
            'Kode template canonical lengkap' => $this->canonicalCodesOk($catalog->definitions()),
        ];

        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%s %s', $ok ? '[OK]' : '[FAIL]', $label));
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) return false;
        }
        return true;
    }

    private function canonicalCodesOk(array $definitions): bool
    {
        $expected = [
            'promotion_bkjb' => ['BKJB', 'SPN'],
            'promotion_mdmf' => ['MDMF', 'SPN'],
            'transfer_mdmf' => ['MDMF', 'SPMK'],
            'transfer_bkjb' => ['BKJB', 'SPMK'],
            'demotion_mdmf' => ['MDMF', 'SKD'],
            'demotion_bkjb' => ['BKJB', 'SKD'],
            'warning_bkjb' => ['BKJB', 'SP'],
            'warning_mdmf' => ['MDMF', 'SP'],
            'verbal_warning' => [null, null],
        ];

        foreach ($expected as $key => [$company, $letter]) {
            if (! isset($definitions[$key])) return false;
            if (($definitions[$key]['company_code'] ?? null) !== $company) return false;
            if (($definitions[$key]['letter_code'] ?? null) !== $letter) return false;
        }
        return count($definitions) === count($expected);
    }
}
