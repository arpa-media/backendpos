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
    private const MODULES = [
        ['code' => 'purchasing-incoming-invoices', 'name' => 'Invoice Masuk', 'path' => '/purchasing/incoming-invoices', 'sort' => 90, 'permission' => 'purchasing.incoming_invoice'],
        ['code' => 'purchasing-outgoing-invoices', 'name' => 'Invoice Keluar', 'path' => '/purchasing/outgoing-invoices', 'sort' => 100, 'permission' => 'purchasing.outgoing_invoice'],
        ['code' => 'purchasing-account-receivables', 'name' => 'Account Receivable', 'path' => '/purchasing/account-receivables', 'sort' => 110, 'permission' => 'purchasing.account_receivable'],
        ['code' => 'purchasing-account-payables', 'name' => 'Account Payable', 'path' => '/purchasing/account-payables', 'sort' => 120, 'permission' => 'purchasing.account_payable'],
    ];

    public function up(): void
    {
        $this->createInvoices();
        $this->createInvoiceItems();
        $this->createPayments();
        $this->createEvents();
        $this->createFinanceOutbox();
        $this->permissionsAndMenus();
    }

    private function createInvoices(): void
    {
        if (Schema::hasTable('pur_invoices')) return;
        Schema::create('pur_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('invoice_number', 80)->unique();
            $table->string('direction', 20)->index();
            $table->string('external_invoice_number', 120)->nullable()->index();
            $table->string('source_document_kind', 50)->nullable()->index();
            $table->string('source_document_id', 64)->nullable()->index();
            $table->string('source_document_number', 100)->nullable();
            $table->foreignUlid('fund_request_id')->nullable()->constrained('pur_fund_requests')->nullOnDelete();
            $table->string('chamber_code', 40)->nullable()->index();
            $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('counterparty_name', 180)->index();
            $table->date('invoice_date')->index();
            $table->date('due_date')->index();
            $table->string('status', 40)->default('DRAFT')->index();
            $table->string('currency', 3)->default('IDR');
            $table->decimal('subtotal', 20, 2)->default(0);
            $table->decimal('tax_amount', 20, 2)->default(0);
            $table->decimal('total_amount', 20, 2)->default(0);
            $table->decimal('paid_amount', 20, 2)->default(0);
            $table->decimal('balance_due', 20, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('journal_status', 30)->default('NOT_POSTED')->index();
            $table->string('journal_reference', 120)->nullable();
            $table->foreignUlid('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['direction', 'source_document_kind', 'source_document_id'], 'pur_inv_source_uq');
            $table->index(['direction', 'status', 'due_date'], 'pur_inv_dir_status_due_idx');
            $table->index(['direction', 'outlet_id', 'status'], 'pur_inv_dir_outlet_status_idx');
            $table->index(['direction', 'chamber_code', 'status'], 'pur_inv_dir_chamber_status_idx');
        });
    }

    private function createInvoiceItems(): void
    {
        if (Schema::hasTable('pur_invoice_items')) return;
        Schema::create('pur_invoice_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained('pur_invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('source_item_kind', 60)->nullable();
            $table->string('source_item_id', 64)->nullable();
            $table->foreignUlid('sku_id')->nullable()->constrained('stk_skus')->nullOnDelete();
            $table->string('item_name', 255);
            $table->string('uom_text', 50)->nullable();
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 2)->default(0);
            $table->string('tax_mode', 20)->default('NO_TAX');
            $table->decimal('tax_percent', 7, 4)->default(0);
            $table->decimal('subtotal', 20, 2)->default(0);
            $table->decimal('tax_amount', 20, 2)->default(0);
            $table->decimal('line_total', 20, 2)->default(0);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'line_no'], 'pur_inv_item_line_uq');
            $table->index(['source_item_kind', 'source_item_id'], 'pur_inv_item_source_idx');
        });
    }

    private function createPayments(): void
    {
        if (Schema::hasTable('pur_invoice_payments')) return;
        Schema::create('pur_invoice_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained('pur_invoices')->cascadeOnDelete();
            $table->string('payment_number', 80)->unique();
            $table->date('payment_date')->index();
            $table->decimal('amount', 20, 2);
            $table->string('payment_method', 40);
            $table->string('reference_number', 120)->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('POSTED')->index();
            $table->string('idempotency_key', 120);
            $table->foreignUlid('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'idempotency_key'], 'pur_inv_payment_idem_uq');
            $table->index(['invoice_id', 'payment_date'], 'pur_inv_payment_date_idx');
        });
    }

    private function createEvents(): void
    {
        if (Schema::hasTable('pur_invoice_events')) return;
        Schema::create('pur_invoice_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained('pur_invoices')->cascadeOnDelete();
            $table->string('event_code', 80)->index();
            $table->string('event_label', 180);
            $table->string('status', 40)->nullable();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['invoice_id', 'idempotency_key'], 'pur_inv_event_idem_uq');
            $table->index(['invoice_id', 'occurred_at'], 'pur_inv_event_timeline_idx');
        });
    }

    private function createFinanceOutbox(): void
    {
        if (Schema::hasTable('pur_finance_posting_outbox')) return;
        Schema::create('pur_finance_posting_outbox', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_key', 191)->unique();
            $table->string('event_type', 80)->index();
            $table->string('aggregate_type', 80)->index();
            $table->string('aggregate_id', 64)->index();
            $table->json('payload');
            $table->string('status', 30)->default('PENDING')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'pur_fin_outbox_status_date_idx');
        });
    }

    private function permissionsAndMenus(): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');
        $allPermissions = [];
        foreach (self::MODULES as $module) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $allPermissions[] = $module['permission'] . '.' . $action;
            }
            $allPermissions[] = $module['permission'] . '.payment';
        }
        $allPermissions[] = 'purchasing.incoming_invoice.issue';
        $allPermissions[] = 'purchasing.outgoing_invoice.issue';

        if (Schema::hasTable('permissions')) {
            foreach (array_unique($allPermissions) as $permission) Permission::findOrCreate($permission, $guard);
            if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
            if (Schema::hasTable('roles')) {
                $admins = Role::query()->where('guard_name', $guard)->where(function ($query): void {
                    $query->whereRaw('LOWER(name) IN (?, ?)', ['admin', 'administrator'])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%super%admin%']);
                })->get();
                foreach ($admins as $admin) $admin->givePermissionTo($allPermissions);
            }
        }

        if (Schema::hasTable('access_portals') && Schema::hasTable('access_menus')) {
            $portal = DB::table('access_portals')->where('code', 'purchasing')->first();
            if ($portal) {
                foreach (self::MODULES as $module) {
                    $existing = DB::table('access_menus')->where('code', $module['code'])->first();
                    DB::table('access_menus')->updateOrInsert(['code' => $module['code']], [
                        'id' => (string) ($existing->id ?? Str::ulid()),
                        'portal_id' => $portal->id,
                        'name' => $module['name'],
                        'path' => $module['path'],
                        'sort_order' => $module['sort'],
                        'permission_view' => $module['permission'] . '.view',
                        'permission_create' => $module['permission'] . '.create',
                        'permission_update' => $module['permission'] . '.update',
                        'permission_delete' => $module['permission'] . '.delete',
                        'is_active' => true,
                        'created_at' => $existing->created_at ?? now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive by design: invoice and payment history must not be dropped automatically.
    }
};
