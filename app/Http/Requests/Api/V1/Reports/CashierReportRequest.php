<?php

namespace App\Http\Requests\Api\V1\Reports;

class CashierReportRequest extends ReportRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'cashier_id' => ['nullable', 'string'],
        ]);
    }
}
