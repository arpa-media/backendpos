<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrOvertimeI06Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HrOvertimeBackofficeI06Controller extends Controller
{
    public function __construct(private readonly HrOvertimeI06Service $service) {}

    public function options(Request $request)
    {
        return ApiResponse::ok($this->service->options($request));
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outlet_id' => ['nullable', 'string', 'max:26'], 'name' => ['nullable', 'string', 'max:180'],
            'nisj' => ['nullable', 'string', 'max:80'], 'source' => ['nullable', 'in:self-service,manual_hr'],
            'status' => ['nullable', 'in:open,completed,cancelled'], 'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'], 'sort_by' => ['nullable', 'string', 'max:60'], 'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);
        if ($validator->fails()) return ApiResponse::error('Filter Data Lembur tidak valid.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        return ApiResponse::ok($this->service->index($request));
    }

    public function manual(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => ['required', 'string', 'max:26'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'outlet_id' => ['nullable', 'string', 'max:26'],
            'start_local' => ['required', 'date'],
            'end_local' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) return ApiResponse::error('Input Lembur Manual tidak valid.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        return ApiResponse::ok($this->service->manualUpsert($request, $validator->validated()), 'Lembur manual tersimpan.');
    }
}
