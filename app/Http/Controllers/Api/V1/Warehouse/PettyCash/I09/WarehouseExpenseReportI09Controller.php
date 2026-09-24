<?php

namespace App\Http\Controllers\Api\V1\Warehouse\PettyCash\I09;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\PettyCash\I09\WarehouseExpenseReportI09Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WarehouseExpenseReportI09Controller extends Controller
{
    public function __construct(private readonly WarehouseExpenseReportI09Service $service) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->index($this->warehouse($request), $this->filters($request, true)));
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request, false);
        $rows = $this->service->exportRows($this->warehouse($request), $filters);
        $name = 'warehouse-expense-report-'.now('Asia/Jakarta')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Tanggal','Petty Cash','Status','Kategori','Item/Kebutuhan','Qty','UOM','Harga Satuan','Subtotal','Tax','Total','Finance Posting','Finance Status','Dibuat Oleh','Catatan']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['request_date'],$row['petty_cash_number'],$row['petty_cash_status'],$row['category'],$row['item_name'],
                    $row['qty'],$row['uom'],$row['unit_price'],$row['subtotal'],$row['tax_amount'],$row['line_total'],
                    $row['finance_posting_no'],$row['finance_posting_status'],$row['created_by_name'],$row['notes'],
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request, bool $withPaging): array
    {
        $rules = [
            'q' => 'nullable|string|max:120',
            'status' => 'nullable|in:DRAFT,AWAITING_APPROVAL,APPROVED,REJECTED,COMPLETED',
            'finance_status' => 'nullable|string|max:40',
            'category' => 'nullable|string|max:60',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ];
        if ($withPaging) {
            $rules['per_page'] = 'nullable|integer|in:10,25,50,100';
            $rules['page'] = 'nullable|integer|min:1';
        }
        return $request->validate($rules);
    }

    private function warehouse(Request $request): string
    {
        $id = trim((string)$request->attributes->get('warehouse_scope_id', ''));
        abort_if($id === '', 422, 'Pilih Warehouse terlebih dahulu.');
        return $id;
    }
}
