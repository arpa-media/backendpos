<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrAttendanceApprovalService;
use App\Services\HumanResource\HrAttendanceBackofficeScopeService;
use App\Services\HumanResource\HrAttendancePresentationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HrAttendanceApprovalController extends Controller
{
    public function __construct(
        private readonly HrAttendanceApprovalService $approvalService,
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrAttendancePresentationService $presentation,
    ) {}

    public function absenceOptions(Request $request) { return $this->options($request, HrAttendanceApprovalService::TYPE_ABSENCE); }
    public function dutyOptions(Request $request) { return $this->options($request, HrAttendanceApprovalService::TYPE_DUTY); }
    public function absenceIndex(Request $request) { return $this->indexByType($request, HrAttendanceApprovalService::TYPE_ABSENCE); }
    public function dutyIndex(Request $request) { return $this->indexByType($request, HrAttendanceApprovalService::TYPE_DUTY); }

    public function absenceSpv(Request $request, string $attendanceId) { return $this->decision($request, $attendanceId, HrAttendanceApprovalService::TYPE_ABSENCE, 'spv'); }
    public function dutySpv(Request $request, string $attendanceId) { return $this->decision($request, $attendanceId, HrAttendanceApprovalService::TYPE_DUTY, 'spv'); }
    public function absenceHrd(Request $request, string $attendanceId) { return $this->decision($request, $attendanceId, HrAttendanceApprovalService::TYPE_ABSENCE, 'hrd'); }
    public function dutyHrd(Request $request, string $attendanceId) { return $this->decision($request, $attendanceId, HrAttendanceApprovalService::TYPE_DUTY, 'hrd'); }
    public function absenceOverrideSpv(Request $request, string $attendanceId) { return $this->decision($request, $attendanceId, HrAttendanceApprovalService::TYPE_ABSENCE, 'override_spv'); }
    public function dutyOverrideSpv(Request $request, string $attendanceId) { return $this->decision($request, $attendanceId, HrAttendanceApprovalService::TYPE_DUTY, 'override_spv'); }

    private function options(Request $request, string $type)
    {
        return ApiResponse::ok([
            'outlets' => $this->scope->options($request),
            'capabilities' => $this->approvalService->capabilities($request, $type),
            'final_statuses' => ['pending', 'approved', 'rejected'],
            'stages' => ['all', 'spv', 'hrd', 'completed'],
            'per_page_options' => [10, 25, 50, 100],
        ], 'OK');
    }

    private function indexByType(Request $request, string $type)
    {
        if (! Schema::hasTable('HR_attendance_approvals')) {
            return ApiResponse::ok($this->emptyResult($request, $type), 'Tabel approval Iterasi 04 belum tersedia.');
        }
        $this->approvalService->syncMissing();

        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outlet_id' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:180'],
            'final_status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'stage' => ['nullable', Rule::in(['all', 'spv', 'hrd', 'completed'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', Rule::in([10, 25, 50, 100])],
            'sort_by' => ['nullable', Rule::in(['business_date', 'name', 'nisj', 'outlet', 'spv_status', 'hrd_status', 'final_status', 'updated_at'])],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Filter Approval tidak valid.', 'HR_ATTENDANCE_APPROVAL_FILTER_INVALID', 422, $validator->errors()->toArray());
        }
        $data = $validator->validated();

        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $from = $data['from'] ?? CarbonImmutable::now('Asia/Jakarta')->subDays(30)->toDateString();
        $to = $data['to'] ?? $today;
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 180) {
            return ApiResponse::error('Rentang approval maksimum 181 hari.', 'HR_ATTENDANCE_APPROVAL_RANGE_TOO_WIDE', 422);
        }

        $query = $this->approvalQuery()->where('ap.approval_type', $type);
        $this->scope->applyAttendanceScope($query, $request, $data['outlet_id'] ?? null);
        $query->whereBetween('a.business_date', [$from, $to])->where('a.record_status', '!=', 'cancelled');

        if (! empty($data['search'])) {
            $search = '%'.trim($data['search']).'%';
            $query->where(function ($q) use ($search) {
                $q->where('e.full_name', 'like', $search)
                    ->orWhere('e.nisj', 'like', $search)
                    ->orWhere('u.name', 'like', $search)
                    ->orWhere('u.nisj', 'like', $search)
                    ->orWhere('a.checkin_note', 'like', $search)
                    ->orWhere('a.checkout_note', 'like', $search)
                    ->orWhere('a.duty_location', 'like', $search)
                    ->orWhere('a.checkout_duty_location', 'like', $search);
            });
        }
        if (! empty($data['final_status'])) $query->where('ap.final_status', $data['final_status']);

        $stage = $data['stage'] ?? 'all';
        if ($stage === 'spv') $query->where('ap.spv_status', 'pending');
        if ($stage === 'hrd') $query->where('ap.spv_status', 'approved')->where('ap.hrd_status', 'pending');
        if ($stage === 'completed') $query->whereIn('ap.final_status', ['approved', 'rejected']);

        $sortBy = $data['sort_by'] ?? 'business_date';
        $sortDir = $data['sort_dir'] ?? 'desc';
        $sortMap = [
            'business_date' => 'a.business_date',
            'name' => DB::raw("COALESCE(e.full_name, u.name, '')"),
            'nisj' => DB::raw("COALESCE(e.nisj, u.nisj, '')"),
            'outlet' => DB::raw("COALESCE(scope_o.name, checkin_o.name, '')"),
            'spv_status' => 'ap.spv_status',
            'hrd_status' => 'ap.hrd_status',
            'final_status' => 'ap.final_status',
            'updated_at' => 'ap.updated_at',
        ];
        $query->orderBy($sortMap[$sortBy], $sortDir)->orderBy('a.id', $sortDir);

        $paginator = $query->paginate((int) ($data['per_page'] ?? 25));
        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn ($row) => $this->presentation->row($row))->values(),
            'pagination' => [
                'page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(), 'to' => $paginator->lastItem(),
            ],
            'capabilities' => $this->approvalService->capabilities($request, $type),
            'filters' => array_merge($data, ['from' => $from, 'to' => $to]),
        ], 'OK');
    }

    private function decision(Request $request, string $attendanceId, string $type, string $stage)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Keputusan approval tidak valid.', 'HR_ATTENDANCE_APPROVAL_DECISION_INVALID', 422, $validator->errors()->toArray());
        }

        $scopeRow = DB::table('HR_attendances as a')
            ->where('a.id', $attendanceId)
            ->first(['a.id', DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id) as scope_outlet_id')]);
        if (! $scopeRow || ! $scopeRow->scope_outlet_id || ! $this->scope->isOutletAllowed($request, (string) $scopeRow->scope_outlet_id)) {
            return ApiResponse::error('Attendance berada di luar scope outlet user.', 'HR_ATTENDANCE_APPROVAL_OUTLET_FORBIDDEN', 403);
        }

        try {
            $status = (string) $validator->validated()['status'];
            $note = $validator->validated()['note'] ?? null;
            $approval = match ($stage) {
                'spv' => $this->approvalService->spvDecision($attendanceId, $type, $status, $note, $request->user()),
                'hrd' => $this->approvalService->hrdDecision($attendanceId, $type, $status, $note, $request->user()),
                'override_spv' => $this->approvalService->overrideSpv($attendanceId, $type, $status, $note, $request->user()),
                default => throw ValidationException::withMessages(['stage' => 'Stage approval tidak dikenal.']),
            };
        } catch (ValidationException $e) {
            return ApiResponse::error('Approval tidak dapat diproses.', 'HR_ATTENDANCE_APPROVAL_WORKFLOW_INVALID', 422, $e->errors());
        }

        return ApiResponse::ok([
            'attendance_id' => $attendanceId,
            'approval' => [
                'id' => (string) $approval->id,
                'type' => (string) $approval->approval_type,
                'spv_status' => (string) $approval->spv_status,
                'hrd_status' => (string) $approval->hrd_status,
                'final_status' => (string) $approval->final_status,
            ],
        ], 'Keputusan approval berhasil disimpan.');
    }

    private function approvalQuery()
    {
        return DB::table('HR_attendance_approvals as ap')
            ->join('HR_attendances as a', 'a.id', '=', 'ap.attendance_id')
            ->leftJoin('employees as e', 'e.id', '=', 'a.employee_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('outlets as scope_o', 'scope_o.id', '=', DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)'))
            ->leftJoin('outlets as checkin_o', 'checkin_o.id', '=', 'a.checkin_outlet_id')
            ->leftJoin('users as spv_u', 'spv_u.id', '=', 'ap.spv_user_id')
            ->leftJoin('users as hrd_u', 'hrd_u.id', '=', 'ap.hrd_user_id')
            ->select([
                'a.*',
                'e.full_name as employee_name', 'e.nisj as employee_nisj',
                'u.name as user_name', 'u.nisj as user_nisj',
                DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id) as scope_outlet_id'),
                'scope_o.code as scope_outlet_code', 'scope_o.name as scope_outlet_name',
                'ap.id as approval_id', 'ap.approval_type', 'ap.spv_status', 'ap.spv_user_id', 'ap.spv_decided_at', 'ap.spv_note',
                'ap.hrd_status', 'ap.hrd_user_id', 'ap.hrd_decided_at', 'ap.hrd_note', 'ap.final_status',
                'spv_u.name as spv_user_name', 'hrd_u.name as hrd_user_name',
            ]);
    }

    private function emptyResult(Request $request, string $type): array
    {
        return [
            'items' => [],
            'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1, 'from' => null, 'to' => null],
            'capabilities' => $this->approvalService->capabilities($request, $type),
        ];
    }
}
