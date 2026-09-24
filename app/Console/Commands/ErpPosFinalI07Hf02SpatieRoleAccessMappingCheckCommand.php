<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosFinalI07Hf02SpatieRoleAccessMappingCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i07-hf02-spatie-role-access-check';
    protected $description = 'Verify Access Role -> Spatie Role mappings, especially cashier, and User Management UI contract.';

    public function handle(): int
    {
        $failures = 0;
        $rolesTable = (string) config('permission.table_names.roles', 'roles');
        $guard = (string) config('auth.defaults.guard', 'web');

        if (! Schema::hasTable('access_roles') || ! Schema::hasTable($rolesTable)) {
            $this->error('access_roles / Spatie roles table belum tersedia.');
            return self::FAILURE;
        }

        $cashierAccessRoles = DB::table('access_roles')
            ->whereIn('code', ['CASHIER', 'SQUAD_DEFAULT'])
            ->get(['code', 'spatie_role_name']);
        foreach ($cashierAccessRoles as $row) {
            if (strtolower(trim((string) $row->spatie_role_name)) !== 'cashier') {
                $this->error("{$row->code}: spatie_role_name bukan cashier.");
                $failures++;
            }
        }

        $cashierExists = DB::table($rolesTable)
            ->where('name', 'cashier')
            ->where('guard_name', $guard)
            ->exists();
        $cashierExists ? $this->info("PASS Spatie role cashier ({$guard}) tersedia.") : $this->error("FAIL Spatie role cashier ({$guard}) tidak tersedia.");
        if (! $cashierExists) $failures++;

        $missingMappings = DB::table('access_roles as ar')
            ->whereNotNull('ar.spatie_role_name')
            ->where('ar.spatie_role_name', '!=', '')
            ->whereNotExists(function ($query) use ($rolesTable, $guard) {
                $query->selectRaw('1')
                    ->from($rolesTable.' as sr')
                    ->whereColumn('sr.name', 'ar.spatie_role_name')
                    ->where('sr.guard_name', $guard);
            })
            ->count();
        if ($missingMappings > 0) {
            $this->error("FAIL {$missingMappings} Access Role masih menunjuk Spatie role yang tidak tersedia.");
            $failures++;
        } else {
            $this->info('PASS semua Access Role mapping memiliki Spatie role target.');
        }

        $root = dirname(base_path());
        foreach (['frontend', 'frontend - Backoffice'] as $frontend) {
            $file = $root.DIRECTORY_SEPARATOR.$frontend.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'pages'.DIRECTORY_SEPARATOR.'UserManagementPage.vue';
            $body = @file_get_contents($file) ?: '';
            foreach (['Spatie Role', 'masters.spatie_roles', 'spatie_role_name'] as $marker) {
                if (! str_contains($body, $marker)) {
                    $this->error("FAIL {$frontend}: UI marker {$marker} belum tersedia.");
                    $failures++;
                }
            }
        }

        $controller = @file_get_contents(base_path('app/Http/Controllers/Api/V1/UserManagementController.php')) ?: '';
        foreach (["'spatie_roles' =>", "'spatie_role_name' =>", 'assertActorCanManageSpatieRoleMapping'] as $marker) {
            if (! str_contains($controller, $marker)) {
                $this->error("FAIL backend controller marker: {$marker}");
                $failures++;
            }
        }

        if ($failures > 0) {
            $this->newLine();
            $this->error("ERP POS FINAL I07-HF02 validation FAIL ({$failures}).");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS FINAL I07-HF02 validation PASS.');
        return self::SUCCESS;
    }
}
