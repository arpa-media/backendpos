<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrKpiPreviewI05Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrBackofficePatchIteration05CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i05-check';
    protected $description = 'Smoke-check HR Backoffice Patch Iteration 05 KPI preview and draft-as-zero mapping rule.';

    public function handle(): int
    {
        $backendService=(string)@file_get_contents(app_path('Services/HumanResource/HrKpiBonusService.php'));
        $frontendRoot=realpath(base_path('../frontend - Backoffice')) ?: base_path('../frontend - Backoffice');
        $mappingPage=(string)@file_get_contents($frontendRoot.'/src/pages/human-resource/HumanResourceKpiMappingPage.vue');
        $dailyPage=(string)@file_get_contents($frontendRoot.'/src/pages/ReportKpiSquadPage.vue');
        $api=(string)@file_get_contents($frontendRoot.'/src/lib/humanResourceKpiPreviewI05Api.js');
        $routeFile=(string)@file_get_contents(base_path('routes/hr_modules/41-kpi-preview-i05.php'));

        $mappingMenu=Schema::hasTable('access_menus')
            ? DB::table('access_menus')->where('code','hr-mapping-kpi')->first()
            : null;

        $checks=[
            'Tabel Daily KPI tersedia' => Schema::hasTable('HR_kpi_daily_reviews') && Schema::hasTable('HR_kpi_daily_entries'),
            'Preview service I05 tersedia' => class_exists(HrKpiPreviewI05Service::class),
            'Preview route terdaftar' => Route::has('hr.kpi.i05.preview'),
            'Preview route memakai KPI Mapping View' => str_contains($routeFile,'permission_or_snapshot:hr.kpi.mapping.view'),
            'Access Matrix Mapping KPI tetap canonical' => $mappingMenu && (string)$mappingMenu->permission_view==='hr.kpi.mapping.view',
            'Rule version KPI berubah ke V2 Draft Zero' => str_contains($backendService,'KPI_COMPOSITE_V2_DRAFT_ZERO'),
            'Draft KPI 1 dihitung eksplisit sebagai 0' => str_contains($backendService,"CASE WHEN r.status='locked' THEN e.raw_score ELSE 0 END"),
            'Audit snapshot menyimpan locked/draft day count' => str_contains($backendService,"'locked_days'") && str_contains($backendService,"'draft_days'") && str_contains($backendService,'daily_review_status_summary'),
            'Daily KPI source tidak dimutasi oleh rule I05' => !str_contains($this->calculateKpiChunk($backendService),"DB::table('HR_kpi_daily_reviews')->update") && !str_contains($this->calculateKpiChunk($backendService),"DB::table('HR_kpi_daily_entries')->update"),
            'Personalia KPI mempunyai tab Preview KPI' => str_contains($mappingPage,'Preview KPI') && str_contains($mappingPage,'fetchKpiPreviewI05'),
            'Preview cell deep-link ke Daily KPI' => str_contains($mappingPage,"path:'/report/kpi-squad'") && str_contains($mappingPage,"outlet_id:outlet.id") && str_contains($mappingPage,'date:cell.date'),
            'Daily KPI membaca query outlet dan tanggal' => str_contains($dailyPage,'useRoute') && str_contains($dailyPage,'route.query?.outlet_id') && str_contains($dailyPage,'route.query?.date'),
            'Frontend API preview terpasang' => str_contains($api,'/human-resource/kpi-mapping/preview'),
        ];

        $failed=false;
        foreach($checks as$label=>$ok){$this->line(($ok?'<info>[PASS]</info> ':'<error>[FAIL]</error> ').$label);$failed=$failed||!(bool)$ok;}

        $this->newLine();
        $this->line('<info>[INFO]</info> I05 tidak membuat permission/menu baru. Preview berada di Personalia → KPI dan mengikuti View existing: hr.kpi.mapping.view.');
        $this->line('<info>[INFO]</info> Recalculate Mapping KPI membaca DRAFT untuk audit tetapi effective KPI 1 = 0; data Daily KPI draft tidak diubah.');
        return $failed?self::FAILURE:self::SUCCESS;
    }

    private function calculateKpiChunk(string $source): string
    {
        $start=strpos($source,'private function calculateKpiRows');
        if($start===false)return'';
        $end=strpos($source,'private function seedProjectionLines',$start);
        return substr($source,$start,$end===false?null:$end-$start);
    }
}
