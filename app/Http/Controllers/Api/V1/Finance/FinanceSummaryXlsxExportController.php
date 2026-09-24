<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinanceSummaryXlsxExportService;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class FinanceSummaryXlsxExportController extends Controller
{
    public function __construct(private readonly FinanceSummaryXlsxExportService $service) {}

    public function store(Request $request)
    {
        $payload = $request->validate([
            'filename' => ['nullable', 'string', 'max:180'],
            'sheet_name' => ['nullable', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:120'],
            'freeze_row' => ['nullable', 'integer', 'min:0', 'max:100'],
            'rows' => ['required', 'array', 'max:100000'],
        ]);

        $user = $request->user();
        $actor = (string) ($user?->full_name ?? $user?->name ?? $user?->email ?? $user?->id ?? 'POS Finance');
        try {
            $export = $this->service->build($payload, $actor);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'FINANCE_SUMMARY_XLSX_EXPORT_FAILED', 422);
        }

        return response()->download(
            $export['path'],
            $export['filename'],
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
                'X-Export-Row-Count' => (string) $export['row_count'],
            ]
        )->deleteFileAfterSend(true);
    }
}
