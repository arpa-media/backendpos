<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseProcurementCheckCommand extends Command
{
    protected $signature='warehouse:procurement-check';
    protected $description='Validate Warehouse Iterasi 08 procurement, private invoice, barcode keeper, and stock-in contracts.';

    public function handle(): int
    {
        $tables=['wh_purchase_requests','wh_purchase_request_items','wh_supplier_purchase_orders','wh_supplier_purchase_order_items','wh_purchase_invoices','wh_stock_ins','wh_stock_in_items','wh_keeper_tasks','wh_stock_in_units'];
        $routes=['warehouse.procurement.options','warehouse.purchase-requests.index','warehouse.purchase-requests.store','warehouse.purchase-requests.show','warehouse.purchase-requests.update','warehouse.purchase-requests.submit','warehouse.purchase-requests.decision','warehouse.supplier-purchase-orders.index','warehouse.supplier-purchase-orders.show','warehouse.supplier-purchase-orders.assign-buyer','warehouse.supplier-purchase-orders.purchase','warehouse.supplier-purchase-orders.approve-purchase','warehouse.supplier-purchase-orders.invoices.store','warehouse.supplier-purchase-orders.invoices.download','warehouse.stock-ins.index','warehouse.stock-ins.show','warehouse.stock-ins.plan','warehouse.stock-ins.print-event','warehouse.stock-ins.approve','warehouse.checker-keeper-tasks.index','warehouse.checker-keeper-tasks.show','warehouse.checker-keeper-tasks.scan'];
        $menus=['warehouse-purchase-requests','warehouse-supplier-purchase-orders','warehouse-stock-ins','warehouse-checker-keeper-tasks'];
        $permissions=['warehouse.procurement.request.view','warehouse.procurement.request.approve','warehouse.procurement.order.view','warehouse.procurement.order.assign','warehouse.procurement.purchase.input','warehouse.procurement.purchase.approve','warehouse.procurement.invoice.upload','warehouse.procurement.invoice.download','warehouse.stock_in.view','warehouse.stock_in.plan','warehouse.stock_in.approve','warehouse.stock_in.keeper.view','warehouse.stock_in.keeper.scan'];
        $missingTables=array_values(array_filter($tables,fn($table)=>!Schema::hasTable($table)));
        $missingRoutes=array_values(array_filter($routes,fn($name)=>!Route::has($name)));
        $missingMenus=Schema::hasTable('access_menus')?array_values(array_diff($menus,DB::table('access_menus')->whereIn('code',$menus)->pluck('code')->all())):$menus;
        $missingPermissions=Schema::hasTable('permissions')?array_values(array_diff($permissions,DB::table('permissions')->whereIn('name',$permissions)->pluck('name')->all())):$permissions;
        $metrics=['Duplicate PR supplier PO'=>0,'PO item supplier mismatch'=>0,'Purchase-approved without Stock In'=>0,'Approved Stock In without ledger'=>0,'Non-approved Stock In with ledger'=>0,'Stock In label count mismatch'=>0,'Stock In unit quantity mismatch'=>0,'Approved PR item without PO line'=>0,'PO actual total mismatch'=>0,'Checker-completed with incomplete task'=>0,'Pre-approval barcode already available'=>0,'Approved barcode not available'=>0,'Invoice path outside private disk'=>0];
        if($missingTables===[]){
            $metrics['Duplicate PR supplier PO']=(int)DB::table('wh_supplier_purchase_orders')->select('purchase_request_id','supplier_source_id')->groupBy('purchase_request_id','supplier_source_id')->havingRaw('COUNT(*) > 1')->get()->count();
            $metrics['PO item supplier mismatch']=(int)DB::table('wh_supplier_purchase_order_items as poi')->join('wh_supplier_purchase_orders as po','po.id','=','poi.purchase_order_id')->join('wh_purchase_request_items as pri','pri.id','=','poi.purchase_request_item_id')->whereColumn('pri.supplier_source_id','!=','po.supplier_source_id')->count();
            $metrics['Purchase-approved without Stock In']=(int)DB::table('wh_supplier_purchase_orders as po')->leftJoin('wh_stock_ins as si','si.purchase_order_id','=','po.id')->whereIn('po.status',['purchase_approved','stock_in_prepare','stock_in_progress','stock_in_completed','stocked_in'])->whereNull('si.id')->count();
            $metrics['Approved Stock In without ledger']=(int)DB::table('wh_stock_ins')->where('status','approved')->whereNull('ledger_posting_id')->count();
            $metrics['Non-approved Stock In with ledger']=(int)DB::table('wh_stock_ins')->where('status','!=','approved')->whereNotNull('ledger_posting_id')->count();
            $metrics['Stock In label count mismatch']=(int)DB::table('wh_stock_in_items as i')->leftJoinSub(DB::table('wh_stock_in_units')->selectRaw('stock_in_item_id, COUNT(*) unit_count, SUM(CASE WHEN status IN (\'stored\',\'available\') THEN 1 ELSE 0 END) stored_count')->groupBy('stock_in_item_id'),'u','u.stock_in_item_id','=','i.id')->whereRaw('i.label_count <> COALESCE(u.unit_count,0) OR i.stored_label_count <> COALESCE(u.stored_count,0)')->count();
            $metrics['Stock In unit quantity mismatch']=(int)DB::table('wh_stock_in_items as i')->leftJoinSub(DB::table('wh_stock_in_units')->selectRaw('stock_in_item_id, SUM(qty_base) unit_qty')->groupBy('stock_in_item_id'),'u','u.stock_in_item_id','=','i.id')->where('i.label_count','>',0)->whereRaw('ABS(i.accepted_qty_base - COALESCE(u.unit_qty,0)) > 0.0001')->count();
            $metrics['Approved PR item without PO line']=(int)DB::table('wh_purchase_request_items as pri')->leftJoin('wh_supplier_purchase_order_items as poi','poi.purchase_request_item_id','=','pri.id')->where('pri.approval_status','approved')->where('pri.approved_qty_base','>',0)->whereNull('poi.id')->count();
            $metrics['PO actual total mismatch']=(int)DB::table('wh_supplier_purchase_orders as po')->leftJoinSub(DB::table('wh_supplier_purchase_order_items')->selectRaw('purchase_order_id, SUM(actual_line_total) item_total')->groupBy('purchase_order_id'),'tot','tot.purchase_order_id','=','po.id')->whereIn('po.status',['purchased','purchase_approved','stock_in_prepare','stock_in_progress','stock_in_completed','stocked_in'])->whereRaw('ABS(po.actual_total - COALESCE(tot.item_total,0)) > 0.01')->count();
            $metrics['Checker-completed with incomplete task']=(int)DB::table('wh_stock_ins as si')->join('wh_keeper_tasks as t','t.stock_in_id','=','si.id')->whereIn('si.status',['checker_completed','approved'])->where('t.status','!=','completed')->count();
            $metrics['Pre-approval barcode already available']=(int)DB::table('wh_stock_ins as si')->join('wh_stock_in_units as u','u.stock_in_id','=','si.id')->join('wh_stock_units as su','su.id','=','u.stock_unit_id')->where('si.status','!=','approved')->where('su.status','available')->count();
            $metrics['Approved barcode not available']=(int)DB::table('wh_stock_ins as si')->join('wh_stock_in_units as u','u.stock_in_id','=','si.id')->join('wh_stock_units as su','su.id','=','u.stock_unit_id')->where('si.status','approved')->where('su.status','!=','available')->count();
            $metrics['Invoice path outside private disk']=(int)DB::table('wh_purchase_invoices')->where(function($q){$q->where('storage_disk','!=','local')->orWhere('storage_path','like','%../%')->orWhere('storage_path','like','public/%');})->count();
        }
        $failed=$missingTables!==[]||$missingRoutes!==[]||$missingMenus!==[]||$missingPermissions!==[]||collect($metrics)->contains(fn($value)=>$value>0);
        $rows=[['Missing tables',$missingTables?implode(', ',$missingTables):'-'],['Missing named routes',$missingRoutes?implode(', ',$missingRoutes):'-'],['Missing Access Matrix menus',$missingMenus?implode(', ',$missingMenus):'-'],['Missing permissions',$missingPermissions?implode(', ',$missingPermissions):'-']];foreach($metrics as $name=>$value)$rows[]=[$name,(string)$value];$rows[]=['Status',$failed?'FAILED':'PASSED'];
        $this->table(['Check','Result'],$rows);return $failed?self::FAILURE:self::SUCCESS;
    }
}
