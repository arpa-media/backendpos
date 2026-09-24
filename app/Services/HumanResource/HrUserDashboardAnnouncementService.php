<?php

namespace App\Services\HumanResource;

use App\Models\User;

/** Iteration 10 dashboard decorator; extends payroll decorator and replaces dummy announcements. */
class HrUserDashboardAnnouncementService extends HrUserDashboardPayrollService
{
    public function dashboard(User $user): array
    {
        $data = parent::dashboard($user);
        $announcements = app(HrAnnouncementAudienceService::class)->forUser($user, 20);
        $data['announcements'] = $announcements;
        $data['summary']['announcement_count'] = count($announcements);
        $data['meta']['contract'] = 'hr-user-dashboard-v1+schedule-i03+leave-i07+payroll-i08+announcement-i10';
        return $data;
    }
}
