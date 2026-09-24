<?php

namespace App\Http\Controllers\Api\V1\Warehouse\FinanceV4;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\FinanceV4\WarehouseFinanceFoundationV4Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class WarehouseFinanceFoundationV4Controller extends Controller
{
    public function __construct(private readonly WarehouseFinanceFoundationV4Service $service)
    {
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->options($this->allowedWarehouseIds($request))]);
    }

    public function coaIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:120',
            'active_only' => 'nullable|boolean',
        ]);

        return response()->json(['data' => $this->service->coaList($filters)]);
    }

    public function coaStore(Request $request): JsonResponse
    {
        $data = $this->coaPayload($request);

        return response()->json([
            'data' => $this->service->createCoa($data, (string) $request->user()->id),
        ], 201);
    }

    public function coaUpdate(Request $request, string $id): JsonResponse
    {
        $data = $this->coaPayload($request);

        return response()->json([
            'data' => $this->service->updateCoa($id, $data, (string) $request->user()->id),
        ]);
    }

    public function templateIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:120',
            'active_only' => 'nullable|boolean',
        ]);

        return response()->json(['data' => $this->service->templateList($filters)]);
    }

    public function templateStore(Request $request): JsonResponse
    {
        $data = $this->templatePayload($request);

        return response()->json([
            'data' => $this->service->createTemplate($data, (string) $request->user()->id),
        ], 201);
    }

    public function templateUpdate(Request $request, string $id): JsonResponse
    {
        $data = $this->templatePayload($request);

        return response()->json([
            'data' => $this->service->updateTemplate($id, $data, (string) $request->user()->id),
        ]);
    }

    public function postingIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(['warehouse', 'all'])],
            'q' => 'nullable|string|max:140',
            'status' => ['nullable', Rule::in(['DRAFT', 'POSTED', 'REVERSED', 'draft', 'posted', 'reversed'])],
            'source_type' => 'nullable|string|max:80',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        return response()->json([
            'data' => $this->service->postingList($this->postingScopeIds($request, $filters['scope'] ?? 'warehouse'), $filters),
        ]);
    }

    public function postingShow(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'data' => $this->service->detail($id, $this->allowedWarehouseIds($request)),
        ]);
    }

    public function postingStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_key' => 'nullable|string|max:191',
            'reference_no' => 'nullable|string|max:140',
            'business_date' => 'required|date',
            'journal_date' => 'nullable|date',
            'currency_code' => 'nullable|string|size:3',
            'description' => 'required|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:2|max:100',
            'lines.*.account_id' => 'required|string|max:26',
            'lines.*.description' => 'nullable|string|max:500',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
        ]);

        return response()->json([
            'data' => $this->service->createManualPosting(
                $this->selectedWarehouseId($request),
                $data,
                (string) $request->user()->id,
            ),
        ], 201);
    }

    public function postingFromTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_code' => 'required|string|max:80',
            'source_key' => 'nullable|string|max:191',
            'source_type' => 'nullable|string|max:80',
            'source_id' => 'nullable|string|max:100',
            'reference_no' => 'nullable|string|max:140',
            'business_date' => 'required|date',
            'journal_date' => 'nullable|date',
            'currency_code' => 'nullable|string|size:3',
            'description' => 'nullable|string|max:2000',
            'amounts' => 'required|array|min:1|max:30',
            'amounts.*' => 'required|numeric|min:0',
            'metadata' => 'nullable|array',
        ]);

        $templateCode = strtoupper((string) $data['template_code']);
        unset($data['template_code']);

        return response()->json([
            'data' => $this->service->createTemplatePosting(
                $this->selectedWarehouseId($request),
                $templateCode,
                $data,
                (string) $request->user()->id,
            ),
        ], 201);
    }

    public function postingPost(Request $request, string $id): JsonResponse
    {
        return response()->json([
            'data' => $this->service->post(
                $id,
                $this->allowedWarehouseIds($request),
                (string) $request->user()->id,
            ),
        ]);
    }

    public function postingReverse(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ]);

        return response()->json([
            'data' => $this->service->reverse(
                $id,
                $this->allowedWarehouseIds($request),
                $data['reason'],
                (string) $request->user()->id,
            ),
        ]);
    }

    private function coaPayload(Request $request): array
    {
        return $request->validate([
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:180',
            'account_type' => ['required', Rule::in(['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE'])],
            'normal_balance' => ['required', Rule::in(['DEBIT', 'CREDIT'])],
            'is_header' => 'nullable|boolean',
            'is_postable' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'description' => 'nullable|string|max:2000',
        ]);
    }

    private function templatePayload(Request $request): array
    {
        return $request->validate([
            'code' => 'required|string|max:80',
            'name' => 'required|string|max:180',
            'source_type' => 'required|string|max:80',
            'description' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
            'lines' => 'required|array|min:2|max:40',
            'lines.*.account_id' => 'required|string|max:26',
            'lines.*.side' => ['required', Rule::in(['DEBIT', 'CREDIT'])],
            'lines.*.amount_key' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            'lines.*.multiplier' => 'nullable|numeric|gt:0|max:1000000',
            'lines.*.memo_template' => 'nullable|string|max:255',
        ]);
    }

    private function selectedWarehouseId(Request $request): string
    {
        $id = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        if ($id === '') {
            abort(422, 'Pilih Warehouse terlebih dahulu.');
        }

        return $id;
    }

    private function allowedWarehouseIds(Request $request): array
    {
        $scope = (array) $request->attributes->get('warehouse_scope', []);
        $ids = collect($scope['warehouses'] ?? [])
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            $selected = $this->selectedWarehouseId($request);
            return [$selected];
        }

        return $ids;
    }

    private function postingScopeIds(Request $request, string $mode): array
    {
        $selected = $this->selectedWarehouseId($request);
        if (strtolower($mode) !== 'all') {
            return [$selected];
        }

        $scope = (array) $request->attributes->get('warehouse_scope', []);
        if (! (bool) ($scope['can_adjust_scope'] ?? false)) {
            return [$selected];
        }

        return $this->allowedWarehouseIds($request);
    }
}
