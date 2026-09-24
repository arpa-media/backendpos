<?php

namespace App\Models\Purchasing;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DocumentAttachment extends Model
{
    use HasUlids;

    protected $table = 'pur_document_attachments';

    protected $fillable = [
        'document_type', 'document_id', 'attachment_type', 'original_name', 'stored_name',
        'mime_type', 'file_size', 'original_file_size', 'compression_status', 'sha256',
        'disk', 'path', 'sort_order', 'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'original_file_size' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
