<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const EXISTING_EXECUTION_TABLES = [
        'pur_goods_receipts',
        'pur_service_acceptances',
        'pur_reimburse_payments',
    ];

    public function up(): void
    {
        $this->createServiceEntrySheets();
        foreach (array_merge(self::EXISTING_EXECUTION_TABLES, ['pur_service_entry_sheets']) as $table) {
            $this->extendRealizationHeader($table);
        }
        $this->permissionsAndMenu();
    }

    private function createServiceEntrySheets(): void
    {
        if (! Schema::hasTable('pur_service_entry_sheets')) {
            Schema::create('pur_service_entry_sheets', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->string('ses_number', 60)->unique();
                $t->string('order_kind', 40)->default('PURCHASE_ORDER')->index();
                $t->string('order_id', 64)->index();
                $t->foreignUlid('fund_request_id')->nullable()->constrained('pur_fund_requests')->nullOnDelete();
                $t->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $t->string('chamber_code', 40)->nullable()->index();
                $t->date('document_date');
                $t->string('status', 40)->default('DRAFT')->index();
                $t->string('currency', 3)->default('IDR');
                $t->decimal('subtotal', 20, 2)->default(0);
                $t->decimal('tax_amount', 20, 2)->default(0);
                $t->decimal('total_amount', 20, 2)->default(0);
                $t->string('external_reference', 120)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedInteger('lock_version')->default(1);
                $t->string('idempotency_key', 120)->nullable()->unique();
                $t->foreignUlid('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('posted_at')->nullable();
                $t->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['order_kind', 'order_id'], 'pur_ses_order_uq');
            });
        }

        if (! Schema::hasTable('pur_service_entry_sheet_items')) {
            Schema::create('pur_service_entry_sheet_items', function (Blueprint $t): void {
                $t->ulid('id')->primary();
                $t->foreignUlid('document_id')->constrained('pur_service_entry_sheets')->cascadeOnDelete();
                $t->string('order_item_id', 64)->nullable()->index();
                $t->unsignedSmallInteger('line_no');
                $t->foreignUlid('sku_id')->nullable()->constrained('stk_skus')->nullOnDelete();
                $t->string('item_name', 255);
                $t->string('uom_text', 50)->nullable();
                $t->decimal('ordered_qty', 18, 4)->default(0);
                $t->decimal('executed_qty', 18, 4)->default(0);
                $t->decimal('unit_price', 18, 2)->default(0);
                $t->decimal('tax_amount', 20, 2)->default(0);
                $t->decimal('line_total', 20, 2)->default(0);
                $t->text('notes')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->unique(['document_id', 'line_no'], 'pur_ses_line_uq');
            });
        }
    }

    private function extendRealizationHeader(string $table): void
    {
        if (! Schema::hasTable($table)) return;

        Schema::table($table, function (Blueprint $t) use ($table): void {
            if (! Schema::hasColumn($table, 'is_auto_generated')) $t->boolean('is_auto_generated')->default(false);
            if (! Schema::hasColumn($table, 'auto_generated_at')) $t->timestamp('auto_generated_at')->nullable();
            if (! Schema::hasColumn($table, 'auto_generation_source')) $t->string('auto_generation_source', 60)->nullable();
            if (! Schema::hasColumn($table, 'realization_date')) $t->date('realization_date')->nullable();
            if (! Schema::hasColumn($table, 'actual_subtotal')) $t->decimal('actual_subtotal', 20, 2)->default(0);
            if (! Schema::hasColumn($table, 'actual_tax_amount')) $t->decimal('actual_tax_amount', 20, 2)->default(0);
            if (! Schema::hasColumn($table, 'actual_total_amount')) $t->decimal('actual_total_amount', 20, 2)->default(0);
            if (! Schema::hasColumn($table, 'evidence_required')) $t->boolean('evidence_required')->default(false);
            if (! Schema::hasColumn($table, 'evidence_status')) $t->string('evidence_status', 30)->default('NOT_REQUIRED');
            if (! Schema::hasColumn($table, 'submitted_by_user_id')) $t->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            if (! Schema::hasColumn($table, 'submitted_at')) $t->timestamp('submitted_at')->nullable();
            if (! Schema::hasColumn($table, 'approved_by_user_id')) $t->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            if (! Schema::hasColumn($table, 'approved_at')) $t->timestamp('approved_at')->nullable();
            if (! Schema::hasColumn($table, 'rejected_by_user_id')) $t->foreignUlid('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            if (! Schema::hasColumn($table, 'rejected_at')) $t->timestamp('rejected_at')->nullable();
            if (! Schema::hasColumn($table, 'rejection_notes')) $t->text('rejection_notes')->nullable();
            if (! Schema::hasColumn($table, 'company_code')) $t->string('company_code', 30)->nullable();
            if (! Schema::hasColumn($table, 'marking')) $t->string('marking', 40)->default('UNMARKING');
            if (! Schema::hasColumn($table, 'posting_template_id')) $t->char('posting_template_id', 26)->nullable();
            if (! Schema::hasColumn($table, 'general_posting_id')) $t->char('general_posting_id', 26)->nullable();
            if (! Schema::hasColumn($table, 'realization_status')) $t->string('realization_status', 30)->default('PENDING');
            if (! Schema::hasColumn($table, 'realization_fingerprint')) $t->string('realization_fingerprint', 64)->nullable();
        });

        $short = match ($table) {
            'pur_goods_receipts' => 'gr',
            'pur_service_acceptances' => 'sa',
            'pur_reimburse_payments' => 'rp',
            default => 'ses',
        };
        $this->ensureIndex($table, ['status', 'realization_date'], "pur06_{$short}_status_date_idx");
        $this->ensureIndex($table, ['evidence_required', 'evidence_status'], "pur06_{$short}_evidence_idx");
    }

    private function permissionsAndMenu(): void
    {
        if (Schema::hasTable('permissions')) {
            $guard = (string) config('auth.defaults.guard', 'web');
            foreach (['view','create','update','delete','submit','approve','upload','print'] as $action) {
                Permission::findOrCreate('purchasing.realization_order.'.$action, $guard);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', 'purchasing')->first();
        if (! $portal) return;

        $now = now();
        $existing = DB::table('access_menus')->where('code', 'purchasing-realization-orders')->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => 'purchasing-realization-orders'], [
            'id' => $menuId,
            'portal_id' => $portal->id,
            'name' => 'Realization Order',
            'path' => '/purchasing/realization-orders',
            'sort_order' => 40,
            'permission_view' => 'purchasing.realization_order.view',
            'permission_create' => 'purchasing.realization_order.create',
            'permission_update' => 'purchasing.realization_order.update',
            'permission_delete' => 'purchasing.realization_order.delete',
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        $legacyCodes = ['purchasing-goods-receipts','purchasing-service-acceptances','purchasing-reimburse-payments'];
        $legacyIds = DB::table('access_menus')->whereIn('code', $legacyCodes)->pluck('id');
        if ($legacyIds->isNotEmpty() && Schema::hasTable('access_role_menu_permissions')) {
            $groups = DB::table('access_role_menu_permissions')
                ->whereIn('menu_id', $legacyIds)
                ->get()
                ->groupBy(fn ($row) => (string) $row->access_role_id.'|'.(string) ($row->access_level_id ?? ''));

            foreach ($groups as $rows) {
                $first = $rows->first();
                $payload = [
                    'can_view' => $rows->contains(fn ($row) => (bool) $row->can_view),
                    'can_create' => $rows->contains(fn ($row) => (bool) $row->can_create),
                    'can_edit' => $rows->contains(fn ($row) => (bool) $row->can_edit),
                    'can_delete' => $rows->contains(fn ($row) => (bool) $row->can_delete),
                    'updated_at' => $now,
                ];
                $q = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $first->access_role_id)
                    ->where('menu_id', $menuId);
                is_null($first->access_level_id) ? $q->whereNull('access_level_id') : $q->where('access_level_id', $first->access_level_id);
                if ($q->exists()) $q->update($payload);
                else DB::table('access_role_menu_permissions')->insert($payload + [
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $first->access_role_id,
                    'access_level_id' => $first->access_level_id,
                    'menu_id' => $menuId,
                    'created_at' => $now,
                ]);
            }
        }

        DB::table('access_menus')->whereIn('code', $legacyCodes)->update(['is_active' => false, 'updated_at' => $now]);
    }

    private function ensureIndex(string $table, array $columns, string $name): void
    {
        $exists = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $name]
        );
        if ((int) ($exists->c ?? 0) > 0) return;
        Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('code', 'purchasing-realization-orders')->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('access_menus')->whereIn('code', ['purchasing-goods-receipts','purchasing-service-acceptances','purchasing-reimburse-payments'])->update(['is_active' => true, 'updated_at' => now()]);
        }
    }
};
