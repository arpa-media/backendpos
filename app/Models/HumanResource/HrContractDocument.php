<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrContractDocument extends Model
{
    use HasUlids, SoftDeletes;
    protected $table = 'HR_contract_documents';
    protected $guarded = [];
    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'effective_date' => 'date', 'payload_snapshot' => 'array', 'branding_snapshot' => 'array',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'effect_applied_at' => 'datetime',
        ];
    }
    public function contract() { return $this->belongsTo(HrContract::class, 'contract_id'); }
    public function approvals() { return $this->hasMany(HrContractApproval::class, 'document_id'); }
    public function template() { return $this->belongsTo(HrContractDocumentTemplate::class, 'template_id'); }
}
