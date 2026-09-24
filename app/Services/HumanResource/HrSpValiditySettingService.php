<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrWarningLetter;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrSpValiditySettingService
{
    private const DEFAULTS = [1 => 30, 2 => 60, 3 => 90];

    public function rules(bool $includePhk = false): array
    {
        $days = $this->allDays();
        $rows = [];
        foreach ([1, 2, 3] as $level) {
            $rows[] = [
                'level' => 'SP '.$level,
                'sp_level' => $level,
                'days' => (int) ($days[$level] ?? self::DEFAULTS[$level]),
            ];
        }

        if ($includePhk) {
            $rows[] = ['level' => 'PHK', 'sp_level' => null, 'days' => null, 'note' => 'ACTIVE DATE'];
        }

        return $rows;
    }

    public function allDays(): array
    {
        $result = self::DEFAULTS;
        if (! Schema::hasTable('HR_sp_validity_settings')) {
            return $result;
        }

        foreach (DB::table('HR_sp_validity_settings')->whereIn('sp_level', [1, 2, 3])->get(['sp_level', 'validity_days']) as $row) {
            $level = (int) $row->sp_level;
            $days = (int) $row->validity_days;
            if ($level >= 1 && $level <= 3 && $days >= 1) {
                $result[$level] = $days;
            }
        }

        return $result;
    }

    public function daysForLevel(int $level): int
    {
        $level = max(1, min(3, $level));
        return (int) ($this->allDays()[$level] ?? self::DEFAULTS[$level]);
    }

    public function validityWindow(mixed $startDate, int $level): array
    {
        $start = Carbon::parse((string) $startDate, 'Asia/Jakarta')->startOfDay();
        $days = $this->daysForLevel($level);

        // Pertahankan semantik existing Recap SP: END = START + validity_days,
        // dan START/END sama-sama dianggap aktif.
        $end = $start->copy()->addDays($days);

        return [
            'start' => $start,
            'end' => $end,
            'days' => $days,
        ];
    }

    public function isActiveLetter(object $letter, mixed $asOf = null): bool
    {
        if (strtolower((string) ($letter->status ?? '')) !== 'approved') {
            return false;
        }

        $startValue = $letter->effective_date ?? $letter->issue_date ?? null;
        if (! $startValue) {
            return false;
        }

        $window = $this->validityWindow($startValue, (int) ($letter->sp_level ?? 1));
        $date = $asOf
            ? Carbon::parse((string) $asOf, 'Asia/Jakarta')->startOfDay()
            : now('Asia/Jakarta')->startOfDay();

        return $date->greaterThanOrEqualTo($window['start']) && $date->lessThanOrEqualTo($window['end']);
    }

    public function currentLevelForEmployee(string $employeeId, mixed $asOf = null): int
    {
        if (! Schema::hasTable('HR_warning_letters')) {
            return 0;
        }

        $date = $asOf
            ? Carbon::parse((string) $asOf, 'Asia/Jakarta')->toDateString()
            : now('Asia/Jakarta')->toDateString();

        $row = HrWarningLetter::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereRaw('DATE(COALESCE(effective_date, issue_date)) <= ?', [$date])
            ->orderByRaw('COALESCE(effective_date, issue_date) DESC')
            ->orderByDesc('created_at')
            ->first(['id', 'employee_id', 'sp_level', 'issue_date', 'effective_date', 'status', 'created_at']);

        if (! $row || ! $this->isActiveLetter($row, $asOf)) {
            return 0;
        }

        return (int) $row->sp_level;
    }

    public function currentStatesForEmployees(iterable $employeeIds, mixed $asOf = null): array
    {
        if (! Schema::hasTable('HR_warning_letters')) {
            return [];
        }

        $ids = collect($employeeIds)->filter()->map(fn ($id) => (string) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $date = $asOf
            ? Carbon::parse((string) $asOf, 'Asia/Jakarta')->toDateString()
            : now('Asia/Jakarta')->toDateString();

        $rows = HrWarningLetter::query()
            ->whereIn('employee_id', $ids->all())
            ->where('status', 'approved')
            ->whereRaw('DATE(COALESCE(effective_date, issue_date)) <= ?', [$date])
            ->orderByRaw('COALESCE(effective_date, issue_date) DESC')
            ->orderByDesc('created_at')
            ->get(['id', 'employee_id', 'sp_level', 'issue_date', 'effective_date', 'status', 'created_at']);

        $latest = [];
        foreach ($rows as $row) {
            $employeeId = (string) $row->employee_id;
            if (! array_key_exists($employeeId, $latest)) {
                $latest[$employeeId] = $row;
            }
        }

        $states = [];
        foreach ($latest as $employeeId => $row) {
            if (! $this->isActiveLetter($row, $asOf)) {
                continue;
            }

            $level = (int) $row->sp_level;
            $window = $this->validityWindow($row->effective_date ?: $row->issue_date, $level);
            $states[$employeeId] = [
                'level' => $level,
                'days' => $window['days'],
                'start' => $window['start']->toDateString(),
                'end' => $window['end']->toDateString(),
            ];
        }

        return $states;
    }

    public function update(array $payload, ?User $actor): array
    {
        $requested = [
            1 => (int) $payload['sp1_days'],
            2 => (int) $payload['sp2_days'],
            3 => (int) $payload['sp3_days'],
        ];

        DB::transaction(function () use ($requested, $actor): void {
            foreach ($requested as $level => $newDays) {
                $oldDays = $this->daysForLevel($level);

                if (Schema::hasTable('HR_sp_validity_settings')) {
                    $query = DB::table('HR_sp_validity_settings')->where('sp_level', $level);
                    if ($query->exists()) {
                        $query->update([
                            'validity_days' => $newDays,
                            'updated_by_user_id' => $actor?->id,
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('HR_sp_validity_settings')->insert([
                            'sp_level' => $level,
                            'validity_days' => $newDays,
                            'updated_by_user_id' => $actor?->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                if ($oldDays !== $newDays && Schema::hasTable('HR_sp_validity_setting_logs')) {
                    DB::table('HR_sp_validity_setting_logs')->insert([
                        'id' => (string) \Illuminate\Support\Str::ulid(),
                        'sp_level' => $level,
                        'old_validity_days' => $oldDays,
                        'new_validity_days' => $newDays,
                        'changed_by_user_id' => $actor?->id,
                        'changed_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        return [
            'rules' => $this->rules(),
            'note' => 'Validity berlaku global dan langsung digunakan untuk klasifikasi SP aktif/nonaktif serta urutan SP berikutnya.',
        ];
    }
}
