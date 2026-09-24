<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrIteration11CheckCommand extends Command
{
    protected $signature = 'hr:iteration-11-check';
    protected $description = 'Runtime contract check HR Iteration 11 Contract Management.';

    public function handle(): int
    {
        $tables = ['HR_contracts','HR_contract_events','HR_contract_document_templates','HR_contract_documents','HR_contract_approvals','HR_contract_reminders'];
        $checks = [];
        foreach ($tables as $table) $checks['Table '.$table] = Schema::hasTable($table);
        $checks['Access menu hr-mapping-contract'] = Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'hr-mapping-contract')->where('path', '/human-resource/mapping-contract')->where('is_active', true)->exists();
        foreach (['hr.contract.view','hr.contract.create','hr.contract.update','hr.contract.delete','hr.contract.submit','hr.contract.approve','hr.contract.document.generate','hr.contract.reminder.manage'] as $permission) {
            $checks['Permission '.$permission] = Schema::hasTable('permissions') && DB::table('permissions')->where('name', $permission)->exists();
        }
        $checks['Default SK templates'] = Schema::hasTable('HR_contract_document_templates') && DB::table('HR_contract_document_templates')->where('is_active', true)->whereIn('document_type', ['contract','extension','promotion','transfer','termination'])->pluck('document_type')->unique()->count() >= 5;
        $missingLegacy = Schema::hasTable('HR_squads') && Schema::hasTable('HR_contracts')
            ? DB::table('HR_squads as s')->leftJoin('HR_contracts as c', function ($join): void {
                $join->on('c.squad_id', '=', 's.id')->whereNull('c.deleted_at');
            })->whereNull('s.deleted_at')->whereNull('c.id')->count()
            : 0;
        $checks['Legacy contracts backfilled'] = ! Schema::hasTable('HR_squads') || $missingLegacy === 0;

        $failed = false;
        foreach ($checks as $name => $ok) {
            $ok ? $this->components->info('PASS · '.$name) : $this->components->error('FAIL · '.$name);
            $failed = $failed || ! $ok;
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
