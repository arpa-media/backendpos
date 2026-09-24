<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

class HrIteration13CheckCommand extends Command
{
    protected $signature = 'hr:iteration-13-check';
    protected $description = 'Validate HR Iteration 13 Punishment/SP installation.';

    public function handle(): int
    {
        $checks=[
            'Punishment rules table'=>Schema::hasTable('HR_punishment_rules'),
            'Violations table'=>Schema::hasTable('HR_violations'),
            'Violation recommendations table'=>Schema::hasTable('HR_violation_recommendations'),
            'Warning letters table'=>Schema::hasTable('HR_warning_letters'),
            'Warning letter approvals table'=>Schema::hasTable('HR_warning_letter_approvals'),
            'Violation recommendation link'=>Schema::hasColumn('HR_violations','recommendation_id'),
            'Punishment route'=>Route::has('hr.punishment.check') || collect(Route::getRoutes())->contains(fn($r)=>$r->uri()==='api/v1/human-resource/punishments/violations'),
            'Permission hr.punishment.view'=>Schema::hasTable('permissions') && Permission::query()->where('name','hr.punishment.view')->exists(),
            'Permission hr.sp.approve'=>Schema::hasTable('permissions') && Permission::query()->where('name','hr.sp.approve')->exists(),
        ];
        $failed=false;
        foreach($checks as $label=>$ok){$this->line(($ok?'<info>PASS</info>':'<error>FAIL</error>').' '.$label); if(!$ok)$failed=true;}
        if($failed){$this->error('HR Iteration 13 check failed.'); return self::FAILURE;}
        $this->info('HR Iteration 13 check passed.'); return self::SUCCESS;
    }
}
