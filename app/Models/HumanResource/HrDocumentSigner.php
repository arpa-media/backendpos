<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrDocumentSigner extends Model
{
    use HasUlids;
    protected $table = 'HR_document_signers';
    protected $guarded = [];
    protected function casts(): array { return ['is_active' => 'boolean']; }
}
