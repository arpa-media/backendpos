<?php

namespace App\Console\Commands;

use App\Services\Console\ConsoleCanonicalAccessRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosFinalI11Hf02ConsoleVisibilityCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i11-hf02-console-visibility-check {--repair : Repair canonical Console access before checking}';

    protected $description = 'Validate that Console is present in Access Matrix, visible to Administrator, and sorted last.';

    public function handle(ConsoleCanonicalAccessRecoveryService $recovery): int
    {
        if ($this->option('repair')) {
            $result = $recovery->repair();
            $this->info('Repair executed.');
            $this->line('Backoffice roles: '.$result['backoffice_roles']);
            $this->line('Portal rows created: '.$result['portal_rows_created']);
            $this->line('Menu rows created: '.$result['menu_rows_created']);
        }

        foreach (['access_portals', 'access_menus', 'access_roles', 'access_role_portal_permissions', 'access_role_menu_permissions'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing table: {$table}");
                return self::FAILURE;
            }
        }

        $failures = 0;
        $portal = DB::table('access_portals')->where('code', 'console')->first();
        if (! $portal) {
            $this->error('FAIL Console portal tidak tersedia.');
            return self::FAILURE;
        }

        $maxOtherSort = (int) (DB::table('access_portals')->where('code', '!=', 'console')->max('sort_order') ?? 0);
        $this->line(sprintf('Console: active=%s sort_order=%s max_other=%s', (int) $portal->is_active, $portal->sort_order, $maxOtherSort));
        if (! (bool) $portal->is_active) {
            $this->error('FAIL Console portal inactive.');
            $failures++;
        }
        if ((int) $portal->sort_order <= $maxOtherSort) {
            $this->error('FAIL Console bukan portal paling akhir.');
            $failures++;
        } else {
            $this->info('PASS Console adalah portal paling akhir.');
        }

        $menus = DB::table('access_menus')
            ->where('portal_id', $portal->id)
            ->whereIn('code', ['console-control-center', 'console-system-health', 'console-file-management'])
            ->get(['id', 'code', 'is_active']);
        if ($menus->count() !== 3 || $menus->contains(fn ($row) => ! (bool) $row->is_active)) {
            $this->error('FAIL tiga menu Console aktif belum lengkap.');
            $failures++;
        } else {
            $this->info('PASS tiga menu Console aktif tersedia.');
        }

        $backofficeTypeIds = Schema::hasTable('access_user_types')
            ? DB::table('access_user_types')->whereRaw("UPPER(COALESCE(code,''))='BACKOFFICE'")->pluck('id')
            : collect();

        $rolesQuery = DB::table('access_roles');
        if ($backofficeTypeIds->isNotEmpty()) {
            $rolesQuery->whereIn('user_type_id', $backofficeTypeIds->all());
        }
        $roles = $rolesQuery->get(['id', 'code', 'name', 'spatie_role_name']);

        foreach ($roles as $role) {
            $row = DB::table('access_role_portal_permissions')
                ->where('access_role_id', $role->id)
                ->whereNull('access_level_id')
                ->where('portal_id', $portal->id)
                ->first();
            if (! $row) {
                $this->error("FAIL base Console row hilang untuk Access Role {$role->code}.");
                $failures++;
                continue;
            }

            $isAdmin = in_array(strtoupper((string) $role->code), ['ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN'], true)
                || in_array(strtolower((string) $role->spatie_role_name), ['admin','administrator','superadmin','super-admin'], true);
            if ($isAdmin && ! (bool) $row->can_view) {
                $this->error("FAIL Administrator {$role->code} tidak dapat melihat Console.");
                $failures++;
            }
        }

        if ($failures > 0) {
            $this->error("ERP POS FINAL I11-HF02 validation FAIL ({$failures} failure). Run with --repair.");
            return self::FAILURE;
        }

        $this->info('ERP POS FINAL I11-HF02 validation PASS.');
        return self::SUCCESS;
    }
}
