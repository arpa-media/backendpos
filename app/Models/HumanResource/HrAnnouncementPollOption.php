<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrAnnouncementPollOption extends Model
{
    use HasUlids;
    protected $table = 'HR_announcement_poll_options';
    protected $guarded = [];
    public function poll() { return $this->belongsTo(HrAnnouncementPoll::class, 'poll_id'); }
}
