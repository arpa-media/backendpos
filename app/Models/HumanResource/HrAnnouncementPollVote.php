<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrAnnouncementPollVote extends Model
{
    use HasUlids;
    protected $table = 'HR_announcement_poll_votes';
    protected $guarded = [];
    protected function casts(): array { return ['voted_at' => 'datetime']; }
    public function poll() { return $this->belongsTo(HrAnnouncementPoll::class, 'poll_id'); }
    public function option() { return $this->belongsTo(HrAnnouncementPollOption::class, 'option_id'); }
}
