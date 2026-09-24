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
    private const ACTION_PERMISSIONS = [
        'purchasing.fund_request.submit',
        'purchasing.fund_request.approve_executive',
        'purchasing.fund_request.approve_stock',
        'purchasing.fund_request.reject',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pur_document_sequences')) {
            Schema::create('pur_document_sequences', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('document_type', 40);
                $table->string('period_key', 12);
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();
                $table->unique(['document_type', 'period_key'], 'pur_doc_sequence_type_period_uq');
            });
        }

        if (! Schema::hasTable('pur_fund_requests')) {
            Schema::create('pur_fund_requests', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('request_number', 60)->unique();
                $table->string('request_type', 30)->index();
                $table->string('chamber_code', 40)->index();
                $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->date('request_date');
                $table->date('needed_date');
                $table->string('status', 40)->default('DRAFT')->index();
                $table->string('approval_route', 40)->index();
                $table->string('currency', 3)->default('IDR');
                $table->decimal('subtotal', 20, 2)->default(0);
                $table->decimal('tax_amount', 20, 2)->default(0);
                $table->decimal('grand_total', 20, 2)->default(0);
                $table->text('notes')->nullable();
                $table->string('source_type', 80)->nullable()->index();
                $table->string('source_id', 64)->nullable()->index();
                $table->string('source_key', 191)->nullable()->unique();
                $table->json('source_payload')->nullable();
                $table->unsignedInteger('lock_version')->default(1);
                $table->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignUlid('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'request_date'], 'pur_fund_request_status_date_idx');
                $table->index(['request_type', 'status'], 'pur_fund_request_type_status_idx');
                $table->index(['chamber_code', 'outlet_id', 'status'], 'pur_fund_request_scope_status_idx');
                $table->index(['approval_route', 'status', 'submitted_at'], 'pur_fund_request_approval_queue_idx');
                $table->index(['created_by_user_id', 'created_at'], 'pur_fund_request_creator_date_idx');
            });
        }

        if (! Schema::hasTable('pur_fund_request_items')) {
            Schema::create('pur_fund_request_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('fund_request_id')->constrained('pur_fund_requests')->cascadeOnDelete();
                $table->unsignedSmallInteger('line_no');
                $table->foreignUlid('sku_id')->nullable()->constrained('stk_skus')->nullOnDelete();
                $table->string('item_name', 255);
                $table->string('uom_text', 50)->nullable();
                $table->decimal('qty', 18, 4);
                $table->decimal('estimated_unit_price', 18, 2)->default(0);
                $table->string('tax_mode', 20)->default('NO_TAX');
                $table->decimal('tax_percent', 7, 4)->default(0);
                $table->decimal('subtotal', 20, 2)->default(0);
                $table->decimal('tax_amount', 20, 2)->default(0);
                $table->decimal('line_total', 20, 2)->default(0);
                $table->text('notes')->nullable();
                $table->string('source_line_key', 191)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['fund_request_id', 'line_no'], 'pur_fund_request_item_line_uq');
                $table->unique(['fund_request_id', 'source_line_key'], 'pur_fund_request_source_line_uq');
                $table->index(['fund_request_id', 'sku_id'], 'pur_fund_request_item_sku_idx');
            });
        }

        if (! Schema::hasTable('pur_fund_request_decisions')) {
            Schema::create('pur_fund_request_decisions', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('fund_request_id')->constrained('pur_fund_requests')->cascadeOnDelete();
                $table->string('step_code', 60)->default('REQUEST_APPROVAL_1');
                $table->string('action', 30);
                $table->string('previous_status', 40)->nullable();
                $table->string('new_status', 40)->nullable();
                $table->text('notes')->nullable();
                $table->string('idempotency_key', 120);
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('actor_snapshot')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->unique(['fund_request_id', 'idempotency_key'], 'pur_fund_request_decision_idempotency_uq');
                $table->index(['fund_request_id', 'occurred_at'], 'pur_fund_request_decision_timeline_idx');
            });
        }

        if (! Schema::hasTable('pur_document_events')) {
            Schema::create('pur_document_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('root_request_id')->constrained('pur_fund_requests')->cascadeOnDelete();
                $table->string('document_type', 40)->index();
                $table->string('document_id', 64)->index();
                $table->string('event_code', 80)->index();
                $table->string('event_label', 150);
                $table->string('status', 40)->nullable()->index();
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name_snapshot', 180)->nullable();
                $table->timestamp('occurred_at');
                $table->text('notes')->nullable();
                $table->string('reference_type', 60)->nullable();
                $table->string('reference_id', 64)->nullable();
                $table->string('reference_number', 100)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['root_request_id', 'occurred_at'], 'pur_document_event_root_date_idx');
                $table->index(['document_type', 'document_id', 'occurred_at'], 'pur_document_event_document_date_idx');
            });
        }

        $this->registerPermissions();
        $this->reconcileFundRequestMenu();
    }

    private function registerPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $guard = (string) config('auth.defaults.guard', 'web');
        foreach (self::ACTION_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (Schema::hasTable('roles')) {
            $adminRoles = Role::query()
                ->where('guard_name', $guard)
                ->where(function ($query): void {
                    $query->whereRaw('LOWER(name) = ?', ['admin'])
                        ->orWhereRaw('LOWER(name) = ?', ['administrator'])
                        ->orWhereRaw('LOWER(name) LIKE ?', ['%super%admin%']);
                })
                ->get();

            foreach ($adminRoles as $role) {
                $role->givePermissionTo(self::ACTION_PERMISSIONS);
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * Reconciliation only: creates a missing Fund Request menu but never
     * changes role/level decisions that an administrator already configured.
     */
    private function reconcileFundRequestMenu(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', 'purchasing')->first();
        if (! $portal) {
            return;
        }

        $existing = DB::table('access_menus')->where('code', 'purchasing-fund-requests')->first();
        if ($existing) {
            return;
        }

        $now = now();
        DB::table('access_menus')->insert([
            'id' => (string) Str::ulid(),
            'portal_id' => $portal->id,
            'code' => 'purchasing-fund-requests',
            'name' => 'Pengajuan Dana',
            'path' => '/purchasing/fund-requests',
            'sort_order' => 20,
            'permission_view' => 'purchasing.fund_request.view',
            'permission_create' => 'purchasing.fund_request.create',
            'permission_update' => 'purchasing.fund_request.update',
            'permission_delete' => 'purchasing.fund_request.delete',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pur_document_events');
        Schema::dropIfExists('pur_fund_request_decisions');
        Schema::dropIfExists('pur_fund_request_items');
        Schema::dropIfExists('pur_fund_requests');
        Schema::dropIfExists('pur_document_sequences');

        // Permissions and Access Matrix records are intentionally retained.
    }
};
