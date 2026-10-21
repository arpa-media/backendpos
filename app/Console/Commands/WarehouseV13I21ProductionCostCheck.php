<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WarehouseV13I21ProductionCostCheck extends Command
{
    protected $signature = 'warehouse:v13-i21:check {--warehouse= : Filter by warehouse ULID} {--limit=200 : Row limit (1-1000)}';
    protected $description = 'Read-only: audit provisional production costs, late opname and missing cost state.';

    public function handle(): int
    {
        if (!Schema::hasTable('wh_v13_i21_production_cost_states')) {
            $this->error('Jalankan php artisan migrate terlebih dahulu.');
            return self::FAILURE;
        }
        $warehouse=trim((string)$this->option('warehouse'));
        $limit=min(max((int)$this->option('limit'),1),1000);
        $query=DB::table('wh_productions as p')
            ->leftJoin('wh_v13_i21_production_cost_states as c','c.production_id','=','p.id')
            ->leftJoin('wh_v7_production_material_opnames as o','o.production_id','=','p.id')
            ->where('p.flow_version','>=',7)
            ->select('p.production_number','p.status as production_status','o.status as opname_status',
                'c.status as cost_status','c.posted_output_value','c.delta_value')
            ->orderByDesc('p.created_at')->limit($limit);
        if ($warehouse !== '') $query->where('p.warehouse_id',$warehouse);
        $rows=$query->get();
        $this->table(['Production','Production Status','Opname','Cost','Booked Output','Difference'],
            $rows->map(fn($r)=>[
                $r->production_number,$r->production_status,$r->opname_status??'not_started',
                $r->cost_status??'legacy_unverified',$r->posted_output_value??'-',$r->delta_value??'-',
            ])->all());
        $need=$rows->filter(fn($r)=>in_array((string)($r->cost_status??'legacy_unverified'),
            ['legacy_unverified','provisional','needs_revaluation','pending_output'],true))->count();
        $this->line("Inspected: {$rows->count()} | Needs review: {$need}. No data modified.");
        return $need ? self::FAILURE : self::SUCCESS;
    }
}
