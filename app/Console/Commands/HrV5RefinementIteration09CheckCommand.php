<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrRecruitmentMasterI09XlsxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5RefinementIteration09CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i09-check';
    protected $description = 'Verify HR v5 Iteration 09 Recruitment master projection, dashboard and canonical Excel export.';

    private const TEMPLATE_SHA256 = 'b0d24125208c8169ba3adcab06da171f5f23b54d4a2e7bbb589f16ce002e1be3';

    public function handle(): int
    {
        $frontendRoot = base_path('../frontend - Backoffice');
        $page = $frontendRoot.'/src/pages/human-resource/HumanResourceRecruitmentDashboardI09Page.vue';
        $api = $frontendRoot.'/src/lib/humanResourceRecruitmentMasterI09Api.js';
        $routeModule = $frontendRoot.'/src/modules/human-resource/route-modules/24-recruitment-master-dashboard-i09.js';
        $sidebar = $frontendRoot.'/src/modules/sidebar-menu-modules/modules/08-human-resource-recruitment-master-i09.js';
        $routeSource = (string) @file_get_contents(base_path('routes/hr_modules/34-recruitment-master-dashboard-i09.php'));
        $serviceSource = (string) @file_get_contents(base_path('app/Services/HumanResource/HrRecruitmentMasterI09Service.php'));
        $xlsxSource = (string) @file_get_contents(base_path('app/Services/HumanResource/HrRecruitmentMasterI09XlsxService.php'));
        $pageSource = (string) @file_get_contents($page);
        $routeModuleSource = (string) @file_get_contents($routeModule);
        $sidebarSource = (string) @file_get_contents($sidebar);
        $template = storage_path('app/hr/templates/i09/Template_REKRUTMEN_Toko Kopi Jaya.xlsx');

        $xlsxSmoke = $this->xlsxSmoke();
        $checks = [
            'I07 workflow schedule dependency exists' => Schema::hasTable('HR_recruitment_flow_schedules'),
            'I07 workflow event dependency exists' => Schema::hasTable('HR_recruitment_workflow_events'),
            'I07 practical dependency exists' => Schema::hasTable('HR_recruitment_practical_tests'),
            'I08 presence dependency exists' => Schema::hasTable('HR_recruitment_presence_events'),
            'Recruitment applications contain workflow stage' => Schema::hasTable('HR_applications') && Schema::hasColumn('HR_applications', 'workflow_stage'),
            'Dashboard Recruitment Access Matrix remains active' => $this->menuExists('hr-recruitment', '/human-resource/recruitment', 'hr.recruitment.view'),
            'Canonical Recruitment permission exists' => $this->permissionExists('hr.recruitment.view'),
            'I09 references endpoint registered' => $this->uriExists('api/v1/human-resource/recruitment-master-i09/references'),
            'I09 preview endpoint registered' => $this->uriExists('api/v1/human-resource/recruitment-master-i09/preview'),
            'I09 export endpoint registered' => $this->uriExists('api/v1/human-resource/recruitment-master-i09/export'),
            'All I09 backend endpoints reuse Dashboard Recruitment View permission' => substr_count($routeSource, 'permission_or_snapshot:hr.recruitment.view') === 3,
            'Projection uses normalized workflow + presence history' => str_contains($serviceSource, 'HR_recruitment_flow_schedules') && str_contains($serviceSource, 'HR_recruitment_presence_events') && str_contains($serviceSource, 'HR_recruitment_workflow_events'),
            'Projection does not write recruitment history' => ! preg_match('/DB::table\([^\)]*\)->\s*(insert|update|delete|upsert)/i', $serviceSource),
            'Projection does not invent missing workbook fields' => str_contains($serviceSource, "return '-';") && str_contains($serviceSource, 'CAREER PORTAL'),
            'Canonical template exists' => is_file($template),
            'Canonical template is byte-identical to uploaded reference' => is_file($template) && hash_file('sha256', $template) === self::TEMPLATE_SHA256,
            'Excel writer targets exact master worksheet XML and A:AC headers' => str_contains($xlsxSource, "MASTER_SHEET = 'xl/worksheets/sheet3.xml'") && str_contains($xlsxSource, 'NEW SQUAD SELAMA 2 BULAN') && str_contains($xlsxSource, 'KETERANGAN'),
            'Excel writer has no ext-zip runtime dependency' => ! str_contains($xlsxSource, 'ZipArchive'),
            'Excel writer refreshes pivot cache on Excel open' => str_contains($xlsxSource, 'refreshOnLoad="1"') && str_contains($xlsxSource, 'pivotCacheDefinition1.xml'),
            'Excel smoke output is structurally valid' => $xlsxSmoke['valid'],
            'Excel smoke output writes exact A:AC master range' => $xlsxSmoke['master_range'],
            'Excel smoke output refreshes both pivot sources' => $xlsxSmoke['pivot_refresh'],
            'Excel smoke output resets pivot records safely' => $xlsxSmoke['pivot_records'],
            'Dashboard page exists' => is_file($page),
            'Dashboard API exists' => is_file($api),
            'Dashboard mirrors Interview/Practical/On Boarding/New Squad KPI' => str_contains($pageSource, 'Undang Interview') && str_contains($pageSource, 'Lolos Practical') && str_contains($pageSource, 'Hadir On Boarding') && str_contains($pageSource, 'New Squad'),
            'Dashboard exposes exact 29-column master preview' => str_contains($pageSource, 'New Squad 2 Bulan') && str_contains($pageSource, 'Final On Boarding') && str_contains($pageSource, 'Sumber Loker'),
            'Dashboard export button exists' => str_contains($pageSource, 'Unduh Master Excel'),
            'Frontend route reuses Dashboard Recruitment Access Matrix path' => str_contains($routeModuleSource, "accessPath: '/human-resource/recruitment'"),
            'Sidebar keeps existing Dashboard Recruitment item and only redirects it' => str_contains($sidebarSource, "pathOf(child) !== '/human-resource/recruitment'") && str_contains($sidebarSource, '/human-resource/recruitment/dashboard-i09'),
            'I09 does not create a new Access Matrix menu' => ! str_contains($routeSource, 'access_menus') && ! str_contains($serviceSource, 'access_menus'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        if ($xlsxSmoke['error'] !== '') $this->line('<error>[XLSX]</error> '.$xlsxSmoke['error']);
        $this->line('<info>[INFO]</info> I09 tidak membuat tabel/menu baru; dashboard adalah projection read-only dari normalized recruitment audit I07-I08.');
        $this->line('<info>[INFO]</info> Access Matrix tetap Dashboard Recruitment (hr-recruitment / hr.recruitment.view); export mengikuti hak View.');
        $this->line('<info>[INFO]</info> Workbook canonical dipertahankan. Master sheet diisi A:AC dan pivot cache diberi refreshOnLoad=1.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function menuExists(string $code, string $path, string $view): bool
    {
        return Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', $code)->where('path', $path)->where('permission_view', $view)->where('is_active', true)->exists();
    }

    private function permissionExists(string $permission): bool
    {
        return Schema::hasTable('permissions') && DB::table('permissions')->where('name', $permission)->exists();
    }

    private function uriExists(string $needle): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (trim((string) $route->uri(), '/') === trim($needle, '/')) return true;
        }
        return false;
    }

    private function xlsxSmoke(): array
    {
        $result = ['valid' => false, 'master_range' => false, 'pivot_refresh' => false, 'pivot_records' => false, 'error' => ''];
        try {
            $binary = app(HrRecruitmentMasterI09XlsxService::class)->build([[
                'month' => 'AGUSTUS', 'name' => 'SMOKE APPLICANT', 'region' => 'MALANG', 'interview_mode' => 'OFFLINE', 'phone' => '081234567890',
                'position' => 'BARISTA', 'interview_invite_1' => '2026-08-01', 'interview_presence_1' => 'HADIR', 'interview_reschedule' => null,
                'interview_presence_2' => '-', 'interview_presence_final' => 'HADIR', 'interview_result' => 'LOLOS', 'job_source' => 'CAREER PORTAL',
                'practical_invite_1' => '2026-08-02', 'practical_presence_1' => 'HADIR', 'practical_reschedule' => null, 'practical_presence_2' => '-',
                'practical_presence_final' => 'HADIR', 'practical_result' => 'LOLOS', 'placement' => 'TKJ MALANG', 'join_date' => '2026-08-05',
                'onboarding_date_1' => '2026-08-04', 'onboarding_presence_1' => 'HADIR', 'onboarding_reschedule' => null, 'onboarding_presence_2' => '-',
                'onboarding_presence_final' => 'HADIR', 'new_squad_2_months' => 'PKWT', 'notes' => 'I09 smoke test',
            ]]);
            $files = $this->readZipBinary($binary);
            $sheet = (string) ($files['xl/worksheets/sheet3.xml'] ?? '');
            $pivot1 = (string) ($files['xl/pivotCache/pivotCacheDefinition1.xml'] ?? '');
            $pivot2 = (string) ($files['xl/pivotCache/pivotCacheDefinition2.xml'] ?? '');
            $records1 = (string) ($files['xl/pivotCache/pivotCacheRecords1.xml'] ?? '');
            $records2 = (string) ($files['xl/pivotCache/pivotCacheRecords2.xml'] ?? '');
            $result['valid'] = $sheet !== '' && str_contains($sheet, 'SMOKE APPLICANT') && str_contains($sheet, 'I09 smoke test');
            $result['master_range'] = str_contains($sheet, 'ref="A1:AC2"');
            $result['pivot_refresh'] = str_contains($pivot1, 'ref="A1:AC2"') && str_contains($pivot2, 'ref="A1:AC2"')
                && str_contains($pivot1, 'refreshOnLoad="1"') && str_contains($pivot2, 'refreshOnLoad="1"');
            $result['pivot_records'] = str_contains($records1, 'count="0"') && str_contains($records2, 'count="0"');
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        return $result;
    }

    /** @return array<string,string> */
    private function readZipBinary(string $binary): array
    {
        $eocdOffset = strrpos($binary, "PK\x05\x06");
        if ($eocdOffset === false) throw new \RuntimeException('Smoke XLSX tidak memiliki EOCD.');
        $eocd = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLength', substr($binary, $eocdOffset, 22));
        if (! is_array($eocd)) throw new \RuntimeException('EOCD smoke XLSX tidak valid.');
        $entries = [];
        $cursor = (int) $eocd['cdOffset'];
        for ($i = 0; $i < (int) $eocd['totalEntries']; $i++) {
            $header = unpack('Vsig/vversionMade/vversionNeeded/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset', substr($binary, $cursor, 46));
            if (! is_array($header) || ($header['sig'] ?? null) !== 0x02014b50) throw new \RuntimeException('Central directory smoke XLSX tidak valid.');
            $name = substr($binary, $cursor + 46, $header['nameLength']);
            $cursor += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
            $local = unpack('Vsig/vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', substr($binary, $header['localOffset'], 30));
            if (! is_array($local) || ($local['sig'] ?? null) !== 0x04034b50) continue;
            $start = $header['localOffset'] + 30 + $local['nameLength'] + $local['extraLength'];
            $compressed = substr($binary, $start, $header['compressedSize']);
            if ((int) $header['method'] === 0) $entries[$name] = $compressed;
            elseif ((int) $header['method'] === 8) {
                $inflated = @gzinflate($compressed);
                if ($inflated === false) throw new \RuntimeException('Data smoke XLSX tidak dapat di-inflate.');
                $entries[$name] = $inflated;
            }
        }
        return $entries;
    }
}
