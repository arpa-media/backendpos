<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\HrPayrollCutoff;
use App\Services\HumanResource\HrPayrollCutoffWorkflowService;
use Illuminate\Http\Request;

class HrPayrollCutoffWorkflowController extends Controller
{
    public function __construct(private readonly HrPayrollCutoffWorkflowService $workflow) {}

    public function submit(Request $request, HrPayrollCutoff $cutoff)
    {
        return ApiResponse::ok($this->workflow->submit($cutoff, $request->user()), 'Cutoff diajukan ke Finance Payroll Posting.');
    }

    public function reopen(Request $request, HrPayrollCutoff $cutoff)
    {
        $data = $request->validate(['reason' => ['required','string','min:5','max:1000']]);
        return ApiResponse::ok($this->workflow->reopen($cutoff, $request->user(), $data['reason']), 'Cutoff dibatalkan dan kembali ke Draft.');
    }

    public function exportAdjustments(HrPayrollCutoff $cutoff)
    {
        return $this->workflow->exportAdjustments($cutoff);
    }

    public function importAdjustments(Request $request, HrPayrollCutoff $cutoff)
    {
        $request->validate(['file' => ['required','file','mimes:xlsx','max:10240']]);
        return ApiResponse::ok($this->workflow->importAdjustments($cutoff, $request->file('file'), $request->user()), 'Import adjustment selesai.');
    }
}
