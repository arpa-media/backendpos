<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrContractDocumentTemplate extends Model
{
    use HasUlids, SoftDeletes;
    protected $table = 'HR_contract_document_templates';
    protected $guarded = [];
    protected function casts(): array { return ['version' => 'integer', 'is_active' => 'boolean']; }
}
