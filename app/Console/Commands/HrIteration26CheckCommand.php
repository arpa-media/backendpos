<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration26CheckCommand extends Command
{
    protected $signature = 'hr:iteration-26-check';
    protected $description = 'Validate HR Iteration 26 recruitment and Career recovery flow.';

    public function handle(): int
    {
        $routes = collect(Route::getRoutes())->map(fn ($r) => [
            'methods' => $r->methods(),
            'uri' => $r->uri(),
        ]);
        $hasRoute = fn (string $method, string $uri): bool => $routes->contains(
            fn ($r) => in_array($method, $r['methods'], true) && $r['uri'] === $uri
        );

        $recoverySource = is_file(app_path('Services/HumanResource/HrCareerRecoveryService.php'))
            ? file_get_contents(app_path('Services/HumanResource/HrCareerRecoveryService.php')) : '';
        $accountSource = is_file(app_path('Services/HumanResource/HrCareerAccountService.php'))
            ? file_get_contents(app_path('Services/HumanResource/HrCareerAccountService.php')) : '';

        $checks = [
            'Career password reset audit table' => Schema::hasTable('HR_career_password_reset_requests'),
            'Reset request account FK column' => Schema::hasTable('HR_career_password_reset_requests')
                && Schema::hasColumn('HR_career_password_reset_requests', 'career_account_id'),
            'Reset request applied_at column' => Schema::hasTable('HR_career_password_reset_requests')
                && Schema::hasColumn('HR_career_password_reset_requests', 'applied_at'),
            'Bulk approve permission' => Schema::hasTable('permissions')
                && DB::table('permissions')->where('name', 'hr.recruitment.registration.bulk_approve')->exists(),
            'Password reset approve permission' => Schema::hasTable('permissions')
                && DB::table('permissions')->where('name', 'hr.recruitment.password_reset.approve')->exists(),
            'Recruitment Access Matrix remains active' => Schema::hasTable('access_menus')
                && DB::table('access_menus')->where('code', 'hr-recruitment')->where('is_active', true)->exists(),
            'Public reset password request route' => $hasRoute('POST', 'api/v1/career/password-reset-request'),
            'Admin reset list route' => $hasRoute('GET', 'api/v1/human-resource/career-access/password-reset-requests'),
            'Admin reset review route' => $hasRoute('POST', 'api/v1/human-resource/career-access/password-reset-requests/{id}/review'),
            'Admin bulk approve route' => $hasRoute('POST', 'api/v1/human-resource/career-access/bulk-approve'),
            'Squad NIK guard enabled' => str_contains($accountSource, '$this->assertNikNotSquad($nik)')
                && str_contains($recoverySource, 'NIK sudah terdaftar sebagai Squad'),
            'Reset revokes old Career tokens' => str_contains($recoverySource, '$account->tokens()->delete()'),
            'Bulk preflight is transactional' => str_contains($recoverySource, 'public function bulkApprove')
                && str_contains($recoverySource, 'DB::transaction(function () use ($requests, $actor)'),
        ];

        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%s %s', $ok ? '[OK]' : '[FAIL]', $label));
        }

        $invalidApproved = Schema::hasTable('HR_career_password_reset_requests')
            ? DB::table('HR_career_password_reset_requests')->where('status', 'approved')->whereNull('applied_at')->count()
            : 0;
        $this->line(($invalidApproved === 0 ? '[OK]' : '[FAIL]')." Approved reset has applied_at (invalid={$invalidApproved})");

        return in_array(false, $checks, true) || $invalidApproved > 0 ? self::FAILURE : self::SUCCESS;
    }
}
