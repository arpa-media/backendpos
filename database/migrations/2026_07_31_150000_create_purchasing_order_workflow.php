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
    private const MENU_PERMISSIONS = [
        'purchasing.purchase_order.view',
        'purchasing.purchase_order.create',
        'purchasing.purchase_order.update',
        'purchasing.purchase_order.delete',
        'purchasing.service_order.view',
        'purchasing.service_order.create',
        'purchasing.service_order.update',
        'purchasing.service_order.delete',
        'purchasing.reimburse_order.view',
        'purchasing.reimburse_order.create',
        'purchasing.reimburse_order.update',
        'purchasing.reimburse_order.delete',
    ];

    private const ACTION_PERMISSIONS = [
        'purchasing.purchase_order.submit',
        'purchasing.purchase_order.approve_finance_1',
        'purchasing.purchase_order.approve_finance_2',
        'purchasing.purchase_order.reject',
        'purchasing.service_order.submit',
        'purchasing.service_order.approve_finance_1',
        'purchasing.service_order.approve_finance_2',
        'purchasing.service_order.reject',
        'purchasing.reimburse_order.submit',
        'purchasing.reimburse_order.approve_finance_1',
        'purchasing.reimburse_order.approve_finance_2',
        'purchasing.reimburse_order.reject',
    ];

    public function up(): void
    {
        $this->extendPurchaseOrders();
        $this->createServiceOrders();
        $this->createReimburseOrders();
        $this->createOrderDecisions();
        $this->registerPermissions();
        $this->reconcileAccessMatrix();
        $this->backfillPurchaseOrderLines();
    }

    private function extendPurchaseOrders(): void
    {
        if (! Schema::hasTable('pur_purchase_orders')) {
            return;
        }

        Schema::table('pur_purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('pur_purchase_orders', 'order_date')) {
                $table->date('order_date')->nullable()->after('order_type');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'needed_date')) {
                $table->date('needed_date')->nullable()->after('order_date');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'chamber_code')) {
                $table->string('chamber_code', 40)->nullable()->after('outlet_id')->index();
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'counterparty_name')) {
                $table->string('counterparty_name', 180)->nullable();
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'subtotal')) {
                $table->decimal('subtotal', 20, 2)->default(0)->after('currency');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'tax_amount')) {
                $table->decimal('tax_amount', 20, 2)->default(0)->after('subtotal');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'notes')) {
                $table->text('notes')->nullable()->after('tax_amount');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'lock_version')) {
                $table->unsignedInteger('lock_version')->default(1)->after('notes');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'submitted_by_user_id')) {
                $table->foreignUlid('submitted_by_user_id')->nullable()->after('lock_version')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('submitted_by_user_id');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'finance_approved_1_by_user_id')) {
                $table->foreignUlid('finance_approved_1_by_user_id')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'finance_approved_1_at')) {
                $table->timestamp('finance_approved_1_at')->nullable()->after('finance_approved_1_by_user_id');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'finance_approved_2_by_user_id')) {
                $table->foreignUlid('finance_approved_2_by_user_id')->nullable()->after('finance_approved_1_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'finance_approved_2_at')) {
                $table->timestamp('finance_approved_2_at')->nullable()->after('finance_approved_2_by_user_id');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'rejected_by_user_id')) {
                $table->foreignUlid('rejected_by_user_id')->nullable()->after('finance_approved_2_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by_user_id');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'rejection_notes')) {
                $table->text('rejection_notes')->nullable()->after('rejected_at');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'warehouse_handoff_key')) {
                $table->string('warehouse_handoff_key', 120)->nullable()->after('rejection_notes')->unique();
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'warehouse_handoff_at')) {
                $table->timestamp('warehouse_handoff_at')->nullable()->after('warehouse_handoff_key');
            }
            if (! Schema::hasColumn('pur_purchase_orders', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Existing Stock-only columns become nullable so Purchase and Asset
        // orders can use the same canonical table without creating a parallel PO.
        Schema::table('pur_purchase_orders', function (Blueprint $table): void {
            $table->ulid('stock_request_id')->nullable()->change();
            $table->ulid('outlet_id')->nullable()->change();
        });

        if (Schema::hasTable('pur_purchase_order_items')) {
            Schema::table('pur_purchase_order_items', function (Blueprint $table): void {
                $table->ulid('stock_request_item_id')->nullable()->change();
                $table->ulid('sku_id')->nullable()->change();

                if (! Schema::hasColumn('pur_purchase_order_items', 'fund_request_item_id')) {
                    $table->foreignUlid('fund_request_item_id')->nullable()->after('stock_request_item_id')->constrained('pur_fund_request_items')->nullOnDelete();
                }
                if (! Schema::hasColumn('pur_purchase_order_items', 'line_no')) {
                    $table->unsignedSmallInteger('line_no')->nullable()->after('purchase_order_id');
                }
                if (! Schema::hasColumn('pur_purchase_order_items', 'item_name')) {
                    $table->string('item_name', 255)->nullable()->after('sku_id');
                }
                if (! Schema::hasColumn('pur_purchase_order_items', 'uom_text')) {
                    $table->string('uom_text', 50)->nullable()->after('item_name');
                }
                if (! Schema::hasColumn('pur_purchase_order_items', 'tax_mode')) {
                    $table->string('tax_mode', 20)->default('NO_TAX')->after('unit_price');
                }
                if (! Schema::hasColumn('pur_purchase_order_items', 'tax_percent')) {
                    $table->decimal('tax_percent', 7, 4)->default(0)->after('tax_mode');
                }
                if (! Schema::hasColumn('pur_purchase_order_items', 'subtotal')) {
                    $table->decimal('subtotal', 20, 2)->default(0)->after('tax_percent');
                }
                if (! Schema::hasColumn('pur_purchase_order_items', 'tax_amount')) {
                    $table->decimal('tax_amount', 20, 2)->default(0)->after('subtotal');
                }
                if (! Schema::hasColumn('pur_purchase_order_items', 'notes')) {
                    $table->text('notes')->nullable()->after('line_total');
                }
                if (! Schema::hasColumn('pur_purchase_order_items', 'source_line_key')) {
                    $table->string('source_line_key', 191)->nullable()->after('notes');
                }
                if (! Schema::hasColumn('pur_purchase_order_items', 'metadata')) {
                    $table->json('metadata')->nullable()->after('source_line_key');
                }
            });

            if (! $this->indexExists('pur_purchase_order_items', 'pur_po_item_line_no_uq')) {
                Schema::table('pur_purchase_order_items', function (Blueprint $table): void {
                    $table->unique(['purchase_order_id', 'line_no'], 'pur_po_item_line_no_uq');
                });
            }

            if (! $this->indexExists('pur_purchase_order_items', 'pur_po_item_fund_request_idx')) {
                Schema::table('pur_purchase_order_items', function (Blueprint $table): void {
                    $table->index(['fund_request_item_id', 'purchase_order_id'], 'pur_po_item_fund_request_idx');
                });
            }
        }

        if (! $this->indexExists('pur_purchase_orders', 'pur_po_fund_request_uq')) {
            Schema::table('pur_purchase_orders', function (Blueprint $table): void {
                $table->unique('fund_request_id', 'pur_po_fund_request_uq');
            });
        }
    }

    private function createServiceOrders(): void
    {
        if (! Schema::hasTable('pur_service_orders')) {
            Schema::create('pur_service_orders', function (Blueprint $table): void {
                $this->orderHeaderColumns($table, 'service_order_number');
                $table->foreignUlid('supplier_source_id')->nullable()->constrained('pur_supplier_sources')->nullOnDelete();
                $table->string('counterparty_name', 180)->nullable();
                $table->unique('fund_request_id', 'pur_service_order_fund_request_uq');
                $table->index(['status', 'order_date'], 'pur_service_order_status_date_idx');
                $table->index(['chamber_code', 'outlet_id', 'status'], 'pur_service_order_scope_idx');
            });
        }

        if (! Schema::hasTable('pur_service_order_items')) {
            Schema::create('pur_service_order_items', function (Blueprint $table): void {
                $this->orderItemColumns($table, 'service_order_id', 'pur_service_orders');
                $table->unique(['service_order_id', 'line_no'], 'pur_service_order_item_line_uq');
            });
        }
    }

    private function createReimburseOrders(): void
    {
        if (! Schema::hasTable('pur_reimburse_orders')) {
            Schema::create('pur_reimburse_orders', function (Blueprint $table): void {
                $this->orderHeaderColumns($table, 'reimburse_order_number');
                $table->string('counterparty_name', 180)->nullable();
                $table->string('payment_destination', 255)->nullable();
                $table->unique('fund_request_id', 'pur_reimburse_order_fund_request_uq');
                $table->index(['status', 'order_date'], 'pur_reimburse_order_status_date_idx');
                $table->index(['chamber_code', 'outlet_id', 'status'], 'pur_reimburse_order_scope_idx');
            });
        }

        if (! Schema::hasTable('pur_reimburse_order_items')) {
            Schema::create('pur_reimburse_order_items', function (Blueprint $table): void {
                $this->orderItemColumns($table, 'reimburse_order_id', 'pur_reimburse_orders');
                $table->unique(['reimburse_order_id', 'line_no'], 'pur_reimburse_order_item_line_uq');
            });
        }
    }

    private function createOrderDecisions(): void
    {
        if (Schema::hasTable('pur_order_decisions')) {
            return;
        }

        Schema::create('pur_order_decisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('document_type', 40)->index();
            $table->string('document_id', 64)->index();
            $table->string('step_code', 60)->index();
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

            $table->unique(['document_type', 'document_id', 'idempotency_key'], 'pur_order_decision_idempotency_uq');
            $table->index(['document_type', 'document_id', 'occurred_at'], 'pur_order_decision_timeline_idx');
        });
    }

    private function orderHeaderColumns(Blueprint $table, string $numberColumn): void
    {
        $table->ulid('id')->primary();
        $table->string($numberColumn, 60)->unique();
        $table->foreignUlid('fund_request_id')->constrained('pur_fund_requests')->cascadeOnDelete();
        $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
        $table->string('chamber_code', 40)->index();
        $table->date('order_date');
        $table->date('needed_date')->nullable();
        $table->string('status', 40)->default('DRAFT')->index();
        $table->string('currency', 3)->default('IDR');
        $table->decimal('subtotal', 20, 2)->default(0);
        $table->decimal('tax_amount', 20, 2)->default(0);
        $table->decimal('total_amount', 20, 2)->default(0);
        $table->text('notes')->nullable();
        $table->unsignedInteger('lock_version')->default(1);
        $table->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('submitted_at')->nullable();
        $table->foreignUlid('finance_approved_1_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('finance_approved_1_at')->nullable();
        $table->foreignUlid('finance_approved_2_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('finance_approved_2_at')->nullable();
        $table->foreignUlid('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('rejected_at')->nullable();
        $table->text('rejection_notes')->nullable();
        $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
        $table->softDeletes();
    }

    private function orderItemColumns(Blueprint $table, string $foreignColumn, string $parentTable): void
    {
        $table->ulid('id')->primary();
        $table->foreignUlid($foreignColumn)->constrained($parentTable)->cascadeOnDelete();
        $table->foreignUlid('fund_request_item_id')->nullable()->constrained('pur_fund_request_items')->nullOnDelete();
        $table->unsignedSmallInteger('line_no');
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
        $table->string('source_line_key', 191)->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
        $table->index(['fund_request_item_id', $foreignColumn], 'pur_' . substr($foreignColumn, 0, 20) . '_fund_idx');
    }

    private function backfillPurchaseOrderLines(): void
    {
        if (! Schema::hasTable('pur_purchase_orders') || ! Schema::hasTable('pur_purchase_order_items')) {
            return;
        }

        DB::table('pur_purchase_orders')
            ->whereNull('order_date')
            ->update([
                'order_date' => DB::raw('DATE(created_at)'),
                'lock_version' => 1,
            ]);

        // Enrich Stock PO drafts generated by Iteration 04 without replacing
        // their immutable source linkage.
        DB::table('pur_purchase_orders')
            ->orderBy('id')
            ->get()
            ->each(function ($order): void {
                $fundRequest = ! empty($order->fund_request_id) && Schema::hasTable('pur_fund_requests')
                    ? DB::table('pur_fund_requests')->where('id', $order->fund_request_id)->first()
                    : null;
                $supplierName = ! empty($order->supplier_source_id) && Schema::hasTable('pur_supplier_sources')
                    ? DB::table('pur_supplier_sources')->where('id', $order->supplier_source_id)->value('name')
                    : null;

                $attributes = [
                    'order_date' => $order->order_date ?: ($fundRequest->request_date ?? substr((string) $order->created_at, 0, 10)),
                    'needed_date' => $order->needed_date ?: ($fundRequest->needed_date ?? null),
                    'chamber_code' => $order->chamber_code ?: ($fundRequest->chamber_code ?? null),
                    'counterparty_name' => $order->counterparty_name ?: $supplierName,
                    'subtotal' => (float) ($order->subtotal ?: $order->total_amount),
                    'tax_amount' => (float) ($order->tax_amount ?? 0),
                    'lock_version' => max(1, (int) ($order->lock_version ?? 1)),
                    'updated_at' => now(),
                ];

                DB::table('pur_purchase_orders')->where('id', $order->id)->update($attributes);
            });

        $groups = DB::table('pur_purchase_order_items')
            ->select('purchase_order_id')
            ->groupBy('purchase_order_id')
            ->pluck('purchase_order_id');

        foreach ($groups as $purchaseOrderId) {
            $rows = DB::table('pur_purchase_order_items')
                ->where('purchase_order_id', $purchaseOrderId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            foreach ($rows as $index => $row) {
                $itemName = null;
                if (! empty($row->sku_id) && Schema::hasTable('stk_skus')) {
                    $itemName = DB::table('stk_skus')->where('id', $row->sku_id)->value('name');
                }

                $fundRequestItemId = null;
                $sourceLineKey = ! empty($row->stock_request_item_id) ? (string) $row->stock_request_item_id : null;
                if ($sourceLineKey && Schema::hasTable('pur_fund_request_items')) {
                    $fundRequestId = DB::table('pur_purchase_orders')
                        ->where('id', $purchaseOrderId)
                        ->value('fund_request_id');
                    if ($fundRequestId) {
                        $fundRequestItemId = DB::table('pur_fund_request_items')
                            ->where('fund_request_id', $fundRequestId)
                            ->where('source_line_key', $sourceLineKey)
                            ->value('id');
                    }
                }

                $resolvedFundRequestItemId = $row->fund_request_item_id ?: $fundRequestItemId;
                $fundLine = $resolvedFundRequestItemId && Schema::hasTable('pur_fund_request_items')
                    ? DB::table('pur_fund_request_items')->where('id', $resolvedFundRequestItemId)->first()
                    : null;
                $taxMode = strtoupper((string) ($row->tax_mode ?: ($fundLine->tax_mode ?? 'NO_TAX')));
                $taxPercent = $taxMode === 'TAX'
                    ? (float) ($row->tax_percent ?: ($fundLine->tax_percent ?? 0))
                    : 0.0;
                $lineSubtotal = (float) ($row->approved_qty ?? 0) * (float) ($row->unit_price ?? 0);
                $lineTax = $taxMode === 'TAX'
                    ? round($lineSubtotal * $taxPercent / 100, 2)
                    : 0.0;
                DB::table('pur_purchase_order_items')
                    ->where('id', $row->id)
                    ->update([
                        'line_no' => $row->line_no ?: $index + 1,
                        'fund_request_item_id' => $resolvedFundRequestItemId,
                        'item_name' => $row->item_name ?: ($fundLine->item_name ?? ($itemName ?: 'Item Stock')),
                        'uom_text' => $row->uom_text ?: ($fundLine->uom_text ?? null),
                        'tax_mode' => $taxMode,
                        'tax_percent' => $taxPercent,
                        'subtotal' => $lineSubtotal,
                        'tax_amount' => $lineTax,
                        'line_total' => $lineSubtotal + $lineTax,
                        'notes' => $row->notes ?: ($fundLine->notes ?? null),
                        'source_line_key' => $row->source_line_key ?: ($fundLine->source_line_key ?? $sourceLineKey),
                        'metadata' => $row->metadata ?: ($fundLine->metadata ?? null),
                    ]);
            }

            $totals = DB::table('pur_purchase_order_items')
                ->where('purchase_order_id', $purchaseOrderId)
                ->selectRaw('COALESCE(SUM(subtotal), 0) AS subtotal, COALESCE(SUM(tax_amount), 0) AS tax_amount, COALESCE(SUM(line_total), 0) AS total_amount')
                ->first();
            DB::table('pur_purchase_orders')->where('id', $purchaseOrderId)->update([
                'subtotal' => (float) ($totals->subtotal ?? 0),
                'tax_amount' => (float) ($totals->tax_amount ?? 0),
                'total_amount' => (float) ($totals->total_amount ?? 0),
                'updated_at' => now(),
            ]);
        }
    }

    private function registerPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $guard = (string) config('auth.defaults.guard', 'web');
        foreach ([...self::MENU_PERMISSIONS, ...self::ACTION_PERMISSIONS] as $permission) {
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
                $role->givePermissionTo([...self::MENU_PERMISSIONS, ...self::ACTION_PERMISSIONS]);
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function reconcileAccessMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $portal = DB::table('access_portals')->where('code', 'purchasing')->first();
        if (! $portal) {
            return;
        }

        $menus = [
            [
                'code' => 'purchasing-purchase-orders',
                'name' => 'Purchase Order',
                'path' => '/purchasing/purchase-orders',
                'sort_order' => 30,
                'permission' => 'purchasing.purchase_order',
            ],
            [
                'code' => 'purchasing-service-orders',
                'name' => 'Service Order',
                'path' => '/purchasing/service-orders',
                'sort_order' => 40,
                'permission' => 'purchasing.service_order',
            ],
            [
                'code' => 'purchasing-reimburse-orders',
                'name' => 'Reimburse Order',
                'path' => '/purchasing/reimburse-orders',
                'sort_order' => 50,
                'permission' => 'purchasing.reimburse_order',
            ],
        ];

        foreach ($menus as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            if ($existing) {
                DB::table('access_menus')->where('id', $existing->id)->update([
                    'name' => $menu['name'],
                    'path' => $menu['path'],
                    'sort_order' => $menu['sort_order'],
                    'permission_view' => $menu['permission'] . '.view',
                    'permission_create' => $menu['permission'] . '.create',
                    'permission_update' => $menu['permission'] . '.update',
                    'permission_delete' => $menu['permission'] . '.delete',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
                continue;
            }

            DB::table('access_menus')->insert([
                'id' => (string) Str::ulid(),
                'portal_id' => $portal->id,
                'code' => $menu['code'],
                'name' => $menu['name'],
                'path' => $menu['path'],
                'sort_order' => $menu['sort_order'],
                'permission_view' => $menu['permission'] . '.view',
                'permission_create' => $menu['permission'] . '.create',
                'permission_update' => $menu['permission'] . '.update',
                'permission_delete' => $menu['permission'] . '.delete',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        $safeTable = str_replace('`', '``', $table);
        $safeIndex = str_replace("'", "''", $indexName);

        return collect(DB::select("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'"))->isNotEmpty();
    }

    public function down(): void
    {
        // Intentionally non-destructive. Live approvals and document links may
        // already depend on this schema after deployment.
    }
};
