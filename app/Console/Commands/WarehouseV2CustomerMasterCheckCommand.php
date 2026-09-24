<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
class WarehouseV2CustomerMasterCheckCommand extends Command {
 protected $signature='warehouse:v2-customer-master-check'; protected $description='Validate Warehouse Customer master foundation';
 public function handle(): int { $tables=['wh_customer_groups','wh_customer_price_tiers','wh_customers','wh_customer_addresses']; $routes=['warehouse.master.customers.index','warehouse.master.customers.options','warehouse.master.customers.store','warehouse.master.customers.update','warehouse.master.customers.destroy','warehouse.master.customers.import']; $missingTables=array_values(array_filter($tables,fn($t)=>!Schema::hasTable($t))); $missingRoutes=array_values(array_filter($routes,fn($r)=>!Route::has($r))); $this->table(['Check','Result'],[['Missing tables',$missingTables?implode(', ',$missingTables):'-'],['Missing routes',$missingRoutes?implode(', ',$missingRoutes):'-'],['Status',(!$missingTables&&!$missingRoutes)?'PASSED':'FAILED']]); return (!$missingTables&&!$missingRoutes)?self::SUCCESS:self::FAILURE; }
}
