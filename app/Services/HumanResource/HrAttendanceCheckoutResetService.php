<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Resets a mistaken check-out without deleting check-in or approval history.
 * A before-image and reason are committed atomically with the mutation.
 */
class HrAttendanceCheckoutResetService
{
    private const CHECKOUT_COLUMNS = [
        'checkout_at', 'checkout_business_date', 'checkout_timezone',
        'checkout_outlet_id', 'checkout_outlet_name', 'checkout_outlet_timezone',
        'checkout_lat', 'checkout_lng', 'checkout_accuracy_m',
        'checkout_distance_m', 'checkout_radius_m', 'checkout_radius_delta_m',
        'checkout_inside_radius', 'checkout_location_status', 'checkout_mode',
        'checkout_note', 'checkout_duty_location', 'checkout_photo_path',
        'checkout_camera_status', 'checkout_camera_note', 'checkout_exception_flags',
    ];

    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrAttendanceCalculationService $calculation,
    ) {}

    public function reset(Request $request, string $attendanceId, string $reason, string $expectedCheckoutAt): array
    {
        // Check this BEFORE entering the transaction. Do not trust the outlet from the client.
        $allowedOutlets = $this->scope->allowedOutletIds($request);
        if ($allowedOutlets === []) {
            abort(403, 'Tidak ada penugasan yang dapat dikelola.');
        }

        return DB::transaction(function () use ($request, $attendanceId, $reason, $expectedCheckoutAt, $allowedOutlets): array {
            $attendance = HrAttendance::query()
                ->whereKey($attendanceId)
                ->lockForUpdate()
                ->first();

            if (! $attendance) {
                abort(404, 'Data absensi tidak ditemukan.');
            }

            $outletId = (string) ($attendance->assignment_outlet_id ?: $attendance->checkin_outlet_id ?: '');
            if ($outletId === '' || ! in_array($outletId, $allowedOutlets, true)) {
                abort(403, 'Data absensi berada di luar scope penugasan Anda.');
            }

            if ((string) $attendance->record_status !== 'complete' || ! $attendance->checkin_at || ! $attendance->checkout_at) {
                throw ValidationException::withMessages([
                    'attendance' => ['Hanya absensi Complete dengan Absen Datang dan Pulang yang dapat di-reset.'],
                ]);
            }

            // Self-service checkout always chooses the oldest open attendance.
            // Never create a second pending checkout for this user: it could
            // silently apply the next checkout to the wrong business date.
            $anotherOpen = HrAttendance::query()
                ->where('user_id', (string) $attendance->user_id)
                ->where('id', '!=', (string) $attendance->id)
                ->where('record_status', 'open')
                ->whereNull('checkout_at')
                ->lockForUpdate()
                ->first(['id']);
            if ($anotherOpen) {
                throw ValidationException::withMessages([
                    'attendance' => ['Masih ada absensi lain yang menunggu Pulang. Selesaikan record Open tersebut sebelum reset Pulang ini.'],
                ]);
            }

            // An optimistic concurrency check prevents resetting a checkout replaced
            // by a new one after the confirmation dialog was opened.
            if ((string) $attendance->getRawOriginal('checkout_at') !== $expectedCheckoutAt) {
                throw ValidationException::withMessages([
                    'attendance' => ['Waktu Absen Pulang telah berubah. Muat ulang Data Absen sebelum mengulangi reset.'],
                ]);
            }

            $before = [
                'record_status' => (string) $attendance->record_status,
                'approval_required' => (bool) $attendance->approval_required,
                'approval_status' => (string) $attendance->approval_status,
                'calculation_eligible' => (bool) $attendance->calculation_eligible,
                'late_minutes' => $attendance->late_minutes,
                'work_minutes' => $attendance->work_minutes,
                'calculation_status' => $attendance->calculation_status,
                'calculated_at' => $attendance->getRawOriginal('calculated_at'),
                'checkout' => [],
            ];

            // Raw values preserve the actual UTC instant and other checkout attributes.
            foreach (self::CHECKOUT_COLUMNS as $column) {
                $before['checkout'][$column] = $attendance->getRawOriginal($column);
            }

            $resetAttributes = array_fill_keys(self::CHECKOUT_COLUMNS, null);
            $resetAttributes['record_status'] = 'open';
            $resetAttributes['work_minutes'] = null;
            // Do not touch check-in, exception_flags (check-in), approval workflow,
            // employee/assignment, or device_info (may contain check-in provenance).
            $attendance->forceFill($resetAttributes)->save();
            $attendance = $this->calculation->recalculate($attendance);

            $actor = $request->user();
            $resetAt = now();
            DB::table('HR_attendance_checkout_reset_logs')->insert([
                'id' => (string) Str::ulid(),
                'attendance_id' => (string) $attendance->id,
                'business_date' => $attendance->business_date?->format('Y-m-d'),
                'actor_user_id' => (string) $actor->id,
                'actor_name_snapshot' => (string) ($actor->name ?: $actor->username ?: $actor->nisj ?: $actor->id),
                'reason' => $reason,
                'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'after_json' => json_encode([
                    'record_status' => (string) $attendance->record_status,
                    'checkout_at' => $attendance->getRawOriginal('checkout_at'),
                    'work_minutes' => $attendance->work_minutes,
                    'calculation_status' => $attendance->calculation_status,
                    'approval_status' => (string) $attendance->approval_status,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => $resetAt,
            ]);

            // Original photo file is retained for investigation/audit; the active row
            // no longer references it. Physical deletion here would be irreversible.
            return [
                'attendance_id' => (string) $attendance->id,
                'record_status' => 'open',
                'reset_at' => $resetAt->toIso8601String(),
            ];
        }, 3); // Deadlock retry (audit insert and mutation are a single transaction).
    }
}
