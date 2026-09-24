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
    public function up(): void
    {
        foreach ([
            'wh_v7_production_material_opnames',
            'wh_v7_production_material_opname_items',
            'wh_productions',
            'outlets',
            'users',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse I04 membutuhkan tabel {$table}. Apply baseline Production Opname terlebih dahulu.");
            }
        }

        if (! Schema::hasColumn('wh_v7_production_material_opname_items', 'remaining_input_uom_mode')) {
            Schema::table('wh_v7_production_material_opname_items', function (Blueprint $table): void {
                $table->string('remaining_input_uom_mode', 20)->default('purchase')->after('remaining_qty_uom');
            });
        }
        if (! Schema::hasColumn('wh_v7_production_material_opname_items', 'remaining_input_qty')) {
            Schema::table('wh_v7_production_material_opname_items', function (Blueprint $table): void {
                $table->decimal('remaining_input_qty', 18, 4)->default(0)->after('remaining_input_uom_mode');
            });
        }
        if (! Schema::hasColumn('wh_v7_production_material_opname_items', 'purchase_uom_id_snapshot')) {
            Schema::table('wh_v7_production_material_opname_items', function (Blueprint $table): void {
                $table->ulid('purchase_uom_id_snapshot')->nullable()->after('remaining_input_qty');
            });
        }
        if (! Schema::hasColumn('wh_v7_production_material_opname_items', 'purchase_uom_code_snapshot')) {
            Schema::table('wh_v7_production_material_opname_items', function (Blueprint $table): void {
                $table->string('purchase_uom_code_snapshot', 32)->nullable()->after('purchase_uom_id_snapshot');
            });
        }
        if (! Schema::hasColumn('wh_v7_production_material_opname_items', 'purchase_conversion_factor_snapshot')) {
            Schema::table('wh_v7_production_material_opname_items', function (Blueprint $table): void {
                $table->decimal('purchase_conversion_factor_snapshot', 24, 8)->nullable()->after('purchase_uom_code_snapshot');
            });
        }

        if (! Schema::hasTable('wh_v7_production_material_opname_reopen_requests')) {
            Schema::create('wh_v7_production_material_opname_reopen_requests', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('request_number', 70);
                $table->ulid('warehouse_id');
                $table->ulid('production_id');
                $table->ulid('opname_id');
                $table->string('status', 24)->default('pending');
                $table->text('reason');
                $table->ulid('requested_by_user_id')->nullable();
                $table->timestamp('requested_at');
                $table->text('decision_notes')->nullable();
                $table->ulid('decided_by_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->json('reversal_snapshot')->nullable();
                $table->timestamps();

                $table->index(['warehouse_id', 'production_id', 'status'], 'whv7_opr_wh_prod_status_idx');
                $table->index(['opname_id', 'requested_at'], 'whv7_opr_opname_time_idx');
            });
        }

        // MySQL limits index/constraint identifiers to 64 characters.
        // Do not rely on Laravel's auto-generated names for this long table.
        // These calls are intentionally outside Schema::create so rerunning after
        // a partially failed migration repairs the already-created table.
        $this->ensureIndex(
            'wh_v7_production_material_opname_reopen_requests',
            ['request_number'],
            'whv7_opr_request_no_uq',
            true
        );
        $this->ensureIndex(
            'wh_v7_production_material_opname_reopen_requests',
            ['status'],
            'whv7_opr_status_idx'
        );

        $this->ensureForeignKey('wh_v7_production_material_opname_reopen_requests', 'warehouse_id', 'outlets', 'id', 'whv7_opr_wh_fk', 'RESTRICT');
        $this->ensureForeignKey('wh_v7_production_material_opname_reopen_requests', 'production_id', 'wh_productions', 'id', 'whv7_opr_prod_fk', 'CASCADE');
        $this->ensureForeignKey('wh_v7_production_material_opname_reopen_requests', 'opname_id', 'wh_v7_production_material_opnames', 'id', 'whv7_opr_opname_fk', 'CASCADE');
        $this->ensureForeignKey('wh_v7_production_material_opname_reopen_requests', 'requested_by_user_id', 'users', 'id', 'whv7_opr_requester_fk', 'SET NULL');
        $this->ensureForeignKey('wh_v7_production_material_opname_reopen_requests', 'decided_by_user_id', 'users', 'id', 'whv7_opr_decider_fk', 'SET NULL');

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            Permission::findOrCreate('warehouse.production.opname.reopen.request', $guard);
            Permission::findOrCreate('warehouse.production.opname.reopen.approve', $guard);
        }

        // Access Matrix tetap memakai satu menu Production Opname.
        // can_edit menjadi fallback untuk requester. can_delete yang sebelumnya
        // tidak memiliki endpoint delete direpurpose sebagai approval authority.
        if (Schema::hasTable('access_menus')) {
            $menu = DB::table('access_menus')->where('path', '/warehouse/production/opname')->first();
            if ($menu) {
                $payload = [];
                if (Schema::hasColumn('access_menus', 'permission_delete')) {
                    $payload['permission_delete'] = 'warehouse.production.opname.reopen.approve';
                }
                if (Schema::hasColumn('access_menus', 'updated_at')) {
                    $payload['updated_at'] = now();
                }
                if ($payload) {
                    DB::table('access_menus')->where('id', $menu->id)->update($payload);
                }
            }
        }

        // Existing rows historically stored the operator input in request UOM.
        // Mark them explicitly as legacy rather than pretending it was Purchase UOM.
        DB::table('wh_v7_production_material_opname_items')->update([
            'remaining_input_uom_mode' => 'request_legacy',
            'remaining_input_qty' => DB::raw('remaining_qty_uom'),
        ]);

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function ensureIndex(
        string $table,
        array $columns,
        string $indexName,
        bool $unique = false
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        $exists = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();

        if ($exists) {
            return;
        }

        // A failed first run may have created an equivalent index under another
        // name. Avoid adding a duplicate when the same ordered columns exist.
        $indexes = DB::table('information_schema.STATISTICS')
            ->select(['INDEX_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX', 'COLUMN_NAME'])
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->orderBy('INDEX_NAME')
            ->orderBy('SEQ_IN_INDEX')
            ->get()
            ->groupBy('INDEX_NAME');

        foreach ($indexes as $rows) {
            $existingColumns = $rows->pluck('COLUMN_NAME')->values()->all();
            $existingUnique = ((int) $rows->first()->NON_UNIQUE) === 0;
            if ($existingColumns === array_values($columns) && (! $unique || $existingUnique)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName, $unique): void {
            if ($unique) {
                $blueprint->unique($columns, $indexName);
            } else {
                $blueprint->index($columns, $indexName);
            }
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
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $exists = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
        if ($exists) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use (
            $column,
            $referencedTable,
            $referencedColumn,
            $constraintName,
            $onDelete
        ): void {
            $foreign = $blueprint->foreign($column, $constraintName)
                ->references($referencedColumn)
                ->on($referencedTable);

            match ($onDelete) {
                'CASCADE' => $foreign->cascadeOnDelete(),
                'SET NULL' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }

    public function down(): void
    {
        // Non-destructive by design: reopen approval/reversal history is audit evidence.
    }
};
