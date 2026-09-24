<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseUatCaseResult extends Model
{
    use HasUlids;
    protected $table = 'wh_uat_case_results';
    protected $guarded = [];
    protected function casts(): array { return ['evidence'=>'array']; }
    public function run(){ return $this->belongsTo(WarehouseUatRun::class,'uat_run_id'); }
}
