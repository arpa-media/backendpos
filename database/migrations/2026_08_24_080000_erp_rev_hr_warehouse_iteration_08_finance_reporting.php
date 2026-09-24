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
            'outlets','users','wh_v4_finance_coa','wh_v4_finance_general_postings','wh_v4_finance_general_posting_lines',
            'finance_chart_of_accounts','finance_general_postings','finance_journal_entries','finance_outlet_company_mappings',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("ERP REV Iteration 08 membutuhkan tabel {$table}. Apply Finance foundation terlebih dahulu.");
            }
        }

        if (! Schema::hasTable('wh_v8_finance_coa_mappings')) {
            Schema::create('wh_v8_finance_coa_mappings', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('warehouse_coa_id');
                $table->ulid('finance_coa_id');
                $table->unique('warehouse_coa_id','whv8_coa_map_wh_uq');
                $table->foreign('warehouse_coa_id','whv8_coa_map_wh_fk')->references('id')->on('wh_v4_finance_coa')->cascadeOnDelete();
                $table->foreign('finance_coa_id','whv8_coa_map_fin_fk')->references('id')->on('finance_chart_of_accounts')->restrictOnDelete();
                $table->string('mapping_method', 40)->default('AUTO');
                $table->string('mapping_note', 255)->nullable();
                $table->timestamps();
                $table->index(['finance_coa_id','warehouse_coa_id'], 'whv8_coa_map_fin_wh_idx');
            });
        }

        if (! Schema::hasTable('wh_v8_finance_posting_bridges')) {
            Schema::create('wh_v8_finance_posting_bridges', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('warehouse_general_posting_id');
                $table->unique('warehouse_general_posting_id','whv8_fin_bridge_whgp_uq');
                $table->foreign('warehouse_general_posting_id','whv8_fin_bridge_whgp_fk')->references('id')->on('wh_v4_finance_general_postings')->cascadeOnDelete();
                $table->ulid('finance_general_posting_id')->nullable()->index();
                $table->ulid('finance_journal_entry_id')->nullable()->index();
                $table->string('status', 24)->default('PENDING')->index();
                $table->char('source_fingerprint', 64)->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('last_attempted_at')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->index(['status','last_attempted_at'], 'whv8_fin_bridge_status_idx');
            });
        }

        $this->registerPermissionsAndMenus();

        // Seed every currently active/postable Warehouse COA into a canonical Finance account.
        // Dynamic Treasury accounts created later are covered by the sync service/command.
        app(\App\Services\Warehouse\FinanceV8\WarehouseFinanceCanonicalBridgeV8Service::class)->ensureMappings();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function registerPermissionsAndMenus(): void
    {
        $permissions = [
            'warehouse.finance.coa.view','warehouse.finance.coa.export',
            'warehouse.finance.general_ledger.view','warehouse.finance.general_ledger.export',
            'warehouse.finance.balance_sheet.view','warehouse.finance.balance_sheet.export',
            'warehouse.finance.cash_flow.view','warehouse.finance.cash_flow.export',
            'warehouse.finance.profit_loss.view','warehouse.finance.profit_loss.export',
            'warehouse.finance.scope.all',
        ];

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard','web');
            foreach ($permissions as $permission) Permission::findOrCreate($permission,$guard);
        }

        if (! Schema::hasTable('access_menus')) return;

        $menus = [
            ['/warehouse/finance/coa','CoA Warehouse','warehouse.finance.coa.view','warehouse.finance.coa.export'],
            ['/warehouse/finance/general-ledger','General Ledger','warehouse.finance.general_ledger.view','warehouse.finance.general_ledger.export'],
            ['/warehouse/finance/balance-sheet','Balance Sheet','warehouse.finance.balance_sheet.view','warehouse.finance.balance_sheet.export'],
            ['/warehouse/finance/cash-flow','Cash Flow','warehouse.finance.cash_flow.view','warehouse.finance.cash_flow.export'],
            ['/warehouse/finance/profit-loss','Profit & Loss','warehouse.finance.profit_loss.view','warehouse.finance.profit_loss.export'],
        ];

        foreach ($menus as [$path,$name,$view,$export]) {
            $row=DB::table('access_menus')->where('path',$path)->first();
            if (! $row) continue;
            $payload=[];
            if (Schema::hasColumn('access_menus','name')) $payload['name']=$name;
            if (Schema::hasColumn('access_menus','permission_view')) $payload['permission_view']=$view;
            // Report screens use Access Matrix Create as Export, matching the existing report convention.
            if (Schema::hasColumn('access_menus','permission_create')) $payload['permission_create']=$export;
            if (Schema::hasColumn('access_menus','permission_update')) $payload['permission_update']=null;
            if (Schema::hasColumn('access_menus','permission_delete')) $payload['permission_delete']=null;
            if (Schema::hasColumn('access_menus','is_active')) $payload['is_active']=true;
            if (Schema::hasColumn('access_menus','updated_at')) $payload['updated_at']=now();
            if ($payload) DB::table('access_menus')->where('id',$row->id)->update($payload);
        }

        // /warehouse/finance/coa is now the single canonical CoA surface.
        // Keep historical menu data but hide the duplicate v4 Chart of Account menu.
        DB::table('access_menus')->where('path','/warehouse/finance/chart-of-accounts')->update([
            'is_active'=>false,
            'updated_at'=>now(),
        ]);

        // Preserve current viewers' ability to export after Create becomes the Export action.
        if (Schema::hasTable('access_role_menu_permissions')) {
            $menuIds=DB::table('access_menus')->whereIn('path',collect($menus)->pluck(0)->all())->pluck('id');
            DB::table('access_role_menu_permissions')->whereIn('menu_id',$menuIds)->where('can_view',true)->update([
                'can_create'=>true,
                'updated_at'=>now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive. Canonical mapping and bridge history are accounting audit data.
    }
};
