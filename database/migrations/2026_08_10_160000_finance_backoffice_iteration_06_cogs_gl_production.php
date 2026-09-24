<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'cogs_calculation_runs','finance_chart_of_accounts','finance_posting_templates','finance_posting_template_lines',
            'finance_journal_entries','finance_journal_entry_lines','finance_outlet_company_mappings',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Finance Backoffice Iterasi 06 membutuhkan {$table}.");
            }
        }

        if (! Schema::hasTable('finance_cogs_account_mappings')) {
            Schema::create('finance_cogs_account_mappings', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->string('company_code', 16)->index();
                $t->char('outlet_id', 26)->nullable()->index();
                $t->char('cogs_account_id', 26)->index();
                $t->char('inventory_account_id', 26)->index();
                $t->char('variance_account_id', 26)->index();
                $t->boolean('is_active')->default(true)->index();
                $t->text('notes')->nullable();
                $t->char('created_by_user_id', 26)->nullable()->index();
                $t->char('updated_by_user_id', 26)->nullable()->index();
                $t->timestamps();
                $t->foreign('cogs_account_id', 'fin_cogs_map_cogs_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $t->foreign('inventory_account_id', 'fin_cogs_map_inv_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $t->foreign('variance_account_id', 'fin_cogs_map_var_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $t->unique(['company_code','outlet_id'], 'fin_cogs_map_company_outlet_uq');
            });
        }

        if (! Schema::hasTable('finance_cogs_postings')) {
            Schema::create('finance_cogs_postings', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->string('posting_no', 80)->unique();
                $t->char('cogs_calculation_run_id', 26)->unique();
                $t->string('company_code', 16)->index();
                $t->char('outlet_id', 26)->index();
                $t->date('period_from')->index();
                $t->date('period_to')->index();
                $t->date('journal_date')->index();
                $t->string('source_fingerprint', 64);
                $t->decimal('final_cogs_value', 20, 2)->default(0);
                $t->decimal('inventory_bridge_cogs_value', 20, 2)->default(0);
                $t->decimal('reconciliation_difference', 20, 2)->default(0);
                $t->decimal('marking_percent', 8, 4)->default(100);
                $t->decimal('unmarking_percent', 8, 4)->default(0);
                $t->char('template_id', 26)->nullable()->index();
                $t->char('mapping_id', 26)->nullable()->index();
                $t->string('status', 24)->default('DRAFT')->index();
                $t->unsignedInteger('posting_version')->default(1);
                $t->text('note')->nullable();
                $t->char('created_by_user_id', 26)->nullable()->index();
                $t->char('updated_by_user_id', 26)->nullable()->index();
                $t->timestamp('posted_at')->nullable();
                $t->char('posted_by_user_id', 26)->nullable()->index();
                $t->timestamp('reopened_at')->nullable();
                $t->char('reopened_by_user_id', 26)->nullable()->index();
                $t->text('reopen_reason')->nullable();
                $t->timestamps();
                $t->foreign('cogs_calculation_run_id', 'fin_cogs_post_run_fk')->references('id')->on('cogs_calculation_runs')->restrictOnDelete();
                $t->foreign('template_id', 'fin_cogs_post_tpl_fk')->references('id')->on('finance_posting_templates')->nullOnDelete();
                $t->foreign('mapping_id', 'fin_cogs_post_map_fk')->references('id')->on('finance_cogs_account_mappings')->nullOnDelete();
                $t->index(['outlet_id','period_from','period_to','status'], 'fin_cogs_post_out_period_idx');
            });
        }

        if (! Schema::hasTable('finance_cogs_posting_journals')) {
            Schema::create('finance_cogs_posting_journals', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->char('cogs_posting_id', 26)->index();
                $t->unsignedInteger('posting_version')->index();
                $t->string('marking', 16)->index();
                $t->decimal('allocation_percent', 8, 4);
                $t->decimal('amount', 20, 2);
                $t->char('journal_entry_id', 26)->index();
                $t->string('journal_no', 48)->index();
                $t->char('reversal_journal_id', 26)->nullable()->index();
                $t->string('reversal_journal_no', 48)->nullable();
                $t->timestamp('posted_at');
                $t->timestamp('reversed_at')->nullable();
                $t->timestamps();
                $t->foreign('cogs_posting_id', 'fin_cogs_j_post_fk')->references('id')->on('finance_cogs_postings')->cascadeOnDelete();
                $t->foreign('journal_entry_id', 'fin_cogs_j_entry_fk')->references('id')->on('finance_journal_entries')->restrictOnDelete();
                $t->foreign('reversal_journal_id', 'fin_cogs_j_rev_fk')->references('id')->on('finance_journal_entries')->nullOnDelete();
                $t->unique(['cogs_posting_id','posting_version','marking'], 'fin_cogs_j_ver_mark_uq');
            });
        }

        $this->registerAccess();
    }

    public function down(): void
    {
        // Finance audit tables are intentionally retained on rollback.
    }

    private function registerAccess(): void
    {
        $permissions = [
            'finance.cogs_posting.view','finance.cogs_posting.create','finance.cogs_posting.update','finance.cogs_posting.delete',
            'finance.cogs_posting.post','finance.cogs_posting.reopen','finance.cogs_posting.manage_mapping',
        ];
        foreach ($permissions as $permission) Permission::findOrCreate($permission, 'web');
        Role::query()->where('guard_name','web')->whereIn(DB::raw('LOWER(name)'), ['admin','administrator','superadmin','super-admin'])
            ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions));

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code','finance')->first();
        if (! $portal) return;
        $old = DB::table('access_menus')->where('code','finance-cogs-posting')->first();
        $menuId = (string) ($old->id ?? Str::ulid());
        $now = now();
        DB::table('access_menus')->updateOrInsert(['code'=>'finance-cogs-posting'], [
            'id'=>$menuId,'portal_id'=>$portal->id,'name'=>'COGS Posting','path'=>'/finance/cogs-posting','sort_order'=>35,
            'permission_view'=>'finance.cogs_posting.view','permission_create'=>'finance.cogs_posting.create',
            'permission_update'=>'finance.cogs_posting.update','permission_delete'=>'finance.cogs_posting.delete',
            'is_active'=>true,'created_at'=>$old->created_at ?? $now,'updated_at'=>$now,
        ]);

        if (! Schema::hasTable('access_role_menu_permissions')) return;
        $sourceId = DB::table('access_menus')->where('code','finance-reconciliation')->value('id')
            ?: DB::table('access_menus')->where('code','finance-general-ledger')->value('id');
        if (! $sourceId) return;
        foreach (DB::table('access_role_menu_permissions')->where('menu_id',$sourceId)->get() as $row) {
            $q = DB::table('access_role_menu_permissions')->where('access_role_id',$row->access_role_id)->where('menu_id',$menuId);
            $row->access_level_id === null ? $q->whereNull('access_level_id') : $q->where('access_level_id',$row->access_level_id);
            if ($q->exists()) continue;
            $role = Schema::hasTable('access_roles') ? DB::table('access_roles')->where('id',$row->access_role_id)->first(['code','spatie_role_name']) : null;
            $admin = str_contains(strtoupper((string)($role->code ?? '')), 'ADMIN') || str_contains(strtolower((string)($role->spatie_role_name ?? '')), 'admin');
            DB::table('access_role_menu_permissions')->insert([
                'id'=>(string)Str::ulid(),'access_role_id'=>$row->access_role_id,'access_level_id'=>$row->access_level_id,'menu_id'=>$menuId,
                'can_view'=>(bool)$row->can_view,'can_create'=>$admin,'can_edit'=>$admin,'can_delete'=>$admin,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
    }
};
