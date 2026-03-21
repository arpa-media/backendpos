<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customers\SearchCustomerRequest;
use App\Http\Requests\Api\V1\Customers\StoreCustomerRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $page = max(1, (int) $request->query('page', 1));
        $sort = (string) $request->query('sort', 'created_at');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $q = trim((string) $request->query('q', ''));
        $qPhone = preg_replace('/\D+/', '', $q);

        $base = Customer::query()->whereNull('deleted_at');
        if ($q !== '') {
            $base->where(function ($w) use ($q, $qPhone) {
                $w->where('name', 'like', '%' . $q . '%');
                if ($qPhone !== '') {
                    $w->orWhere('phone', 'like', '%' . $qPhone . '%');
                }
            });
        }

        $aggregated = $base
            ->selectRaw('MIN(id) as id')
            ->selectRaw('MIN(outlet_id) as outlet_id')
            ->selectRaw('phone')
            ->selectRaw('MAX(name) as name')
            ->selectRaw('MIN(created_at) as created_at')
            ->selectRaw('MAX(updated_at) as updated_at')
            ->groupBy('phone');

        $sortMap = [
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            'name' => 'name',
            'phone' => 'phone',
        ];
        $sortColumn = $sortMap[$sort] ?? 'created_at';

        $paginator = DB::query()
            ->fromSub($aggregated, 'customer_groups')
            ->orderBy($sortColumn, $dir)
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function ($row) {
            return [
                'id' => (string) $row->id,
                'outlet_id' => (string) $row->outlet_id,
                'name' => (string) $row->name,
                'phone' => (string) $row->phone,
                'created_at' => optional($row->created_at)->toISOString(),
                'updated_at' => optional($row->updated_at)->toISOString(),
            ];
        })->values();

        return ApiResponse::ok([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ], 'OK');
    }

    public function search(SearchCustomerRequest $request)
    {
        $v = $request->validated();
        $limit = max(1, min(50, (int) ($v['limit'] ?? 20)));
        $q = trim((string) ($v['q'] ?? ''));
        $phone = trim((string) ($v['phone'] ?? ''));

        if ($q === '' && $phone === '') {
            return ApiResponse::ok(['items' => []], 'OK');
        }

        $query = Customer::query()->whereNull('deleted_at');
        if ($q !== '') {
            $qPhone = preg_replace('/\D+/', '', $q);
            $query->where(function ($w) use ($q, $qPhone) {
                $w->where('name', 'like', '%' . $q . '%');
                if ($qPhone !== '') {
                    $w->orWhere('phone', 'like', '%' . $qPhone . '%');
                }
            });
        }
        if ($phone !== '') {
            $query->where('phone', $phone);
        }

        $rows = $query
            ->orderByDesc('updated_at')
            ->limit($limit * 5)
            ->get()
            ->unique('phone')
            ->take($limit)
            ->values();

        return ApiResponse::ok([
            'items' => CustomerResource::collection($rows),
        ], 'OK');
    }

    public function show(string $id)
    {
        $customer = Customer::query()->findOrFail($id);
        $representative = $this->representativeByPhone((string) $customer->phone);

        return ApiResponse::ok([
            'id' => (string) $representative->id,
            'outlet_id' => (string) $representative->outlet_id,
            'name' => (string) $representative->name,
            'phone' => (string) $representative->phone,
            'created_at' => optional($representative->created_at)->toISOString(),
            'updated_at' => optional($representative->updated_at)->toISOString(),
        ], 'OK');
    }

    public function stats(string $id)
    {
        $customer = Customer::query()->findOrFail($id);
        $customerIds = Customer::query()
            ->whereNull('deleted_at')
            ->where('phone', $customer->phone)
            ->pluck('id');

        $paidSales = Sale::query()
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'PAID');

        $summary = (clone $paidSales)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total_spent')
            ->selectRaw('MAX(updated_at) as last_paid_at')
            ->first();

        $topItems = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.customer_id', $customerIds)
            ->where('sales.status', 'PAID')
            ->selectRaw('sale_items.product_id')
            ->selectRaw('COALESCE(sale_items.variant_name, "") as variant_name_snapshot')
            ->selectRaw('MAX(sale_items.product_name) as product_name_snapshot')
            ->selectRaw('SUM(sale_items.qty) as qty_total')
            ->selectRaw('SUM(sale_items.line_total) as amount_total')
            ->groupBy('sale_items.product_id', 'sale_items.variant_name')
            ->orderByDesc('qty_total')
            ->orderByDesc('amount_total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (string) $row->product_id,
                'variant_name_snapshot' => (string) $row->variant_name_snapshot,
                'product_name_snapshot' => (string) $row->product_name_snapshot,
                'qty_total' => (int) $row->qty_total,
                'amount_total' => (int) $row->amount_total,
            ])
            ->values();

        $recentSales = (clone $paidSales)
            ->with('outlet:id,name')
            ->orderByDesc('updated_at')
            ->limit(3)
            ->get([
                'id', 'outlet_id', 'sale_number', 'channel', 'status', 'grand_total', 'paid_total', 'created_at', 'updated_at',
            ])
            ->map(fn ($sale) => [
                'id' => (string) $sale->id,
                'outlet_id' => (string) $sale->outlet_id,
                'outlet_name' => (string) optional($sale->outlet)->name,
                'sale_number' => (string) $sale->sale_number,
                'channel' => (string) $sale->channel,
                'status' => (string) $sale->status,
                'grand_total' => (int) $sale->grand_total,
                'paid_at' => optional($sale->updated_at)->toISOString(),
                'created_at' => optional($sale->created_at)->toISOString(),
            ])
            ->values();

        $byOutlet = (clone $paidSales)
            ->join('outlets', 'outlets.id', '=', 'sales.outlet_id')
            ->selectRaw('sales.outlet_id')
            ->selectRaw('MAX(outlets.name) as outlet_name')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('COALESCE(SUM(sales.grand_total), 0) as total_spent')
            ->selectRaw('MAX(sales.updated_at) as last_paid_at')
            ->groupBy('sales.outlet_id')
            ->orderByDesc('total_orders')
            ->get()
            ->map(fn ($row) => [
                'outlet_id' => (string) $row->outlet_id,
                'outlet_name' => (string) $row->outlet_name,
                'total_orders' => (int) $row->total_orders,
                'total_spent' => (int) $row->total_spent,
                'last_paid_at' => optional($row->last_paid_at)->toISOString(),
            ])
            ->values();

        return ApiResponse::ok([
            'summary' => [
                'total_orders' => (int) ($summary->total_orders ?? 0),
                'total_spent' => (int) ($summary->total_spent ?? 0),
                'last_paid_at' => optional($summary->last_paid_at)->toISOString(),
            ],
            'top_items' => $topItems,
            'recent_sales' => $recentSales,
            'by_outlet' => $byOutlet,
        ], 'OK');
    }

    public function store(StoreCustomerRequest $request)
    {
        $v = $request->validated();
        $existing = Customer::query()
            ->whereNull('deleted_at')
            ->where('phone', (string) $v['phone'])
            ->first();

        if ($existing) {
            return ApiResponse::error('Phone already registered', 'PHONE_EXISTS', 409, [], [
                'customer' => new CustomerResource($existing),
            ]);
        }

        $outletId = $v['outlet_id']
            ?? optional($request->user())->outlet_id
            ?? Outlet::query()->where('type', 'outlet')->value('id')
            ?? Outlet::query()->value('id');

        if (!$outletId) {
            return ApiResponse::error('Outlet not found', 'OUTLET_NOT_FOUND', 422);
        }

        $customer = Customer::query()->create([
            'outlet_id' => (string) $outletId,
            'phone' => (string) $v['phone'],
            'name' => (string) $v['name'],
        ]);

        return ApiResponse::ok(new CustomerResource($customer), 'Customer created', 201);
    }

    protected function representativeByPhone(string $phone): Customer
    {
        return Customer::query()
            ->whereNull('deleted_at')
            ->where('phone', $phone)
            ->orderByDesc('updated_at')
            ->firstOrFail();
    }
}
