<?php

namespace App\Services\HumanResource;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class HrRecruitmentMasterResetI15Service
{
    private const CONFIRMATION = 'RESET RECRUITMENT';

    /** @var array<string,string> */
    private const CHILD_TABLES = [
        'presence_events' => 'HR_recruitment_presence_events',
        'presence_windows' => 'HR_recruitment_presence_windows',
        'workflow_events' => 'HR_recruitment_workflow_events',
        'practical_tests' => 'HR_recruitment_practical_tests',
        'interviews' => 'HR_interviews',
        'stage_histories' => 'HR_application_stage_histories',
        'flow_schedules' => 'HR_recruitment_flow_schedules',
        'hiring_conversions' => 'HR_hiring_conversions',
    ];

    public function __construct(private readonly HrAttendanceBackofficeScopeService $scope) {}

    public function preview(Request $request): array
    {
        $context = $this->context($request);
        $counts = $this->counts($context['application_ids']);

        $lastReset = Schema::hasTable('HR_recruitment_reset_audits')
            ? DB::table('HR_recruitment_reset_audits')->latest('reset_at')->first(['id', 'reset_at', 'actor_user_id', 'deleted_counts'])
            : null;

        return [
            'confirmation_phrase' => self::CONFIRMATION,
            'scope' => [
                'mode' => 'allowed_outlets',
                'outlet_count' => count($context['allowed_outlet_ids']),
                'outlets' => $context['outlets'],
                'note' => 'Reset hanya menghapus history Recruitment pada outlet yang berada dalam scope Access Matrix user saat ini.',
            ],
            'range' => $context['range'],
            'counts' => $counts,
            'total_deletable' => array_sum($counts),
            'preserved' => $this->preservedCounts($context['application_ids'], $context['registration_request_ids']),
            'preserved_note' => 'Vacancy/Recruitment Card, Applicant Register, posisi, kualifikasi, akun Career, profile/CV, identity sequence NISJ, serta Squad/Employee/Contract hasil hiring tidak dihapus.',
            'last_reset' => $lastReset ? [
                'id' => (string) $lastReset->id,
                'reset_at' => (string) $lastReset->reset_at,
                'actor_user_id' => $lastReset->actor_user_id ? (string) $lastReset->actor_user_id : null,
                'deleted_counts' => $this->jsonArray($lastReset->deleted_counts),
            ] : null,
        ];
    }

    public function reset(Request $request, string $confirmation, ?string $reason = null): array
    {
        if (trim($confirmation) !== self::CONFIRMATION) {
            throw ValidationException::withMessages([
                'confirmation' => 'Ketik tepat "'.self::CONFIRMATION.'" untuk menjalankan reset.',
            ]);
        }

        return DB::transaction(function () use ($request, $confirmation, $reason): array {
            $context = $this->context($request, true);
            $before = $this->counts($context['application_ids']);
            $preserved = $this->preservedCounts($context['application_ids'], $context['registration_request_ids']);
            $total = array_sum($before);
            if ($total < 1) {
                throw ValidationException::withMessages([
                    'confirmation' => 'Tidak ada history Recruitment pada scope Anda yang dapat di-reset.',
                ]);
            }

            $deleted = [];
            foreach (self::CHILD_TABLES as $key => $table) {
                $deleted[$key] = $this->deleteByIds($table, 'application_id', $context['application_ids']);
            }
            $deleted['applications'] = $this->deleteByIds('HR_applications', 'id', $context['application_ids']);

            $auditId = (string) Str::ulid();
            if (Schema::hasTable('HR_recruitment_reset_audits')) {
                DB::table('HR_recruitment_reset_audits')->insert([
                    'id' => $auditId,
                    'actor_user_id' => $request->user()?->id,
                    'scope_mode' => 'allowed_outlets',
                    'outlet_count' => count($context['allowed_outlet_ids']),
                    'range_from' => $context['range']['from'],
                    'range_to' => $context['range']['to'],
                    'snapshot_counts' => json_encode($before, JSON_UNESCAPED_SLASHES),
                    'deleted_counts' => json_encode($deleted, JSON_UNESCAPED_SLASHES),
                    'preserved_counts' => json_encode($preserved, JSON_UNESCAPED_SLASHES),
                    'reason' => $reason ? trim($reason) : null,
                    'confirmation_phrase' => trim($confirmation),
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                    'reset_at' => now(),
                    'metadata' => json_encode([
                        'source' => 'dashboard_recruitment_i15',
                        'preserved_policy' => 'vacancy_registration_requests_career_accounts_operational_hires',
                    ], JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [
                'audit_id' => $auditId,
                'reset_at' => now()->toIso8601String(),
                'deleted_counts' => $deleted,
                'deleted_total' => array_sum($deleted),
                'range' => $context['range'],
                'scope' => [
                    'mode' => 'allowed_outlets',
                    'outlet_count' => count($context['allowed_outlet_ids']),
                ],
                'preserved' => $preserved,
                'message' => 'Master Data Recruitment pada scope Anda berhasil di-reset. Vacancy, Applicant Register dan akun Career tetap dipertahankan.',
            ];
        }, 3);
    }

    private function context(Request $request, bool $lock = false): array
    {
        if (! Schema::hasTable('HR_applications') || ! Schema::hasTable('HR_recruitment_positions')) {
            return [
                'allowed_outlet_ids' => [], 'outlets' => [], 'application_ids' => [],
                'registration_request_ids' => [], 'range' => ['from' => null, 'to' => null],
            ];
        }

        $allowed = $this->scope->allowedOutletIds($request);
        $outlets = $this->scope->options($request);
        if ($allowed === []) {
            return [
                'allowed_outlet_ids' => [], 'outlets' => [], 'application_ids' => [],
                'registration_request_ids' => [], 'range' => ['from' => null, 'to' => null],
            ];
        }

        $query = DB::table('HR_applications as a')
            ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')
            ->whereIn('p.destination_outlet_id', $allowed)
            ->select('a.id', 'a.registration_request_id', 'a.applied_at', 'a.created_at')
            ->orderBy('a.id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get();
        $applicationIds = $rows->pluck('id')->map(fn ($v) => (string) $v)->values()->all();
        $registrationIds = $rows->pluck('registration_request_id')->filter()->map(fn ($v) => (string) $v)->unique()->values()->all();

        $dates = $rows->map(function ($row): ?Carbon {
            $value = $row->applied_at ?: $row->created_at;
            if (! $value) return null;
            try { return Carbon::parse((string) $value); } catch (\Throwable) { return null; }
        })->filter()->sort()->values();

        return [
            'allowed_outlet_ids' => $allowed,
            'outlets' => $outlets,
            'application_ids' => $applicationIds,
            'registration_request_ids' => $registrationIds,
            'range' => [
                'from' => $dates->first()?->format('Y-m-d H:i:s'),
                'to' => $dates->last()?->format('Y-m-d H:i:s'),
            ],
        ];
    }

    private function counts(array $applicationIds): array
    {
        $counts = [];
        foreach (self::CHILD_TABLES as $key => $table) {
            $counts[$key] = $this->countByIds($table, 'application_id', $applicationIds);
        }
        $counts['applications'] = $this->countByIds('HR_applications', 'id', $applicationIds);
        return $counts;
    }

    private function preservedCounts(array $applicationIds, array $registrationRequestIds = []): array
    {
        $careerIds = [];
        $conversionRows = collect();
        if ($applicationIds !== [] && Schema::hasTable('HR_applications')) {
            foreach (array_chunk($applicationIds, 500) as $chunk) {
                $careerIds = array_merge($careerIds, DB::table('HR_applications')->whereIn('id', $chunk)->whereNotNull('career_account_id')->pluck('career_account_id')->map(fn ($v) => (string) $v)->all());
            }
        }
        if ($applicationIds !== [] && Schema::hasTable('HR_hiring_conversions')) {
            $parts = [];
            foreach (array_chunk($applicationIds, 500) as $chunk) {
                $parts[] = DB::table('HR_hiring_conversions')->whereIn('application_id', $chunk)->get(['squad_id', 'employee_id', 'contract_id']);
            }
            $conversionRows = collect($parts)->flatten(1);
        }

        return [
            'recruitments' => Schema::hasTable('HR_recruitments') ? DB::table('HR_recruitments')->count() : 0,
            'recruitment_positions' => Schema::hasTable('HR_recruitment_positions') ? DB::table('HR_recruitment_positions')->count() : 0,
            'career_accounts_linked' => count(array_unique($careerIds)),
            'registration_requests_linked' => $this->countByIds('HR_career_registration_requests', 'id', $registrationRequestIds),
            'operational_squads_linked' => $conversionRows->pluck('squad_id')->filter()->unique()->count(),
            'employees_linked' => $conversionRows->pluck('employee_id')->filter()->unique()->count(),
            'contracts_linked' => $conversionRows->pluck('contract_id')->filter()->unique()->count(),
            'identity_sequences_preserved' => Schema::hasTable('HR_identity_sequences') ? DB::table('HR_identity_sequences')->count() : 0,
        ];
    }

    private function countByIds(string $table, string $column, array $ids): int
    {
        if ($ids === [] || ! Schema::hasTable($table)) return 0;
        $count = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $count += DB::table($table)->whereIn($column, $chunk)->count();
        }
        return $count;
    }

    private function deleteByIds(string $table, string $column, array $ids): int
    {
        if ($ids === [] || ! Schema::hasTable($table)) return 0;
        $deleted = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $deleted += DB::table($table)->whereIn($column, $chunk)->delete();
        }
        return $deleted;
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
