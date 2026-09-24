<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpRevHrWarehouseIteration09CheckCommand extends Command
{
    protected $signature = 'erp-rev:hr-warehouse-iteration-09-check';
    protected $description = 'Verify ERP REV Iteration 09 Warehouse Return Request stock/spoil/finance flow.';

    public function handle(): int
    {
        $checks = [
            'Return Request header table exists' => Schema::hasTable('wh_v9_return_requests'),
            'Return Request item table exists' => Schema::hasTable('wh_v9_return_request_items'),
            'Return Request event table exists' => Schema::hasTable('wh_v9_return_request_events'),
            'Iteration 08 CoA mapping table exists' => Schema::hasTable('wh_v8_finance_coa_mappings'),
            'Iteration 08 posting bridge table exists' => Schema::hasTable('wh_v8_finance_posting_bridges'),

            'Return options route exists' => Route::has('warehouse.return-v9.options.i09'),
            'Return index route exists' => Route::has('warehouse.return-v9.index.i09'),
            'Return store route exists' => Route::has('warehouse.return-v9.store.i09'),
            'Return submit route exists' => Route::has('warehouse.return-v9.submit.i09'),
            'Return approve route exists' => Route::has('warehouse.return-v9.approve.i09'),
            'Return execute route exists' => Route::has('warehouse.return-v9.execute.i09'),
            'Return cancel route exists' => Route::has('warehouse.return-v9.cancel.i09'),

            'Return Request service available' => class_exists(\App\Services\Warehouse\ReturnRequestV9\WarehouseReturnRequestV9Service::class),
            'Warehouse return_in movement exists' => in_array('return_in', \App\Services\Warehouse\WarehouseLedgerService::ALL_TYPES, true),
        ];

        if (Schema::hasTable('permissions')) {
            foreach ([
                'warehouse.procurement.return_request.view',
                'warehouse.procurement.return_request.create',
                'warehouse.procurement.return_request.update',
                'warehouse.procurement.return_request.delete',
                'warehouse.procurement.return_request.approve',
                'warehouse.procurement.return_request.execute',
                'warehouse.procurement.return_request.cancel',
            ] as $permission) {
                $checks["Permission {$permission} exists"] = DB::table('permissions')->where('name',$permission)->exists();
            }
        }

        if (Schema::hasTable('access_menus')) {
            $menu=DB::table('access_menus')->where('path','/warehouse/purchasing/return-requests')->first();
            $checks['Return Request Access Matrix canonical'] = $menu
                && (! Schema::hasColumn('access_menus','permission_view') || $menu->permission_view === 'warehouse.procurement.return_request.view')
                && (! Schema::hasColumn('access_menus','permission_create') || $menu->permission_create === 'warehouse.procurement.return_request.create')
                && (! Schema::hasColumn('access_menus','permission_update') || $menu->permission_update === 'warehouse.procurement.return_request.update')
                && (! Schema::hasColumn('access_menus','permission_delete') || $menu->permission_delete === 'warehouse.procurement.return_request.delete');
        }

        if (Schema::hasTable('wh_v4_finance_posting_templates')) {
            $template=DB::table('wh_v4_finance_posting_templates')->where('code','RETURN_SPOIL_LOSS')->where('is_active',true)->first();
            $checks['Finance template RETURN_SPOIL_LOSS exists'] = (bool)$template;
            $checks['Finance spoil template has debit+credit'] = $template
                && DB::table('wh_v4_finance_posting_template_lines')->where('template_id',$template->id)->where('side','DEBIT')->exists()
                && DB::table('wh_v4_finance_posting_template_lines')->where('template_id',$template->id)->where('side','CREDIT')->exists();
        }

        if (Schema::hasTable('wh_v8_finance_coa_mappings')) {
            $spoilAccountIds=DB::table('wh_v4_finance_coa')->whereIn('code',['5300','1210'])->pluck('id');
            $checks['SPOIL COA 5300/1210 mapped to canonical Finance'] = $spoilAccountIds->count() === 2
                && DB::table('wh_v8_finance_coa_mappings')->whereIn('warehouse_coa_id',$spoilAccountIds)->distinct()->count('warehouse_coa_id') === 2;
        }

        $frontendRoot=base_path('../frontend - Backoffice/src/modules/warehouse');
        $page=@file_get_contents($frontendRoot.'/pages/WarehouseReturnRequestV9Page.vue') ?: '';
        $api=@file_get_contents($frontendRoot.'/lib/warehouseReturnRequestV9Api.js') ?: '';
        $route=@file_get_contents($frontendRoot.'/route-modules/98-return-request-v9.js') ?: '';
        $financeEngine=@file_get_contents(app_path('Services/Warehouse/FinanceV4/WarehouseGeneralPostingEngine.php')) ?: '';
        $service=@file_get_contents(app_path('Services/Warehouse/ReturnRequestV9/WarehouseReturnRequestV9Service.php')) ?: '';

        $checks['Frontend Return Request replaces placeholder additively'] =
            str_contains($route,"name: 'warehouse-v3-purchasing-return-requests'")
            && str_contains($route,"warehouseV3Menus.find")
            && str_contains($route,"menu.placeholder = false");

        $checks['Iteration 08 canonical bridge remains connected to General Posting'] =
            str_contains($financeEngine,'canonicalBridge->syncPosting');

        $checks['Frontend exposes stock vs spoil outcome'] =
            str_contains($page,'Kembali ke Stock')
            && str_contains($page,'Spoil')
            && str_contains($page,'Tidak masuk stock');

        $checks['Frontend API connects execute/cancel'] =
            str_contains($api,'/execute')
            && str_contains($api,'/cancel');

        $checks['SPOIL does not create Warehouse ledger line'] =
            str_contains($service,"if ((string)\$item->outcome==='RETURN_TO_STOCK')")
            && str_contains($service,"\$spoilValue+=")
            && ! str_contains($service,"'movement_type'=>'damage_out'");

        $checks['RETURN_TO_STOCK uses return_in ledger'] =
            str_contains($service,"'movement_type'=>'return_in'")
            && str_contains($service,"'outcome'=>'RETURN_TO_STOCK'");

        $checks['SPOIL posts Finance template'] =
            str_contains($service,"'RETURN_SPOIL_LOSS'")
            && str_contains($service,"'stock_increased'=>false");

        $checks['Executed document cancel guard exists'] =
            str_contains($service,"Return Request sudah posted dan tidak dapat dicancel");

        if (Schema::hasTable('wh_v9_return_requests') && Schema::hasTable('wh_v9_return_request_items')) {
            $checks['Executed SPOIL has Finance posting'] =
                ! DB::table('wh_v9_return_requests')
                    ->where('status','executed')->where('spoil_qty_base','>',0)
                    ->whereNull('finance_spoil_posting_id')->exists();

            $checks['Executed RETURN_TO_STOCK has stock posting'] =
                ! DB::table('wh_v9_return_requests')
                    ->where('status','executed')->where('return_to_stock_qty_base','>',0)
                    ->whereNull('stock_ledger_posting_id')->exists();

            $checks['SPOIL items never have batch'] =
                ! DB::table('wh_v9_return_request_items')
                    ->where('outcome','SPOIL')->whereNotNull('batch_id')->exists();

            $checks['Executed Return Stock items have batch'] =
                ! DB::table('wh_v9_return_request_items as i')
                    ->join('wh_v9_return_requests as r','r.id','=','i.return_request_id')
                    ->where('r.status','executed')->where('i.outcome','RETURN_TO_STOCK')
                    ->whereNull('i.batch_id')->exists();


            if (Schema::hasTable('wh_v8_finance_posting_bridges')) {
                $checks['Executed SPOIL has Iteration 08 canonical bridge row'] =
                    ! DB::table('wh_v9_return_requests as r')
                        ->leftJoin('wh_v8_finance_posting_bridges as b','b.warehouse_general_posting_id','=','r.finance_spoil_posting_id')
                        ->where('r.status','executed')->where('r.spoil_qty_base','>',0)
                        ->whereNull('b.id')->exists();

                $checks['Executed SPOIL canonical bridge is not FAILED'] =
                    ! DB::table('wh_v9_return_requests as r')
                        ->join('wh_v8_finance_posting_bridges as b','b.warehouse_general_posting_id','=','r.finance_spoil_posting_id')
                        ->where('r.status','executed')->where('r.spoil_qty_base','>',0)
                        ->where('b.status','FAILED')->exists();
            }
        }

        $failed=false;
        foreach ($checks as $label=>$ok) {
            $ok=(bool)$ok;
            $this->line(sprintf('[%s] %s',$ok?'OK':'FAIL',$label));
            if (! $ok) $failed=true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
