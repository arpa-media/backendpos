<?php

namespace App\Console\Commands;

use App\Services\Console\ConsoleCanonicalAccessRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosFinalI11Hf03ConsoleVisibilityCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i11-hf03-console-visibility-check {--repair : Force canonical Console repair before checking}';

    protected $description = 'Validate deterministic Console visibility in Access Matrix and runtime access.';

    public function handle(ConsoleCanonicalAccessRecoveryService $recovery): int
    {
        if ($this->option('repair')) {
            $recovery->repair();
        } else {
            $recovery->ensureReady();
        }

        $failures = 0;
        $check = function (bool $ok, string $label, ?string $detail = null) use (&$failures): void {
            $this->line(($ok ? 'PASS ' : 'FAIL ').$label.($detail ? ' · '.$detail : ''));
            if (! $ok) {
                $failures++;
            }
        };

        if (! Schema::hasTable('access_portals')) {
            $this->error('access_portals belum tersedia.');
            return self::FAILURE;
        }

        $portal = DB::table('access_portals')->where('code', 'console')->first();
        $check((bool) $portal, 'Portal Console tersedia');
        if (! $portal) {
            return self::FAILURE;
        }

        $maxSort = (int) (DB::table('access_portals')->max('sort_order') ?? 0);
        $check((bool) $portal->is_active, 'Portal Console aktif');
        $check((int) $portal->sort_order === $maxSort, 'Portal Console paling akhir', 'console='.(int) $portal->sort_order.' max='.$maxSort);

        $menuCodes = ['console-control-center', 'console-system-health', 'console-file-management'];
        $menus = DB::table('access_menus')
            ->where('portal_id', $portal->id)
            ->whereIn('code', $menuCodes)
            ->get();
        $check($menus->count() === 3, 'Tiga menu Console canonical tersedia', 'count='.$menus->count());

        $adminRoles = DB::table('access_roles')
            ->where(function ($query): void {
                $query->whereRaw("UPPER(COALESCE(code,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')")
                    ->orWhereRaw("UPPER(COALESCE(spatie_role_name,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')");
            })
            ->get(['id', 'code', 'name']);
        $check($adminRoles->isNotEmpty(), 'Access Role Administrator tersedia');

        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->get(['id', 'code']) : collect();
        foreach ($adminRoles as $role) {
            $base = DB::table('access_role_portal_permissions')
                ->where('access_role_id', $role->id)
                ->whereNull('access_level_id')
                ->where('portal_id', $portal->id)
                ->first();
            $check((bool) $base && (bool) $base->can_view, 'ADMIN base Console ON', (string) $role->code);

            foreach ($levels as $level) {
                $exact = DB::table('access_role_portal_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('access_level_id', $level->id)
                    ->where('portal_id', $portal->id)
                    ->first();
                $check((bool) $exact && (bool) $exact->can_view, 'ADMIN exact level Console ON', (string) $role->code.' + '.(string) $level->code);
            }
        }

        if (Schema::hasTable('access_user_types')) {
            $backofficeIds = DB::table('access_user_types')
                ->whereRaw("UPPER(COALESCE(code,'')) = 'BACKOFFICE'")
                ->pluck('id');
            $backofficeRoles = DB::table('access_roles')
                ->when($backofficeIds->isNotEmpty(), fn ($q) => $q->whereIn('user_type_id', $backofficeIds->all()))
                ->get(['id', 'code']);
            foreach ($backofficeRoles as $role) {
                $exists = DB::table('access_role_portal_permissions')
                    ->where('access_role_id', $role->id)
                    ->whereNull('access_level_id')
                    ->where('portal_id', $portal->id)
                    ->exists();
                $check($exists, 'Console muncul sebagai opsi Access Matrix', (string) $role->code);
            }
        }

        $this->newLine();
        if ($failures > 0) {
            $this->error("ERP POS FINAL I11-HF03 validation FAIL · failures={$failures}");
            return self::FAILURE;
        }

        $this->info('ERP POS FINAL I11-HF03 validation PASS.');
        return self::SUCCESS;
    }
}
