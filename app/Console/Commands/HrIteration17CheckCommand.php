<?php

namespace App\Console\Commands;

use App\Http\Middleware\AuthenticateCareerAccount;
use App\Http\Middleware\EnforceCareerTokenBoundary;
use App\Models\HumanResource\HrCareerAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration17CheckCommand extends Command
{
    protected $signature = 'hr:iteration-17-check';
    protected $description = 'Validate HR Iteration 17 Career Portal, Interview, and Hiring Conversion installation.';

    public function handle(): int
    {
        $routes = collect(Route::getRoutes());
        $hasUri = fn (string $method, string $uri): bool => $routes->contains(
            fn ($route) => in_array($method, $route->methods(), true) && $route->uri() === $uri
        );

        $checks = [
            'Career accounts table' => Schema::hasTable('HR_career_accounts'),
            'Career profiles table' => Schema::hasTable('HR_career_profiles'),
            'Career documents table' => Schema::hasTable('HR_career_documents'),
            'Application stage history table' => Schema::hasTable('HR_application_stage_histories'),
            'Interview table' => Schema::hasTable('HR_interviews'),
            'Identity sequence table' => Schema::hasTable('HR_identity_sequences'),
            'Hiring conversion table' => Schema::hasTable('HR_hiring_conversions'),
            'Iteration 16 registration Career FK column' => Schema::hasTable('HR_career_registration_requests') && Schema::hasColumn('HR_career_registration_requests', 'career_account_id'),
            'Iteration 16 application Career FK column' => Schema::hasTable('HR_applications') && Schema::hasColumn('HR_applications', 'career_account_id'),
            'Official NISJ sequence seeded' => Schema::hasTable('HR_identity_sequences') && DB::table('HR_identity_sequences')->where('code', 'OFFICIAL_NISJ')->exists(),
            'Temporary account sequence seeded' => Schema::hasTable('HR_identity_sequences') && DB::table('HR_identity_sequences')->where('code', 'TEMP_ACCOUNT')->exists(),
            'Interview view permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.recruitment.interview.view')->exists(),
            'Interview update permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.recruitment.interview.update')->exists(),
            'Hiring permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.recruitment.hire')->exists(),
            'Career CV permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.recruitment.career_document.view')->exists(),
            'Interview Access Matrix menu' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'hr-recruitment-interview')->where('path', '/human-resource/recruitment/interview')->where('is_active', true)->exists(),
            'Career register route' => $hasUri('POST', 'api/v1/career/register'),
            'Career login route' => $hasUri('POST', 'api/v1/career/login'),
            'Career profile route' => $hasUri('PUT', 'api/v1/career/profile'),
            'Career CV route' => $hasUri('POST', 'api/v1/career/cv'),
            'Career recruitment route' => $hasUri('GET', 'api/v1/career/recruitments'),
            'Career apply route' => $hasUri('POST', 'api/v1/career/recruitments/{id}/apply'),
            'Interview queue route' => $hasUri('GET', 'api/v1/human-resource/recruitment-interviews'),
            'Interview detail route' => $hasUri('GET', 'api/v1/human-resource/recruitments/applications/{id}'),
            'Call interview route' => $hasUri('POST', 'api/v1/human-resource/recruitments/applications/{id}/call-interview'),
            'Record interview route' => $hasUri('POST', 'api/v1/human-resource/recruitments/applications/{id}/interviews'),
            'Hiring conversion route' => $hasUri('POST', 'api/v1/human-resource/recruitments/applications/{id}/hire'),
            'Career model is isolated Authenticatable' => is_subclass_of(HrCareerAccount::class, \Illuminate\Contracts\Auth\Authenticatable::class),
            'Career boundary middleware exists' => class_exists(EnforceCareerTokenBoundary::class),
            'Career auth middleware exists' => class_exists(AuthenticateCareerAccount::class),
        ];

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').' '.$label);
            if (! $ok) $failed++;
        }

        if ($failed) {
            $this->error("HR Iteration 17 check failed: {$failed} issue(s).");
            return self::FAILURE;
        }
        $this->info('HR Iteration 17 check passed.');
        return self::SUCCESS;
    }
}
