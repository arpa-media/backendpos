<?php
namespace App\Models\Purchasing;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
class LegacyDocumentLink extends Model
{
    use HasUlids;
    protected $table = 'pur_legacy_document_links';
    protected $guarded = [];
    protected $casts = ['is_primary' => 'boolean', 'confidence' => 'decimal:4', 'metadata' => 'array', 'linked_at' => 'datetime'];
}
