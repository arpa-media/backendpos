<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\HumanResource\HrAttendance;
use App\Services\HumanResource\HrAttendanceCalculationService;
use App\Services\HumanResource\HrAttendanceIdentityService;
use App\Services\HumanResource\HrAttendancePhotoService;
use App\Services\HumanResource\HrGeofenceService;
use App\Services\HumanResource\HrManualAttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class HrAttendanceSelfController extends Controller
{
    public function __construct(
        private readonly HrAttendanceIdentityService $identityService,
        private readonly HrGeofenceService $geofenceService,
        private readonly HrAttendancePhotoService $photoService,
        private readonly HrManualAttendanceService $manualAttendanceService,
        private readonly HrAttendanceCalculationService $calculationService,
    ) {}

    public function context(Request $request)
    {
        $identity = $this->identityService->resolve($request->user());
        $deviceTimezone = $this->validTimezone((string) $request->query('device_timezone'));
        $referenceTimezone = $this->referenceTimezone($identity, $deviceTimezone);

        $contextLatitude = $request->query('latitude');
        $contextLongitude = $request->query('longitude');
        if (is_numeric($contextLatitude) && is_numeric($contextLongitude)) {
            $latitude = (float) $contextLatitude;
            $longitude = (float) $contextLongitude;
            if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
                $contextGeofence = $this->resolveAttendanceGeofence($identity, $latitude, $longitude);
                if ($contextGeofence['outlet']) {
                    $referenceTimezone = $this->attendanceTimezone($identity, $contextGeofence, $deviceTimezone);
                }
            }
        }

        $referenceDate = CarbonImmutable::now('UTC')->setTimezone($referenceTimezone)->toDateString();

        $pending = $this->pendingCheckout((string) $request->user()->id);
        $today = HrAttendance::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('business_date', $referenceDate)
            ->where('record_status', '!=', 'cancelled')
            ->first();

        $history = HrAttendance::query()
            ->where('user_id', $request->user()->id)
            ->where('record_status', '!=', 'cancelled')
            ->orderByDesc('business_date')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (HrAttendance $row) => $this->attendancePayload($row))
            ->values();

        return ApiResponse::ok([
            'user' => $this->userPayload($identity),
            'squad' => $this->squadPayload($identity['squad']),
            'assignment' => $this->assignmentPayload($identity),
            'reference_timezone' => $referenceTimezone,
            'reference_date' => $referenceDate,
            'outlets' => $this->geofenceService->eligibleOutlets()->map(fn ($outlet) => [
                'id' => (string) $outlet->id,
                'code' => $outlet->code,
                'name' => $outlet->name,
                'type' => $outlet->type,
                'address' => $outlet->address,
                'timezone' => $this->validTimezone((string) $outlet->timezone) ?: 'Asia/Jakarta',
                'latitude' => (float) $outlet->latitude,
                'longitude' => (float) $outlet->longitude,
                'radius_m' => (int) $outlet->radius_m,
            ])->values(),
            'pending_checkout' => $pending ? $this->attendancePayload($pending) : null,
            'today' => $today ? $this->attendancePayload($today) : null,
            'quick_history' => $history,
            'requirements' => [
                'location_required' => true,
                'camera_required' => false,
                'camera_failure_requires_approval' => true,
                'outside_radius_requires_confirmation' => true,
                'outside_radius_requires_approval' => true,
                'field_duty_requires_approval' => true,
                'strict_assignment_geofence' => $this->strictAssignmentGeofence($identity),
            ],
        ], 'Attendance context ready');
    }

    public function checkIn(Request $request)
    {
        $validated = $this->validateAttendanceRequest($request);
        if ($validated['response']) {
            return $validated['response'];
        }
        $data = $validated['data'];

        $identity = $this->identityService->resolve($request->user());
        $pending = $this->pendingCheckout((string) $request->user()->id);
        if ($pending) {
            return ApiResponse::error(
                'Anda masih memiliki absen datang yang belum dipulangkan. Selesaikan Absen Pulang terlebih dahulu.',
                'ATTENDANCE_PENDING_CHECKOUT',
                409,
                [],
                ['pending_attendance' => $this->attendancePayload($pending)]
            );
        }

        $geofence = $this->resolveAttendanceGeofence($identity, (float) $data['latitude'], (float) $data['longitude']);
        if (! $geofence['outlet']) {
            $message = $this->strictAssignmentGeofence($identity)
                ? 'Outlet penugasan belum memiliki koordinat/radius yang valid. Hubungi HR/Admin.'
                : 'Belum ada outlet aktif yang memiliki koordinat dan radius. Hubungi HR/Admin.';
            return ApiResponse::error(
                $message,
                'ATTENDANCE_GEOFENCE_NOT_CONFIGURED',
                422
            );
        }

        $outsideValidation = $this->validateOutsideMode($geofence, $data);
        if ($outsideValidation) {
            return $outsideValidation;
        }

        $deviceTimezone = $this->validTimezone((string) ($data['device_timezone'] ?? ''));
        $timezone = $this->attendanceTimezone($identity, $geofence, $deviceTimezone);
        $nowUtc = CarbonImmutable::now('UTC');
        $businessDate = $nowUtc->setTimezone($timezone)->toDateString();

        $existing = HrAttendance::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('business_date', $businessDate)
            ->where('record_status', '!=', 'cancelled')
            ->first();
        if ($existing) {
            return ApiResponse::error(
                'Dalam satu tanggal hanya boleh ada satu record absen datang dan pulang.',
                'ATTENDANCE_ALREADY_EXISTS',
                409,
                [],
                ['attendance' => $this->attendancePayload($existing)]
            );
        }

        try {
            $photoPath = $this->photoService->storeBase64($data['photo_base64'] ?? null, 'attendance_in');
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'ATTENDANCE_PHOTO_INVALID', 422);
        }

        $exceptions = $this->buildExceptions(
            (bool) $geofence['inside_radius'],
            (string) ($data['outside_mode'] ?? 'normal'),
            $photoPath
        );
        $requiresApproval = $exceptions !== [];
        $outlet = $geofence['outlet'];
        $assignmentOutlet = $identity['assignment_outlet'];

        try {
            $attendance = DB::transaction(function () use (
                $request,
                $identity,
                $assignmentOutlet,
                $businessDate,
                $timezone,
                $deviceTimezone,
                $nowUtc,
                $data,
                $geofence,
                $outlet,
                $photoPath,
                $exceptions,
                $requiresApproval
            ): HrAttendance {
                return HrAttendance::query()->create([
                    'user_id' => (string) $request->user()->id,
                    'squad_id' => $identity['squad']->id ?? null,
                    'employee_id' => $identity['employee']?->id,
                    'assignment_outlet_id' => $assignmentOutlet?->id,
                    'business_date' => $businessDate,
                    'attendance_timezone' => $timezone,
                    'device_timezone' => $deviceTimezone,
                    'assignment_timezone' => $this->validTimezone((string) ($assignmentOutlet?->timezone ?? '')),
                    'record_status' => 'open',
                    'checkin_at' => $nowUtc->format('Y-m-d H:i:s'),
                    'checkin_outlet_id' => $outlet?->id,
                    'checkin_outlet_name' => $outlet?->name,
                    'checkin_outlet_timezone' => $this->validTimezone((string) ($outlet?->timezone ?? '')),
                    'checkin_lat' => $data['latitude'],
                    'checkin_lng' => $data['longitude'],
                    'checkin_accuracy_m' => $data['accuracy_m'] ?? null,
                    'checkin_distance_m' => $geofence['distance_m'],
                    'checkin_radius_m' => $geofence['radius_m'],
                    'checkin_radius_delta_m' => $geofence['radius_delta_m'],
                    'checkin_inside_radius' => (bool) $geofence['inside_radius'],
                    'checkin_location_status' => $geofence['inside_radius'] ? 'inside' : 'outside',
                    'checkin_mode' => $geofence['inside_radius'] ? 'normal' : (string) $data['outside_mode'],
                    'checkin_note' => $data['note'] ?? null,
                    'duty_location' => $data['duty_location'] ?? null,
                    'checkin_photo_path' => $photoPath,
                    'checkin_camera_status' => (string) $data['camera_status'],
                    'checkin_camera_note' => $data['camera_note'] ?? null,
                    'approval_required' => $requiresApproval,
                    'approval_status' => $requiresApproval ? 'pending' : 'not_required',
                    'calculation_eligible' => ! $requiresApproval,
                    'exception_flags' => $exceptions,
                    'device_info' => $data['device_info'] ?? null,
                    'source' => 'attendance-portal',
                ]);
            });
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'hr_att_user_date_uq')) {
                return ApiResponse::error('Anda sudah memiliki record absensi pada tanggal tersebut.', 'ATTENDANCE_ALREADY_EXISTS', 409);
            }
            throw $e;
        } catch (\Throwable $e) {
            Log::error('HR attendance check-in failed', ['user_id' => $request->user()->id, 'error' => $e->getMessage()]);
            return ApiResponse::error('Gagal menyimpan Absen Datang.', 'ATTENDANCE_CHECKIN_FAILED', 500);
        }

        $attendance = $this->calculationService->recalculate($attendance);

        return ApiResponse::ok([
            'attendance' => $this->attendancePayload($attendance),
            'approval_required' => $requiresApproval,
        ], $requiresApproval
            ? 'Absen Datang tersimpan dan sudah dapat diproses approval karena terdapat exception.'
            : 'Absen Datang berhasil disimpan.', 201);
    }

    public function checkOut(Request $request)
    {
        $validated = $this->validateAttendanceRequest($request);
        if ($validated['response']) {
            return $validated['response'];
        }
        $data = $validated['data'];

        $attendance = $this->pendingCheckout((string) $request->user()->id);
        if (! $attendance) {
            return ApiResponse::error('Tidak ada Absen Datang yang masih menunggu Absen Pulang.', 'ATTENDANCE_NO_PENDING_CHECKOUT', 404);
        }

        $identity = $this->identityService->resolve($request->user());
        $geofence = $this->resolveAttendanceGeofence($identity, (float) $data['latitude'], (float) $data['longitude']);
        if (! $geofence['outlet']) {
            $message = $this->strictAssignmentGeofence($identity)
                ? 'Outlet penugasan belum memiliki koordinat/radius yang valid. Hubungi HR/Admin.'
                : 'Belum ada outlet aktif yang memiliki konfigurasi geofence.';
            return ApiResponse::error($message, 'ATTENDANCE_GEOFENCE_NOT_CONFIGURED', 422);
        }

        $outsideValidation = $this->validateOutsideMode($geofence, $data);
        if ($outsideValidation) {
            return $outsideValidation;
        }

        try {
            $photoPath = $this->photoService->storeBase64($data['photo_base64'] ?? null, 'attendance_out');
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'ATTENDANCE_PHOTO_INVALID', 422);
        }

        $deviceTimezone = $this->validTimezone((string) ($data['device_timezone'] ?? ''));
        $checkoutTimezone = $this->attendanceTimezone($identity, $geofence, $deviceTimezone);
        $nowUtc = CarbonImmutable::now('UTC');
        $outlet = $geofence['outlet'];

        // Iterasi 21: approval belongs to Absen Datang. Checkout exception remains an
        // audit signal only and must never create/reset the two-stage approval workflow.
        $checkoutExceptions = $this->buildExceptions(
            (bool) $geofence['inside_radius'],
            (string) ($data['outside_mode'] ?? 'normal'),
            $photoPath,
            'checkout_'
        );
        $requiresApproval = (bool) $attendance->approval_required;
        $manualBefore = (string) $attendance->source === 'manual' ? [
            'record_status' => (string) $attendance->record_status,
            'checkin_at' => $attendance->getRawOriginal('checkin_at'),
            'checkout_at' => $attendance->getRawOriginal('checkout_at'),
            'source' => (string) $attendance->source,
        ] : null;

        try {
            DB::transaction(function () use (
                $attendance,
                $data,
                $geofence,
                $outlet,
                $photoPath,
                $checkoutTimezone,
                $nowUtc,
                $checkoutExceptions
            ): void {
                $attendance->forceFill([
                    'checkout_at' => $nowUtc->format('Y-m-d H:i:s'),
                    'checkout_business_date' => $nowUtc->setTimezone($checkoutTimezone)->toDateString(),
                    'checkout_timezone' => $checkoutTimezone,
                    'checkout_outlet_id' => $outlet?->id,
                    'checkout_outlet_name' => $outlet?->name,
                    'checkout_outlet_timezone' => $this->validTimezone((string) ($outlet?->timezone ?? '')),
                    'checkout_lat' => $data['latitude'],
                    'checkout_lng' => $data['longitude'],
                    'checkout_accuracy_m' => $data['accuracy_m'] ?? null,
                    'checkout_distance_m' => $geofence['distance_m'],
                    'checkout_radius_m' => $geofence['radius_m'],
                    'checkout_radius_delta_m' => $geofence['radius_delta_m'],
                    'checkout_inside_radius' => (bool) $geofence['inside_radius'],
                    'checkout_location_status' => $geofence['inside_radius'] ? 'inside' : 'outside',
                    'checkout_mode' => $geofence['inside_radius'] ? 'normal' : (string) $data['outside_mode'],
                    'checkout_note' => $data['note'] ?? null,
                    'checkout_duty_location' => $data['duty_location'] ?? null,
                    'checkout_photo_path' => $photoPath,
                    'checkout_camera_status' => (string) $data['camera_status'],
                    'checkout_camera_note' => $data['camera_note'] ?? null,
                    'record_status' => 'complete',
                    'checkout_exception_flags' => $checkoutExceptions,
                    // approval_required/status/calculation_eligible/exception_flags are
                    // intentionally preserved from check-in.
                    'device_info' => $data['device_info'] ?? $attendance->device_info,
                ])->save();
            });
        } catch (\Throwable $e) {
            Log::error('HR attendance check-out failed', ['attendance_id' => $attendance->id, 'error' => $e->getMessage()]);
            return ApiResponse::error('Gagal menyimpan Absen Pulang.', 'ATTENDANCE_CHECKOUT_FAILED', 500);
        }

        $freshAttendance = $this->calculationService->recalculate($attendance->fresh());
        if ((string) $freshAttendance->source === 'manual') {
            try {
                $this->manualAttendanceService->recordSelfCheckoutContinuation($freshAttendance, $request->user(), $manualBefore);
            } catch (\Throwable $auditError) {
                Log::warning('HR manual attendance continuation audit failed', [
                    'attendance_id' => $freshAttendance->id,
                    'error' => $auditError->getMessage(),
                ]);
            }
        }

        return ApiResponse::ok([
            'attendance' => $this->attendancePayload($freshAttendance),
            'approval_required' => $requiresApproval,
        ], $requiresApproval
            ? 'Absen Pulang berhasil disimpan. Status approval tetap mengikuti Absen Datang.'
            : 'Absen Pulang berhasil disimpan.');
    }

    private function validateAttendanceRequest(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'photo_base64' => ['nullable', 'string'],
            'camera_status' => ['required', 'in:ok,error,unavailable'],
            'camera_note' => ['nullable', 'string', 'max:255'],
            'device_timezone' => ['nullable', 'string', 'max:64'],
            'device_info' => ['nullable', 'string', 'max:2000'],
            'outside_mode' => ['nullable', 'in:outside_radius,field_duty'],
            'note' => ['nullable', 'string', 'max:1000'],
            'duty_location' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return [
                'response' => ApiResponse::error(
                    'Data absensi belum lengkap atau tidak valid.',
                    'ATTENDANCE_VALIDATION_FAILED',
                    422,
                    $validator->errors()->toArray()
                ),
                'data' => null,
            ];
        }

        return ['response' => null, 'data' => $validator->validated()];
    }

    private function validateOutsideMode(array $geofence, array $data)
    {
        if ($geofence['inside_radius']) {
            return null;
        }

        $mode = (string) ($data['outside_mode'] ?? '');
        if (! in_array($mode, ['outside_radius', 'field_duty'], true)) {
            return ApiResponse::error(
                'Anda berada di luar radius. Konfirmasi Absen di Luar Radius atau Dinas Luar terlebih dahulu.',
                'ATTENDANCE_OUTSIDE_CONFIRMATION_REQUIRED',
                422,
                [],
                ['geofence' => $this->geofencePayload($geofence)]
            );
        }

        if (trim((string) ($data['note'] ?? '')) === '') {
            return ApiResponse::error(
                'Keterangan wajib diisi untuk absensi di luar radius.',
                'ATTENDANCE_OUTSIDE_NOTE_REQUIRED',
                422,
                ['note' => ['Keterangan wajib diisi.']]
            );
        }

        if ($mode === 'field_duty' && trim((string) ($data['duty_location'] ?? '')) === '') {
            return ApiResponse::error(
                'Lokasi dinas wajib diisi untuk Dinas Luar.',
                'ATTENDANCE_DUTY_LOCATION_REQUIRED',
                422,
                ['duty_location' => ['Lokasi dinas wajib diisi.']]
            );
        }

        return null;
    }

    private function pendingCheckout(string $userId): ?HrAttendance
    {
        // Deliberately no 1/2-day cut-off. Any unfinished attendance keeps
        // blocking the next check-in until the user completes it.
        return HrAttendance::query()
            ->where('user_id', $userId)
            ->whereNull('checkout_at')
            ->where('record_status', 'open')
            ->orderBy('business_date')
            ->orderBy('created_at')
            ->first();
    }

    private function resolveAttendanceGeofence(array $identity, float $latitude, float $longitude): array
    {
        if ($this->strictAssignmentGeofence($identity)) {
            return $this->geofenceService->resolveAssignedOutlet($identity['assignment_outlet'], $latitude, $longitude);
        }

        return $this->geofenceService->resolve($latitude, $longitude);
    }

    private function strictAssignmentGeofence(array $identity): bool
    {
        $assignmentOutlet = $identity['assignment_outlet'] ?? null;
        return ! empty($identity['squad'])
            && $assignmentOutlet
            && strtolower((string) ($assignmentOutlet->type ?? '')) === 'outlet';
    }

    private function attendanceTimezone(array $identity, array $geofence, ?string $deviceTimezone): string
    {
        if ($geofence['inside_radius'] && $geofence['outlet']) {
            return $this->validTimezone((string) $geofence['outlet']->timezone) ?: 'Asia/Jakarta';
        }

        $assignmentTimezone = $this->validTimezone((string) ($identity['assignment_outlet']?->timezone ?? ''));
        return $assignmentTimezone
            ?: $deviceTimezone
            ?: $this->validTimezone((string) ($geofence['outlet']?->timezone ?? ''))
            ?: 'Asia/Jakarta';
    }

    private function referenceTimezone(array $identity, ?string $deviceTimezone): string
    {
        return $this->validTimezone((string) ($identity['assignment_outlet']?->timezone ?? ''))
            ?: $deviceTimezone
            ?: 'Asia/Jakarta';
    }

    private function validTimezone(string $timezone): ?string
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            return null;
        }

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : null;
    }

    private function buildExceptions(bool $insideRadius, string $mode, ?string $photoPath, string $prefix = ''): array
    {
        $exceptions = [];
        if (! $insideRadius) {
            $exceptions[] = $prefix.($mode === 'field_duty' ? 'field_duty' : 'outside_radius');
        }
        if (! $photoPath) {
            $exceptions[] = $prefix.'missing_photo';
        }
        return $exceptions;
    }

    private function geofencePayload(array $geofence): array
    {
        return [
            'nearest_outlet_id' => $geofence['outlet']?->id,
            'nearest_outlet_name' => $geofence['outlet']?->name,
            'distance_m' => $geofence['distance_m'],
            'radius_m' => $geofence['radius_m'],
            'radius_delta_m' => $geofence['radius_delta_m'],
            'inside_radius' => (bool) $geofence['inside_radius'],
        ];
    }

    private function attendancePayload(HrAttendance $attendance): array
    {
        $tz = $this->validTimezone((string) $attendance->attendance_timezone) ?: 'Asia/Jakarta';
        $checkoutTz = $this->validTimezone((string) $attendance->checkout_timezone) ?: $tz;

        return [
            'id' => (string) $attendance->id,
            'business_date' => $attendance->business_date?->format('Y-m-d') ?? (string) $attendance->business_date,
            'attendance_timezone' => $tz,
            'record_status' => $attendance->record_status,
            'checkin_at' => $this->localTimestamp($attendance->getRawOriginal('checkin_at'), $tz),
            'checkout_at' => $this->localTimestamp($attendance->getRawOriginal('checkout_at'), $checkoutTz),
            'checkout_business_date' => $attendance->checkout_business_date?->format('Y-m-d'),
            'checkin' => [
                'outlet_id' => $attendance->checkin_outlet_id,
                'outlet_name' => $attendance->checkin_outlet_name,
                'latitude' => $attendance->checkin_lat !== null ? (float) $attendance->checkin_lat : null,
                'longitude' => $attendance->checkin_lng !== null ? (float) $attendance->checkin_lng : null,
                'accuracy_m' => $attendance->checkin_accuracy_m !== null ? (float) $attendance->checkin_accuracy_m : null,
                'distance_m' => $attendance->checkin_distance_m,
                'radius_m' => $attendance->checkin_radius_m,
                'radius_delta_m' => $attendance->checkin_radius_delta_m,
                'inside_radius' => (bool) $attendance->checkin_inside_radius,
                'location_status' => $attendance->checkin_location_status,
                'mode' => $attendance->checkin_mode,
                'note' => $attendance->checkin_note,
                'duty_location' => $attendance->duty_location,
                'camera_status' => $attendance->checkin_camera_status,
                'camera_note' => $attendance->checkin_camera_note,
                'photo_path' => $attendance->checkin_photo_path,
                'photo_url' => $attendance->checkin_photo_path ? asset('storage/'.$attendance->checkin_photo_path) : null,
            ],
            'checkout' => [
                'outlet_id' => $attendance->checkout_outlet_id,
                'outlet_name' => $attendance->checkout_outlet_name,
                'latitude' => $attendance->checkout_lat !== null ? (float) $attendance->checkout_lat : null,
                'longitude' => $attendance->checkout_lng !== null ? (float) $attendance->checkout_lng : null,
                'accuracy_m' => $attendance->checkout_accuracy_m !== null ? (float) $attendance->checkout_accuracy_m : null,
                'distance_m' => $attendance->checkout_distance_m,
                'radius_m' => $attendance->checkout_radius_m,
                'radius_delta_m' => $attendance->checkout_radius_delta_m,
                'inside_radius' => $attendance->checkout_inside_radius,
                'location_status' => $attendance->checkout_location_status,
                'mode' => $attendance->checkout_mode,
                'note' => $attendance->checkout_note,
                'duty_location' => $attendance->checkout_duty_location,
                'camera_status' => $attendance->checkout_camera_status,
                'camera_note' => $attendance->checkout_camera_note,
                'photo_path' => $attendance->checkout_photo_path,
                'photo_url' => $attendance->checkout_photo_path ? asset('storage/'.$attendance->checkout_photo_path) : null,
            ],
            'approval_required' => (bool) $attendance->approval_required,
            'approval_status' => $attendance->approval_status,
            'calculation_eligible' => (bool) $attendance->calculation_eligible,
            'shift_schedule_id' => $attendance->shift_schedule_id,
            'late_minutes' => $attendance->late_minutes !== null ? (int) $attendance->late_minutes : null,
            'work_minutes' => $attendance->work_minutes !== null ? (int) $attendance->work_minutes : null,
            'calculation_status' => $attendance->calculation_status,
            'calculated_at' => $attendance->calculated_at?->toIso8601String(),
            'exception_flags' => $attendance->exception_flags ?: [],
            'checkout_exception_flags' => $attendance->checkout_exception_flags ?: [],
        ];
    }

    private function localTimestamp(?string $utcValue, string $timezone): ?string
    {
        if (! $utcValue) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $utcValue, 'UTC')
            ->setTimezone($timezone)
            ->format('Y-m-d H:i:s');
    }

    private function userPayload(array $identity): array
    {
        $user = $identity['user'];
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'nisj' => $identity['squad']->nisj ?? $user->nisj,
            'username' => $identity['squad']->username ?? $user->username,
            'email' => $identity['squad']->email ?? $user->email,
        ];
    }

    private function squadPayload(?object $squad): array
    {
        if (! $squad) {
            return [];
        }

        return [
            'id' => $squad->id,
            'full_name' => $squad->full_name ?? null,
            'nickname' => $squad->nickname ?? null,
            'nisj' => $squad->nisj ?? null,
            'role_name' => $squad->role_name ?? null,
            'assignment' => $squad->assignment ?? null,
            'chamber_name' => $squad->chamber_name ?? null,
            'division_name' => $squad->division_name ?? null,
            'position_name' => $squad->position_name ?? null,
            'employee_type' => $squad->employee_type ?? null,
            'status' => $squad->status ?? null,
            'leave_quota' => $squad->leave_quota ?? null,
            'photo_path' => $squad->photo_path ?? null,
            'birth_place' => $squad->birth_place ?? null,
            'birth_date' => $squad->birth_date ?? null,
            'gender' => $squad->gender ?? null,
            'religion' => $squad->religion ?? null,
            'education' => $squad->education ?? null,
            'marital_status' => $squad->marital_status ?? null,
            'whatsapp' => $squad->whatsapp ?? null,
            'email' => $squad->email ?? null,
            'address' => $squad->address ?? null,
            'ppi_status' => (bool) ($squad->ppi_status ?? false),
            'contract_type' => $squad->contract_type ?? null,
            'contract_start_date' => $squad->contract_start_date ?? null,
            'contract_end_date' => $squad->contract_end_date ?? null,
        ];
    }

    private function assignmentPayload(array $identity): ?array
    {
        $outlet = $identity['assignment_outlet'];
        if (! $outlet) {
            return null;
        }

        return [
            'outlet_id' => (string) $outlet->id,
            'outlet_name' => $outlet->name,
            'outlet_type' => strtolower((string) ($outlet->type ?? 'outlet')),
            'timezone' => $this->validTimezone((string) $outlet->timezone) ?: 'Asia/Jakarta',
            'latitude' => $outlet->latitude !== null ? (float) $outlet->latitude : null,
            'longitude' => $outlet->longitude !== null ? (float) $outlet->longitude : null,
            'radius_m' => $outlet->radius_m !== null ? (int) $outlet->radius_m : null,
            'strict_geofence' => $this->strictAssignmentGeofence($identity),
            'role_title' => $identity['employee']?->assignment?->role_title,
        ];
    }
}
