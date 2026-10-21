<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Cogs\V13\VarianceAllocationI26Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class VarianceAllocationV13I26Controller extends CogsBaseController
{
    public function __construct(private readonly VarianceAllocationI26Service $allocator)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $validated = $request->validate([
            'variance_id' => ['required', 'ulid', Rule::exists('cogs_stock_variances', 'id')],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        return ApiResponse::ok($this->allocator->report(
            $validated['variance_id'], $outletId,
            (int) ($validated['page'] ?? 1), (int) ($validated['per_page'] ?? 25),
        ));
    }
}
