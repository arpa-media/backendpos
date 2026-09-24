<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrContractLifecycleXlsxService;
use App\Services\HumanResource\HrContractStageLifecycleService;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class HrV5RefinementIteration05CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i05-check';
    protected $description = 'Verify HR v5 Iteration 05 contract lifecycle SPT-PKWT5-PKWTT and recap patch.';

    public function handle(
        HrContractStageLifecycleService $lifecycle,
        HrContractLifecycleXlsxService $xlsx,
        SimpleXlsxService $reader,
    ): int {
        $template = storage_path('app/hr/contract-i05/REKAP KONTRAK SPT PKWT 2026.xlsx');
        $frontendRoot = base_path('../frontend - Backoffice');
        $page = $frontendRoot.'/src/pages/human-resource/HumanResourceContractLifecyclePage.vue';
        $routeModule = $frontendRoot.'/src/modules/human-resource/route-modules/21-contract-lifecycle.js';
        $sidebarModule = $frontendRoot.'/src/modules/sidebar-menu-modules/modules/05-human-resource-contract-lifecycle.js';
        $apiModule = $frontendRoot.'/src/lib/humanResourceContractLifecycleApi.js';

        $rules = collect($lifecycle->durationRules())->keyBy('key');
        $checks = [
            'HR_contracts exists' => Schema::hasTable('HR_contracts'),
            'Lifecycle stage column' => Schema::hasTable('HR_contracts') && Schema::hasColumn('HR_contracts', 'lifecycle_stage'),
            'Lifecycle group column' => Schema::hasTable('HR_contracts') && Schema::hasColumn('HR_contracts', 'lifecycle_group'),
            'Lifecycle review column' => Schema::hasTable('HR_contracts') && Schema::hasColumn('HR_contracts', 'lifecycle_review_status'),
            'Next contract due column' => Schema::hasTable('HR_contracts') && Schema::hasColumn('HR_contracts', 'next_contract_due_at'),
            'Lifecycle audit table' => Schema::hasTable('HR_contract_lifecycle_events'),
            'Access Matrix lifecycle menu' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'hr-mapping-contract-lifecycle')->where('path', '/human-resource/mapping-contract/lifecycle')->exists(),
            'Contract view permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.contract.view')->exists(),
            'Contract create permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.contract.create')->exists(),
            'Contract update permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.contract.update')->exists(),
            'Lifecycle references route' => Route::has('hr.contract.lifecycle.references'),
            'Lifecycle index route' => Route::has('hr.contract.lifecycle.index'),
            'Lifecycle recap route' => Route::has('hr.contract.lifecycle.recap'),
            'Lifecycle export route' => Route::has('hr.contract.lifecycle.export'),
            'Lifecycle resolve route' => Route::has('hr.contract.lifecycle.resolve'),
            'Lifecycle transition route' => Route::has('hr.contract.lifecycle.transition'),
            'SPT Squad duration = 61' => (int) data_get($rules->get('SPT_SQUAD'), 'days', -1) === 61,
            'SPT Management duration = 92' => (int) data_get($rules->get('SPT_MANAGEMENT'), 'days', -1) === 92,
            'PKWT Finance duration = 184' => (int) data_get($rules->get('PKWT_FINANCE'), 'days', -1) === 184,
            'PKWT Standard duration = 365' => (int) data_get($rules->get('PKWT_STANDARD'), 'days', -1) === 365,
            'Reference workbook present' => is_file($template) && filesize($template) > 0,
            'Frontend lifecycle page' => is_file($page),
            'Frontend lifecycle API' => is_file($apiModule),
            'Frontend route module' => is_file($routeModule),
            'Frontend sidebar module' => is_file($sidebarModule),
            'Own Access Matrix path used' => is_file($routeModule) && str_contains((string) file_get_contents($routeModule), "accessPath: '/human-resource/mapping-contract/lifecycle'"),
            'All seven lifecycle stages exposed' => is_file($page)
                && collect(['SPT','PKWT1','PKWT2','PKWT3','PKWT4','PKWT5','PKWTT'])->every(fn (string $stage) => str_contains((string) file_get_contents($page), $stage)),
            'Recap download action exposed' => is_file($page) && str_contains((string) file_get_contents($page), 'Unduh Excel'),
        ];

        [$xlsxOk, $xlsxMessage] = $this->xlsxSmoke($xlsx, $reader);
        $checks['XLSX recap smoke'] = $xlsxOk;

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }
        $this->line(($xlsxOk ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').'XLSX smoke detail: '.$xlsxMessage);
        $this->line('<info>[INFO]</info> Plain PKWT legacy sengaja tidak ditebak stage-nya; record ambigu harus di-resolve manual.');
        $this->line('<info>[INFO]</info> I05 menambah Access Matrix sendiri dan mewarisi grant awal dari menu Contract existing.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0:bool,1:string} */
    private function xlsxSmoke(HrContractLifecycleXlsxService $xlsx, SimpleXlsxService $reader): array
    {
        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir) && ! @mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            return [false, 'storage/app/tmp tidak dapat dibuat'];
        }
        $tmp = $tmpDir.'/hr_i05_contract_recap_smoke.xlsx';
        $stage = fn (string $kind, string $start, ?string $end, ?string $renewal, string $status = 'DONE'): array => [
            'contract_kind' => $kind,
            'start_date' => $start,
            'end_date' => $end,
            'renewal_date' => $renewal,
            'salary_tier_name' => 'LEVEL 2',
            'nominal_fee' => 3300000,
            'status' => $status,
        ];

        try {
            $binary = $xlsx->build([
                'rows' => [[
                    'assignment_label' => 'OUTLET',
                    'lifecycle_group' => 'SQUAD',
                    'outlet_name' => 'I05 OUTLET',
                    'full_name' => 'I05 Smoke Squad',
                    'position_name' => 'BARISTA',
                    'division_name' => 'BAR',
                    'stages' => [
                        'SPT' => $stage('SPT SQUAD', '2026-01-01', '2026-03-03', '2026-03-04'),
                        'PKWT1' => $stage('PKWT', '2026-03-04', '2027-03-04', '2027-03-05'),
                        'PKWT2' => $stage('PKWT', '2027-03-05', '2028-03-04', '2028-03-05'),
                        'PKWT3' => $stage('PKWT', '2028-03-05', '2029-03-05', '2029-03-06'),
                        'PKWT4' => $stage('PKWT', '2029-03-06', '2030-03-06', '2030-03-07'),
                        'PKWT5' => $stage('PKWT', '2030-03-07', '2031-03-07', '2031-03-08'),
                        'PKWTT' => $stage('PKWTT', '2031-03-08', null, null, 'ACTIVE'),
                    ],
                ]],
            ]);
            if ($binary === '' || file_put_contents($tmp, $binary) === false) return [false, 'binary smoke tidak dapat ditulis'];
            $sheets = $reader->readWorksheets($tmp);
            $flat = [];
            foreach ($sheets as $sheet) {
                foreach (($sheet['rows'] ?? []) as $row) foreach ($row as $cell) $flat[] = (string) $cell;
            }
            $required = ['SPT', 'PKWT 1', 'PKWT 2', 'PKWT 3', 'PKWT 4', 'PKWT 5', 'PKWTT', 'I05 Smoke Squad', 'SQUAD', 'BARISTA'];
            $ok = collect($required)->every(fn (string $needle) => in_array($needle, $flat, true));
            return [$ok, $ok ? '7 stage + mapping SQUAD/BARISTA terbaca pada workbook hasil export' : 'stage/mapping workbook hasil export tidak lengkap'];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }
}
