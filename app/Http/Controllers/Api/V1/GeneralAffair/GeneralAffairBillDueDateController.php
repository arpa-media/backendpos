<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Models\GeneralAffair\BillDueDate;
use App\Models\Outlet;
use App\Services\GeneralAffair\BillDueDateSpreadsheetService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GeneralAffairBillDueDateController extends Controller
{
    public function __construct(private readonly BillDueDateSpreadsheetService $sheet) {}

    public function meta(): JsonResponse
    {
        return response()->json(['data' => [
            'bill_types' => [
                ['value'=>'PLN','label'=>'PLN'],
                ['value'=>'INTERNET','label'=>'Internet'],
                ['value'=>'AIR','label'=>'Air'],
            ],
            'outlets' => Outlet::query()->orderBy('name')->get(['id','code','name']),
            'years' => range((int) now()->year - 5, (int) now()->year + 1),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $query = $this->filteredQuery($filters);
        $page = $query->orderBy('due_date')->orderBy('outlet_name_snapshot')->paginate((int) ($filters['per_page'] ?? 25));
        return response()->json(['data' => [
            'items' => collect($page->items())->map(fn (BillDueDate $row) => $this->serialize($row))->values(),
            'pagination' => ['current_page'=>$page->currentPage(),'last_page'=>$page->lastPage(),'per_page'=>$page->perPage(),'total'=>$page->total(),'from'=>$page->firstItem(),'to'=>$page->lastItem()],
            'summary' => [
                'count' => $this->filteredQuery($filters)->count(),
                'nominal' => (float) $this->filteredQuery($filters)->sum('nominal'),
            ],
        ]]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $data = $request->validate(['year'=>['nullable','integer','min:2000','max:2100'],'outlet_id'=>['nullable','string','max:64']]);
        $year = (int) ($data['year'] ?? now()->year);
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        $typeRows = DB::table('ga_bill_due_dates')->whereNull('deleted_at')->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("DATE_FORMAT(due_date, '%Y-%m') as month_key, bill_type, COUNT(*) as bill_count, SUM(nominal) as total_nominal")
            ->groupBy('month_key','bill_type')->orderBy('month_key')->get();

        $outletQuery = DB::table('ga_bill_due_dates')->whereNull('deleted_at')->whereBetween('due_date', [$start->toDateString(), $end->toDateString()]);
        if (! empty($data['outlet_id'])) $outletQuery->where('outlet_id', $data['outlet_id']);
        $outletRows = $outletQuery
            ->selectRaw("DATE_FORMAT(due_date, '%Y-%m') as month_key, outlet_id, outlet_code_snapshot, outlet_name_snapshot, COUNT(*) as bill_count, SUM(nominal) as total_nominal")
            ->groupBy('month_key','outlet_id','outlet_code_snapshot','outlet_name_snapshot')->orderBy('month_key')->orderBy('outlet_name_snapshot')->get();

        return response()->json(['data' => [
            'year' => $year,
            'by_type_month' => $typeRows->map(fn ($r) => ['month'=>$r->month_key,'bill_type'=>$r->bill_type,'count'=>(int)$r->bill_count,'nominal'=>(float)$r->total_nominal])->values(),
            'by_outlet_month' => $outletRows->map(fn ($r) => ['month'=>$r->month_key,'outlet_id'=>(string)$r->outlet_id,'outlet_code'=>$r->outlet_code_snapshot,'outlet_name'=>$r->outlet_name_snapshot,'count'=>(int)$r->bill_count,'nominal'=>(float)$r->total_nominal])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);
        $outlet = Outlet::query()->findOrFail($payload['outlet_id']);
        $natural = BillDueDate::withTrashed()
            ->where('bill_type', $payload['bill_type'])->where('outlet_id', $outlet->id)
            ->where('customer_account_id', trim($payload['customer_account_id']))->whereDate('due_date', $payload['due_date'])->first();
        if ($natural && ! $natural->trashed()) abort(422, 'Bill dengan jenis, outlet, Customer ID dan Due Date yang sama sudah ada.');
        if ($natural && $natural->trashed()) {
            $natural->restore();
            $natural->forceFill([
                'outlet_code_snapshot'=>$outlet->code, 'outlet_name_snapshot'=>$outlet->name, 'nominal'=>$payload['nominal'],
                'notes'=>filled($payload['notes'] ?? null) ? trim($payload['notes']) : null, 'updated_by_user_id'=>$request->user()?->id,
            ])->save();
            return response()->json(['data'=>$this->serialize($natural->fresh()), 'restored'=>true], 201);
        }
        $row = BillDueDate::query()->create([
            'id'=>(string) Str::ulid(), 'bill_type'=>$payload['bill_type'], 'due_date'=>$payload['due_date'], 'outlet_id'=>$outlet->id,
            'outlet_code_snapshot'=>$outlet->code, 'outlet_name_snapshot'=>$outlet->name, 'customer_account_id'=>trim($payload['customer_account_id']),
            'nominal'=>$payload['nominal'], 'notes'=>filled($payload['notes'] ?? null) ? trim($payload['notes']) : null,
            'created_by_user_id'=>$request->user()?->id, 'updated_by_user_id'=>$request->user()?->id,
        ]);
        return response()->json(['data'=>$this->serialize($row)], 201);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate(['rows'=>['required','array','min:1','max:200'],'rows.*'=>['required','array']]);
        $result = ['success'=>true,'status'=>'success','total_rows'=>count($data['rows']),'processed'=>0,'inserted'=>0,'updated'=>0,'unchanged'=>0,'restored'=>0,'error_count'=>0,'errors'=>[],'row_results'=>[]];
        foreach ($data['rows'] as $index => $input) {
            try {
                $validator = validator($input, $this->payloadRules());
                if ($validator->fails()) throw new \InvalidArgumentException($validator->errors()->first());
                $payload = $validator->validated();
                $outlet = Outlet::query()->findOrFail($payload['outlet_id']);
                $saved = $this->sheet->upsert([
                    'bill_type'=>$payload['bill_type'],'due_date'=>$payload['due_date'],'outlet_id'=>$outlet->id,
                    'outlet_code_snapshot'=>$outlet->code,'outlet_name_snapshot'=>$outlet->name,
                    'customer_account_id'=>trim($payload['customer_account_id']),'nominal'=>$payload['nominal'],
                    'notes'=>filled($payload['notes'] ?? null) ? trim($payload['notes']) : null,
                ], $request->user()?->id);
                $result[$saved['action']]++; if ($saved['restored']) $result['restored']++; $result['processed']++;
                $result['row_results'][]=['line'=>$index+1,'action'=>$saved['action'],'restored'=>$saved['restored'],'changed_fields'=>$saved['changed_fields']];
            } catch (\Throwable $e) {
                $result['error_count']++;
                $result['errors'][]=['line'=>$index+1,'details'=>[['column'=>'Row','field'=>'row','value'=>'','message'=>$e->getMessage()]],'error_text'=>$e->getMessage()];
            }
        }
        $result['success']=$result['error_count']===0; $result['status']=$result['success']?'success':($result['processed']>0?'partial':'failed');
        return response()->json(['data'=>$result,'message'=>$result['success']?'Bulk input berhasil.':'Bulk input selesai dengan catatan.']);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = BillDueDate::query()->findOrFail($id);
        $payload = $this->validatePayload($request, $row->id);
        $outlet = Outlet::query()->findOrFail($payload['outlet_id']);
        $row->forceFill([
            'bill_type'=>$payload['bill_type'],'due_date'=>$payload['due_date'],'outlet_id'=>$outlet->id,
            'outlet_code_snapshot'=>$outlet->code,'outlet_name_snapshot'=>$outlet->name,
            'customer_account_id'=>trim($payload['customer_account_id']),'nominal'=>$payload['nominal'],
            'notes'=>filled($payload['notes'] ?? null) ? trim($payload['notes']) : null,'updated_by_user_id'=>$request->user()?->id,
        ])->save();
        return response()->json(['data'=>$this->serialize($row->fresh())]);
    }

    public function destroy(string $id): JsonResponse
    {
        BillDueDate::query()->findOrFail($id)->delete();
        return response()->json(['data'=>null,'message'=>'Bill Due Date dihapus.']);
    }

    public function template()
    {
        return $this->sheet->templateResponse();
    }

    public function export(Request $request)
    {
        $filters = $this->validatedFilters($request, false);
        return $this->sheet->exportResponse($this->filteredQuery($filters)->orderBy('due_date')->orderBy('outlet_name_snapshot')->get());
    }

    public function import(Request $request): JsonResponse
    {
        @set_time_limit(0); @ini_set('max_execution_time','0'); @ini_set('memory_limit','512M'); @ignore_user_abort(true);
        $data = $request->validate([
            'file'=>['required','file','mimes:xlsx','max:20480'],
            'chunk_offset'=>['nullable','integer','min:0'],
            'chunk_size'=>['nullable','integer','min:1','max:100'],
        ]);
        try {
            $result = $this->sheet->importChunk($request->file('file'), (int)($data['chunk_offset']??0), (int)($data['chunk_size']??30), $request->user()?->id);
            return response()->json(['data'=>$result,'message'=>$result['success']?'Import chunk berhasil.':'Import chunk selesai dengan catatan.']);
        } catch (\Throwable $e) {
            return response()->json(['message'=>$e->getMessage(),'data'=>[
                'success'=>false,'status'=>'failed','total_rows'=>0,'processed'=>0,'inserted'=>0,'updated'=>0,'unchanged'=>0,'restored'=>0,'error_count'=>1,
                'errors'=>[['line'=>'-','details'=>[['column'=>'File XLSX','field'=>'file','value'=>'','message'=>$e->getMessage()]],'error_text'=>$e->getMessage()]],
            ]], 422);
        }
    }

    private function validatedFilters(Request $request, bool $withPaging = true): array
    {
        $rules = [
            'q'=>['nullable','string','max:120'],'bill_type'=>['nullable',Rule::in(['PLN','INTERNET','AIR'])],'outlet_id'=>['nullable','string','max:64'],
            'month'=>['nullable','integer','min:1','max:12'],'year'=>['nullable','integer','min:2000','max:2100'],
        ];
        if ($withPaging) { $rules['page']=['nullable','integer','min:1']; $rules['per_page']=['nullable','integer','min:10','max:100']; }
        return $request->validate($rules);
    }

    private function filteredQuery(array $filters)
    {
        $query = BillDueDate::query();
        if (! empty($filters['bill_type'])) $query->where('bill_type',$filters['bill_type']);
        if (! empty($filters['outlet_id'])) $query->where('outlet_id',$filters['outlet_id']);
        if (! empty($filters['year'])) $query->whereYear('due_date',(int)$filters['year']);
        if (! empty($filters['month'])) $query->whereMonth('due_date',(int)$filters['month']);
        if (! empty($filters['q'])) {
            $q=trim($filters['q']); $query->where(fn($x)=>$x->where('customer_account_id','like',"%{$q}%")->orWhere('outlet_name_snapshot','like',"%{$q}%")->orWhere('outlet_code_snapshot','like',"%{$q}%")->orWhere('notes','like',"%{$q}%"));
        }
        return $query;
    }

    private function validatePayload(Request $request, ?string $ignoreId = null): array
    {
        $data = $request->validate($this->payloadRules());
        $duplicate = BillDueDate::withTrashed()->where('bill_type',$data['bill_type'])->where('outlet_id',$data['outlet_id'])->where('customer_account_id',trim($data['customer_account_id']))->whereDate('due_date',$data['due_date']);
        if ($ignoreId) $duplicate->where('id','<>',$ignoreId);
        if ($duplicate->exists()) abort(422, 'Bill dengan jenis, outlet, Customer ID dan Due Date yang sama sudah ada.');
        return $data;
    }

    private function payloadRules(): array
    {
        return [
            'bill_type'=>['required',Rule::in(['PLN','INTERNET','AIR'])], 'due_date'=>['required','date'],
            'outlet_id'=>['required','string',Rule::exists('outlets','id')], 'customer_account_id'=>['required','string','max:120'],
            'nominal'=>['required','numeric','min:0','max:999999999999999999.99'], 'notes'=>['nullable','string','max:2000'],
        ];
    }

    private function serialize(BillDueDate $row): array
    {
        return [
            'id'=>(string)$row->id,'bill_type'=>$row->bill_type,'due_date'=>optional($row->due_date)->format('Y-m-d'),
            'outlet_id'=>(string)$row->outlet_id,'outlet_code'=>$row->outlet_code_snapshot,'outlet_name'=>$row->outlet_name_snapshot,
            'customer_account_id'=>$row->customer_account_id,'nominal'=>(float)$row->nominal,'notes'=>$row->notes,
            'created_at'=>optional($row->created_at)->toISOString(),'updated_at'=>optional($row->updated_at)->toISOString(),
        ];
    }
}
