<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration18CheckCommand extends Command
{
    protected $signature = 'hr:iteration-18-check';
    protected $description = 'Validate HR Iteration 18 KPI Squad daily grooming installation.';

    public function handle(): int
    {
        $routes=collect(Route::getRoutes());
        $has=fn(string $method,string $uri):bool=>$routes->contains(fn($route)=>in_array($method,$route->methods(),true)&&$route->uri()===$uri);
        $checks=[
            'KPI settings table'=>Schema::hasTable('HR_kpi_settings'),
            'Grooming criteria table'=>Schema::hasTable('HR_kpi_grooming_criteria'),
            'Daily review table'=>Schema::hasTable('HR_kpi_daily_reviews'),
            'Daily entry table'=>Schema::hasTable('HR_kpi_daily_entries'),
            'Daily score table'=>Schema::hasTable('HR_kpi_daily_scores'),
            'KPI audit table'=>Schema::hasTable('HR_kpi_daily_audits'),
            'Workbook target 25 days'=>Schema::hasTable('HR_kpi_settings')&&DB::table('HR_kpi_settings')->where('code','GROOMING_TARGET_DAYS')->where('value_json','like','%25%')->exists(),
            'Workbook grooming 40 weight'=>Schema::hasTable('HR_kpi_settings')&&DB::table('HR_kpi_settings')->where('code','GROOMING_COMPONENT_WEIGHT')->where('value_json','like','%40%')->exists(),
            'Barista max 4 criteria'=>Schema::hasTable('HR_kpi_grooming_criteria')&&DB::table('HR_kpi_grooming_criteria')->where('division_name','BARISTA')->where('is_active',true)->count()===4,
            'Kasir max 3 criteria'=>Schema::hasTable('HR_kpi_grooming_criteria')&&DB::table('HR_kpi_grooming_criteria')->where('division_name','KASIR')->where('is_active',true)->count()===3,
            'KPI Squad Access Matrix'=>Schema::hasTable('access_menus')&&DB::table('access_menus')->where('code','report-kpi-squad')->where('path','/report/kpi-squad')->where('permission_view','hr.kpi.squad.view')->exists(),
            'KPI input permission'=>Schema::hasTable('permissions')&&DB::table('permissions')->where('name','hr.kpi.squad.input')->exists(),
            'KPI lock permission'=>Schema::hasTable('permissions')&&DB::table('permissions')->where('name','hr.kpi.squad.lock')->exists(),
            'KPI reopen permission'=>Schema::hasTable('permissions')&&DB::table('permissions')->where('name','hr.kpi.squad.reopen')->exists(),
            'KPI import permission'=>Schema::hasTable('permissions')&&DB::table('permissions')->where('name','hr.kpi.squad.import')->exists(),
            'KPI violation permission'=>Schema::hasTable('permissions')&&DB::table('permissions')->where('name','hr.kpi.squad.violation.create')->exists(),
            'Daily KPI route'=>$has('GET','api/v1/human-resource/kpi-squad/daily'),
            'Save KPI route'=>$has('PUT','api/v1/human-resource/kpi-squad/daily'),
            'Lock KPI route'=>$has('POST','api/v1/human-resource/kpi-squad/daily/{id}/lock'),
            'Reopen KPI route'=>$has('POST','api/v1/human-resource/kpi-squad/daily/{id}/reopen'),
            'Period KPI route'=>$has('GET','api/v1/human-resource/kpi-squad/period'),
            'Punishment bridge route'=>$has('POST','api/v1/human-resource/kpi-squad/violation'),
            'Daily export route'=>$has('GET','api/v1/human-resource/kpi-squad/export/daily'),
            'Period export route'=>$has('GET','api/v1/human-resource/kpi-squad/export/period'),
            'KPI import route'=>$has('POST','api/v1/human-resource/kpi-squad/import'),
        ];
        $failed=0;foreach($checks as$label=>$ok){$this->line(($ok?'<info>PASS</info>':'<error>FAIL</error>').' '.$label);if(!$ok)$failed++;}
        if($failed){$this->error("HR Iteration 18 check failed: {$failed} issue(s).");return self::FAILURE;}
        $this->info('HR Iteration 18 check passed.');return self::SUCCESS;
    }
}
