<?php

namespace App\Http\Requests\Api\V1\Dashboard;

use App\Support\SaleStatuses;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // enforced by middleware permission:dashboard.view
        return true;
    }

    public function rules(): array
    {
        return [
            // range (YYYY-MM-DD). default today if null
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],

            // include only PAID by default; optionally allow request
            'status' => ['nullable', 'string', Rule::in(SaleStatuses::ALL)],

            // recent sales pagination
            'recent_limit' => ['nullable', 'integer', 'min:1', 'max:50'],

            // breakdown pagination (By Channel, By Payment Method, Top Items)
            'breakdown_per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
            'by_channel_page' => ['nullable', 'integer', 'min:1'],
            'by_payment_page' => ['nullable', 'integer', 'min:1'],
            'top_items_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
