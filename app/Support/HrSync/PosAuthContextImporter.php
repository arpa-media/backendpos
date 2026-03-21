<?php

namespace App\Support\HrSync;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use App\Support\Auth\ManualAssignmentOverrideApplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PosAuthContextImporter
{
    public const CONTRACT = 'hr-pos-auth-context';
    public const MIN_VERSION = 2;

    public function __construct(
        protected OutletCompatibilityPreserver $compatibilityPreserver,
        protected ManualAssignmentOverrideApplier $manualAssignmentOverrideApplier,
    ) {
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function import(array $snapshot, array $options = []): array
    {
        $this->guardContract($snapshot);

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $preserveCompatibility = (bool) ($options['preserve_compatibility'] ?? true);

        $summary = [
            'meta' => [
                'contract' => Arr::get($snapshot, 'meta.contract'),
                'version' => Arr::get($snapshot, 'meta.version'),
                'checksum' => Arr::get($snapshot, 'meta.checksums.snapshot'),
                'exported_at' => Arr::get($snapshot, 'meta.exported_at'),
                'dry_run' => $dryRun,
            ],
            'entities' => [
                'outlets' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
                'users' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
                'employees' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
                'assignments' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
                'employee_assignment_links' => ['updated' => 0, 'skipped' => 0],
                'legacy_user_outlet_links' => ['updated' => 0, 'skipped' => 0],
            ],
            'warnings' => Arr::get($snapshot, 'meta.validation.warnings', []),
            'compatibility' => null,
            'notes' => [
                'No destructive delete is executed in iterasi 4.',
                'Existing outlet matched by code will be adopted as HR outlet to preserve downstream product binding.',
                'Legacy non-HR outlets that are still referenced will be marked as compatibility stub.',
            ],
        ];

        $runner = function () use ($snapshot, $preserveCompatibility, $dryRun, &$summary): void {
            $outletRows = collect(Arr::get($snapshot, 'outlets', []));
            $outletMap = $this->importOutlets($outletRows, $summary);
            $userMap = $this->importUsers(
                collect(Arr::get($snapshot, 'users', [])),
                collect(Arr::get($snapshot, 'employees', [])),
                $summary
            );
            $employeeMap = $this->importEmployees(collect(Arr::get($snapshot, 'employees', [])), $userMap, $summary);
            $assignmentMap = $this->importAssignments(collect(Arr::get($snapshot, 'assignments', [])), $employeeMap, $outletMap, $summary);
            $this->linkEmployeesToPrimaryAssignments(collect(Arr::get($snapshot, 'employees', [])), $employeeMap, $assignmentMap, $summary);
            $this->syncLegacyUserOutletPointers($employeeMap, $summary);

            if ($preserveCompatibility) {
                $summary['compatibility'] = $this->compatibilityPreserver->reconcile(
                    $outletRows->pluck('code')->filter()->values(),
                    $dryRun,
                );
            }

            $summary['manual_assignment_overrides'] = $this->manualAssignmentOverrideApplier->sync([
                'dry_run' => $dryRun,
                'manage_transaction' => false,
            ]);
        };

        if ($dryRun) {
            DB::beginTransaction();
            try {
                $runner();
                DB::rollBack();
            } catch (\Throwable $throwable) {
                DB::rollBack();
                throw $throwable;
            }

            $this->recordSyncRun($summary, true);

            return $summary;
        }

        DB::transaction($runner);
        $this->recordSyncRun($summary, false);

        return $summary;
    }

    protected function importOutlets(Collection $outlets, array &$summary): array
    {
        $map = [];

        foreach ($outlets as $row) {
            $hrOutletId = trim((string) ($row['hr_outlet_id'] ?? ''));
            if ($hrOutletId === '') {
                continue;
            }

            $sourceUpdatedAt = $this->parseDateTime($row['updated_at'] ?? null);
            $code = $this->nullableString($row['code'] ?? null);

            $local = Outlet::query()
                ->where(function ($query) use ($hrOutletId, $code) {
                    $query->where('hr_outlet_id', $hrOutletId);
                    if ($code) {
                        $query->orWhere('code', $code);
                    }
                })
                ->first();

            if (! $local) {
                $local = new Outlet();
                $summary['entities']['outlets']['created']++;
            } elseif (! $this->shouldUpdate($local->source_updated_at ?? null, $sourceUpdatedAt)) {
                $summary['entities']['outlets']['skipped']++;
                $map[$hrOutletId] = (string) $local->id;
                continue;
            } else {
                $summary['entities']['outlets']['updated']++;
            }

            $local->fill([
                'hr_outlet_id' => $hrOutletId,
                'code' => $code,
                'name' => $this->nullableString($row['name'] ?? null) ?: $code ?: 'Outlet HR',
                'type' => strtolower((string) ($row['type'] ?? 'outlet')),
                'timezone' => $this->nullableString($row['timezone'] ?? null) ?: 'Asia/Jakarta',
                'address' => $this->nullableString($row['address'] ?? null),
                'phone' => $this->nullableString($row['phone'] ?? null),
                'latitude' => $row['latitude'] ?? null,
                'longitude' => $row['longitude'] ?? null,
                'radius_m' => $row['radius_m'] ?? null,
                'is_hr_source' => true,
                'is_compatibility_stub' => false,
                'is_active' => true,
            ]);
            $local->source_updated_at = $sourceUpdatedAt;
            $local->imported_at = now();
            $local->save();

            $map[$hrOutletId] = (string) $local->id;
        }

        return $map;
    }

    protected function importUsers(Collection $users, Collection $employees, array &$summary): array
    {
        $employeeByUserId = $employees->keyBy(fn (array $row) => (string) ($row['hr_user_id'] ?? ''));
        $map = [];

        foreach ($users as $row) {
            $hrUserId = trim((string) ($row['hr_user_id'] ?? ''));
            if ($hrUserId === '') {
                continue;
            }

            $sourceUpdatedAt = $this->parseDateTime($row['updated_at'] ?? null);
            $employee = $employeeByUserId->get($hrUserId, []);
            $nisj = $this->nullableString($employee['nisj'] ?? null);
            $fullName = $this->nullableString($employee['full_name'] ?? null);
            $username = $this->nullableString($row['username'] ?? null);

            $local = User::query()->where('hr_user_id', $hrUserId)->first();
            if (! $local && $nisj) {
                $local = User::query()->where('nisj', $nisj)->first();
            }

            if (! $local) {
                $local = new User();
                $summary['entities']['users']['created']++;
            } elseif (! $this->shouldUpdate($local->source_updated_at ?? null, $sourceUpdatedAt)) {
                $summary['entities']['users']['skipped']++;
                $map[$hrUserId] = (string) $local->id;
                continue;
            } else {
                $summary['entities']['users']['updated']++;
            }

            $local->fill([
                'hr_user_id' => $hrUserId,
                'name' => $fullName ?: $username ?: $nisj ?: 'HR User',
                'nisj' => $nisj,
                'email' => $this->resolveUserEmail($row, $employee),
                'password' => (string) ($row['password'] ?? Str::password(32)),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ]);
            $local->source_updated_at = $sourceUpdatedAt;
            $local->imported_at = now();
            $local->save();

            $map[$hrUserId] = (string) $local->id;
        }

        return $map;
    }

    protected function importEmployees(Collection $employees, array $userMap, array &$summary): array
    {
        $map = [];

        foreach ($employees as $row) {
            $hrEmployeeId = trim((string) ($row['hr_employee_id'] ?? ''));
            if ($hrEmployeeId === '') {
                continue;
            }

            $sourceUpdatedAt = $this->parseDateTime($row['updated_at'] ?? null);
            $local = Employee::query()->where('hr_employee_id', $hrEmployeeId)->first();
            if (! $local && ! empty($row['nisj'])) {
                $local = Employee::query()->where('nisj', (string) $row['nisj'])->first();
            }

            if (! $local) {
                $local = new Employee();
                $summary['entities']['employees']['created']++;
            } elseif (! $this->shouldUpdate($local->source_updated_at ?? null, $sourceUpdatedAt)) {
                $summary['entities']['employees']['skipped']++;
                $map[$hrEmployeeId] = (string) $local->id;
                continue;
            } else {
                $summary['entities']['employees']['updated']++;
            }

            $local->fill([
                'hr_employee_id' => $hrEmployeeId,
                'user_id' => $userMap[(string) ($row['hr_user_id'] ?? '')] ?? null,
                'nisj' => $this->nullableString($row['nisj'] ?? null),
                'full_name' => $this->nullableString($row['full_name'] ?? null) ?: $this->nullableString($row['nickname'] ?? null),
                'nickname' => $this->nullableString($row['nickname'] ?? null),
                'employment_status' => $this->nullableString($row['employment_status'] ?? null),
            ]);
            $local->source_updated_at = $sourceUpdatedAt;
            $local->imported_at = now();
            $local->save();

            $map[$hrEmployeeId] = (string) $local->id;
        }

        return $map;
    }

    protected function importAssignments(Collection $assignments, array $employeeMap, array $outletMap, array &$summary): array
    {
        $map = [];

        foreach ($assignments as $row) {
            $hrAssignmentId = trim((string) ($row['hr_assignment_id'] ?? ''));
            if ($hrAssignmentId === '') {
                continue;
            }

            $sourceUpdatedAt = $this->parseDateTime($row['updated_at'] ?? null);
            $local = Assignment::query()->where('hr_assignment_id', $hrAssignmentId)->first();

            if (! $local) {
                $local = new Assignment();
                $summary['entities']['assignments']['created']++;
            } elseif (! $this->shouldUpdate($local->source_updated_at ?? null, $sourceUpdatedAt)) {
                $summary['entities']['assignments']['skipped']++;
                $map[$hrAssignmentId] = (string) $local->id;
                continue;
            } else {
                $summary['entities']['assignments']['updated']++;
            }

            $local->fill([
                'hr_assignment_id' => $hrAssignmentId,
                'employee_id' => $employeeMap[(string) ($row['hr_employee_id'] ?? '')] ?? null,
                'outlet_id' => $outletMap[(string) ($row['hr_outlet_id'] ?? '')] ?? null,
                'role_title' => $this->nullableString($row['role_title'] ?? null),
                'start_date' => $this->nullableString($row['start_date'] ?? null),
                'end_date' => $this->nullableString($row['end_date'] ?? null),
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'status' => $this->nullableString($row['status'] ?? null),
            ]);
            $local->source_updated_at = $sourceUpdatedAt;
            $local->imported_at = now();
            $local->save();

            $map[$hrAssignmentId] = (string) $local->id;
        }

        return $map;
    }

protected function linkEmployeesToPrimaryAssignments(Collection $employees, array $employeeMap, array $assignmentMap, array &$summary): void
{
    foreach ($employees as $row) {
        $localEmployeeId = $employeeMap[(string) ($row['hr_employee_id'] ?? '')] ?? null;
        if (! $localEmployeeId) {
            continue;
        }

        $employee = Employee::query()->find($localEmployeeId);
        if (! $employee) {
            continue;
        }

        // HR snapshot memakai assignment_id, bukan primary_hr_assignment_id
        $hrAssignmentId = (string) ($row['assignment_id'] ?? '');

        // fallback tambahan: bila field assignment_id kosong,
        // coba cari dari assignment primary milik employee tsb
        $newAssignmentId = $assignmentMap[$hrAssignmentId] ?? null;

        if (! $newAssignmentId) {
            $newAssignmentId = Assignment::query()
                ->where('employee_id', $employee->id)
                ->where('is_primary', true)
                ->value('id');
        }

        if (! $newAssignmentId) {
            $newAssignmentId = Assignment::query()
                ->where('employee_id', $employee->id)
                ->orderByDesc('is_primary')
                ->orderBy('start_date')
                ->value('id');
        }

        if ($employee->assignment_id === $newAssignmentId) {
            $summary['entities']['employee_assignment_links']['skipped']++;
            continue;
        }

        $employee->assignment_id = $newAssignmentId;
        $employee->save();

        $summary['entities']['employee_assignment_links']['updated']++;
    }
}
    protected function syncLegacyUserOutletPointers(array $employeeMap, array &$summary): void
    {
        $employees = Employee::query()->with('assignment')->whereIn('id', array_values($employeeMap))->get();

        foreach ($employees as $employee) {
            $user = $employee->user;
            if (! $user) {
                continue;
            }

            $newOutletId = $employee->assignment?->outlet_id;
            if ($user->outlet_id === $newOutletId) {
                $summary['entities']['legacy_user_outlet_links']['skipped']++;
                continue;
            }

            $user->outlet_id = $newOutletId;
            $user->save();
            $summary['entities']['legacy_user_outlet_links']['updated']++;
        }
    }

    protected function shouldUpdate(mixed $existingUpdatedAt, ?CarbonImmutable $incomingUpdatedAt): bool
    {
        if (! $existingUpdatedAt instanceof \DateTimeInterface) {
            return true;
        }

        if (! $incomingUpdatedAt) {
            return true;
        }

        return $incomingUpdatedAt->greaterThan(CarbonImmutable::instance($existingUpdatedAt));
    }

    protected function resolveUserEmail(array $userRow, array $employeeRow): ?string
    {
        return $this->nullableString($userRow['email'] ?? null)
            ?: $this->nullableString($employeeRow['email'] ?? null);
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function parseDateTime(mixed $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function guardContract(array $snapshot): void
    {
        $contract = Arr::get($snapshot, 'meta.contract');
        $version = (int) Arr::get($snapshot, 'meta.version', 0);

        if ($contract !== self::CONTRACT || $version < self::MIN_VERSION) {
            throw new RuntimeException(sprintf(
                'Unsupported HR snapshot contract. Expected %s v%s+, got %s v%s.',
                self::CONTRACT,
                self::MIN_VERSION,
                (string) $contract,
                (string) $version,
            ));
        }
    }

    protected function recordSyncRun(array $summary, bool $dryRun): void
    {
        DB::table('hr_sync_runs')->insert([
            'id' => (string) Str::ulid(),
            'contract' => (string) Arr::get($summary, 'meta.contract'),
            'version' => (int) Arr::get($summary, 'meta.version', 0),
            'snapshot_checksum' => (string) Arr::get($summary, 'meta.checksum', ''),
            'exported_at' => $this->parseDateTime(Arr::get($summary, 'meta.exported_at')),
            'is_dry_run' => $dryRun,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
