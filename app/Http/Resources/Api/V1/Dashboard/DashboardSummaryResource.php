<?php

namespace App\Http\Resources\Api\V1\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardSummaryResource extends JsonResource
{
    /**
     * @param Request $request
     */
    public function toArray($request): array
    {
        $d = $this->resource;

        return [
            'range' => $d['range'] ?? [],
            'metrics' => $d['metrics'] ?? [],
            'by_channel' => $d['by_channel'] ?? [],
            'by_channel_meta' => $d['by_channel_meta'] ?? [],
            'by_payment_method' => $d['by_payment_method'] ?? [],
            'by_payment_method_meta' => $d['by_payment_method_meta'] ?? [],
            'top_items' => $d['top_items'] ?? [],
            'top_items_meta' => $d['top_items_meta'] ?? [],
            'recent_sales' => $d['recent_sales'] ?? [],
        ];
    }
}
