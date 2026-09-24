<?php
namespace App\Models\Purchasing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
class ReconciliationSnapshot extends Model
{
    use HasUlids;
    protected $table = 'pur_reconciliation_snapshots';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array'];
}
