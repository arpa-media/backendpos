<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['wh_v3_delivery_orders','wh_ledger_postings','wh_productions','wh_production_inputs','wh_v3_production_material_request_items','outlets','stk_skus','stk_uoms','users'] as $table) {
            if (! Schema::hasTable($table)) throw new RuntimeException("ERP REV Iteration 07 membutuhkan tabel {$table}.");
        }

        Schema::table('wh_v3_delivery_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_v3_delivery_orders','dispatch_ledger_posting_id')) {
                $table->ulid('dispatch_ledger_posting_id')->nullable()->after('dispatched_at');
                $table->foreign('dispatch_ledger_posting_id','whv3_do_i07_ledger_fk')->references('id')->on('wh_ledger_postings')->nullOnDelete();
                $table->index('dispatch_ledger_posting_id','whv3_do_i07_ledger_idx');
            }
            if (! Schema::hasColumn('wh_v3_delivery_orders','stock_dispatched_at')) {
                $table->timestamp('stock_dispatched_at')->nullable()->after('dispatch_ledger_posting_id');
            }
        });

        if (! Schema::hasTable('wh_v7_production_stock_balances')) {
            Schema::create('wh_v7_production_stock_balances', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('warehouse_id');
                $table->ulid('sku_id');
                $table->decimal('qty_base',18,4)->default(0);
                $table->decimal('average_unit_cost',20,6)->default(0);
                $table->decimal('inventory_value',22,2)->default(0);
                $table->timestamp('last_opname_at')->nullable();
                $table->unsignedInteger('lock_version')->default(1);
                $table->timestamps();
                $table->unique(['warehouse_id','sku_id'],'whv7_prodstock_wh_sku_uq');
            });
        }
        $this->ensureForeignKey('wh_v7_production_stock_balances','warehouse_id','outlets','id','whv7_ps_wh_fk','RESTRICT');
        $this->ensureForeignKey('wh_v7_production_stock_balances','sku_id','stk_skus','id','whv7_ps_sku_fk','RESTRICT');

        if (! Schema::hasTable('wh_v7_production_material_allocations')) {
            Schema::create('wh_v7_production_material_allocations', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('warehouse_id');
                $table->ulid('production_id');
                $table->ulid('production_input_id');
                $table->ulid('production_request_item_id')->nullable();
                $table->index('production_request_item_id','whv7_pa_reqitem_idx');
                $table->ulid('sku_id');
                $table->decimal('carry_in_qty_base',18,4)->default(0);
                $table->decimal('carry_in_value',22,2)->default(0);
                $table->decimal('warehouse_issue_qty_base',18,4)->default(0);
                $table->decimal('warehouse_issue_value',22,2)->default(0);
                $table->decimal('available_qty_base',18,4)->default(0);
                $table->decimal('available_value',22,2)->default(0);
                $table->decimal('remaining_qty_base',18,4)->default(0);
                $table->decimal('remaining_value',22,2)->default(0);
                $table->decimal('consumed_qty_base',18,4)->default(0);
                $table->decimal('consumed_value',22,2)->default(0);
                $table->string('status',32)->default('allocated');
                $table->index('status','whv7_pa_status_idx');
                $table->timestamps();
                $table->unique('production_input_id','whv7_prodalloc_input_uq');
                $table->index(['warehouse_id','production_id'],'whv7_prodalloc_lookup_idx');
            });
        }
        // Self-heal partial MySQL DDL from a previous failed run: keep any FK
        // already created and add only missing column relationships with short names.
        $this->ensureForeignKey('wh_v7_production_material_allocations','warehouse_id','outlets','id','whv7_pa_wh_fk','RESTRICT');
        $this->ensureForeignKey('wh_v7_production_material_allocations','production_id','wh_productions','id','whv7_pa_prod_fk','CASCADE');
        $this->ensureForeignKey('wh_v7_production_material_allocations','production_input_id','wh_production_inputs','id','whv7_pa_input_fk','CASCADE');
        $this->ensureForeignKey('wh_v7_production_material_allocations','sku_id','stk_skus','id','whv7_pa_sku_fk','RESTRICT');
        $this->ensureIndex('wh_v7_production_material_allocations',['production_request_item_id'],'whv7_pa_reqitem_idx');
        $this->ensureIndex('wh_v7_production_material_allocations',['status'],'whv7_pa_status_idx');

        if (! Schema::hasTable('wh_v7_production_material_opnames')) {
            Schema::create('wh_v7_production_material_opnames', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('opname_number',60);
                $table->unique('opname_number','whv7_po_no_uq');
                $table->ulid('warehouse_id');
                $table->ulid('production_id');
                $table->string('status',24)->default('draft');
                $table->index('status','whv7_po_status_idx');
                $table->date('opname_date');
                $table->index('opname_date','whv7_po_date_idx');
                $table->text('notes')->nullable();
                $table->ulid('created_by_user_id')->nullable();
                $table->ulid('updated_by_user_id')->nullable();
                $table->ulid('finalized_by_user_id')->nullable();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();
                $table->unique('production_id','whv7_prodopname_prod_uq');
            });
        }
        $this->ensureForeignKey('wh_v7_production_material_opnames','warehouse_id','outlets','id','whv7_po_wh_fk','RESTRICT');
        $this->ensureForeignKey('wh_v7_production_material_opnames','production_id','wh_productions','id','whv7_po_prod_fk','CASCADE');
        $this->ensureForeignKey('wh_v7_production_material_opnames','created_by_user_id','users','id','whv7_po_created_fk','SET NULL');
        $this->ensureForeignKey('wh_v7_production_material_opnames','updated_by_user_id','users','id','whv7_po_updated_fk','SET NULL');
        $this->ensureForeignKey('wh_v7_production_material_opnames','finalized_by_user_id','users','id','whv7_po_final_fk','SET NULL');
        $this->ensureIndex('wh_v7_production_material_opnames',['status'],'whv7_po_status_idx');
        $this->ensureIndex('wh_v7_production_material_opnames',['opname_date'],'whv7_po_date_idx');

        if (! Schema::hasTable('wh_v7_production_material_opname_items')) {
            Schema::create('wh_v7_production_material_opname_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('opname_id');
                $table->ulid('production_input_id');
                $table->ulid('sku_id');
                $table->ulid('uom_id');
                $table->decimal('conversion_factor_snapshot',24,8)->default(1);
                $table->decimal('available_qty_base',18,4)->default(0);
                $table->decimal('remaining_qty_uom',18,4)->default(0);
                $table->decimal('remaining_qty_base',18,4)->default(0);
                $table->decimal('consumed_qty_base',18,4)->default(0);
                $table->decimal('unit_cost_snapshot',20,6)->default(0);
                $table->decimal('remaining_value',22,2)->default(0);
                $table->decimal('consumed_value',22,2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['opname_id','production_input_id'],'whv7_prodopitem_uq');
            });
        }
        $this->ensureForeignKey('wh_v7_production_material_opname_items','opname_id','wh_v7_production_material_opnames','id','whv7_pi_opname_fk','CASCADE');
        $this->ensureForeignKey('wh_v7_production_material_opname_items','production_input_id','wh_production_inputs','id','whv7_pi_input_fk','CASCADE');
        $this->ensureForeignKey('wh_v7_production_material_opname_items','sku_id','stk_skus','id','whv7_pi_sku_fk','RESTRICT');
        $this->ensureForeignKey('wh_v7_production_material_opname_items','uom_id','stk_uoms','id','whv7_pi_uom_fk','RESTRICT');

        if (Schema::hasTable('permissions')) {
            $guard=config('auth.defaults.guard','web');
            foreach (['warehouse.production.opname.view','warehouse.production.opname.create','warehouse.production.opname.update','warehouse.production.opname.delete','warehouse.production.opname.finalize'] as $permission) {
                Permission::findOrCreate($permission,$guard);
            }
        }
        if (Schema::hasTable('access_menus')) {
            $q=DB::table('access_menus')->where('path','/warehouse/production/opname');
            if ($q->exists()) {
                $payload=[];
                if (Schema::hasColumn('access_menus','permission_view')) $payload['permission_view']='warehouse.production.opname.view';
                if (Schema::hasColumn('access_menus','permission_create')) $payload['permission_create']='warehouse.production.opname.create';
                if (Schema::hasColumn('access_menus','permission_update')) $payload['permission_update']='warehouse.production.opname.update';
                if (Schema::hasColumn('access_menus','permission_delete')) $payload['permission_delete']='warehouse.production.opname.delete';
                if (Schema::hasColumn('access_menus','is_active')) $payload['is_active']=true;
                if (Schema::hasColumn('access_menus','updated_at')) $payload['updated_at']=now();
                if ($payload) $q->update($payload);
            }
        }
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function ensureIndex(string $table,array $columns,string $indexName): void
    {
        if (! Schema::hasTable($table)) return;
        foreach ($columns as $column) if (! Schema::hasColumn($table,$column)) return;

        $exists=DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME',$table)
            ->where('INDEX_NAME',$indexName)
            ->exists();
        if ($exists) return;

        // A previous failed migration may have created the same column index
        // using Laravel's long auto-name. Reuse it instead of adding a duplicate.
        if (count($columns)===1) {
            $columnIndexed=DB::table('information_schema.STATISTICS')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME',$table)
                ->where('COLUMN_NAME',$columns[0])
                ->exists();
            if ($columnIndexed) return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns,$indexName): void {
            $blueprint->index($columns,$indexName);
        });
    }

    private function ensureForeignKey(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $constraintName,
        string $onDelete
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table,$column)) return;
        if ($this->hasForeignKeyForColumn($table,$column)) return;

        Schema::table($table, function (Blueprint $blueprint) use (
            $column,$referencedTable,$referencedColumn,$constraintName,$onDelete
        ): void {
            $foreign=$blueprint->foreign($column,$constraintName)
                ->references($referencedColumn)
                ->on($referencedTable);

            match ($onDelete) {
                'CASCADE' => $foreign->cascadeOnDelete(),
                'SET NULL' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }

    private function hasForeignKeyForColumn(string $table,string $column): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME',$table)
            ->where('COLUMN_NAME',$column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }

    public function down(): void
    {
        // Non-destructive by design: ledger/opname audit history is retained.
    }
};
