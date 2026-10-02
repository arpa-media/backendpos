<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GA_PORTAL = 'general-affair';
    private const REPORT_PORTAL = 'report';

    private const GA_MENU = [
        'code' => 'ga-cctv-requests',
        'name' => 'Manajemen Request CCTV',
        'path' => '/general-affair/cctv-requests',
        'sort_order' => 40,
        'permission' => 'ga.cctv',
    ];

    private const REPORT_MENU = [
        'code' => 'report-ga-cctv-request',
        'name' => 'Request CCTV',
        'path' => '/report/general-affair/cctv',
        'sort_order' => 80,
        'permission' => 'report.ga.cctv',
    ];

    public function up(): void
    {
        $this->createTables();
        $this->ensurePermissions();
        $this->registerGaMenu();
        $this->registerReportMenu();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive by design. CCTV requests and Access Matrix assignments
        // are operational history and are consumed by later GA iterations.
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('ga_cctv_requests')) {
            Schema::create('ga_cctv_requests', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('request_no', 48)->unique();

                $table->foreignUlid('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('officer_name_snapshot', 180);
                $table->string('officer_nisj_snapshot', 50)->nullable();

                $table->foreignUlid('cctv_category_id')->nullable()->constrained('ga_cctv_categories')->nullOnDelete();
                $table->string('category_code_snapshot', 50);
                $table->string('category_name_snapshot', 150);
                $table->string('category_custom_value', 180)->nullable();

                $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->string('outlet_code_snapshot', 50)->nullable();
                $table->string('outlet_name_snapshot', 180);

                $table->text('description');
                $table->string('status', 32)->default('DIAJUKAN')->index();
                $table->text('manager_note')->nullable();
                $table->dateTime('submitted_at')->nullable()->index();
                $table->dateTime('completed_at')->nullable()->index();

                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['requester_user_id', 'created_at'], 'ga_cctv_req_requester_date_idx');
                $table->index(['outlet_id', 'status', 'created_at'], 'ga_cctv_req_outlet_status_idx');
                $table->index(['cctv_category_id', 'status'], 'ga_cctv_req_category_status_idx');
            });
        }

        if (! Schema::hasTable('ga_cctv_request_attachments')) {
            Schema::create('ga_cctv_request_attachments', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('cctv_request_id')->constrained('ga_cctv_requests')->cascadeOnDelete();
                $table->string('kind', 24)->index();
                $table->boolean('is_current')->default(true)->index();
                $table->string('disk', 40)->default('public');
                $table->string('path', 500);
                $table->string('original_name', 255)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->foreignUlid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['cctv_request_id', 'kind', 'is_current'], 'ga_cctv_attach_current_idx');
            });
        }

        if (! Schema::hasTable('ga_cctv_request_events')) {
            Schema::create('ga_cctv_request_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('cctv_request_id')->constrained('ga_cctv_requests')->cascadeOnDelete();
                $table->string('event_type', 40)->index();
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name_snapshot', 180)->nullable();
                $table->text('summary');
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32)->nullable();
                $table->json('meta')->nullable();
                $table->dateTime('event_at')->index();
                $table->timestamps();
                $table->index(['cctv_request_id', 'event_at'], 'ga_cctv_event_timeline_idx');
            });
        }
    }

    private function ensurePermissions(): void
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) return;

        $now = now();
        foreach ([self::GA_MENU, self::REPORT_MENU] as $menu) {
            foreach (['view', 'create', 'update'] as $action) {
                DB::table($table)->insertOrIgnore([
                    'name' => $menu['permission'].'.'.$action,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function registerGaMenu(): void
    {
        $portal = $this->portal(self::GA_PORTAL);
        if (! $portal) return;
        $menuId = $this->upsertMenu((string) $portal->id, self::GA_MENU);
        $this->seedGaMenuMatrix($menuId);
    }

    private function registerReportMenu(): void
    {
        $portal = $this->portal(self::REPORT_PORTAL);
        if (! $portal) return;
        $menuId = $this->upsertMenu((string) $portal->id, self::REPORT_MENU);
        $this->seedReportMenuMatrix((string) $portal->id, $menuId);
    }

    private function portal(string $code): ?object
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return null;
        return DB::table('access_portals')->where('code', $code)->first();
    }

    private function upsertMenu(string $portalId, array $menu): string
    {
        $now = now();
        $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
        $menuId = (string) ($existing->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(
            ['code' => $menu['code']],
            [
                'id' => $menuId,
                'portal_id' => $portalId,
                'name' => $menu['name'],
                'path' => $menu['path'],
                'sort_order' => $menu['sort_order'],
                'permission_view' => $menu['permission'].'.view',
                'permission_create' => $menu['permission'].'.create',
                'permission_update' => $menu['permission'].'.update',
                'permission_delete' => null,
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        return $menuId;
    }

    private function seedGaMenuMatrix(string $menuId): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $now = now();
        $roles = DB::table('access_roles')->select('id', 'code', 'name')->get();

        foreach ($roles as $role) {
            $code = strtoupper(trim((string) ($role->code ?? '')));
            $name = strtoupper(trim((string) ($role->name ?? '')));
            $enabled = in_array($code, ['ADMIN', 'ADMINISTRATOR', 'GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS'], true)
                || in_array($name, ['ADMIN', 'ADMINISTRATOR', 'GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS'], true);
            foreach ($this->levelScopes() as $levelId) {
                $this->insertMenuMatrixIfMissing((string) $role->id, $levelId, $menuId, $enabled, $enabled, $enabled, $now);
            }
        }
    }

    private function seedReportMenuMatrix(string $reportPortalId, string $menuId): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $now = now();
        $roles = DB::table('access_roles')->select('id')->get();
        foreach ($roles as $role) {
            foreach ($this->levelScopes() as $levelId) {
                $enabled = $this->reportPortalEnabled((string) $role->id, $levelId, $reportPortalId);
                $this->insertMenuMatrixIfMissing((string) $role->id, $levelId, $menuId, $enabled, $enabled, $enabled, $now);
            }
        }
    }

    private function reportPortalEnabled(string $roleId, ?string $levelId, string $portalId): bool
    {
        if (! Schema::hasTable('access_role_portal_permissions')) return false;
        $base = DB::table('access_role_portal_permissions')
            ->where('access_role_id', $roleId)->where('portal_id', $portalId)->whereNull('access_level_id')->first();
        if ($levelId === null) return (bool) ($base->can_view ?? false);
        $exact = DB::table('access_role_portal_permissions')
            ->where('access_role_id', $roleId)->where('portal_id', $portalId)->where('access_level_id', $levelId)->first();
        return (bool) (($exact ?: $base)->can_view ?? false);
    }

    private function levelScopes(): array
    {
        if (! Schema::hasTable('access_levels')) return [null];
        return array_merge([null], DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    private function insertMenuMatrixIfMissing(string $roleId, ?string $levelId, string $menuId, bool $view, bool $create, bool $edit, $now): void
    {
        $query = DB::table('access_role_menu_permissions')->where('access_role_id', $roleId)->where('menu_id', $menuId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
        if ($query->exists()) return;

        DB::table('access_role_menu_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'menu_id' => $menuId,
            'can_view' => $view,
            'can_create' => $create,
            'can_edit' => $edit,
            'can_delete' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
