<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL = 'general-affair';
    private const MENU = [
        'code' => 'ga-costing',
        'name' => 'Costing GA',
        'path' => '/general-affair/costing',
        'sort_order' => 50,
        'permission' => 'ga.costing',
    ];

    public function up(): void
    {
        $this->createTables();
        $this->ensurePermissions();
        $this->registerMenu();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Costing GA is financial/operational history
        // and is referenced by the Purchasing bridge in later iterations.
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('ga_document_sequences')) {
            Schema::create('ga_document_sequences', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('document_type', 40);
                $table->string('period_key', 12);
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();
                $table->unique(['document_type', 'period_key'], 'ga_doc_sequence_type_period_uq');
            });
        }

        if (! Schema::hasTable('ga_costing_requests')) {
            Schema::create('ga_costing_requests', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('request_no', 48)->unique();
                $table->foreignUlid('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('requester_name_snapshot', 180);
                $table->string('requester_nisj_snapshot', 50)->nullable();

                $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->string('outlet_code_snapshot', 50)->nullable();
                $table->string('outlet_name_snapshot', 180);

                $table->foreignUlid('costing_category_id')->nullable()->constrained('ga_costing_categories')->nullOnDelete();
                $table->string('category_code_snapshot', 50);
                $table->string('category_name_snapshot', 150);
                $table->string('workflow_code_snapshot', 40)->index();

                $table->text('description');
                $table->string('destination_account', 255);
                $table->decimal('amount', 20, 2);
                $table->string('status', 32)->default('DRAFT')->index();
                $table->string('generated_document_kind', 40)->nullable()->index();

                $table->timestamp('submitted_at')->nullable()->index();
                $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable()->index();
                $table->foreignUlid('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable()->index();
                $table->text('rejection_reason')->nullable();

                $table->ulid('purchasing_fund_request_id')->nullable()->unique();
                $table->string('purchasing_fund_request_number', 60)->nullable();
                $table->string('purchasing_order_kind', 40)->nullable();
                $table->string('purchasing_order_id', 64)->nullable()->index();
                $table->string('purchasing_order_number', 80)->nullable();
                $table->timestamp('purchasing_handoff_at')->nullable()->index();
                $table->text('bridge_error')->nullable();

                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['outlet_id', 'status', 'created_at'], 'ga_costing_outlet_status_idx');
                $table->index(['costing_category_id', 'status', 'created_at'], 'ga_costing_cat_status_idx');
                $table->index(['requester_user_id', 'created_at'], 'ga_costing_requester_idx');
            });
        }

        if (! Schema::hasTable('ga_costing_generated_orders')) {
            Schema::create('ga_costing_generated_orders', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('costing_request_id')->constrained('ga_costing_requests')->cascadeOnDelete();
                $table->string('order_number', 64)->unique();
                $table->string('order_kind', 40)->index();
                $table->string('status', 32)->default('WAITING_APPROVAL')->index();
                $table->decimal('amount', 20, 2);
                $table->string('destination_account', 255);
                $table->string('purchasing_order_kind', 40)->nullable();
                $table->string('purchasing_order_id', 64)->nullable()->index();
                $table->string('purchasing_order_number', 80)->nullable();
                $table->timestamp('handed_off_at')->nullable()->index();
                $table->timestamps();
                $table->unique('costing_request_id', 'ga_costing_generated_request_uq');
            });
        }

        if (! Schema::hasTable('ga_costing_attachments')) {
            Schema::create('ga_costing_attachments', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('costing_request_id')->constrained('ga_costing_requests')->cascadeOnDelete();
                $table->string('kind', 20)->index(); // INVOICE | ITEM
                $table->boolean('is_current')->default(true)->index();
                $table->string('disk', 40)->default('public');
                $table->string('path', 500);
                $table->string('original_name', 255)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->foreignUlid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['costing_request_id', 'kind', 'is_current'], 'ga_costing_attach_current_idx');
            });
        }

        if (! Schema::hasTable('ga_costing_request_events')) {
            Schema::create('ga_costing_request_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('costing_request_id')->constrained('ga_costing_requests')->cascadeOnDelete();
                $table->string('event_type', 50)->index();
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name_snapshot', 180)->nullable();
                $table->text('summary');
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32)->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('event_at')->index();
                $table->timestamps();
                $table->index(['costing_request_id', 'event_at'], 'ga_costing_event_timeline_idx');
            });
        }
    }

    private function ensurePermissions(): void
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) return;
        $now = now();
        foreach (['view', 'create', 'update', 'delete', 'approve'] as $action) {
            DB::table($table)->insertOrIgnore([
                'name' => self::MENU['permission'].'.'.$action,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function registerMenu(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        if (! $portal) return;

        $now = now();
        $existing = DB::table('access_menus')->where('code', self::MENU['code'])->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(
            ['code' => self::MENU['code']],
            [
                'id' => $menuId,
                'portal_id' => (string) $portal->id,
                'name' => self::MENU['name'],
                'path' => self::MENU['path'],
                'sort_order' => self::MENU['sort_order'],
                'permission_view' => 'ga.costing.view',
                'permission_create' => 'ga.costing.create',
                'permission_update' => 'ga.costing.update',
                'permission_delete' => 'ga.costing.delete',
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        foreach (DB::table('access_roles')->select('id', 'code', 'name')->get() as $role) {
            $code = strtoupper(trim((string) ($role->code ?? '')));
            $name = strtoupper(trim((string) ($role->name ?? '')));
            $enabled = in_array($code, ['ADMIN', 'ADMINISTRATOR', 'GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS'], true)
                || in_array($name, ['ADMIN', 'ADMINISTRATOR', 'GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS'], true);
            foreach ($this->levelScopes() as $levelId) {
                $this->insertMatrixIfMissing((string) $role->id, $levelId, $menuId, $enabled, $now);
            }
        }
    }

    private function levelScopes(): array
    {
        if (! Schema::hasTable('access_levels')) return [null];
        return array_merge([null], DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    private function insertMatrixIfMissing(string $roleId, ?string $levelId, string $menuId, bool $enabled, $now): void
    {
        $query = DB::table('access_role_menu_permissions')->where('access_role_id', $roleId)->where('menu_id', $menuId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
        if ($query->exists()) return;

        DB::table('access_role_menu_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'menu_id' => $menuId,
            'can_view' => $enabled,
            'can_create' => $enabled,
            'can_edit' => $enabled,
            'can_delete' => $enabled,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
