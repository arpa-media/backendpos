<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL = 'warehouse-operations';

    public function up(): void
    {
        foreach (['outlets','users','wh_ledger_postings','wh_ledger_entries','wh_batch_balances','wh_operational_reconciliation_runs','wh_v4_finance_coa','wh_v4_finance_general_postings','wh_v4_finance_general_posting_lines','wh_v4_treasury_transactions'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse v4 Iterasi 07 membutuhkan tabel {$table}. Apply Iterasi 01-06 terlebih dahulu.");
            }
        }

        $this->createBaselines();
        $this->createRuns();
        $this->createIssues();
        $this->registerAccessMatrix();
    }

    private function createBaselines(): void
    {
        if (Schema::hasTable('wh_v4_finance_recon_baselines')) return;
        Schema::create('wh_v4_finance_recon_baselines', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->char('warehouse_id', 26)->index();
            $t->string('control_key', 32);
            $t->string('account_code', 32)->index();
            $t->decimal('operational_value', 22, 2)->default(0);
            $t->decimal('finance_value', 22, 2)->default(0);
            $t->decimal('offset_value', 22, 2)->default(0);
            $t->char('operational_run_id', 26)->nullable()->index();
            $t->timestamp('captured_at')->index();
            $t->char('captured_by_user_id', 26)->nullable()->index();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['warehouse_id','control_key'], 'whv4_recon_base_wh_control_uq');
            $t->foreign('warehouse_id', 'whv4_recon_base_wh_fk')->references('id')->on('outlets')->restrictOnDelete();
            $t->foreign('captured_by_user_id', 'whv4_recon_base_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createRuns(): void
    {
        if (Schema::hasTable('wh_v4_finance_recon_runs')) return;
        Schema::create('wh_v4_finance_recon_runs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('scope_mode', 16)->default('warehouse')->index();
            $t->char('warehouse_id', 26)->nullable()->index();
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->string('status', 32)->default('RUNNING')->index();
            $t->unsignedInteger('checked_rule_count')->default(0);
            $t->unsignedInteger('critical_count')->default(0);
            $t->unsignedInteger('warning_count')->default(0);
            $t->unsignedInteger('issue_count')->default(0);
            $t->json('summary')->nullable();
            $t->char('executed_by_user_id', 26)->nullable()->index();
            $t->timestamp('started_at')->index();
            $t->timestamp('completed_at')->nullable()->index();
            $t->timestamps();
            $t->index(['warehouse_id','created_at'], 'whv4_recon_run_wh_created_idx');
            $t->foreign('warehouse_id', 'whv4_recon_run_wh_fk')->references('id')->on('outlets')->nullOnDelete();
            $t->foreign('executed_by_user_id', 'whv4_recon_run_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createIssues(): void
    {
        if (Schema::hasTable('wh_v4_finance_recon_issues')) return;
        Schema::create('wh_v4_finance_recon_issues', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->char('run_id', 26)->index();
            $t->char('warehouse_id', 26)->nullable()->index();
            $t->string('severity', 16)->index();
            $t->string('rule_key', 100)->index();
            $t->string('reference_type', 80)->nullable()->index();
            $t->string('reference_id', 120)->nullable()->index();
            $t->text('message');
            $t->decimal('operational_value', 22, 2)->nullable();
            $t->decimal('finance_value', 22, 2)->nullable();
            $t->decimal('variance', 22, 2)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->foreign('run_id', 'whv4_recon_issue_run_fk')->references('id')->on('wh_v4_finance_recon_runs')->cascadeOnDelete();
            $t->foreign('warehouse_id', 'whv4_recon_issue_wh_fk')->references('id')->on('outlets')->nullOnDelete();
            $t->index(['run_id','severity','rule_key'], 'whv4_recon_issue_run_rule_idx');
        });
    }

    private function registerAccessMatrix(): void
    {
        $permissions = [
            'warehouse.finance.reconciliation.view',
            'warehouse.finance.reconciliation.run',
            'warehouse.finance.reconciliation.baseline',
        ];

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach ($permissions as $permission) Permission::findOrCreate($permission, $guard);
            if (Schema::hasTable('roles')) {
                Role::query()->where('guard_name', $guard)
                    ->whereIn(DB::raw('LOWER(name)'), ['admin','administrator','superadmin','super-admin','warehouse'])
                    ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions));
            }
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        if (! $portal) return;

        $existing = DB::table('access_menus')->where('code', 'warehouse-finance-reconciliation-v4')->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        $now = now();
        DB::table('access_menus')->updateOrInsert(
            ['code' => 'warehouse-finance-reconciliation-v4'],
            [
                'id' => $menuId,
                'portal_id' => $portal->id,
                'name' => 'Finance Reconciliation & Go-Live',
                'path' => '/warehouse/finance/reconciliation-v4',
                'sort_order' => 780,
                'permission_view' => 'warehouse.finance.reconciliation.view',
                'permission_create' => 'warehouse.finance.reconciliation.run',
                'permission_update' => 'warehouse.finance.reconciliation.baseline',
                'permission_delete' => null,
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_menu_permissions')) {
            $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->all() : [];
            foreach (DB::table('access_roles')->get(['id','code']) as $role) {
                $enabled = in_array(strtoupper(trim((string) $role->code)), ['ADMIN','WAREHOUSE'], true);
                foreach (array_merge([null], $levels) as $levelId) {
                    $q = DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->where('menu_id',$menuId);
                    $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id',$levelId);
                    if ($q->exists()) continue;
                    DB::table('access_role_menu_permissions')->insert([
                        'id'=>(string) Str::ulid(), 'access_role_id'=>$role->id, 'access_level_id'=>$levelId,
                        'menu_id'=>$menuId, 'can_view'=>$enabled, 'can_create'=>$enabled, 'can_edit'=>$enabled,
                        'can_delete'=>false, 'created_at'=>$now, 'updated_at'=>$now,
                    ]);
                }
            }
        }

        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Audit history is intentionally non-destructive.
    }
};
