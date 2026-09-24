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
        if (! Schema::hasTable('wh_v3_production_results')) {
            throw new RuntimeException('Warehouse I05 membutuhkan tabel wh_v3_production_results. Apply baseline Production terlebih dahulu.');
        }

        if (! Schema::hasColumn('wh_v3_production_results', 'rejection_reason')) {
            Schema::table('wh_v3_production_results', function (Blueprint $table): void {
                $table->text('rejection_reason')->nullable()->after('notes');
            });
        }
        if (! Schema::hasColumn('wh_v3_production_results', 'rejected_by_user_id')) {
            Schema::table('wh_v3_production_results', function (Blueprint $table): void {
                $table->ulid('rejected_by_user_id')->nullable()->after('approved_at');
            });
        }
        if (! Schema::hasColumn('wh_v3_production_results', 'rejected_at')) {
            Schema::table('wh_v3_production_results', function (Blueprint $table): void {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by_user_id');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('wh_v3_production_results', 'rejected_by_user_id')) {
            $fkExists = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'wh_v3_production_results')
                ->where('COLUMN_NAME', 'rejected_by_user_id')
                ->whereNotNull('REFERENCED_TABLE_NAME')->exists();
            if (! $fkExists) {
                Schema::table('wh_v3_production_results', function (Blueprint $table): void {
                    $table->foreign('rejected_by_user_id', 'wh_prod_i05_rejector_fk')->references('id')->on('users')->nullOnDelete();
                });
            }
        }

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            Permission::findOrCreate('warehouse.production.result.reject', $guard);
            Permission::findOrCreate('warehouse.production.result.delete', $guard);
        }

        // Existing Production Order menu remains the single access-matrix entry.
        // can_edit authorizes Reject/Not Approved fallback; can_delete authorizes result delete.
        if (Schema::hasTable('access_menus')) {
            $menu = DB::table('access_menus')->where('path', '/warehouse/production/orders')->first();
            if ($menu) {
                $payload = [];
                if (Schema::hasColumn('access_menus', 'permission_delete')) $payload['permission_delete'] = 'warehouse.production.result.delete';
                if (Schema::hasColumn('access_menus', 'updated_at')) $payload['updated_at'] = now();
                if ($payload) DB::table('access_menus')->where('id', $menu->id)->update($payload);
            }
        }

        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive: rejection audit columns are retained intentionally.
    }
};
