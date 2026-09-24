<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration16CheckCommand extends Command
{
    protected $signature = 'hr:iteration-16-check';
    protected $description = 'Validate HR Iteration 16 Recruitment Admin Foundation installation.';

    public function handle(): int
    {
        $routes=collect(Route::getRoutes());
        $hasUri=fn(string $method,string $uri):bool=>$routes->contains(fn($r)=>in_array($method,$r->methods(),true)&&$r->uri()===$uri);
        $checks=[
            'Recruitment table'=>Schema::hasTable('HR_recruitments'),
            'Recruitment positions table'=>Schema::hasTable('HR_recruitment_positions'),
            'Recruitment qualifications table'=>Schema::hasTable('HR_recruitment_qualifications'),
            'Career registration request table'=>Schema::hasTable('HR_career_registration_requests'),
            'Applications table'=>Schema::hasTable('HR_applications'),
            'Recruitment menu'=>Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','hr-recruitment')->where('path','/human-resource/recruitment')->where('is_active',true)->exists(),
            'Recruitment view permission'=>Schema::hasTable('permissions') && DB::table('permissions')->where('name','hr.recruitment.view')->exists(),
            'Recruitment publish permission'=>Schema::hasTable('permissions') && DB::table('permissions')->where('name','hr.recruitment.publish')->exists(),
            'Applicant view permission'=>Schema::hasTable('permissions') && DB::table('permissions')->where('name','hr.recruitment.applicant.view')->exists(),
            'Registration approval permission'=>Schema::hasTable('permissions') && DB::table('permissions')->where('name','hr.recruitment.registration.approve')->exists(),
            'Recruitment index route'=>$hasUri('GET','api/v1/human-resource/recruitments'),
            'Recruitment publish route'=>$hasUri('POST','api/v1/human-resource/recruitments/{id}/publish'),
            'Applicant route'=>$hasUri('GET','api/v1/human-resource/recruitments/{id}/applicants'),
            'Registration request route'=>$hasUri('GET','api/v1/human-resource/recruitments/registration-requests'),
            'Registration review route'=>$hasUri('POST','api/v1/human-resource/recruitments/registration-requests/{id}/review'),
        ];
        $failed=0;foreach($checks as$label=>$ok){$this->line(($ok?'<info>PASS</info>':'<error>FAIL</error>').' '.$label);if(!$ok)$failed++;}
        if($failed){$this->error("HR Iteration 16 check failed: {$failed} issue(s).");return self::FAILURE;}
        $this->info('HR Iteration 16 check passed.');return self::SUCCESS;
    }
}
