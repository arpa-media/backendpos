<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up():void
    {
        foreach(['outlets','users','stk_skus','stk_uoms','wh_storages','wh_batches','wh_ledger_postings','wh_v4_finance_coa','wh_v4_finance_posting_templates','wh_v4_finance_posting_template_lines','wh_v4_finance_general_postings'] as $table)if(!Schema::hasTable($table))throw new RuntimeException("Warehouse I08 membutuhkan {$table}.");

        if(!Schema::hasTable('wh_i08_petty_cash'))Schema::create('wh_i08_petty_cash',function(Blueprint $t):void{
            $t->ulid('id')->primary();$t->string('petty_cash_number',80)->unique();$t->foreignUlid('warehouse_id')->constrained('outlets')->restrictOnDelete();$t->date('request_date')->index();$t->string('status',32)->default('DRAFT')->index();$t->string('currency_code',8)->default('IDR');
            $t->decimal('subtotal',22,2)->default(0);$t->decimal('tax_amount',22,2)->default(0);$t->decimal('grand_total',22,2)->default(0);$t->decimal('sku_gross_total',22,2)->default(0);$t->decimal('expense_gross_total',22,2)->default(0);
            $t->string('stock_receipt_status',24)->default('PENDING')->index();$t->string('finance_status',24)->default('NOT_POSTED')->index();$t->text('notes')->nullable();$t->unsignedInteger('lock_version')->default(1);
            $t->foreignUlid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('submitted_at')->nullable();$t->foreignUlid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('approved_at')->nullable();$t->text('approval_notes')->nullable();$t->foreignUlid('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('rejected_at')->nullable();$t->text('rejection_reason')->nullable();
            $t->ulid('stock_ledger_posting_id')->nullable()->index();$t->ulid('expense_finance_posting_id')->nullable()->index();$t->ulid('stock_finance_posting_id')->nullable()->index();$t->foreignUlid('stock_received_by_user_id')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('stock_received_at')->nullable();$t->timestamp('completed_at')->nullable();
            $t->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();$t->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();$t->timestamps();$t->index(['warehouse_id','request_date','status'],'wh_i08_pc_wh_date_status_idx');
        });
        if(!Schema::hasTable('wh_i08_petty_cash_items'))Schema::create('wh_i08_petty_cash_items',function(Blueprint $t):void{
            $t->ulid('id')->primary();$t->foreignUlid('petty_cash_id')->constrained('wh_i08_petty_cash')->cascadeOnDelete();$t->unsignedInteger('line_no');$t->foreignUlid('sku_id')->nullable()->constrained('stk_skus')->restrictOnDelete();$t->string('item_name_snapshot',200);$t->string('expense_category',60)->default('GENERAL')->index();
            $t->foreignUlid('uom_id')->nullable()->constrained('stk_uoms')->restrictOnDelete();$t->string('uom_code_snapshot',30)->nullable();$t->foreignUlid('base_uom_id')->nullable()->constrained('stk_uoms')->restrictOnDelete();$t->string('base_uom_code_snapshot',30)->nullable();$t->decimal('conversion_factor_snapshot',24,8)->default(1);$t->decimal('qty_uom',18,4);$t->decimal('qty_base',18,4);$t->decimal('unit_price',22,2);$t->string('tax_mode',16)->default('NO_TAX');$t->decimal('tax_percent',8,4)->default(0);$t->decimal('subtotal',22,2);$t->decimal('tax_amount',22,2)->default(0);$t->decimal('line_total',22,2);$t->decimal('unit_cost_base_gross',22,6)->default(0);$t->text('notes')->nullable();
            $t->foreignUlid('received_storage_id')->nullable()->constrained('wh_storages')->nullOnDelete();$t->foreignUlid('batch_id')->nullable()->constrained('wh_batches')->nullOnDelete();$t->decimal('received_qty_base',18,4)->default(0);$t->timestamp('received_at')->nullable();$t->json('metadata')->nullable();$t->timestamps();$t->unique(['petty_cash_id','line_no'],'wh_i08_pc_item_line_uq');$t->index(['sku_id','expense_category'],'wh_i08_pc_item_sku_cat_idx');
        });
        if(!Schema::hasTable('wh_i08_petty_cash_events'))Schema::create('wh_i08_petty_cash_events',function(Blueprint $t):void{$t->ulid('id')->primary();$t->foreignUlid('petty_cash_id')->constrained('wh_i08_petty_cash')->cascadeOnDelete();$t->string('event_type',80)->index();$t->json('payload')->nullable();$t->text('notes')->nullable();$t->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();$t->timestamp('occurred_at')->useCurrent()->index();$t->timestamps();});

        foreach(['warehouse.purchasing.petty_cash.view','warehouse.purchasing.petty_cash.create','warehouse.purchasing.petty_cash.update','warehouse.purchasing.petty_cash.delete','warehouse.purchasing.petty_cash.approve','warehouse.purchasing.petty_cash.receive'] as $p)Permission::findOrCreate($p,config('auth.defaults.guard','web'));
        $this->registerMenuFoundation();$this->template('PETTY_CASH_EXPENSE_I08','Petty Cash Expense','PETTY_CASH_EXPENSE','Pengeluaran Petty Cash non-SKU.','expense_total','6100','1010');$this->template('PETTY_CASH_STOCK_I08','Petty Cash Inventory','PETTY_CASH_STOCK','Pembelian persediaan melalui Petty Cash.','inventory_value','1210','1010');app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    public function down():void{}

    private function registerMenuFoundation():void
    {
        if(!Schema::hasTable('access_menus')||!Schema::hasTable('access_portals'))return;$portal=DB::table('access_portals')->where('code','warehouse-operations')->first();if(!$portal)return;$old=DB::table('access_menus')->where('code','warehouse-petty-cash')->first();$now=now();DB::table('access_menus')->updateOrInsert(['code'=>'warehouse-petty-cash'],['id'=>$old->id??(string)Str::ulid(),'portal_id'=>$portal->id,'name'=>'Petty Cash','path'=>'/warehouse/purchasing/petty-cash','sort_order'=>458,'permission_view'=>'warehouse.purchasing.petty_cash.view','permission_create'=>'warehouse.purchasing.petty_cash.create','permission_update'=>'warehouse.purchasing.petty_cash.update','permission_delete'=>'warehouse.purchasing.petty_cash.approve','is_active'=>true,'created_at'=>$old->created_at??$now,'updated_at'=>$now]);
    }
    private function template(string $code,string $name,string $sourceType,string $description,string $amountKey,string $debitCode,string $creditCode):void
    {
        $existing=DB::table('wh_v4_finance_posting_templates')->where('code',$code)->first();$id=$existing?->id ?: (string)Str::ulid();if(!$existing)DB::table('wh_v4_finance_posting_templates')->insert(['id'=>$id,'code'=>$code,'name'=>$name,'source_type'=>$sourceType,'description'=>$description,'is_system'=>true,'is_active'=>true,'created_by_user_id'=>null,'updated_by_user_id'=>null,'created_at'=>now(),'updated_at'=>now()]);if(DB::table('wh_v4_finance_posting_template_lines')->where('template_id',$id)->exists())return;$accounts=DB::table('wh_v4_finance_coa')->whereIn('code',[$debitCode,$creditCode])->pluck('id','code');if(!$accounts->get($debitCode)||!$accounts->get($creditCode))throw new RuntimeException("COA {$debitCode}/{$creditCode} tidak ditemukan untuk {$code}.");foreach([[$debitCode,'DEBIT'],[$creditCode,'CREDIT']] as $i=>[$account,$side])DB::table('wh_v4_finance_posting_template_lines')->insert(['id'=>(string)Str::ulid(),'template_id'=>$id,'sort_order'=>$i+1,'account_id'=>$accounts[$account],'side'=>$side,'amount_key'=>$amountKey,'multiplier'=>1,'memo_template'=>$name.' {{reference_no}}','created_at'=>now(),'updated_at'=>now()]);
    }
};
