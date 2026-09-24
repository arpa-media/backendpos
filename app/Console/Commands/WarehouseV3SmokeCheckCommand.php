<?php

namespace App\Console\Commands;

use App\Services\Warehouse\FinalV3\WarehouseV3FinalService;
use Illuminate\Console\Command;

class WarehouseV3SmokeCheckCommand extends Command
{
    protected $signature = 'warehouse-v3:smoke-check {--warehouse= : Warehouse outlet ULID} {--json : Output JSON}';
    protected $description = 'Final Warehouse v3 go-live smoke check: schema, ledger, reconciliation, navigation, and cross-module integrity.';

    public function handle(WarehouseV3FinalService $service): int
    {
        $result=$service->smoke($this->option('warehouse') ?: null);
        if($this->option('json')){$this->line(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return $result['status']==='passed'?self::SUCCESS:self::FAILURE;}
        if (empty($result['warehouses'])) { $this->error($result['message'] ?? 'Tidak ada Warehouse yang dapat diperiksa.'); return self::FAILURE; }
        foreach($result['warehouses'] as $warehouse){
            $this->newLine();$this->info(($warehouse['warehouse']['code']??'-').' - '.($warehouse['warehouse']['name']??'Warehouse').' : '.strtoupper($warehouse['status']));
            $rows=[];foreach($warehouse['checks'] as $check){$rows[]=[strtoupper($check['status']),strtoupper($check['severity']),$check['label'],$check['message']??($check['failure_count'].' failure')];}
            $this->table(['Status','Severity','Check','Result'],$rows);
        }
        $this->newLine();$result['status']==='passed'?$this->info('Warehouse v3 GO-LIVE GATE: PASS'):$this->error('Warehouse v3 GO-LIVE GATE: FAILED');
        return $result['status']==='passed'?self::SUCCESS:self::FAILURE;
    }
}
