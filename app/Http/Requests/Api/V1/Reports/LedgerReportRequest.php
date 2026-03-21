<?php

namespace App\Http\Requests\Api\V1\Reports;

class LedgerReportRequest extends ReportRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'payment_method_name' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'string', 'max:50'],
        ]);
    }
}
