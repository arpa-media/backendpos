<?php

namespace App\Models\Purchasing;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DocumentEvent extends Model
{
    use HasUlids;

    protected $table = 'pur_document_events';

    protected $fillable = [
        'root_request_id',
        'document_type',
        'document_id',
        'event_code',
        'event_label',
        'status',
        'actor_user_id',
        'actor_name_snapshot',
        'occurred_at',
        'notes',
        'reference_type',
        'reference_id',
        'reference_number',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function rootRequest()
    {
        return $this->belongsTo(FundRequest::class, 'root_request_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
