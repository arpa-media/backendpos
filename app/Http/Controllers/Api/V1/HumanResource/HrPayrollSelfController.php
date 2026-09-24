<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrPayrollService;
use Illuminate\Http\Request;

class HrPayrollSelfController extends Controller
{
    public function __construct(private readonly HrPayrollService $payroll) {}
    public function index(Request $request) { return ApiResponse::ok($this->payroll->selfSlips($request->user())); }
    public function show(Request $request, string $id) { return ApiResponse::ok($this->payroll->selfSlip($request->user(), $id)); }
}
