<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('HR_payroll_cutoffs') || ! Schema::hasTable('HR_payroll_slips')) {
            throw new RuntimeException('HR Iterasi 14 membutuhkan HR Payroll Iterasi 08.');
        }
        if (! Schema::hasTable('finance_payroll_posting_inbox')) {
            throw new RuntimeException('HR Iterasi 14 membutuhkan Finance Payroll Posting.');
        }

        $this->extendCutoffs();
        $this->extendSlips();
        $this->extendFinanceInbox();
        $this->createEvents();
        $this->createImportBatches();
        $this->registerAccess();
        $this->backfillWorkflow();
    }

    public function down(): void
    {
        // Audit/payroll data intentionally preserved. Iteration 14 is non-destructive.
    }

    private function extendCutoffs(): void
    {
        Schema::table('HR_payroll_cutoffs', function (Blueprint $t): void {
            if (! Schema::hasColumn('HR_payroll_cutoffs', 'submitted_by')) $t->char('submitted_by', 26)->nullable()->index();
            if (! Schema::hasColumn('HR_payroll_cutoffs', 'submitted_at')) $t->timestamp('submitted_at')->nullable();
            if (! Schema::hasColumn('HR_payroll_cutoffs', 'finance_posting_id')) $t->char('finance_posting_id', 26)->nullable()->index();
            if (! Schema::hasColumn('HR_payroll_cutoffs', 'finance_processing_by')) $t->char('finance_processing_by', 26)->nullable()->index();
            if (! Schema::hasColumn('HR_payroll_cutoffs', 'finance_processing_at')) $t->timestamp('finance_processing_at')->nullable();
            if (! Schema::hasColumn('HR_payroll_cutoffs', 'reopened_by')) $t->char('reopened_by', 26)->nullable()->index();
            if (! Schema::hasColumn('HR_payroll_cutoffs', 'reopened_at')) $t->timestamp('reopened_at')->nullable();
            if (! Schema::hasColumn('HR_payroll_cutoffs', 'reopen_reason')) $t->string('reopen_reason', 1000)->nullable();
            if (! Schema::hasColumn('HR_payroll_cutoffs', 'workflow_version')) $t->string('workflow_version', 24)->default('I14');
        });
    }

    private function extendSlips(): void
    {
        Schema::table('HR_payroll_slips', function (Blueprint $t): void {
            if (! Schema::hasColumn('HR_payroll_slips', 'bpjs_health')) $t->decimal('bpjs_health', 15, 2)->default(0);
            if (! Schema::hasColumn('HR_payroll_slips', 'bpjs_employment')) $t->decimal('bpjs_employment', 15, 2)->default(0);
            if (! Schema::hasColumn('HR_payroll_slips', 'bpjs_other')) $t->decimal('bpjs_other', 15, 2)->default(0);
            if (! Schema::hasColumn('HR_payroll_slips', 'bpjs_total')) $t->decimal('bpjs_total', 15, 2)->default(0);
        });
    }

    private function extendFinanceInbox(): void
    {
        Schema::table('finance_payroll_posting_inbox', function (Blueprint $t): void {
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'hr_cutoff_id')) $t->char('hr_cutoff_id', 26)->nullable()->index();
        });

        $indexes = collect(DB::select('SHOW INDEX FROM finance_payroll_posting_inbox'));
        if (! $indexes->contains(fn ($r) => (string)($r->Key_name ?? '') === 'fin_pay_hr_cutoff_uq')) {
            Schema::table('finance_payroll_posting_inbox', fn (Blueprint $t) => $t->unique('hr_cutoff_id', 'fin_pay_hr_cutoff_uq'));
        }
    }

    private function createEvents(): void
    {
        if (Schema::hasTable('HR_payroll_cutoff_events')) return;
        Schema::create('HR_payroll_cutoff_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->char('cutoff_id', 26)->index();
            $t->string('event', 48)->index();
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24)->nullable();
            $t->char('actor_user_id', 26)->nullable()->index();
            $t->char('finance_posting_id', 26)->nullable()->index();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->foreign('cutoff_id', 'hr_pay_evt_cutoff_fk')->references('id')->on('HR_payroll_cutoffs')->cascadeOnDelete();
            $t->foreign('actor_user_id', 'hr_pay_evt_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createImportBatches(): void
    {
        if (Schema::hasTable('HR_payroll_import_batches')) return;
        Schema::create('HR_payroll_import_batches', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->char('cutoff_id', 26)->index();
            $t->char('finance_posting_id', 26)->nullable()->index();
            $t->string('kind', 32)->index();
            $t->string('filename', 255)->nullable();
            $t->string('status', 24)->default('VALIDATED')->index();
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->unsignedInteger('invalid_rows')->default(0);
            $t->unsignedInteger('applied_rows')->default(0);
            $t->json('validation_report')->nullable();
            $t->char('actor_user_id', 26)->nullable()->index();
            $t->timestamps();
            $t->foreign('cutoff_id', 'hr_pay_imp_cutoff_fk')->references('id')->on('HR_payroll_cutoffs')->cascadeOnDelete();
            $t->foreign('actor_user_id', 'hr_pay_imp_actor_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function backfillWorkflow(): void
    {
        DB::table('HR_payroll_cutoffs')->whereNull('workflow_version')->update(['workflow_version' => 'I14']);
        DB::table('HR_payroll_slips')->whereNull('bpjs_health')->update(['bpjs_health' => 0]);
        DB::table('HR_payroll_slips')->whereNull('bpjs_employment')->update(['bpjs_employment' => 0]);
        DB::table('HR_payroll_slips')->whereNull('bpjs_other')->update(['bpjs_other' => 0]);
        DB::table('HR_payroll_slips')->whereNull('bpjs_total')->update(['bpjs_total' => 0]);
    }

    private function registerAccess(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.payroll.cutoff.submit','hr.payroll.cutoff.reopen','hr.payroll.cutoff.import','hr.payroll.cutoff.export',
            'hr.bonus.cutoff.view','finance.payroll_posting.bpjs.import','finance.payroll_posting.bpjs.export',
        ] as $permission) {
            if (Schema::hasTable('permissions')) Permission::findOrCreate($permission, $guard);
        }

        if (Schema::hasTable('access_portals') && Schema::hasTable('access_menus')) {
            $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
            if ($portal) {
                $now = now();
                $old = DB::table('access_menus')->where('code', 'hr-bonus-cutoff')->first();
                $id = (string)($old->id ?? Str::ulid());
                DB::table('access_menus')->updateOrInsert(['code' => 'hr-bonus-cutoff'], [
                    'id' => $id, 'portal_id' => $portal->id, 'name' => 'Cutoff Bonus',
                    'path' => '/human-resource/cutoff-bonus', 'sort_order' => 54,
                    'permission_view' => 'hr.bonus.cutoff.view', 'permission_create' => null,
                    'permission_update' => null, 'permission_delete' => null, 'is_active' => true,
                    'created_at' => $old->created_at ?? $now, 'updated_at' => $now,
                ]);
                $this->cloneMenuMatrix($id, $now);
            }
        }

        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function cloneMenuMatrix(string $targetMenuId, $now): void
    {
        if (! Schema::hasTable('access_role_menu_permissions')) return;
        $source = DB::table('access_menus')->where('code', 'hr-payroll-cutoff')->value('id');
        if (! $source) return;
        foreach (DB::table('access_role_menu_permissions')->where('menu_id', $source)->get() as $row) {
            $q = DB::table('access_role_menu_permissions')->where('access_role_id', $row->access_role_id)->where('menu_id', $targetMenuId);
            $row->access_level_id === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $row->access_level_id);
            if ($q->exists()) continue;
            DB::table('access_role_menu_permissions')->insert([
                'id' => (string)Str::ulid(), 'access_role_id' => $row->access_role_id, 'access_level_id' => $row->access_level_id,
                'menu_id' => $targetMenuId, 'can_view' => (bool)$row->can_view, 'can_create' => false,
                'can_edit' => false, 'can_delete' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
