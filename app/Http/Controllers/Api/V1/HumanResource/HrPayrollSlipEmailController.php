<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\HrPayrollCutoff;
use App\Models\HrPayrollSlip;
use App\Services\HumanResource\HrPayrollSlipEmailService;
use Illuminate\Http\Request;

final class HrPayrollSlipEmailController extends Controller
{
    public function __construct(private readonly HrPayrollSlipEmailService $service) {}

    public function payrollStatuses(HrPayrollCutoff $cutoff)
    {
        return ApiResponse::ok($this->service->payrollStatuses($cutoff));
    }

    public function payrollPdf(HrPayrollCutoff $cutoff, HrPayrollSlip $slip)
    {
        $doc = $this->service->payrollPdf($cutoff, $slip);
        return response($doc['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$doc['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function sendPayroll(Request $request, HrPayrollCutoff $cutoff, HrPayrollSlip $slip)
    {
        $result = $this->service->sendPayroll($cutoff, $slip, $request->user());
        if (($result['success'] ?? false) === true) {
            return ApiResponse::ok($result, 'Slip gaji berhasil dikirim ke email Squad.');
        }

        return ApiResponse::error(
            (string) ($result['message'] ?? 'Gagal mengirim slip melalui SMTP HR.'),
            (string) ($result['error_code'] ?? 'HR_SMTP_DELIVERY_FAILED'),
            422,
            ['email' => [(string) ($result['error_message'] ?? 'Pengiriman SMTP gagal.')]],
            $result,
        );
    }

    public function bonusStatuses(Request $request, string $projectionId)
    {
        return ApiResponse::ok($this->service->bonusStatuses($request, $projectionId));
    }

    public function bonusPdf(Request $request, string $projectionId, string $lineId)
    {
        $doc = $this->service->bonusPdf($request, $projectionId, $lineId);
        return response($doc['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$doc['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function sendBonus(Request $request, string $projectionId, string $lineId)
    {
        $result = $this->service->sendBonus($request, $projectionId, $lineId, $request->user());
        if (($result['success'] ?? false) === true) {
            return ApiResponse::ok($result, 'Slip gaji + bonus berhasil dikirim ke email Squad.');
        }

        return ApiResponse::error(
            (string) ($result['message'] ?? 'Gagal mengirim slip melalui SMTP HR.'),
            (string) ($result['error_code'] ?? 'HR_SMTP_DELIVERY_FAILED'),
            422,
            ['email' => [(string) ($result['error_message'] ?? 'Pengiriman SMTP gagal.')]],
            $result,
        );
    }

    public function diagnostics()
    {
        $result = $this->service->diagnostics();
        if (($result['success'] ?? false) === true) {
            return ApiResponse::ok($result, 'Diagnostic SMTP HR berhasil.');
        }

        return ApiResponse::error(
            (string) ($result['message'] ?? 'Diagnostic SMTP HR gagal.'),
            (string) ($result['error_code'] ?? 'HR_SMTP_DIAGNOSTIC_FAILED'),
            422,
            ['smtp' => [(string) ($result['safe_error'] ?? 'Koneksi SMTP gagal.')]],
            $result,
        );
    }
}
