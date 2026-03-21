<?php

namespace App\Http\Requests\Api\V1\Reports;

class TaxReportRequest extends ReportRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'sale_number' => ['nullable', 'string', 'max:50'],
            'payment_method_name' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
