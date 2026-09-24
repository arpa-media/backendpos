<?php

namespace App\Services\HumanResource;

use App\Models\Assignment;
use App\Models\HrAssignmentHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class HrAssignmentHistoryService
{
    private const TABLE = 'HR_assignment_histories';

    /** @return array<string,mixed> */
    public function snapshot(Assignment $assignment): array
    {
        return [
            'assignment_id' => (string) $assignment->id,
            'employee_id' => (string) $assignment->employee_id,
            'outlet_id' => $assignment->outlet_id ? (string) $assignment->outlet_id : null,
            'role_title' => $this->nullableString($assignment->role_title),
            'start_date' => $this->dateValue($assignment->start_date),
            'end_date' => $this->dateValue($assignment->end_date),
            'status' => $this->nullableString($assignment->status),
            'is_primary' => (bool) $assignment->is_primary,
        ];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    public function snapshotFromArray(array $values): array
    {
        return [
            'assignment_id' => isset($values['id']) ? (string) $values['id'] : null,
            'employee_id' => isset($values['employee_id']) ? (string) $values['employee_id'] : null,
            'outlet_id' => filled($values['outlet_id'] ?? null) ? (string) $values['outlet_id'] : null,
            'role_title' => $this->nullableString($values['role_title'] ?? null),
            'start_date' => $this->dateValue($values['start_date'] ?? null),
            'end_date' => $this->dateValue($values['end_date'] ?? null),
            'status' => $this->nullableString($values['status'] ?? null),
            'is_primary' => filter_var($values['is_primary'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * Records changes raised by the core Assignment model. This makes assignment history
     * work not only from the HR menu, but also from User Management / other POS flows.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $afterOverride
     */
    public function recordModelEvent(
        Assignment $assignment,
        string $action,
        ?array $before = null,
        ?array $afterOverride = null,
    ): void {
        if (! Schema::hasTable(self::TABLE)) return;

        $after = $afterOverride ?? ($action === 'deleted' ? null : $this->snapshot($assignment));
        $snapshot = $after ?? $before ?? $this->snapshot($assignment);
        $actor = Auth::user();

        HrAssignmentHistory::query()->create([
            'employee_id' => $snapshot['employee_id'] ?: $assignment->employee_id,
            'assignment_id' => $assignment->exists ? $assignment->id : ($snapshot['assignment_id'] ?? null),
            'outlet_id' => $snapshot['outlet_id'] ?? null,
            'role_title' => $snapshot['role_title'] ?? null,
            'start_date' => $snapshot['start_date'] ?? null,
            'end_date' => $snapshot['end_date'] ?? null,
            'status' => $snapshot['status'] ?? null,
            'is_primary' => (bool) ($snapshot['is_primary'] ?? false),
            'action' => substr(trim($action) ?: 'updated', 0, 40),
            'source' => $this->requestSource(),
            'changed_by_user_id' => $actor?->id,
            'changed_by_name_snapshot' => $this->actorName($actor),
            'note' => null,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'changed_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $payload */
    public function recordManual(string $employeeId, array $payload, ?User $actor): HrAssignmentHistory
    {
        return HrAssignmentHistory::query()->create([
            'employee_id' => $employeeId,
            'assignment_id' => null,
            'outlet_id' => $payload['outlet_id'] ?? null,
            'role_title' => $this->nullableString($payload['role_title'] ?? null),
            'start_date' => $payload['start_date'] ?? null,
            'end_date' => $payload['end_date'] ?? null,
            'status' => $this->nullableString($payload['status'] ?? 'historical') ?: 'historical',
            'is_primary' => false,
            'action' => 'manual_history',
            'source' => 'manual',
            'changed_by_user_id' => $actor?->id,
            'changed_by_name_snapshot' => $this->actorName($actor),
            'note' => $this->nullableString($payload['note'] ?? null),
            'before_snapshot' => null,
            'after_snapshot' => [
                'employee_id' => $employeeId,
                'outlet_id' => $payload['outlet_id'] ?? null,
                'role_title' => $this->nullableString($payload['role_title'] ?? null),
                'start_date' => $payload['start_date'] ?? null,
                'end_date' => $payload['end_date'] ?? null,
                'status' => $this->nullableString($payload['status'] ?? 'historical') ?: 'historical',
                'is_primary' => false,
            ],
            'changed_at' => $payload['changed_at'] ?? now(),
        ]);
    }

    private function requestSource(): string
    {
        if (! app()->bound('request')) return 'model_event';
        $route = request()?->route()?->getName();
        if (is_string($route) && str_starts_with($route, 'hr.assignments.')) return 'assignment_menu';
        if (is_string($route) && str_contains($route, 'user')) return 'user_management';
        return 'model_event';
    }

    private function actorName(?User $actor): ?string
    {
        if (! $actor) return null;
        return $this->nullableString($actor->name ?: $actor->username ?: $actor->nisj);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function dateValue(mixed $value): ?string
    {
        if (! $value) return null;
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            $text = trim((string) $value);
            return $text === '' ? null : substr($text, 0, 10);
        }
    }
}
