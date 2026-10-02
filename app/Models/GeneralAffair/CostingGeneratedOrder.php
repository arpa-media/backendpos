<?php

namespace App\Models\GeneralAffair;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CostingGeneratedOrder extends Model
{
    use HasUlids;
    protected $table = 'ga_costing_generated_orders';
    protected $guarded = [];
    protected function casts(): array { return ['amount' => 'decimal:2', 'handed_off_at' => 'datetime']; }
    public function request() { return $this->belongsTo(CostingRequest::class, 'costing_request_id'); }
}
