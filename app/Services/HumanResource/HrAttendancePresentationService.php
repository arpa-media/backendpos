<?php

namespace App\Services\HumanResource;

use Carbon\CarbonImmutable;

class HrAttendancePresentationService
{
    public function row(object $row): array
    {
        $timezone = $this->safeTimezone((string) ($row->attendance_timezone ?? ''));
        $checkoutTimezone = $this->safeTimezone((string) ($row->checkout_timezone ?? $timezone));
        $flags = json_decode((string) ($row->exception_flags ?? '[]'), true);
        $flags = is_array($flags) ? array_values(array_filter(array_map('strval', $flags))) : [];

        return [
            'id' => (string) $row->id,
            'employee_id' => isset($row->employee_id) && $row->employee_id ? (string) $row->employee_id : null,
            'user_id' => isset($row->user_id) && $row->user_id ? (string) $row->user_id : null,
            'employee' => [
                'full_name' => (string) ($row->employee_name ?? $row->user_name ?? '-'),
                'nisj' => (string) ($row->employee_nisj ?? $row->user_nisj ?? '-'),
            ],
            'business_date' => (string) $row->business_date,
            'attendance_timezone' => $timezone,
            'record_status' => (string) $row->record_status,
            'assignment_outlet' => [
                'id' => isset($row->scope_outlet_id) && $row->scope_outlet_id ? (string) $row->scope_outlet_id : null,
                'code' => $row->scope_outlet_code ?? null,
                'name' => $row->scope_outlet_name ?? null,
                'type' => strtolower(trim((string) ($row->scope_outlet_type ?? 'outlet'))) ?: 'outlet',
            ],
            'checkin' => $this->eventPayload($row, 'checkin', $timezone),
            'checkout' => $this->eventPayload($row, 'checkout', $checkoutTimezone),
            'approval_required' => (bool) ($row->approval_required ?? false),
            'approval_status' => (string) ($row->approval_status ?? 'not_required'),
            'calculation_eligible' => (bool) ($row->calculation_eligible ?? false),
            'late_minutes' => isset($row->late_minutes) && $row->late_minutes !== null ? (int) $row->late_minutes : null,
            'work_minutes' => isset($row->work_minutes) && $row->work_minutes !== null ? (int) $row->work_minutes : null,
            'calculation_status' => $row->calculation_status ?? null,
            'exception_flags' => $flags,
            'approval' => isset($row->approval_id) && $row->approval_id ? [
                'id' => (string) $row->approval_id,
                'type' => (string) ($row->approval_type ?? ''),
                'spv_status' => (string) ($row->spv_status ?? 'pending'),
                'spv_user_id' => $row->spv_user_id ?? null,
                'spv_user_name' => $row->spv_user_name ?? null,
                'spv_decided_at' => $this->plainTimestamp($row->spv_decided_at ?? null),
                'spv_note' => $row->spv_note ?? null,
                'hrd_status' => (string) ($row->hrd_status ?? 'pending'),
                'hrd_user_id' => $row->hrd_user_id ?? null,
                'hrd_user_name' => $row->hrd_user_name ?? null,
                'hrd_decided_at' => $this->plainTimestamp($row->hrd_decided_at ?? null),
                'hrd_note' => $row->hrd_note ?? null,
                'final_status' => (string) ($row->final_status ?? 'pending'),
                'actionable' => (string) $row->record_status !== 'cancelled' && ! empty($row->checkin_at),
            ] : null,
        ];
    }

    private function eventPayload(object $row, string $prefix, string $timezone): array
    {
        $at = $row->{$prefix.'_at'} ?? null;
        $path = $row->{$prefix.'_photo_path'} ?? null;
        $inside = $row->{$prefix.'_inside_radius'} ?? null;
        return [
            'at' => $this->localTimestamp($at, $timezone),
            'time' => $this->localTime($at, $timezone),
            'outlet_id' => $row->{$prefix.'_outlet_id'} ?? null,
            'outlet_name' => $row->{$prefix.'_outlet_name'} ?? null,
            'latitude' => isset($row->{$prefix.'_lat'}) && $row->{$prefix.'_lat'} !== null ? (float) $row->{$prefix.'_lat'} : null,
            'longitude' => isset($row->{$prefix.'_lng'}) && $row->{$prefix.'_lng'} !== null ? (float) $row->{$prefix.'_lng'} : null,
            'accuracy_m' => isset($row->{$prefix.'_accuracy_m'}) && $row->{$prefix.'_accuracy_m'} !== null ? (float) $row->{$prefix.'_accuracy_m'} : null,
            'distance_m' => isset($row->{$prefix.'_distance_m'}) ? $row->{$prefix.'_distance_m'} : null,
            'radius_m' => isset($row->{$prefix.'_radius_m'}) ? $row->{$prefix.'_radius_m'} : null,
            'radius_delta_m' => isset($row->{$prefix.'_radius_delta_m'}) ? $row->{$prefix.'_radius_delta_m'} : null,
            'inside_radius' => $inside === null ? null : (bool) $inside,
            'location_status' => $row->{$prefix.'_location_status'} ?? null,
            'mode' => $row->{$prefix.'_mode'} ?? null,
            'note' => $row->{$prefix.'_note'} ?? null,
            'duty_location' => $prefix === 'checkin' ? ($row->duty_location ?? null) : ($row->checkout_duty_location ?? null),
            'camera_status' => $row->{$prefix.'_camera_status'} ?? null,
            'camera_note' => $row->{$prefix.'_camera_note'} ?? null,
            'photo_path' => $path,
            'photo_url' => $path ? asset('storage/'.$path) : null,
        ];
    }

    private function localTimestamp(?string $value, string $timezone): ?string
    {
        if (! $value) return null;
        try {
            return CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function localTime(?string $value, string $timezone): ?string
    {
        $local = $this->localTimestamp($value, $timezone);
        return $local ? substr($local, 11, 8) : null;
    }

    private function plainTimestamp(mixed $value): ?string
    {
        if (! $value) return null;
        return is_string($value) ? $value : (string) $value;
    }

    private function safeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'Asia/Jakarta';
    }
}
