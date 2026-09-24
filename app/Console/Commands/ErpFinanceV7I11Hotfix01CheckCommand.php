<?php

namespace App\Console\Commands;

use App\Models\UserAccessAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV7I11Hotfix01CheckCommand extends Command
{
    protected $signature = 'erp-finance-v7:i11-hotfix01-check';
    protected $description = 'Read-only health check for I11.01 User Management performance hotfix';

    public function handle(): int
    {
        $ok = true;

        $this->components->info('ERP Finance V7 I11.01 User Management Performance Check');

        $indexes = Schema::hasTable('user_access_assignments')
            ? collect(Schema::getIndexes('user_access_assignments'))->pluck('name')
            : collect();
        $hasScopeIndex = $indexes->contains('uaa_role_level_user_idx');
        $this->line(($hasScopeIndex ? '[OK] ' : '[FAIL] ').'user_access_assignments role+level+user index');
        $ok = $ok && $hasScopeIndex;

        $permissionPivot = config('permission.table_names.model_has_permissions', 'model_has_permissions');
        $rolePivot = config('permission.table_names.model_has_roles', 'model_has_roles');
        foreach ([$permissionPivot, $rolePivot, 'access_role_menu_permissions', 'access_role_portal_permissions'] as $table) {
            $exists = Schema::hasTable($table);
            $this->line(($exists ? '[OK] ' : '[FAIL] ').$table);
            $ok = $ok && $exists;
        }

        if (Schema::hasTable('user_access_assignments')) {
            $largestScopes = UserAccessAssignment::query()
                ->select(['access_role_id', 'access_level_id', DB::raw('COUNT(*) as users_count')])
                ->groupBy('access_role_id', 'access_level_id')
                ->orderByDesc('users_count')
                ->limit(5)
                ->get();

            $this->newLine();
            $this->line('Largest Access Matrix scopes:');
            foreach ($largestScopes as $scope) {
                $this->line(sprintf(
                    '  role=%s level=%s users=%d',
                    (string) $scope->access_role_id,
                    $scope->access_level_id ? (string) $scope->access_level_id : 'NULL',
                    (int) $scope->users_count,
                ));
            }

            $sample = $largestScopes->first();
            if ($sample) {
                $sql = $sample->access_level_id
                    ? 'EXPLAIN SELECT user_id, access_level_id FROM user_access_assignments WHERE access_role_id = ? AND access_level_id = ?'
                    : 'EXPLAIN SELECT user_id, access_level_id FROM user_access_assignments WHERE access_role_id = ? AND access_level_id IS NULL';
                $bindings = $sample->access_level_id
                    ? [(string) $sample->access_role_id, (string) $sample->access_level_id]
                    : [(string) $sample->access_role_id];
                $explain = collect(DB::select($sql, $bindings))->first();
                $key = $explain->key ?? null;
                $this->line('[INFO] EXPLAIN scope key='.($key ?: 'NONE'));
            }
        }

        $this->newLine();
        $this->line('[INFO] Spatie teams='.((bool) config('permission.teams', false) ? 'ON (fallback sync path)' : 'OFF (set-based pivot sync active)'));

        if (! $ok) {
            $this->components->error('I11.01 check FAILED. Jalankan migration hotfix dan php artisan optimize:clear.');
            return self::FAILURE;
        }

        $this->components->info('I11.01 check PASS.');
        return self::SUCCESS;
    }
}
