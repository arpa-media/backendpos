<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tax\IndexTaxRequest;
use App\Http\Requests\Api\V1\Tax\StoreTaxRequest;
use App\Http\Requests\Api\V1\Tax\UpdateTaxRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Tax\TaxResource;
use App\Models\Outlet;
use App\Models\Tax;
use App\Services\TaxService;
use App\Support\OutletScope;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    public function __construct(private readonly TaxService $taxService)
    {
    }

    private function resolveOutletId(Request $request): ?string
    {
        $outletId = OutletScope::id($request);
        if ($outletId) {
            return $outletId;
        }

        if (OutletScope::isLocked($request)) {
            return null;
        }

        $candidate = $request->input('outlet_id') ?? $request->query('outlet_id');
        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        $candidate = trim($candidate);
        if (! Outlet::query()->whereKey($candidate)->exists()) {
            return null;
        }

        return $candidate;
    }

    private function findTax(string $id): ?Tax
    {
        return Tax::query()->whereKey($id)->first();
    }

    public function index(IndexTaxRequest $request)
    {
        $outletId = $this->resolveOutletId($request);
        if (! $outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $taxes = $this->taxService->paginateForOutlet((string) $outletId, $request->validated());

        return ApiResponse::ok([
            'items' => TaxResource::collection($taxes->items()),
            'pagination' => [
                'total' => $taxes->total(),
                'per_page' => $taxes->perPage(),
                'current_page' => $taxes->currentPage(),
                'last_page' => $taxes->lastPage(),
            ],
        ]);
    }

    public function store(StoreTaxRequest $request)
    {
        $outletId = $this->resolveOutletId($request);
        if (! $outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $tax = $this->taxService->create((string) $outletId, $request->validated());

        return ApiResponse::ok(new TaxResource($tax), 'Created', 201);
    }

    public function show(Request $request, string $id)
    {
        $outletId = $this->resolveOutletId($request);
        if (! $outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $tax = $this->findTax($id);
        if (! $tax) {
            return ApiResponse::error('Tax not found', 'NOT_FOUND', 404);
        }

        $this->taxService->ensureOutletPivotCoverage((string) $outletId, $tax);

        return ApiResponse::ok(new TaxResource($tax->load(['outlets' => function ($query) use ($outletId) {
            $query->where('outlets.id', (string) $outletId);
        }])));
    }

    public function update(UpdateTaxRequest $request, string $id)
    {
        $outletId = $this->resolveOutletId($request);
        if (! $outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $tax = $this->findTax($id);
        if (! $tax) {
            return ApiResponse::error('Tax not found', 'NOT_FOUND', 404);
        }

        $tax = $this->taxService->update((string) $outletId, $tax, $request->validated());

        return ApiResponse::ok(new TaxResource($tax), 'Updated');
    }

    public function updateStatus(Request $request, string $id)
    {
        $outletId = $this->resolveOutletId($request);
        if (! $outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $tax = $this->findTax($id);
        if (! $tax) {
            return ApiResponse::error('Tax not found', 'NOT_FOUND', 404);
        }

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $tax = $this->taxService->setActiveForOutlet((string) $outletId, $tax, (bool) $validated['is_active']);

        return ApiResponse::ok(new TaxResource($tax), 'Status updated');
    }

    public function destroy(Request $request, string $id)
    {
        $outletId = $this->resolveOutletId($request);
        if (! $outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $tax = $this->findTax($id);
        if (! $tax) {
            return ApiResponse::error('Tax not found', 'NOT_FOUND', 404);
        }

        $tax->delete();
        $this->taxService->ensureFallbackDefault((string) $outletId);

        return ApiResponse::ok(null, 'Deleted');
    }

    public function setDefault(Request $request, string $id)
    {
        $outletId = $this->resolveOutletId($request);
        if (! $outletId) {
            return ApiResponse::error('Please select an outlet', 'OUTLET_REQUIRED', 422);
        }

        $tax = $this->findTax($id);
        if (! $tax) {
            return ApiResponse::error('Tax not found', 'NOT_FOUND', 404);
        }

        $tax = $this->taxService->setDefaultForOutlet((string) $outletId, $tax);
        return ApiResponse::ok(new TaxResource($tax), 'Default updated');
    }

    public function default(Request $request)
    {
        $outletId = $this->resolveOutletId($request);
        if (! $outletId) {
            return ApiResponse::ok(null);
        }

        $tax = $this->taxService->getActiveDefaultForOutlet((string) $outletId);
        return ApiResponse::ok($tax ? new TaxResource($tax) : null);
    }
}
