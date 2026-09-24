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
        foreach(['wh_productions','wh_v3_production_results','wh_v4_finance_general_postings'] as $table) if(!Schema::hasTable($table)) throw new RuntimeException("Warehouse I07 membutuhkan {$table}.");
        if(!Schema::hasTable('wh_i07_production_cogs_reconciliations')) Schema::create('wh_i07_production_cogs_reconciliations',function(Blueprint $t):void{
            $t->ulid('id')->primary();$t->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();$t->foreignUlid('production_id')->unique()->constrained('wh_productions')->cascadeOnDelete();
            $t->string('production_number',80)->index();$t->date('production_date')->index();$t->string('reconciliation_status',32)->default('RECONCILED')->index('wh_i07_cogs_recon_status_idx');$t->string('finance_status',32)->default('NOT_POSTED')->index();
            $t->decimal('material_cost',22,2)->default(0);$t->decimal('labor_cost',22,2)->default(0);$t->decimal('overhead_cost',22,2)->default(0);$t->decimal('waste_cost_memo',22,2)->default(0);$t->decimal('final_cogs',22,2)->default(0);
            $t->decimal('output_qty_base',18,4)->default(0);$t->decimal('output_inventory_value',22,2)->default(0);$t->decimal('selected_output_value',22,2)->default(0);$t->decimal('variance_value',22,2)->default(0);$t->decimal('yield_percent',9,4)->default(0);$t->decimal('unit_cogs',20,6)->default(0);
            $t->char('source_fingerprint',64)->index();$t->json('source_snapshot');$t->json('finance_snapshot')->nullable();$t->ulid('reconciled_by_user_id')->nullable();$t->foreign('reconciled_by_user_id','wh_i07_cogs_reconciler_fk')->references('id')->on('users')->nullOnDelete();$t->timestamp('reconciled_at')->nullable();$t->timestamp('posted_at')->nullable();$t->timestamp('last_synced_at')->nullable();$t->timestamps();
            $t->index(['warehouse_id','production_date','finance_status'],'wh_i07_cogs_wh_date_fin_idx');
        });
        if(!Schema::hasTable('wh_i07_production_cogs_reconciliation_events')) Schema::create('wh_i07_production_cogs_reconciliation_events',function(Blueprint $t):void{
            $t->ulid('id')->primary();$t->ulid('reconciliation_id');$t->foreign('reconciliation_id','wh_i07_cogs_evt_recon_fk')->references('id')->on('wh_i07_production_cogs_reconciliations')->cascadeOnDelete();$t->string('event_type',80)->index();$t->json('payload')->nullable();$t->ulid('actor_user_id')->nullable();$t->foreign('actor_user_id','wh_i07_cogs_evt_actor_fk')->references('id')->on('users')->nullOnDelete();$t->timestamp('occurred_at')->useCurrent()->index();$t->timestamps();
        });
        foreach(['warehouse.production.cogs.reconcile','warehouse.production.cogs.post'] as $name) Permission::findOrCreate($name,config('auth.defaults.guard','web'));
        if(Schema::hasTable('access_menus')){
            foreach(['/warehouse/production/cogs','/warehouse/production/costing'] as $path){DB::table('access_menus')->where('path',$path)->update(['name'=>'COGS Warehouse','permission_view'=>'warehouse.production.cost.view','permission_create'=>'warehouse.production.cogs.reconcile','permission_update'=>'warehouse.production.cogs.post','updated_at'=>now()]);}
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    public function down():void{}
};
