<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Models\GeneralAffair\InventoryItem;
use App\Models\Outlet;
use App\Services\GeneralAffair\AssetInventoryPhotoService;
use App\Services\GeneralAffair\AssetInventorySpreadsheetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GeneralAffairInventoryController extends Controller
{
    public function __construct(
        private readonly AssetInventorySpreadsheetService $sheet,
        private readonly AssetInventoryPhotoService $photos,
    ) {}

    public function meta(): JsonResponse
    {
        return response()->json(['data' => [
            'outlets' => Outlet::query()->orderBy('name')->get(['id','code','name']),
            'types' => InventoryItem::query()->whereNotNull('item_type')->select('item_type')->distinct()->orderBy('item_type')->pluck('item_type')->values(),
            'conditions' => AssetInventorySpreadsheetService::CONDITIONS,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters);
        $page = (clone $query)->orderBy('item_name')->orderBy('inventory_code')->paginate((int) ($filters['per_page'] ?? 25));
        return response()->json(['data' => [
            'items' => collect($page->items())->map(fn (InventoryItem $row) => $this->serialize($row))->values(),
            'pagination' => ['current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'per_page'=>$page->perPage(),'total'=>$page->total(),'from'=>$page->firstItem(),'to'=>$page->lastItem()],
            'summary' => [
                'count'=>$page->total(), 'total_qty'=>(float)(clone $query)->sum('quantity'), 'total_value'=>(float)(clone $query)->sum('total_value'),
                'type_count'=>(int)(clone $query)->distinct()->count('item_type'),
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $outlet = Outlet::query()->findOrFail((string) $data['outlet_id']);
        $code = mb_strtoupper(trim((string) $data['inventory_code']));
        $existing = InventoryItem::withTrashed()->where('inventory_code', $code)->first();
        if ($existing && ! $existing->trashed()) abort(422, 'Kode Inventory sudah digunakan.');
        $payload = $this->payload($data, $outlet, $code);
        $row = DB::transaction(function () use ($existing, $payload, $request): InventoryItem {
            if ($existing) {
                $existing->restore();
                $existing->forceFill($payload + ['updated_by_user_id'=>$request->user()?->id])->save();
                return $existing;
            }
            return InventoryItem::query()->create($payload + [
                'id'=>(string) Str::ulid(), 'created_by_user_id'=>$request->user()?->id, 'updated_by_user_id'=>$request->user()?->id,
            ]);
        }, 3);
        if ($request->hasFile('photo')) $this->photos->store($row, $request->file('photo'), 'inventory', $row->inventory_code, $row->item_name, $row->outlet_code_snapshot);
        return response()->json(['data'=>$this->serialize($row->fresh()),'message'=>'Inventory berhasil disimpan.'], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = InventoryItem::query()->findOrFail($id);
        $data = $this->validatePayload($request);
        $outlet = Outlet::query()->findOrFail((string) $data['outlet_id']);
        $code = mb_strtoupper(trim((string) $data['inventory_code']));
        if (InventoryItem::withTrashed()->where('inventory_code',$code)->where('id','!=',$row->id)->exists()) abort(422, 'Kode Inventory sudah digunakan oleh data lain.');
        $row->forceFill($this->payload($data, $outlet, $code) + ['updated_by_user_id'=>$request->user()?->id])->save();
        if ($request->hasFile('photo')) $this->photos->store($row, $request->file('photo'), 'inventory', $row->inventory_code, $row->item_name, $row->outlet_code_snapshot);
        return response()->json(['data'=>$this->serialize($row->fresh()),'message'=>'Inventory berhasil diperbarui.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = InventoryItem::query()->findOrFail($id);
        $row->forceFill(['updated_by_user_id'=>$request->user()?->id])->save();
        $row->delete();
        return response()->json(['data'=>['id'=>$id],'message'=>'Inventory dipindahkan ke arsip.']);
    }

    public function template() { return $this->sheet->inventoryTemplateResponse(); }
    public function export(Request $request)
    {
        $rows = $this->filtered($this->filters($request))->orderBy('outlet_name_snapshot')->orderBy('item_name')->get();
        return $this->sheet->inventoryExportResponse($rows);
    }
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate(['file'=>['required','file','mimes:xlsx','max:20480'],'chunk_offset'=>['nullable','integer','min:0'],'chunk_size'=>['nullable','integer','min:1','max:100']]);
        $result = $this->sheet->importInventoryChunk($request->file('file'), (int)($data['chunk_offset'] ?? 0), (int)($data['chunk_size'] ?? 30), $request->user()?->id);
        return response()->json(['data'=>$result,'message'=>$result['error_count'] ? 'Import inventory selesai dengan catatan.' : 'Import inventory berhasil.']);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'inventory_code'=>['required','string','max:100'], 'item_name'=>['required','string','max:200'], 'item_type'=>['required','string','max:160'],
            'quantity'=>['required','numeric','min:0','max:999999999'], 'condition'=>['required', Rule::in(AssetInventorySpreadsheetService::CONDITIONS)],
            'location'=>['nullable','string','max:200'], 'unit_price'=>['required','numeric','min:0','max:999999999999999999'],
            'outlet_id'=>['required', Rule::exists('outlets','id')], 'photo'=>['nullable','file','mimes:jpg,jpeg,png,webp','max:4096'],
        ]);
    }

    private function payload(array $data, Outlet $outlet, string $code): array
    {
        $qty = round((float)$data['quantity'], 3); $price = round((float)$data['unit_price'], 2);
        return [
            'inventory_code'=>$code, 'item_name'=>trim((string)$data['item_name']), 'item_type'=>trim((string)$data['item_type']),
            'quantity'=>$qty, 'condition'=>(string)$data['condition'], 'location'=>$this->nullable($data['location'] ?? null),
            'unit_price'=>$price, 'total_value'=>round($qty * $price, 2),
            'outlet_id'=>(string)$outlet->id, 'outlet_code_snapshot'=>$outlet->code, 'outlet_name_snapshot'=>$outlet->name,
        ];
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'q'=>['nullable','string','max:180'], 'item_type'=>['nullable','string','max:160'], 'condition'=>['nullable','string','max:40'],
            'outlet_id'=>['nullable','string','max:64'], 'page'=>['nullable','integer','min:1'], 'per_page'=>['nullable','integer','min:10','max:100'],
        ]);
    }

    private function filtered(array $f): Builder
    {
        $q = InventoryItem::query();
        if (filled($f['q'] ?? null)) {
            $term = '%'.trim((string)$f['q']).'%';
            $q->where(fn (Builder $x) => $x->where('inventory_code','like',$term)->orWhere('item_name','like',$term)->orWhere('location','like',$term));
        }
        if (filled($f['item_type'] ?? null)) $q->where('item_type',$f['item_type']);
        if (filled($f['condition'] ?? null)) $q->where('condition',$f['condition']);
        if (filled($f['outlet_id'] ?? null)) $q->where('outlet_id',$f['outlet_id']);
        return $q;
    }

    private function serialize(InventoryItem $row): array
    {
        return [
            'id'=>(string)$row->id, 'inventory_code'=>$row->inventory_code, 'item_name'=>$row->item_name, 'item_type'=>$row->item_type,
            'quantity'=>(float)$row->quantity, 'condition'=>$row->condition, 'location'=>$row->location, 'unit_price'=>(float)$row->unit_price,
            'total_value'=>(float)$row->total_value, 'outlet_id'=>(string)$row->outlet_id, 'outlet_code'=>$row->outlet_code_snapshot,
            'outlet_name'=>$row->outlet_name_snapshot, 'photo_url'=>$this->photos->url($row), 'photo_path'=>$row->photo_path,
            'created_at'=>$row->created_at?->toIso8601String(), 'updated_at'=>$row->updated_at?->toIso8601String(),
        ];
    }
    private function nullable($v): ?string { $v = trim((string)($v ?? '')); return $v !== '' ? $v : null; }
}
