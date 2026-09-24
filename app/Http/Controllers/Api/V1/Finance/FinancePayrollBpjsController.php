<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinancePayrollPostingService;
use App\Services\HumanResource\HrPayrollCutoffWorkflowService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinancePayrollBpjsController extends Controller
{
    public function __construct(
        private readonly HrPayrollCutoffWorkflowService $workflow,
        private readonly FinancePayrollPostingService $posting,
    ) {}

    public function export(Request $request, string $id)
    {
        $this->assertRecordScope($request, $id);
        return $this->workflow->exportBpjsByPosting($id, $request->user()?->id);
    }

    public function import(Request $request, string $id)
    {
        $this->assertRecordScope($request, $id);
        $request->validate(['file' => ['required','file','mimes:xlsx','max:10240']]);
        return ApiResponse::ok($this->workflow->importBpjsByPosting($id, $request->file('file'), $request->user()), 'Import BPJS selesai.');
    }

    public function report(Request $request, string $id)
    {
        $this->assertRecordScope($request, $id);
        return ApiResponse::ok($this->workflow->latestImportReport($id));
    }

    private function assertRecordScope(Request $request, string $id): void
    {
        $row = $this->posting->show($id);
        $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, false);
        $ids = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        $canAdjust = (bool)$request->attributes->get('outlet_scope_can_adjust', false);
        $corporate = $canAdjust && ! OutletScope::isLocked($request);
        $outletId = $row['outlet_id'] ?? null;
        if ($outletId && ! in_array((string)$outletId, $ids, true)) {
            throw ValidationException::withMessages(['posting' => ['Payroll Posting berada di luar scope outlet Finance user.']]);
        }
        if (! $outletId && ! $corporate) {
            throw ValidationException::withMessages(['posting' => ['User tidak memiliki akses payroll scope PT/corporate.']]);
        }
    }
}
