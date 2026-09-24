<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class WarehouseProductionCheckCommand extends Command
{
    protected $signature='warehouse:production-check';
    protected $description='Validate Warehouse Iterasi 09 production planning, checker, material consumption, output barcode, and ledger contracts.';

    public function handle(): int
    {
        $tables=['wh_productions','wh_production_inputs','wh_production_outputs','wh_production_tasks','wh_production_input_allocations','wh_production_output_units'];
        $routes=[
            'warehouse.production.options','warehouse.productions.index','warehouse.productions.store','warehouse.productions.show','warehouse.productions.update',
            'warehouse.productions.submit','warehouse.productions.assign','warehouse.productions.release-materials','warehouse.productions.done','warehouse.productions.approve','warehouse.productions.print-event',
            'warehouse.checker-production-tasks.index','warehouse.checker-production-tasks.show','warehouse.checker-production-tasks.scan',
            'warehouse.checker-production-tasks.allocations.destroy','warehouse.checker-production-tasks.confirm-shortage',
        ];
        $menus=['warehouse-productions','warehouse-checker-production-tasks'];
        $permissions=[
            'warehouse.production.view','warehouse.production.create','warehouse.production.update','warehouse.production.submit','warehouse.production.assign',
            'warehouse.production.release','warehouse.production.done','warehouse.production.approve','warehouse.production.print',
            'warehouse.production.checker.view','warehouse.production.checker.update','warehouse.production.checker.scan','warehouse.production.checker.override',
        ];
        $missingTables=array_values(array_filter($tables,fn($table)=>!Schema::hasTable($table)));
        $missingRoutes=array_values(array_filter($routes,fn($name)=>!Route::has($name)));
        $missingMenus=Schema::hasTable('access_menus')?array_values(array_diff($menus,DB::table('access_menus')->whereIn('code',$menus)->pluck('code')->all())):$menus;
        $missingPermissions=Schema::hasTable('permissions')?array_values(array_diff($permissions,DB::table('permissions')->whereIn('name',$permissions)->pluck('name')->all())):$permissions;

        $metrics=[
            'Production without input/output'=>0,
            'Released production without input ledger'=>0,
            'Pending/completed without output batch'=>0,
            'Completed production without output ledger'=>0,
            'Input task production mismatch'=>0,
            'Released production with incomplete task'=>0,
            'Reserved allocation/unit mismatch'=>0,
            'Consumed allocation/unit mismatch'=>0,
            'Input allocation above planned qty'=>0,
            'Actual material cost mismatch'=>0,
            'Output allocation total mismatch'=>0,
            'Output unit quantity mismatch'=>0,
            'Pre-approval output barcode available'=>0,
            'Completed output barcode not available'=>0,
            'Invalid output price band'=>0,
            'Duplicate production ledger phase'=>0,
        ];

        if($missingTables===[]){
            $metrics['Production without input/output']=(int)DB::table('wh_productions as p')
                ->leftJoinSub(DB::table('wh_production_inputs')->selectRaw('production_id, COUNT(*) c')->groupBy('production_id'),'i','i.production_id','=','p.id')
                ->leftJoinSub(DB::table('wh_production_outputs')->selectRaw('production_id, COUNT(*) c')->groupBy('production_id'),'o','o.production_id','=','p.id')
                ->whereRaw('COALESCE(i.c,0)=0 OR COALESCE(o.c,0)=0')->count();
            $metrics['Released production without input ledger']=(int)DB::table('wh_productions')->whereIn('status',['on_progress','pending_approval','completed'])->whereNull('input_ledger_posting_id')->count();
            $metrics['Pending/completed without output batch']=(int)DB::table('wh_productions as p')->join('wh_production_outputs as o','o.production_id','=','p.id')
                ->whereIn('p.status',['pending_approval','completed'])->where('o.actual_qty_base','>',0)->where(function($q){$q->whereNull('o.batch_id')->orWhereNull('o.storage_id');})->count();
            $metrics['Completed production without output ledger']=(int)DB::table('wh_productions')->where('status','completed')->whereNull('output_ledger_posting_id')->count();
            $metrics['Input task production mismatch']=(int)DB::table('wh_production_tasks as t')->join('wh_production_inputs as i','i.id','=','t.production_input_id')->whereColumn('t.production_id','!=','i.production_id')->count();
            $metrics['Released production with incomplete task']=(int)DB::table('wh_productions as p')->join('wh_production_tasks as t','t.production_id','=','p.id')->whereIn('p.status',['on_progress','pending_approval','completed'])->where('t.status','!=','completed')->count();
            $metrics['Reserved allocation/unit mismatch']=(int)DB::table('wh_production_input_allocations as a')->join('wh_stock_units as u','u.id','=','a.stock_unit_id')->where('a.status','reserved')->where('u.status','!=','reserved')->count();
            $metrics['Consumed allocation/unit mismatch']=(int)DB::table('wh_production_input_allocations as a')->join('wh_stock_units as u','u.id','=','a.stock_unit_id')->where('a.status','consumed')->where('u.status','!=','consumed')->count();
            $metrics['Input allocation above planned qty']=(int)DB::table('wh_production_inputs as i')->leftJoinSub(DB::table('wh_production_input_allocations')->whereIn('status',['reserved','consumed'])->selectRaw('production_input_id, SUM(qty_base) qty')->groupBy('production_input_id'),'a','a.production_input_id','=','i.id')->whereRaw('COALESCE(a.qty,0) - i.planned_qty_base > 0.0001')->count();
            $metrics['Actual material cost mismatch']=(int)DB::table('wh_production_inputs as i')->leftJoinSub(DB::table('wh_production_input_allocations')->where('status','consumed')->selectRaw('production_input_id, SUM(total_cost_snapshot) cost')->groupBy('production_input_id'),'a','a.production_input_id','=','i.id')->join('wh_productions as p','p.id','=','i.production_id')->whereIn('p.status',['on_progress','pending_approval','completed'])->whereRaw('ABS(i.actual_material_cost - COALESCE(a.cost,0)) > 0.01')->count();
            $metrics['Output allocation total mismatch']=(int)DB::table('wh_productions as p')->joinSub(DB::table('wh_production_outputs')->selectRaw('production_id, SUM(CASE WHEN actual_qty_base > 0 THEN cost_allocation_percent ELSE 0 END) pct, SUM(CASE WHEN actual_qty_base > 0 THEN 1 ELSE 0 END) positive_count')->groupBy('production_id'),'o','o.production_id','=','p.id')->whereIn('p.status',['pending_approval','completed'])->where('o.positive_count','>',0)->whereRaw('ABS(o.pct - 100) > 0.01')->count();
            $metrics['Output unit quantity mismatch']=(int)DB::table('wh_production_outputs as o')->leftJoinSub(DB::table('wh_production_output_units')->selectRaw('production_output_id, SUM(qty_base) qty')->groupBy('production_output_id'),'u','u.production_output_id','=','o.id')->where('o.actual_qty_base','>',0)->whereRaw('ABS(o.actual_qty_base - COALESCE(u.qty,0)) > 0.0001')->count();
            $metrics['Pre-approval output barcode available']=(int)DB::table('wh_productions as p')->join('wh_production_outputs as o','o.production_id','=','p.id')->join('wh_production_output_units as pu','pu.production_output_id','=','o.id')->join('wh_stock_units as su','su.id','=','pu.stock_unit_id')->where('p.status','!=','completed')->where('su.status','available')->count();
            $metrics['Completed output barcode not available']=(int)DB::table('wh_productions as p')->join('wh_production_outputs as o','o.production_id','=','p.id')->join('wh_production_output_units as pu','pu.production_output_id','=','o.id')->join('wh_stock_units as su','su.id','=','pu.stock_unit_id')->where('p.status','completed')->where('o.actual_qty_base','>',0)->where('su.status','!=','available')->count();
            $metrics['Invalid output price band']=(int)DB::table('wh_production_outputs')->where('actual_qty_base','>',0)->whereNotIn('selected_price_band',['MIN','MAX'])->count();
            $metrics['Duplicate production ledger phase']=(int)DB::table('wh_ledger_postings')->where('reference_type','wh_production')->whereIn('movement_type',['production_out','production_in'])->select('reference_id','movement_type')->groupBy('reference_id','movement_type')->havingRaw('COUNT(*) > 1')->get()->count();
        }

        $failed=$missingTables!==[]||$missingRoutes!==[]||$missingMenus!==[]||$missingPermissions!==[]||collect($metrics)->contains(fn($value)=>$value>0);
        $rows=[
            ['Missing tables',$missingTables?implode(', ',$missingTables):'-'],
            ['Missing named routes',$missingRoutes?implode(', ',$missingRoutes):'-'],
            ['Missing Access Matrix menus',$missingMenus?implode(', ',$missingMenus):'-'],
            ['Missing permissions',$missingPermissions?implode(', ',$missingPermissions):'-'],
        ];
        foreach($metrics as $name=>$value)$rows[]=[$name,(string)$value];
        $rows[]=['Status',$failed?'FAILED':'PASSED'];
        $this->table(['Check','Result'],$rows);
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
