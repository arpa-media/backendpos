<?php

namespace App\Http\Controllers\Api\V1\Warehouse\FinanceV4;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\FinanceV4\WarehouseFinanceReconciliationV4Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class WarehouseFinanceReconciliationV4Controller extends Controller
{
    public function __construct(private readonly WarehouseFinanceReconciliationV4Service $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['warehouse','all'])],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);
        [$from,$to] = $this->dates($data);
        [$ids,$mode] = $this->scope($request, (string)($data['scope'] ?? 'warehouse'));
        return response()->json(['data'=>$this->service->overview($ids,$mode,$from,$to)]);
    }

    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['warehouse','all'])],
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);
        [$from,$to] = $this->dates($data);
        [$ids,$mode] = $this->scope($request, (string)($data['scope'] ?? 'warehouse'));
        return response()->json(['data'=>$this->service->run($ids,$mode,$from,$to,(string)$request->user()->id)]);
    }

    public function baseline(Request $request): JsonResponse
    {
        $data = $request->validate(['scope'=>['nullable',Rule::in(['warehouse','all'])]]);
        [$ids] = $this->scope($request, (string)($data['scope'] ?? 'warehouse'));
        return response()->json(['data'=>$this->service->captureBaseline($ids,(string)$request->user()->id,false)],201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data'=>$this->service->latestRunDetail($id,$this->allowedWarehouseIds($request))]);
    }

    private function dates(array $data): array
    {
        $to = (string)($data['date_to'] ?? now('Asia/Jakarta')->toDateString());
        $from = (string)($data['date_from'] ?? now('Asia/Jakarta')->subDays(30)->toDateString());
        return [$from,$to];
    }

    private function scope(Request $request, string $mode): array
    {
        $selected = trim((string)$request->attributes->get('warehouse_scope_id',''));
        if ($selected === '') abort(422,'Pilih Warehouse terlebih dahulu.');
        if ($mode !== 'all') return [[$selected],'warehouse'];
        $scope = (array)$request->attributes->get('warehouse_scope',[]);
        if (! (bool)($scope['can_adjust_scope'] ?? false)) return [[$selected],'warehouse'];
        $ids = $this->allowedWarehouseIds($request);
        return [$ids === [] ? [$selected] : $ids,'all'];
    }

    private function allowedWarehouseIds(Request $request): array
    {
        $scope = (array)$request->attributes->get('warehouse_scope',[]);
        return collect($scope['warehouses'] ?? [])->pluck('id')->filter()->map(fn($id)=>(string)$id)->unique()->values()->all();
    }
}
