<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

class ErpPosFinalI11ConsoleFileManagementCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i11-console-file-management-check';
    protected $description = 'Validate ERP POS FINAL I11 Console ordering and Storage File Management contracts.';

    public function handle(): int
    {
        $failures = 0;
        $check = function (bool $ok, string $label, string $detail = '') use (&$failures): void {
            $ok ? $this->info('PASS '.$label.($detail ? ' · '.$detail : '')) : $this->error('FAIL '.$label.($detail ? ' · '.$detail : ''));
            if (! $ok) $failures++;
        };

        $check(Schema::hasTable('console_file_management_audit_logs'), 'Audit table tersedia');
        $menu = Schema::hasTable('access_menus') ? DB::table('access_menus')->where('code', 'console-file-management')->first() : null;
        $check((bool) $menu, 'Access Matrix menu File Management tersedia');
        if ($menu) {
            $check((string) $menu->path === '/console/file-management', 'Menu path canonical', (string) $menu->path);
            $check((string) $menu->permission_view === 'console.file_management.view', 'View permission canonical');
            $check((string) $menu->permission_update === 'console.file_management.move', 'Move permission canonical');
            $check((string) $menu->permission_delete === 'console.file_management.delete', 'Delete permission canonical');
        }

        if (Schema::hasTable('access_portals')) {
            $consoleSort = (int) DB::table('access_portals')->where('code', 'console')->value('sort_order');
            $maxSort = (int) DB::table('access_portals')->where('is_active', true)->max('sort_order');
            $check($consoleSort === $maxSort, 'Console portal berada paling akhir', "console={$consoleSort}; max={$maxSort}");
        }

        foreach ([
            'console.file-management.index.i11',
            'console.file-management.download.i11',
            'console.file-management.folder.i11',
            'console.file-management.move.i11',
            'console.file-management.delete.i11',
        ] as $routeName) {
            $check(Route::has($routeName), 'Route '.$routeName);
        }

        foreach ([
            'console.file_management.view',
            'console.file_management.create_folder',
            'console.file_management.move',
            'console.file_management.delete',
        ] as $permission) {
            $check(Permission::query()->where('guard_name', 'web')->where('name', $permission)->exists(), 'Permission '.$permission);
        }

        foreach (['local', 'public'] as $disk) {
            $driver = (string) config("filesystems.disks.{$disk}.driver", '');
            $root = (string) config("filesystems.disks.{$disk}.root", '');
            $check($driver === 'local' && $root !== '', "Disk {$disk} local dan memiliki root");
        }

        $this->newLine();
        if ($failures > 0) {
            $this->error("ERP POS FINAL I11 validation FAIL · {$failures} failure(s).");
            return self::FAILURE;
        }
        $this->info('ERP POS FINAL I11 validation PASS.');
        return self::SUCCESS;
    }
}
