<?php

namespace App\Models\Cogs;

use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CogsCalculationItem extends Model
{
    use HasUlids;

    protected $table = 'cogs_calculation_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'warning_codes' => 'array',
            'trace_snapshot' => 'array',
        ];
    }

    public function run()
    {
        return $this->belongsTo(CogsCalculationRun::class, 'calculation_run_id');
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id')->withTrashed();
    }

    public function baseUom()
    {
        return $this->belongsTo(StockUom::class, 'base_uom_id')->withTrashed();
    }
}
