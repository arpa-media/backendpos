<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class HrAssignmentController extends Controller
{
    public function __construct(private readonly HrAssignmentService $service) {}

    public function options(Request $request)
    {
        return ApiResponse::ok($this->service->options($request), 'OK');
    }

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->index($request), 'OK');
    }

    public function timeline(Request $request, string $employeeId)
    {
        return ApiResponse::ok($this->service->timeline($request, $employeeId), 'OK');
    }

    public function store(Request $request, string $employeeId)
    {
        $validator = $this->assignmentValidator($request);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi assignment gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }
        return ApiResponse::ok(
            $this->service->createCurrent($request, $employeeId, $validator->validated()),
            'Assignment berhasil dibuat.',
            201,
        );
    }

    public function update(Request $request, string $employeeId)
    {
        $validator = $this->assignmentValidator($request);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi assignment gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }
        return ApiResponse::ok(
            $this->service->updateCurrent($request, $employeeId, $validator->validated()),
            'Assignment berhasil diperbarui dan history otomatis direkam.',
        );
    }

    public function storeHistory(Request $request, string $employeeId)
    {
        $validator = Validator::make($request->all(), [
            'outlet_id' => ['required', 'string', 'exists:outlets,id'],
            'role_title' => ['required', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'string', 'max:40'],
            'changed_at' => ['nullable', 'date'],
            'note' => ['required', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Validasi history penugasan gagal.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        }
        return ApiResponse::ok(
            $this->service->addManualHistory($request, $employeeId, $validator->validated()),
            'History penugasan berhasil ditambahkan.',
            201,
        );
    }

    private function assignmentValidator(Request $request)
    {
        $today = Carbon::now()->toDateString();
        return Validator::make($request->all(), [
            'outlet_id' => ['required', 'string', 'exists:outlets,id'],
            'role_title' => ['required', 'string', 'max:150'],
            'effective_date' => ['required', 'date', 'before_or_equal:'.$today],
            'end_date' => ['nullable', 'date', 'after_or_equal:'.$today, 'after_or_equal:effective_date'],
        ], [
            'effective_date.before_or_equal' => 'Tanggal berlaku assignment tidak boleh di masa depan pada Iterasi 06.',
            'end_date.after_or_equal' => 'Tanggal selesai assignment aktif tidak boleh sebelum hari ini / tanggal berlaku.',
        ]);
    }
}
