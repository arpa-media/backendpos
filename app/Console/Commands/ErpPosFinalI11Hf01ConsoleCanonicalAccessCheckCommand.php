<?php

namespace App\Console\Commands;

use App\Services\UserManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class ErpPosFinalI11Hf01ConsoleCanonicalAccessCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i11-hf01-console-access-check {--repair : Re-run canonical User Management master sync before validation}';
    protected $description = 'Validate and optionally repair canonical Console portal/menu registration in User Management Access Matrix.';

    public function handle(UserManagementService $userManagement): int
    {
        if ($this->option('repair')) {
            $this->warn('Repair: syncing canonical User Management masters...');
            $userManagement->ensureMasters();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $failures = 0;
        $check = function (bool $ok, string $label, string $detail = '') use (&$failures): void {
            if ($ok) {
                $this->info('PASS '.$label.($detail !== '' ? ' · '.$detail : ''));
            } else {
                $this->error('FAIL '.$label.($detail !== '' ? ' · '.$detail : ''));
                $failures++;
            }
        };

        $check(Schema::hasTable('access_portals'), 'access_portals tersedia');
        $check(Schema::hasTable('access_menus'), 'access_menus tersedia');

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return self::FAILURE;
        }

        $portal = DB::table('access_portals')->where('code', 'console')->first();
        $check((bool) $portal, 'Portal Console canonical tersedia');

        if ($portal) {
            $check((bool) $portal->is_active, 'Portal Console aktif');
            $maxSort = (int) DB::table('access_portals')->where('is_active', true)->max('sort_order');
            $check((int) $portal->sort_order === $maxSort, 'Portal Console berada paling akhir', 'console='.(int) $portal->sort_order.' max='.$maxSort);
        }

        $menuCodes = [
            'console-control-center' => '/console/control-center',
            'console-system-health' => '/console/system-health',
            'console-file-management' => '/console/file-management',
        ];
        foreach ($menuCodes as $code => $path) {
            $menu = DB::table('access_menus')->where('code', $code)->first();
            $check((bool) $menu, 'Menu '.$code.' tersedia');
            if ($menu) {
                $check((string) $menu->path === $path, 'Path '.$code.' canonical', (string) $menu->path);
                $check((bool) $menu->is_active, 'Menu '.$code.' aktif');
                if ($portal) {
                    $check((string) $menu->portal_id === (string) $portal->id, 'Menu '.$code.' terhubung ke Console');
                }
            }
        }

        foreach ([
            'console.control_center.view',
            'console.control_center.run',
            'console.control_center.configure',
            'console.control_center.force_rebuild',
            'console.system_health.view',
            'console.file_management.view',
            'console.file_management.create_folder',
            'console.file_management.move',
            'console.file_management.delete',
        ] as $permission) {
            $check(Permission::query()->where('guard_name', 'web')->where('name', $permission)->exists(), 'Permission '.$permission.' [web]');
        }

        if ($portal && Schema::hasTable('access_roles') && Schema::hasTable('access_role_portal_permissions')) {
            $adminRole = DB::table('access_roles')->where('code', 'ADMIN')->first();
            $check((bool) $adminRole, 'Access Role ADMIN tersedia');
            if ($adminRole) {
                $basePortal = DB::table('access_role_portal_permissions')
                    ->where('access_role_id', $adminRole->id)
                    ->whereNull('access_level_id')
                    ->where('portal_id', $portal->id)
                    ->first();
                $check((bool) $basePortal, 'ADMIN memiliki row Portal Console');
                if ($basePortal) {
                    $check((bool) $basePortal->can_view, 'ADMIN dapat melihat Portal Console');
                }
            }
        }

        $this->newLine();
        if ($failures > 0) {
            $this->error("ERP POS FINAL I11-HF01 validation FAIL · {$failures} failure(s).");
            return self::FAILURE;
        }

        $this->info('ERP POS FINAL I11-HF01 validation PASS.');
        return self::SUCCESS;
    }
}
