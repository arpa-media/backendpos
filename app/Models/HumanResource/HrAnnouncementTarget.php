<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrAnnouncementTarget extends Model
{
    use HasUlids;
    protected $table = 'HR_announcement_targets';
    protected $guarded = [];
    public function announcement() { return $this->belongsTo(HrAnnouncement::class, 'announcement_id'); }
}
