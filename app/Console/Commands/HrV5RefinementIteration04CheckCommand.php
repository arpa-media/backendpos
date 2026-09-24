<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrPunishmentRecapXlsxService;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class HrV5RefinementIteration04CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i04-check';
    protected $description = 'Verify HR v5 Iteration 04 Punishment Rekap SP preview/export patch.';

    public function handle(HrPunishmentRecapXlsxService $xlsx, SimpleXlsxService $reader): int
    {
        $template = storage_path('app/hr/recap-i04/REKAP SP 2026.xlsx');
        $frontendRoot = base_path('../frontend - Backoffice');
        $recapPage = $frontendRoot.'/src/pages/human-resource/HumanResourcePunishmentRecapPage.vue';
        $punishmentPage = $frontendRoot.'/src/pages/human-resource/HumanResourcePunishmentPage.vue';
        $routeModule = $frontendRoot.'/src/modules/human-resource/route-modules/20-punishment-recap.js';
        $apiModule = $frontendRoot.'/src/lib/humanResourcePunishmentRecapApi.js';

        $checks = [
            'Punishment Access Matrix existing' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'hr-punishment')->exists(),
            'Punishment view permission existing' => Schema::hasTable('permissions') && DB::table('permissions')->whereIn('name', ['hr.punishment.view', 'hr.sp.view'])->exists(),
            'Recap references route' => Route::has('hr.v5.i04.punishment-recap.references'),
            'Recap preview route' => Route::has('hr.v5.i04.punishment-recap.preview'),
            'Recap export route' => Route::has('hr.v5.i04.punishment-recap.export'),
            'Reference workbook present' => is_file($template) && filesize($template) > 0,
            'Frontend recap page' => is_file($recapPage),
            'Frontend recap API' => is_file($apiModule),
            'Frontend recap route module' => is_file($routeModule),
            'Existing Punishment exposes Rekap SP' => is_file($punishmentPage) && str_contains((string) file_get_contents($punishmentPage), '/human-resource/punishment/rekap-sp'),
            'Recap reuses Punishment Access Matrix path' => is_file($routeModule) && str_contains((string) file_get_contents($routeModule), "accessPath: '/human-resource/punishment'"),
            'Canonical SP preview preserved' => is_file($recapPage) && str_contains((string) file_get_contents($recapPage), 'printHrWarningLetter'),
            'Active/non-active tabs present' => is_file($recapPage) && str_contains((string) file_get_contents($recapPage), 'SP AKTIF') && str_contains((string) file_get_contents($recapPage), 'SP NON AKTIF'),
            'Excel export action present' => is_file($recapPage) && str_contains((string) file_get_contents($recapPage), 'Unduh Excel'),
        ];

        [$xlsxSmokeOk, $xlsxSmokeMessage] = $this->xlsxSmoke($xlsx, $reader);
        $checks['XLSX template-preserving smoke'] = $xlsxSmokeOk;

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }
        $this->line(($xlsxSmokeOk ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').'XLSX smoke detail: '.$xlsxSmokeMessage);

        if (! Schema::hasTable('HR_warning_letters')) {
            $this->line('<comment>[WARN]</comment> HR_warning_letters belum tersedia. Jalankan migration HR Iterasi sebelumnya sebelum functional test Rekap SP.');
        } else {
            $this->line('<info>[OK]</info> HR_warning_letters tersedia untuk functional test.');
        }

        $this->line('<info>[INFO]</info> I04 tidak menambah menu baru; preview/export memakai Access Matrix Punishment existing.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0:bool,1:string} */
    private function xlsxSmoke(HrPunishmentRecapXlsxService $xlsx, SimpleXlsxService $reader): array
    {
        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir) && ! @mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            return [false, 'storage/app/tmp tidak dapat dibuat'];
        }
        $tmp = $tmpDir.'/hr_i04_rekap_smoke.xlsx';
        try {
            $base = [
                'letter_no' => 'I04-TEST-0001', 'type' => 'SP', 'previous_level' => '', 'current_level' => 'SP 1',
                'nisj' => '00001234', 'full_name' => 'I04 Smoke Squad', 'division' => 'BAR',
                'supervisor_nisj' => '00000001', 'supervisor_name' => 'I04 Smoke SPV', 'mistake' => 'Smoke test',
                'incident_date' => '2026-08-01', 'chamber' => 'HR', 'approved_by' => 'I04 Approver',
                'validity_start' => '2026-08-02', 'validity_end' => '2026-09-01', 'releaser' => 'I04 Releaser',
            ];
            $binary = $xlsx->build([
                'period' => '2026-08', 'period_label' => 'Agustus 2026',
                'active' => [[...$base, 'status' => 'AKTIF']],
                'inactive' => [[...$base, 'letter_no' => 'I04-TEST-0002', 'status' => 'NON AKTIF']],
            ]);
            if ($binary === '' || file_put_contents($tmp, $binary) === false) return [false, 'binary smoke tidak dapat ditulis'];
            $sheets = $reader->readWorksheets($tmp);
            $names = array_map(fn (array $sheet): string => (string) ($sheet['name'] ?? ''), $sheets);
            $flat = [];
            foreach ($sheets as $sheet) {
                foreach (($sheet['rows'] ?? []) as $row) {
                    foreach ($row as $cell) $flat[] = (string) $cell;
                }
            }
            $ok = in_array('SP AKTIF', $names, true)
                && in_array('SP NON AKTIF', $names, true)
                && in_array('I04-TEST-0001', $flat, true)
                && in_array('I04-TEST-0002', $flat, true)
                && in_array('00001234', $flat, true);
            return [$ok, $ok ? '2 sheet + active/non-active rows + leading-zero NISJ terbaca' : 'sheet/row/NISJ smoke tidak sesuai'];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }
}
