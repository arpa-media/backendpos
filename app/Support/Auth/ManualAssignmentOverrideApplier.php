<?php

namespace App\Support\Auth;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ManualAssignmentOverrideApplier
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sync(array $options = []): array
    {
        $enabled = (bool) config('pos_sync.manual_assignment_overrides.enabled', true);
        $dryRun = (bool) Arr::get($options, 'dry_run', false);
        $manageTransaction = (bool) Arr::get($options, 'manage_transaction', true);
        $records = $this->records();

        $summary = [
            'enabled' => $enabled,
            'configured_records' => $records->count(),
            'processed_records' => 0,
            'outlet' => null,
            'outlet_created' => 0,
            'outlet_updated' => 0,
            'employees_created' => 0,
            'assignments_created' => 0,
            'assignments_updated' => 0,
            'demoted_primary_assignments' => 0,
            'employee_links_updated' => 0,
            'legacy_user_outlets_updated' => 0,
            'roles_synced' => 0,
            'skipped' => 0,
            'missing_users' => [],
            'details' => [],
        ];

        if (! $enabled || $records->isEmpty()) {
            return $summary;
        }

        $runner = function () use ($records, &$summary): void {
            $outlet = $this->ensureOutlet($summary);

            foreach ($records as $record) {
                $summary['processed_records']++;
                $detail = [
                    'nisj' => (string) ($record['nisj'] ?? ''),
                    'status' => 'skipped',
                ];

                $user = User::query()->where('nisj', (string) ($record['nisj'] ?? ''))->first();

                if (! $user) {
                    $detail['status'] = 'missing_user';
                    $summary['missing_users'][] = (string) ($record['nisj'] ?? '');
                    $summary['details'][] = $detail;
                    $summary['skipped']++;
                    continue;
                }

                $employee = Employee::query()
                    ->where(function ($query) use ($user, $record) {
                        $query->where('user_id', $user->id);
                        if (! empty($record['nisj'])) {
                            $query->orWhere('nisj', (string) $record['nisj']);
                        }
                    })
                    ->first();

                if (! $employee) {
                    $employee = new Employee();
                    $employee->fill([
                        'user_id' => $user->id,
                        'nisj' => $user->nisj,
                        'full_name' => $user->name,
                        'employment_status' => 'active',
                    ]);
                    $employee->save();
                    $summary['employees_created']++;
                } elseif ((string) $employee->user_id !== (string) $user->id || blank($employee->nisj)) {
                    $employee->forceFill([
                        'user_id' => $user->id,
                        'nisj' => $employee->nisj ?: $user->nisj,
                        'employment_status' => $employee->employment_status ?: 'active',
                    ])->save();
                }

                $assignment = $this->resolveAssignment($employee, $outlet);
                $assignmentExists = $assignment->exists;
                $assignment->fill([
                    'employee_id' => $employee->id,
                    'outlet_id' => $outlet->id,
                    'role_title' => $this->normalizeRoleTitle($record['role_title'] ?? 'squad'),
                    'start_date' => $assignment->start_date?->toDateString() ?: now()->toDateString(),
                    'end_date' => null,
                    'is_primary' => true,
                    'status' => (string) ($record['status'] ?? 'active'),
                ]);
                $assignment->source_updated_at = now();
                $assignment->imported_at = now();
                $assignment->save();

                if (! $assignmentExists) {
                    $summary['assignments_created']++;
                } else {
                    $summary['assignments_updated']++;
                }

                $demoted = Assignment::query()
                    ->where('employee_id', $employee->id)
                    ->where('id', '!=', $assignment->id)
                    ->where('is_primary', true)
                    ->update([
                        'is_primary' => false,
                        'updated_at' => now(),
                    ]);
                $summary['demoted_primary_assignments'] += (int) $demoted;

                if ((string) $employee->assignment_id !== (string) $assignment->id) {
                    $employee->forceFill([
                        'assignment_id' => $assignment->id,
                        'employment_status' => $employee->employment_status ?: 'active',
                    ])->save();
                    $summary['employee_links_updated']++;
                }

                if ((string) $user->outlet_id !== (string) $outlet->id || ! $user->is_active) {
                    $user->forceFill([
                        'outlet_id' => $outlet->id,
                        'is_active' => true,
                    ])->save();
                    $summary['legacy_user_outlets_updated']++;
                }

                $detail['status'] = 'synced';
                $detail['user_id'] = (string) $user->id;
                $detail['employee_id'] = (string) $employee->id;
                $detail['assignment_id'] = (string) $assignment->id;
                $detail['outlet_id'] = (string) $outlet->id;
                $detail['outlet_code'] = (string) ($outlet->code ?? '');
                $summary['details'][] = $detail;
            }
        };

        if ($dryRun && $manageTransaction) {
            DB::beginTransaction();
            try {
                $runner();
                DB::rollBack();
            } catch (\Throwable $throwable) {
                DB::rollBack();
                throw $throwable;
            }

            return $summary;
        }

        if ($manageTransaction) {
            DB::transaction($runner);
        } else {
            $runner();
        }

        return $summary;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function records(): Collection
    {
        return collect(config('pos_sync.manual_assignment_overrides.records', []))
            ->map(fn ($record) => is_array($record) ? $record : [])
            ->filter(fn (array $record) => filled($record['nisj'] ?? null))
            ->values();
    }

    protected function ensureOutlet(array &$summary): Outlet
    {
        $config = (array) config('pos_sync.manual_assignment_overrides.outlet', []);
        $targetCode = trim((string) ($config['code'] ?? 'KTA'));
        $targetName = trim((string) ($config['name'] ?? 'Kuta'));
        $targetType = strtolower(trim((string) ($config['type'] ?? 'outlet'))) ?: 'outlet';
        $defaultTimezone = trim((string) ($config['timezone'] ?? 'Asia/Jakarta')) ?: 'Asia/Jakarta';

        $legacyCodes = collect($config['legacy_lookup_codes'] ?? [])->filter()->map(fn ($value) => trim((string) $value));
        $legacyNames = collect($config['legacy_lookup_names'] ?? [])->filter()->map(fn ($value) => trim((string) $value));

        $outlet = Outlet::query()->where('code', $targetCode)->first();

        if (! $outlet) {
            foreach ($legacyCodes as $code) {
                $outlet = Outlet::query()->where('code', $code)->first();
                if ($outlet) {
                    break;
                }
            }
        }

        if (! $outlet) {
            $outlet = Outlet::query()->where('name', $targetName)->first();
        }

        if (! $outlet) {
            foreach ($legacyNames as $name) {
                $outlet = Outlet::query()->where('name', $name)->first();
                if ($outlet) {
                    break;
                }
            }
        }

        $isNew = false;
        if (! $outlet) {
            $outlet = new Outlet();
            $isNew = true;
        }

        $original = $outlet->exists ? [
            'code' => $outlet->code,
            'name' => $outlet->name,
            'type' => $outlet->type,
            'timezone' => $outlet->timezone,
            'is_hr_source' => (bool) $outlet->is_hr_source,
            'is_compatibility_stub' => (bool) $outlet->is_compatibility_stub,
            'is_active' => (bool) ($outlet->is_active ?? true),
        ] : null;

        $outlet->forceFill([
            'code' => $targetCode,
            'name' => $targetName,
            'type' => $targetType,
            'timezone' => $outlet->timezone ?: $defaultTimezone,
            'is_hr_source' => (bool) ($outlet->is_hr_source ?? false),
            'is_compatibility_stub' => false,
            'is_active' => true,
        ])->save();

        if ($isNew) {
            $summary['outlet_created']++;
        } else {
            $current = [
                'code' => $outlet->code,
                'name' => $outlet->name,
                'type' => $outlet->type,
                'timezone' => $outlet->timezone,
                'is_hr_source' => (bool) $outlet->is_hr_source,
                'is_compatibility_stub' => (bool) $outlet->is_compatibility_stub,
                'is_active' => (bool) ($outlet->is_active ?? true),
            ];
            if ($original !== $current) {
                $summary['outlet_updated']++;
            }
        }

        $summary['outlet'] = [
            'id' => (string) $outlet->id,
            'code' => (string) ($outlet->code ?? ''),
            'name' => (string) ($outlet->name ?? ''),
            'type' => (string) ($outlet->type ?? ''),
            'timezone' => (string) ($outlet->timezone ?? ''),
            'is_active' => (bool) ($outlet->is_active ?? true),
            'is_hr_source' => (bool) ($outlet->is_hr_source ?? false),
            'is_compatibility_stub' => (bool) ($outlet->is_compatibility_stub ?? false),
        ];

        return $outlet;
    }

    protected function resolveAssignment(Employee $employee, Outlet $outlet): Assignment
    {
        $existing = Assignment::query()
            ->where('employee_id', $employee->id)
            ->where('outlet_id', $outlet->id)
            ->orderByRaw('CASE WHEN hr_assignment_id IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('is_primary')
            ->first();

        return $existing ?: new Assignment();
    }

    protected function normalizeRoleTitle(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return $value !== '' ? $value : 'squad';
    }
}
