<?php
namespace App\Models\Purchasing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
class ReconciliationIssue extends Model
{
    use HasUlids;
    protected $table = 'pur_reconciliation_issues';
    protected $guarded = [];
    protected $casts = ['metadata' => 'array', 'resolved_at' => 'datetime'];
}
