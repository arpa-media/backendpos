<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrIteration23CheckCommand extends Command
{
    protected $signature = 'hr:iteration-23-check';
    protected $description = 'Validate HR Iteration 23 squad lifecycle and contract identity hardening.';

    public function handle(): int
    {
        $checks = [
            'Squad deletion audit table' => Schema::hasTable('HR_squad_deletion_audits'),
            'Contract first SK field' => Schema::hasTable('HR_contracts') && Schema::hasColumn('HR_contracts', 'first_sk_date'),
            'Assignment history source' => Schema::hasTable('HR_assignment_histories'),
            'Birth date source' => Schema::hasTable('HR_squads') && Schema::hasColumn('HR_squads', 'birth_date'),
            'Squad delete permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.squad.delete')->exists(),
            'Data Squad Access Matrix delete mapping' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'hr-data-squad')->where('permission_delete', 'hr.squad.delete')->exists(),
        ];
        foreach ($checks as $label => $ok) $this->line(sprintf('%s %s', $ok ? '[OK]' : '[FAIL]', $label));
        $invalid = Schema::hasTable('HR_contracts') ? DB::table('HR_contracts')->whereNotNull('assignment_label')->whereRaw("BINARY assignment_label NOT IN ('OUTLET','MANAGEMENT','WAREHOUSE')")->count() : 0;
        $this->line(($invalid === 0 ? '[OK]' : '[FAIL]')." Contract assignment labels valid (invalid={$invalid})");
        return in_array(false, $checks, true) || $invalid > 0 ? self::FAILURE : self::SUCCESS;
    }
}
