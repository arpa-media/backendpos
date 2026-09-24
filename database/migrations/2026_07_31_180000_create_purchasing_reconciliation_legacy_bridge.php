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
    private const PERMISSIONS = [
        'purchasing.reconciliation.view', 'purchasing.reconciliation.create',
        'purchasing.reconciliation.update', 'purchasing.reconciliation.delete',
        'purchasing.reconciliation.scan', 'purchasing.reconciliation.apply',
        'purchasing.reconciliation.resolve',
    ];

    public function up(): void
    {
        $this->createLinks();
        $this->createRuns();
        $this->createIssues();
        $this->createSnapshots();
        $this->extendLegacyTables();
        $this->permissionsAndMenus();
    }

    private function createLinks(): void
    {
        if (Schema::hasTable('pur_legacy_document_links')) return;
        Schema::create('pur_legacy_document_links', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('source_system', 30)->index();
            $t->string('source_table', 80)->index();
            $t->string('source_id', 64)->index();
            $t->string('source_number', 120)->nullable()->index();
            $t->string('canonical_type', 60)->index();
            $t->string('canonical_id', 64)->nullable()->index();
            $t->string('canonical_number', 120)->nullable()->index();
            $t->ulid('root_fund_request_id')->nullable()->index();
            $t->string('link_status', 30)->default('UNRESOLVED')->index();
            $t->string('match_strategy', 60)->nullable();
            $t->decimal('confidence', 6, 4)->default(0);
            $t->string('identity_key', 64)->unique();
            $t->string('primary_slot', 191)->nullable()->unique();
            $t->boolean('is_primary')->default(false)->index();
            $t->json('metadata')->nullable();
            $t->timestamp('linked_at')->nullable();
            $t->timestamps();
            $t->unique(['source_table', 'source_id', 'canonical_type'], 'pur_legacy_link_source_type_uq');
            $t->index(['canonical_type', 'canonical_id', 'link_status'], 'pur_legacy_link_canonical_idx');
        });
    }

    private function createRuns(): void
    {
        if (Schema::hasTable('pur_reconciliation_runs')) return;
        Schema::create('pur_reconciliation_runs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('run_number', 80)->unique();
            $t->string('mode', 20)->default('DRY_RUN')->index();
            $t->string('source_scope', 30)->default('ALL')->index();
            $t->string('status', 30)->default('RUNNING')->index();
            $t->unsignedInteger('row_limit')->default(500);
            $t->json('options')->nullable();
            $t->json('totals')->nullable();
            $t->ulid('actor_user_id')->nullable()->index();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->text('notes')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamps();
            $t->index(['status', 'started_at'], 'pur_recon_run_status_date_idx');
        });
    }

    private function createIssues(): void
    {
        if (Schema::hasTable('pur_reconciliation_issues')) return;
        Schema::create('pur_reconciliation_issues', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('run_id')->index();
            $t->string('fingerprint', 64);
            $t->string('severity', 20)->default('WARNING')->index();
            $t->string('issue_code', 80)->index();
            $t->string('source_system', 30)->nullable()->index();
            $t->string('source_table', 80)->nullable();
            $t->string('source_id', 64)->nullable();
            $t->string('source_number', 120)->nullable();
            $t->string('canonical_type', 60)->nullable();
            $t->string('canonical_id', 64)->nullable();
            $t->ulid('root_fund_request_id')->nullable()->index();
            $t->text('message');
            $t->string('resolution_status', 20)->default('OPEN')->index();
            $t->text('resolution_notes')->nullable();
            $t->ulid('resolved_by_user_id')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['run_id', 'fingerprint'], 'pur_recon_issue_run_fingerprint_uq');
            $t->index(['run_id', 'severity', 'resolution_status'], 'pur_recon_issue_run_severity_idx');
        });
    }

    private function createSnapshots(): void
    {
        if (Schema::hasTable('pur_reconciliation_snapshots')) return;
        Schema::create('pur_reconciliation_snapshots', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('run_id')->index();
            $t->string('row_key', 191);
            $t->string('source_system', 30)->index();
            $t->string('source_table', 80);
            $t->string('source_id', 64);
            $t->string('source_number', 120)->nullable();
            $t->ulid('fund_request_id')->nullable()->index();
            $t->string('fund_request_number', 120)->nullable();
            $t->string('request_type', 30)->nullable()->index();
            $t->string('request_status', 40)->nullable();
            $t->string('chamber_code', 40)->nullable()->index();
            $t->ulid('outlet_id')->nullable()->index();
            $t->string('outlet_name', 180)->nullable();
            $t->string('order_kind', 50)->nullable();
            $t->string('order_id', 64)->nullable()->index();
            $t->string('order_number', 120)->nullable();
            $t->string('order_status', 40)->nullable();
            $t->string('fulfillment_id', 64)->nullable();
            $t->string('fulfillment_status', 40)->nullable();
            $t->string('execution_kind', 60)->nullable();
            $t->string('execution_id', 64)->nullable();
            $t->string('execution_number', 120)->nullable();
            $t->string('execution_status', 40)->nullable();
            $t->string('receipt_id', 64)->nullable();
            $t->string('receipt_number', 120)->nullable();
            $t->string('receipt_status', 40)->nullable();
            $t->string('invoice_id', 64)->nullable();
            $t->string('invoice_number', 120)->nullable();
            $t->string('invoice_status', 40)->nullable();
            $t->string('overall_status', 30)->default('UNRESOLVED')->index();
            $t->unsignedInteger('issue_count')->default(0);
            $t->unsignedInteger('warning_count')->default(0);
            $t->unsignedInteger('error_count')->default(0);
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['run_id', 'row_key'], 'pur_recon_snapshot_run_row_uq');
            $t->index(['run_id', 'overall_status', 'source_system'], 'pur_recon_snapshot_filter_idx');
        });
    }

    private function extendLegacyTables(): void
    {
        $columns = [
            'wh_purchase_requests' => ['canonical_fund_request_id', 'wh_pr_canonical_fund_idx'],
            'wh_supplier_purchase_orders' => ['canonical_purchase_order_id', 'wh_spo_canonical_po_idx'],
            'wh_stock_ins' => ['canonical_goods_receipt_id', 'wh_si_canonical_gr_idx'],
            'wh_purchase_invoices' => ['canonical_invoice_id', 'wh_pinv_canonical_invoice_idx'],
        ];
        foreach ($columns as $table => [$column, $index]) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, $column)) continue;
            Schema::table($table, function (Blueprint $t) use ($column, $index): void {
                $t->ulid($column)->nullable();
                $t->index($column, $index);
            });
        }
    }

    private function permissionsAndMenus(): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');
        if (Schema::hasTable('permissions')) {
            foreach (self::PERMISSIONS as $permission) Permission::findOrCreate($permission, $guard);
            if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
            if (Schema::hasTable('roles')) {
                $admins = Role::query()->where('guard_name', $guard)->where(function ($q): void {
                    $q->whereRaw('LOWER(name) IN (?, ?)', ['admin', 'administrator'])
                      ->orWhereRaw('LOWER(name) LIKE ?', ['%super%admin%']);
                })->get();
                foreach ($admins as $admin) $admin->givePermissionTo(self::PERMISSIONS);
            }
        }

        if (Schema::hasTable('access_portals') && Schema::hasTable('access_menus')) {
            $portal = DB::table('access_portals')->where('code', 'purchasing')->first();
            if ($portal) {
                $old = DB::table('access_menus')->where('code', 'purchasing-reconciliation')->first();
                DB::table('access_menus')->updateOrInsert(['code' => 'purchasing-reconciliation'], [
                    'id' => (string) ($old->id ?? Str::ulid()), 'portal_id' => $portal->id,
                    'name' => 'Reconciliation', 'path' => '/purchasing/reconciliation', 'sort_order' => 130,
                    'permission_view' => 'purchasing.reconciliation.view',
                    'permission_create' => 'purchasing.reconciliation.create',
                    'permission_update' => 'purchasing.reconciliation.update',
                    'permission_delete' => 'purchasing.reconciliation.delete',
                    'is_active' => true, 'created_at' => $old->created_at ?? now(), 'updated_at' => now(),
                ]);
            }
            DB::table('access_menus')->whereIn('code', [
                'warehouse-purchase-requests', 'warehouse-supplier-purchase-orders',
                'purchasing-stock-request-approval', 'inventory-manual-stock', 'inventory-receive-stock',
            ])->update(['is_active' => false, 'updated_at' => now()]);
        }
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive: reconciliation links and audit history are retained.
    }
};
