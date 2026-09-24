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
    private const MODULES = [
        ['kind'=>'GOODS_RECEIPT','table'=>'pur_goods_receipts','items'=>'pur_goods_receipt_items','number'=>'gr_number','prefix'=>'purchasing.goods_receipt','code'=>'purchasing-goods-receipts','name'=>'Goods Receipt','path'=>'/purchasing/goods-receipts','sort'=>60],
        ['kind'=>'SERVICE_ACCEPTANCE','table'=>'pur_service_acceptances','items'=>'pur_service_acceptance_items','number'=>'acceptance_number','prefix'=>'purchasing.service_acceptance','code'=>'purchasing-service-acceptances','name'=>'Service Acceptance','path'=>'/purchasing/service-acceptances','sort'=>70],
        ['kind'=>'REIMBURSE_PAYMENT','table'=>'pur_reimburse_payments','items'=>'pur_reimburse_payment_items','number'=>'payment_number','prefix'=>'purchasing.reimburse_payment','code'=>'purchasing-reimburse-payments','name'=>'Reimburse Payment','path'=>'/purchasing/reimburse-payments','sort'=>80],
    ];

    public function up(): void
    {
        foreach (self::MODULES as $module) $this->createModule($module);
        $this->createDecisions();
        $this->extendOrders();
        $this->permissionsAndMenus();
        $this->reorderStockInventoryMenus();
    }

    private function createModule(array $m): void
    {
        if (!Schema::hasTable($m['table'])) {
            Schema::create($m['table'], function(Blueprint $t) use($m): void {
                $t->ulid('id')->primary();
                $t->string($m['number'],60)->unique();
                $t->string('order_kind',40)->index();
                $t->string('order_id',64)->index();
                $t->foreignUlid('fund_request_id')->nullable()->constrained('pur_fund_requests')->nullOnDelete();
                $t->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $t->string('chamber_code',40)->nullable()->index();
                $t->date('document_date');
                $t->string('status',40)->default('DRAFT')->index();
                $t->string('currency',3)->default('IDR');
                $t->decimal('subtotal',20,2)->default(0); $t->decimal('tax_amount',20,2)->default(0); $t->decimal('total_amount',20,2)->default(0);
                $t->string('external_reference',120)->nullable(); $t->text('notes')->nullable();
                $t->unsignedInteger('lock_version')->default(1);
                $t->string('idempotency_key',120)->nullable();
                $t->foreignUlid('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('posted_at')->nullable();
                $t->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps(); $t->softDeletes();
                $t->unique(['order_kind','order_id'], 'pur_'.substr($m['kind'],0,18).'_order_uq');
                $t->unique('idempotency_key', 'pur_'.substr($m['kind'],0,18).'_idem_uq');
            });
        }
        if (!Schema::hasTable($m['items'])) {
            Schema::create($m['items'], function(Blueprint $t) use($m): void {
                $t->ulid('id')->primary(); $t->foreignUlid('document_id')->constrained($m['table'])->cascadeOnDelete();
                $t->string('order_item_id',64)->nullable()->index(); $t->unsignedSmallInteger('line_no');
                $t->foreignUlid('sku_id')->nullable()->constrained('stk_skus')->nullOnDelete();
                $t->string('item_name',255); $t->string('uom_text',50)->nullable();
                $t->decimal('ordered_qty',18,4)->default(0); $t->decimal('executed_qty',18,4)->default(0);
                $t->decimal('unit_price',18,2)->default(0); $t->decimal('tax_amount',20,2)->default(0); $t->decimal('line_total',20,2)->default(0);
                $t->text('notes')->nullable(); $t->json('metadata')->nullable(); $t->timestamps();
                $t->unique(['document_id','line_no'], 'pur_'.substr($m['kind'],0,18).'_line_uq');
            });
        }
    }

    private function createDecisions(): void
    {
        if (Schema::hasTable('pur_execution_decisions')) return;
        Schema::create('pur_execution_decisions', function(Blueprint $t): void {
            $t->ulid('id')->primary(); $t->string('document_kind',40); $t->string('document_id',64); $t->string('action',30);
            $t->string('previous_status',40)->nullable(); $t->string('new_status',40)->nullable(); $t->text('notes')->nullable();
            $t->string('idempotency_key',120); $t->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('occurred_at'); $t->json('metadata')->nullable(); $t->timestamps();
            $t->unique(['document_kind','document_id','idempotency_key'],'pur_execution_decision_idem_uq');
            $t->index(['document_kind','document_id','occurred_at'],'pur_execution_timeline_idx');
        });
    }

    private function extendOrders(): void
    {
        foreach (['pur_purchase_orders','pur_service_orders','pur_reimburse_orders'] as $table) {
            if (!Schema::hasTable($table)) continue;
            Schema::table($table, function(Blueprint $t) use($table): void {
                if (!Schema::hasColumn($table,'executed_qty')) $t->decimal('executed_qty',18,4)->default(0);
                if (!Schema::hasColumn($table,'execution_status')) $t->string('execution_status',40)->nullable()->index();
                if (!Schema::hasColumn($table,'executed_at')) $t->timestamp('executed_at')->nullable();
            });
        }
    }

    private function permissionsAndMenus(): void
    {
        $guard=(string)config('auth.defaults.guard','web');
        foreach(self::MODULES as $m) foreach(['view','create','update','delete','post'] as $a) Permission::findOrCreate($m['prefix'].'.'.$a,$guard);
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) return;
        $portal=DB::table('access_portals')->where('code','purchasing')->first(); if(!$portal) return;
        foreach(self::MODULES as $m) {
            $old=DB::table('access_menus')->where('code',$m['code'])->first();
            DB::table('access_menus')->updateOrInsert(['code'=>$m['code']], [
                'id'=>(string)($old->id??Str::ulid()),'portal_id'=>$portal->id,'name'=>$m['name'],'path'=>$m['path'],'sort_order'=>$m['sort'],
                'permission_view'=>$m['prefix'].'.view','permission_create'=>$m['prefix'].'.create','permission_update'=>$m['prefix'].'.update','permission_delete'=>$m['prefix'].'.delete',
                'is_active'=>true,'created_at'=>$old->created_at??now(),'updated_at'=>now(),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function reorderStockInventoryMenus(): void
    {
        if (!Schema::hasTable('access_menus')) return;
        DB::table('access_menus')->where('code','inventory-receive-stock')->update(['is_active'=>false,'updated_at'=>now()]);
        DB::table('access_menus')->where('code','inventory-receiving-stock')->update(['name'=>'Receiving Stock','sort_order'=>105,'is_active'=>true,'updated_at'=>now()]);
        DB::table('access_menus')->where('code','inventory-cancellation-approval')->update(['sort_order'=>110,'updated_at'=>now()]);
    }

    public function down(): void { /* non-destructive by design */ }
};
