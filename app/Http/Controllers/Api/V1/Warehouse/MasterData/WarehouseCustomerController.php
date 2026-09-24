<?php

namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseCustomer;
use App\Models\Warehouse\WarehouseCustomerAddress;
use App\Models\Warehouse\WarehouseCustomerGroup;
use App\Models\Warehouse\WarehouseCustomerPriceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class WarehouseCustomerController extends WarehouseMasterDataBaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'is_active');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'customer_type' => ['nullable', Rule::in(['chain', 'external'])],
            'customer_group_id' => ['nullable', 'string', 'max:40'],
            'price_tier_id' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WarehouseCustomer::query()->with(['group:id,code,name', 'priceTier:id,code,name,discount_percent', 'addresses']);
        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function ($builder) use ($term): void {
                $builder->where('code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('contact_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('tax_number', 'like', "%{$term}%");
            });
        }
        foreach (['customer_type', 'customer_group_id', 'price_tier_id'] as $field) {
            if (! empty($filters[$field])) $query->where($field, $filters[$field]);
        }
        if (array_key_exists('is_active', $filters)) $query->where('is_active', (bool) $filters['is_active']);

        $paginator = $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 50));
        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (WarehouseCustomer $row) => $this->serialize($row))->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function options(): JsonResponse
    {
        return ApiResponse::ok([
            'groups' => WarehouseCustomerGroup::query()->where('is_active', true)->orderBy('name')->get(['id','code','name']),
            'price_tiers' => WarehouseCustomerPriceTier::query()->where('is_active', true)->orderBy('name')->get(['id','code','name','discount_percent']),
            'outlets' => Schema::hasTable('outlets') ? DB::table('outlets')->where('is_active', true)->orderBy('name')->get(['id','code','name']) : [],
            'customer_types' => [
                ['value' => 'chain', 'label' => 'Outlet Chain Supply'],
                ['value' => 'external', 'label' => 'Customer Eksternal'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $userId = $request->user()?->id;
        $customer = DB::transaction(function () use ($data, $userId): WarehouseCustomer {
            $customer = WarehouseCustomer::query()->create([
                ...$this->normalizeCustomer($data),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);
            $this->syncAddresses($customer, $data['addresses'] ?? []);
            return $customer;
        });
        return ApiResponse::ok($this->serialize($customer->load(['group','priceTier','addresses'])), 'Customer Warehouse berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $customer = WarehouseCustomer::query()->find($id);
        if (! $customer) return ApiResponse::error('Customer Warehouse tidak ditemukan.', 'NOT_FOUND', 404);
        $data = $this->validated($request, $id);
        DB::transaction(function () use ($customer, $data, $request): void {
            $customer->fill([...$this->normalizeCustomer($data), 'updated_by_user_id' => $request->user()?->id])->save();
            $this->syncAddresses($customer, $data['addresses'] ?? []);
        });
        return ApiResponse::ok($this->serialize($customer->fresh()->load(['group','priceTier','addresses'])), 'Customer Warehouse berhasil diperbarui.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $customer = WarehouseCustomer::query()->find($id);
        if (! $customer) return ApiResponse::error('Customer Warehouse tidak ditemukan.', 'NOT_FOUND', 404);
        $used = (Schema::hasTable('wh_sales_orders') && DB::table('wh_sales_orders')->where('customer_id', $id)->exists())
            || (Schema::hasTable('wh_sales_invoices') && DB::table('wh_sales_invoices')->where('customer_id', $id)->exists());
        if ($used) {
            $customer->forceFill(['is_active' => false, 'updated_by_user_id' => $request->user()?->id])->save();
            return ApiResponse::ok($this->serialize($customer->fresh()->load(['group','priceTier','addresses'])), 'Customer sudah digunakan dan dinonaktifkan.');
        }
        $customer->delete();
        return ApiResponse::ok(null, 'Customer Warehouse berhasil dihapus.');
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        $header = array_map(fn ($v) => strtolower(trim((string) $v)), fgetcsv($handle) ?: []);
        $required = ['code','name','customer_type'];
        if (array_diff($required, $header)) return ApiResponse::error('Header CSV wajib memuat code, name, customer_type.', 'INVALID_CSV', 422);

        $created = 0; $updated = 0; $failed = [];
        DB::beginTransaction();
        try {
            $line = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $line++; if (! array_filter($row, fn ($v) => trim((string) $v) !== '')) continue;
                $payload = array_combine($header, array_pad($row, count($header), null));
                try {
                    $code = strtoupper(trim((string) ($payload['code'] ?? '')));
                    $name = trim((string) ($payload['name'] ?? ''));
                    $type = strtolower(trim((string) ($payload['customer_type'] ?? 'external')));
                    if ($code === '' || $name === '' || ! in_array($type, ['chain','external'], true)) throw new \RuntimeException('code, name, atau customer_type tidak valid');
                    $groupId = $this->resolveGroup(trim((string) ($payload['group_code'] ?? '')), $request->user()?->id);
                    $tierId = $this->resolveTier(trim((string) ($payload['price_tier_code'] ?? '')), $request->user()?->id);
                    $existing = WarehouseCustomer::withTrashed()->where('code', $code)->first();
                    $values = [
                        'name'=>$name,'customer_type'=>$type,'customer_group_id'=>$groupId,'price_tier_id'=>$tierId,
                        'contact_name'=>$this->nullText($payload['contact_name'] ?? null),'phone'=>$this->nullText($payload['phone'] ?? null),
                        'email'=>$this->nullText($payload['email'] ?? null),'tax_number'=>$this->nullText($payload['tax_number'] ?? null),
                        'credit_term_days'=>max(0,(int)($payload['credit_term_days'] ?? 0)),'credit_limit'=>max(0,(float)($payload['credit_limit'] ?? 0)),
                        'notes'=>$this->nullText($payload['notes'] ?? null),'is_active'=>true,'updated_by_user_id'=>$request->user()?->id,'deleted_at'=>null,
                    ];
                    if ($existing) { $existing->forceFill($values)->save(); $updated++; }
                    else { WarehouseCustomer::create(['code'=>$code,...$values,'created_by_user_id'=>$request->user()?->id]); $created++; }
                } catch (\Throwable $e) { $failed[] = ['line'=>$line,'message'=>$e->getMessage()]; }
            }
            DB::commit();
        } catch (\Throwable $e) { DB::rollBack(); throw $e; } finally { fclose($handle); }
        return ApiResponse::ok(['created'=>$created,'updated'=>$updated,'failed'=>$failed], 'Import Customer Warehouse selesai.');
    }

    public function template()
    {
        $csv = "code,name,customer_type,group_code,price_tier_code,contact_name,phone,email,tax_number,credit_term_days,credit_limit,notes\nCUST-001,Customer Contoh,external,RETAIL,REGULAR,Budi,08123456789,budi@example.com,,14,5000000,Contoh import\n";
        return response($csv, 200, ['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="warehouse_customer_template.csv"']);
    }

    private function validated(Request $request, ?string $ignoreId = null): array
    {
        return $request->validate([
            'code'=>['required','string','max:60',Rule::unique('wh_customers','code')->ignore($ignoreId)], 'name'=>['required','string','max:180'],
            'customer_type'=>['required',Rule::in(['chain','external'])], 'outlet_id'=>['nullable','string','max:40',Rule::unique('wh_customers','outlet_id')->ignore($ignoreId)->where(fn($q)=>$q->where('customer_type','chain'))],
            'customer_group_id'=>['nullable','string','max:40'], 'price_tier_id'=>['nullable','string','max:40'], 'contact_name'=>['nullable','string','max:150'],
            'phone'=>['nullable','string','max:80'], 'email'=>['nullable','email:rfc','max:180'], 'tax_number'=>['nullable','string','max:80'],
            'tax_name'=>['nullable','string','max:180'], 'tax_address'=>['nullable','string','max:2000'], 'credit_term_days'=>['nullable','integer','min:0','max:3650'],
            'credit_limit'=>['nullable','numeric','min:0'], 'currency_code'=>['nullable','string','size:3'], 'notes'=>['nullable','string','max:2000'], 'is_active'=>['nullable','boolean'],
            'addresses'=>['nullable','array','max:20'], 'addresses.*.id'=>['nullable','string','max:40'], 'addresses.*.label'=>['required_with:addresses','string','max:100'],
            'addresses.*.recipient_name'=>['nullable','string','max:150'], 'addresses.*.phone'=>['nullable','string','max:80'], 'addresses.*.address'=>['required_with:addresses','string','max:2000'],
            'addresses.*.city'=>['nullable','string','max:120'], 'addresses.*.province'=>['nullable','string','max:120'], 'addresses.*.postal_code'=>['nullable','string','max:20'],
            'addresses.*.latitude'=>['nullable','numeric','between:-90,90'], 'addresses.*.longitude'=>['nullable','numeric','between:-180,180'],
            'addresses.*.is_default'=>['nullable','boolean'], 'addresses.*.is_active'=>['nullable','boolean'],
        ]);
    }

    private function normalizeCustomer(array $data): array
    {
        foreach (['outlet_id','customer_group_id','price_tier_id','contact_name','phone','email','tax_number','tax_name','tax_address','notes'] as $field) $data[$field] = $this->nullText($data[$field] ?? null);
        $data['code']=strtoupper(trim($data['code'])); $data['name']=trim($data['name']); $data['customer_type']=strtolower($data['customer_type']);
        if ($data['customer_type'] === 'external') $data['outlet_id'] = null;
        $data['credit_term_days']=max(0,(int)($data['credit_term_days'] ?? 0)); $data['credit_limit']=max(0,(float)($data['credit_limit'] ?? 0));
        $data['currency_code']=strtoupper(trim((string)($data['currency_code'] ?? 'IDR'))); $data['is_active']=(bool)($data['is_active'] ?? true);
        unset($data['addresses']); return $data;
    }

    private function syncAddresses(WarehouseCustomer $customer, array $addresses): void
    {
        $keep=[]; $defaultAssigned=false;
        foreach ($addresses as $index=>$row) {
            $isDefault=!$defaultAssigned && ((bool)($row['is_default'] ?? false) || $index===0); if ($isDefault) $defaultAssigned=true;
            $address = !empty($row['id']) ? $customer->addresses()->whereKey($row['id'])->first() : null;
            $values=['label'=>trim($row['label']),'recipient_name'=>$this->nullText($row['recipient_name']??null),'phone'=>$this->nullText($row['phone']??null),'address'=>trim($row['address']),
                'city'=>$this->nullText($row['city']??null),'province'=>$this->nullText($row['province']??null),'postal_code'=>$this->nullText($row['postal_code']??null),
                'latitude'=>$row['latitude']??null,'longitude'=>$row['longitude']??null,'is_default'=>$isDefault,'is_active'=>(bool)($row['is_active']??true)];
            if ($address) $address->fill($values)->save(); else $address=$customer->addresses()->create($values); $keep[]=(string)$address->id;
        }
        $customer->addresses()->whereNotIn('id',$keep ?: [''])->delete();
    }

    private function resolveGroup(string $code, ?string $userId): ?string { if($code==='')return null; $row=WarehouseCustomerGroup::withTrashed()->firstOrCreate(['code'=>strtoupper($code)],['name'=>ucwords(strtolower($code)),'is_active'=>true,'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId]); if($row->trashed()){$row->restore();$row->update(['is_active'=>true]);} return (string)$row->id; }
    private function resolveTier(string $code, ?string $userId): ?string { if($code==='')return null; $row=WarehouseCustomerPriceTier::withTrashed()->firstOrCreate(['code'=>strtoupper($code)],['name'=>ucwords(strtolower($code)),'discount_percent'=>0,'is_active'=>true,'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId]); if($row->trashed()){$row->restore();$row->update(['is_active'=>true]);} return (string)$row->id; }
    private function nullText(mixed $value): ?string { $value=trim((string)$value); return $value===''?null:$value; }
    private function serialize(WarehouseCustomer $row): array { return [
        'id'=>(string)$row->id,'code'=>(string)$row->code,'name'=>(string)$row->name,'customer_type'=>(string)$row->customer_type,'outlet_id'=>$row->outlet_id,
        'customer_group_id'=>$row->customer_group_id,'group'=>$row->group,'price_tier_id'=>$row->price_tier_id,'price_tier'=>$row->priceTier,
        'contact_name'=>$row->contact_name,'phone'=>$row->phone,'email'=>$row->email,'tax_number'=>$row->tax_number,'tax_name'=>$row->tax_name,'tax_address'=>$row->tax_address,
        'credit_term_days'=>(int)$row->credit_term_days,'credit_limit'=>(float)$row->credit_limit,'currency_code'=>(string)$row->currency_code,'notes'=>$row->notes,'is_active'=>(bool)$row->is_active,
        'addresses'=>$row->addresses->map(fn(WarehouseCustomerAddress $a)=>['id'=>(string)$a->id,'label'=>$a->label,'recipient_name'=>$a->recipient_name,'phone'=>$a->phone,'address'=>$a->address,'city'=>$a->city,'province'=>$a->province,'postal_code'=>$a->postal_code,'latitude'=>$a->latitude,'longitude'=>$a->longitude,'is_default'=>(bool)$a->is_default,'is_active'=>(bool)$a->is_active])->values(),
        'updated_at'=>$row->updated_at?->toIso8601String(),
    ]; }
}
