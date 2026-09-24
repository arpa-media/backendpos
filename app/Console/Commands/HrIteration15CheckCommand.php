<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration15CheckCommand extends Command
{
    protected $signature = 'hr:iteration-15-check';
    protected $description = 'Validate HR Iteration 15 Development v2 installation.';

    public function handle(): int
    {
        $routes=collect(Route::getRoutes());
        $hasUri=fn(string $method,string $uri):bool=>$routes->contains(fn($r)=>in_array($method,$r->methods(),true)&&$r->uri()===$uri);
        $checks=[
            'Development table'=>Schema::hasTable('HR_developments'),
            'Development batch table'=>Schema::hasTable('HR_development_batches'),
            'Participant table'=>Schema::hasTable('HR_development_participants'),
            'Dynamic field table'=>Schema::hasTable('HR_development_fields') && Schema::hasTable('HR_development_field_values'),
            'Versioned scoring table'=>Schema::hasTable('HR_development_scoring_policies'),
            'Achievement table'=>Schema::hasTable('HR_development_achievements'),
            'Certificate template table'=>Schema::hasTable('HR_development_certificate_templates'),
            'Development menu'=>Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','hr-development')->where('path','/human-resource/development')->where('is_active',true)->exists(),
            'Development view permission'=>Schema::hasTable('permissions') && DB::table('permissions')->where('name','hr.development.view')->exists(),
            'Participant manage permission'=>Schema::hasTable('permissions') && DB::table('permissions')->where('name','hr.development.participant.manage')->exists(),
            'Score permission'=>Schema::hasTable('permissions') && DB::table('permissions')->where('name','hr.development.test.score')->exists(),
            'Result publish permission'=>Schema::hasTable('permissions') && DB::table('permissions')->where('name','hr.development.result.publish')->exists(),
            'Program route'=>$hasUri('GET','api/v1/human-resource/developments'),
            'Participant route'=>$hasUri('GET','api/v1/human-resource/developments/{id}/participants'),
            'Self service route'=>$hasUri('GET','api/v1/human-resource/development-self'),
            'Self certificate template route'=>$hasUri('GET','api/v1/human-resource/development-self/certificate/{participantId}/template'),
            'Import route'=>$hasUri('POST','api/v1/human-resource/developments/import'),
        ];
        $failed=0;foreach($checks as$label=>$ok){$this->line(($ok?'<info>PASS</info>':'<error>FAIL</error>').' '.$label);if(!$ok)$failed++;}
        if($failed){$this->error("HR Iteration 15 check failed: {$failed} issue(s).");return self::FAILURE;}
        $this->info('HR Iteration 15 check passed.');return self::SUCCESS;
    }
}
