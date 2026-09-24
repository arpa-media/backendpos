<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EnsureRecruitmentPresenceI08
{
    public function handle(Request $request, Closure $next, string $flow): Response
    {
        $applicationId = (string) $request->route('id');
        if ($applicationId === '') {
            throw ValidationException::withMessages(['application' => ['Application recruitment tidak ditemukan.']]);
        }

        $application = DB::table('HR_applications')->where('id', $applicationId)->first(['id', 'career_account_id']);
        if (! $application) abort(404, 'Lamaran tidak ditemukan.');
        if (! $application->career_account_id) {
            throw ValidationException::withMessages(['presence' => ['Applicant belum memiliki Career Account sehingga presensi self-service belum dapat diverifikasi.']]);
        }

        $schedule = DB::table('HR_recruitment_flow_schedules')
            ->where('application_id', $applicationId)
            ->where('flow_type', $flow)
            ->where('status', 'scheduled')
            ->orderByDesc('sequence_no')
            ->first(['id']);
        if (! $schedule) {
            throw ValidationException::withMessages(['presence' => ['Jadwal aktif untuk presensi '.$this->label($flow).' tidak ditemukan.']]);
        }

        $present = DB::table('HR_recruitment_presence_events')
            ->where('schedule_id', $schedule->id)
            ->where('career_account_id', $application->career_account_id)
            ->exists();
        if (! $present) {
            throw ValidationException::withMessages(['presence' => ['Applicant wajib mengisi presensi '.$this->label($flow).' sebelum HR menyimpan hasil/menyelesaikan tahap ini.']]);
        }

        return $next($request);
    }

    private function label(string $flow): string
    {
        return match ($flow) {
            'interview' => 'Interview',
            'practical' => 'Practical Test',
            'onboarding_contract' => 'On Boarding / TTD Kontrak',
            default => 'Recruitment',
        };
    }
}
