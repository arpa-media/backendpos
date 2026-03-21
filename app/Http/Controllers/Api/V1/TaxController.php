<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tax\IndexTaxRequest;
use App\Http\Requests\Api\V1\Tax\StoreTaxRequest;
use App\Http\Requests\Api\V1\Tax\UpdateTaxRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Tax\TaxResource;
use App\Models\Tax;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxController extends Controller
{
    public function __construct(private readonly TaxService $taxService)
    {
    }

    public function index(IndexTaxRequest $request)
    {
        $q = Tax::query();

        if ($request->filled('q')) {
            $kw = trim((string) $request->input('q'));
            $q->where(function ($w) use ($kw) {
                $w->where('jenis_pajak', 'like', "%{$kw}%")
                    ->orWhere('display_name', 'like', "%{$kw}%");
            });
        }

        if ($request->has('is_active')) {
            $q->where('is_active', (bool) $request->boolean('is_active'));
        }

        if ($request->has('is_default')) {
            $q->where('is_default', (bool) $request->boolean('is_default'));
        }

        $q->orderByRaw('COALESCE(sort_order, 999999) ASC')
            ->orderBy('display_name');

        $perPage = (int) ($request->input('per_page') ?? 15);
        $taxes = $q->paginate($perPage);

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
        $tax = new Tax();
        $tax->fill($request->validated());
        $tax->save();

        $tax = $this->taxService->enforceDefaultInvariant($tax);

        return ApiResponse::ok(new TaxResource($tax), 'Created', 201);
    }

    public function show(string $id)
    {
        $tax = Tax::query()->findOrFail($id);
        return ApiResponse::ok(new TaxResource($tax));
    }

    public function update(UpdateTaxRequest $request, string $id)
    {
        $tax = Tax::query()->findOrFail($id);

        $validated = $request->validated();

        if (array_key_exists('jenis_pajak', $validated)) {
            $request->validate([
                'jenis_pajak' => [
                    'string',
                    'max:80',
                    Rule::unique('taxes', 'jenis_pajak')->ignore($tax->id),
                ],
            ]);
        }

        $tax->fill($validated);
        $tax->save();

        $tax = $this->taxService->enforceDefaultInvariant($tax);

        return ApiResponse::ok(new TaxResource($tax), 'Updated');
    }

    public function destroy(string $id)
    {
        $tax = Tax::query()->findOrFail($id);
        $tax->delete();
        return ApiResponse::ok(null, 'Deleted');
    }

    public function setDefault(Request $request, string $id)
    {
        $tax = Tax::query()->findOrFail($id);
        $tax = $this->taxService->setDefault($tax);
        return ApiResponse::ok(new TaxResource($tax), 'Default updated');
    }

    public function default()
    {
        $tax = $this->taxService->getActiveDefault();
        return ApiResponse::ok($tax ? new TaxResource($tax) : null);
    }
}
