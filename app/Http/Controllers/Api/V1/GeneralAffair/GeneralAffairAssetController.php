<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Models\GeneralAffair\Asset;
use App\Models\Outlet;
use App\Services\GeneralAffair\AssetInventoryPhotoService;
use App\Services\GeneralAffair\AssetInventorySpreadsheetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GeneralAffairAssetController extends Controller
{
    public function __construct(
        private readonly AssetInventorySpreadsheetService $sheet,
        private readonly AssetInventoryPhotoService $photos,
    ) {}

    public function meta(): JsonResponse
    {
        return response()->json(['data' => [
            'outlets' => Outlet::query()->orderBy('name')->get(['id','code','name']),
            'categories' => Asset::query()->whereNotNull('category')->select('category')->distinct()->orderBy('category')->pluck('category')->values(),
            'years' => Asset::query()->whereNotNull('purchase_year')->select('purchase_year')->distinct()->orderByDesc('purchase_year')->pluck('purchase_year')->values(),
            'conditions' => AssetInventorySpreadsheetService::CONDITIONS,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $query = $this->filtered($filters);
        $page = (clone $query)->orderBy('item_name')->orderBy('asset_code')->paginate((int) ($filters['per_page'] ?? 25));
        $sum = clone $query;
        $totalValue = (float) (clone $sum)->sum('purchase_total');
        $depreciation = (float) (clone $query)->sum('total_depreciation');
        return response()->json(['data' => [
            'items' => collect($page->items())->map(fn (Asset $row) => $this->serialize($row))->values(),
            'pagination' => ['current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'per_page'=>$page->perPage(),'total'=>$page->total(),'from'=>$page->firstItem(),'to'=>$page->lastItem()],
            'summary' => ['count'=>$page->total(),'total_asset_value'=>$totalValue,'total_depreciation'=>$depreciation,'net_book_value'=>max(0, $totalValue - $depreciation)],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $outlet = Outlet::query()->findOrFail((string) $data['outlet_id']);
        $code = mb_strtoupper(trim((string) $data['asset_code']));
        $existing = Asset::withTrashed()->where('asset_code', $code)->first();
        if ($existing && ! $existing->trashed()) abort(422, 'Kode Asset sudah digunakan.');

        $payload = $this->payload($data, $outlet, $code);
        $row = DB::transaction(function () use ($existing, $payload, $request): Asset {
            if ($existing) {
                $existing->restore();
                $existing->forceFill($payload + ['updated_by_user_id'=>$request->user()?->id])->save();
                return $existing;
            }
            return Asset::query()->create($payload + [
                'id'=>(string) Str::ulid(), 'created_by_user_id'=>$request->user()?->id, 'updated_by_user_id'=>$request->user()?->id,
            ]);
        }, 3);

        if ($request->hasFile('photo')) $this->photos->store($row, $request->file('photo'), 'asset', $row->asset_code, $row->item_name, $row->outlet_code_snapshot);
        return response()->json(['data'=>$this->serialize($row->fresh()),'message'=>'Asset berhasil disimpan.'], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = Asset::query()->findOrFail($id);
        $data = $this->validatePayload($request);
        $outlet = Outlet::query()->findOrFail((string) $data['outlet_id']);
        $code = mb_strtoupper(trim((string) $data['asset_code']));
        $duplicate = Asset::withTrashed()->where('asset_code', $code)->where('id', '!=', $row->id)->exists();
        if ($duplicate) abort(422, 'Kode Asset sudah digunakan oleh data lain.');
        $row->forceFill($this->payload($data, $outlet, $code) + ['updated_by_user_id'=>$request->user()?->id])->save();
        if ($request->hasFile('photo')) $this->photos->store($row, $request->file('photo'), 'asset', $row->asset_code, $row->item_name, $row->outlet_code_snapshot);
        return response()->json(['data'=>$this->serialize($row->fresh()),'message'=>'Asset berhasil diperbarui.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = Asset::query()->findOrFail($id);
        $row->forceFill(['updated_by_user_id'=>$request->user()?->id])->save();
        $row->delete();
        return response()->json(['data'=>['id'=>$id],'message'=>'Asset dipindahkan ke arsip.']);
    }

    public function template() { return $this->sheet->assetTemplateResponse(); }

    public function export(Request $request)
    {
        $rows = $this->filtered($this->filters($request))->orderBy('outlet_name_snapshot')->orderBy('item_name')->get();
        return $this->sheet->assetExportResponse($rows);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file'=>['required','file','mimes:xlsx','max:20480'],
            'chunk_offset'=>['nullable','integer','min:0'],
            'chunk_size'=>['nullable','integer','min:1','max:100'],
        ]);
        $result = $this->sheet->importAssetChunk($request->file('file'), (int) ($data['chunk_offset'] ?? 0), (int) ($data['chunk_size'] ?? 30), $request->user()?->id);
        return response()->json(['data'=>$result,'message'=>$result['error_count'] ? 'Import asset selesai dengan catatan.' : 'Import asset berhasil.']);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'asset_code'=>['required','string','max:100'], 'category'=>['required','string','max:160'], 'item_name'=>['required','string','max:200'],
            'specification'=>['nullable','string','max:5000'], 'brand'=>['nullable','string','max:160'], 'serial_number'=>['nullable','string','max:180'],
            'vendor_name'=>['nullable','string','max:200'], 'outlet_id'=>['required', Rule::exists('outlets','id')],
            'quantity'=>['required','integer','min:1','max:1000000'], 'purchase_price'=>['required','numeric','min:0','max:999999999999999999'],
            'total_depreciation'=>['nullable','numeric','min:0','max:999999999999999999'],
            'purchase_year'=>['nullable','integer','min:1900','max:'.((int) now()->year + 1)],
            'condition'=>['nullable', Rule::in(AssetInventorySpreadsheetService::CONDITIONS)],
            'photo'=>['nullable','file','mimes:jpg,jpeg,png,webp','max:4096'],
        ]);
    }

    private function payload(array $data, Outlet $outlet, string $code): array
    {
        $qty = (int) $data['quantity']; $price = round((float) $data['purchase_price'], 2);
        $purchaseTotal = round($qty * $price, 2);
        $depreciation = round((float) ($data['total_depreciation'] ?? 0), 2);
        if ($depreciation > $purchaseTotal) abort(422, 'Total Penyusutan tidak boleh melebihi Harga Total Pembelian.');
        return [
            'asset_code'=>$code, 'category'=>trim((string) $data['category']), 'item_name'=>trim((string) $data['item_name']),
            'specification'=>$this->nullable($data['specification'] ?? null), 'brand'=>$this->nullable($data['brand'] ?? null),
            'serial_number'=>$this->nullable($data['serial_number'] ?? null), 'vendor_name'=>$this->nullable($data['vendor_name'] ?? null),
            'outlet_id'=>(string) $outlet->id, 'outlet_code_snapshot'=>$outlet->code, 'outlet_name_snapshot'=>$outlet->name,
            'quantity'=>$qty, 'purchase_price'=>$price, 'purchase_total'=>$purchaseTotal,
            'total_depreciation'=>$depreciation,
            'purchase_year'=>filled($data['purchase_year'] ?? null) ? (int) $data['purchase_year'] : null,
            'condition'=>(string) ($data['condition'] ?? 'Baik'),
        ];
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'q'=>['nullable','string','max:180'], 'category'=>['nullable','string','max:160'], 'outlet_id'=>['nullable','string','max:64'],
            'purchase_year'=>['nullable','integer','min:1900','max:2100'], 'condition'=>['nullable','string','max:40'],
            'page'=>['nullable','integer','min:1'], 'per_page'=>['nullable','integer','min:10','max:100'],
        ]);
    }

    private function filtered(array $f): Builder
    {
        $q = Asset::query();
        if (filled($f['q'] ?? null)) {
            $term = '%'.trim((string) $f['q']).'%';
            $q->where(fn (Builder $x) => $x->where('asset_code','like',$term)->orWhere('item_name','like',$term)->orWhere('brand','like',$term)->orWhere('serial_number','like',$term)->orWhere('vendor_name','like',$term));
        }
        if (filled($f['category'] ?? null)) $q->where('category', $f['category']);
        if (filled($f['outlet_id'] ?? null)) $q->where('outlet_id', $f['outlet_id']);
        if (filled($f['purchase_year'] ?? null)) $q->where('purchase_year', (int) $f['purchase_year']);
        if (filled($f['condition'] ?? null)) $q->where('condition', $f['condition']);
        return $q;
    }

    private function serialize(Asset $row): array
    {
        return [
            'id'=>(string)$row->id, 'asset_code'=>$row->asset_code, 'category'=>$row->category, 'item_name'=>$row->item_name,
            'specification'=>$row->specification, 'brand'=>$row->brand, 'serial_number'=>$row->serial_number, 'vendor_name'=>$row->vendor_name,
            'outlet_id'=>(string)$row->outlet_id, 'outlet_code'=>$row->outlet_code_snapshot, 'outlet_name'=>$row->outlet_name_snapshot,
            'quantity'=>(int)$row->quantity, 'purchase_price'=>(float)$row->purchase_price, 'purchase_total'=>(float)$row->purchase_total,
            'total_depreciation'=>(float)$row->total_depreciation, 'net_book_value'=>max(0, (float)$row->purchase_total - (float)$row->total_depreciation),
            'purchase_year'=>$row->purchase_year ? (int)$row->purchase_year : null, 'condition'=>$row->condition,
            'photo_url'=>$this->photos->url($row), 'photo_path'=>$row->photo_path, 'created_at'=>$row->created_at?->toIso8601String(), 'updated_at'=>$row->updated_at?->toIso8601String(),
        ];
    }
    private function nullable($v): ?string { $v = trim((string) ($v ?? '')); return $v !== '' ? $v : null; }
}
