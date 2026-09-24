<?php

namespace App\Services\Purchasing;

use App\Models\Outlet;
use App\Models\Purchasing\FundRequest;
use App\Models\StockInventory\StockSku;
use App\Models\User;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FundRequestCatalog
{
    /**
     * Canonical Fund Request type contract. Extra metadata is intentionally
     * additive so existing UI clients that only read code/name remain compatible.
     */
    public const REQUEST_TYPES = [
        ['code' => FundRequest::TYPE_PURCHASE, 'name' => 'Purchase', 'selectable' => true, 'order_kind' => 'PURCHASE_ORDER'],
        ['code' => FundRequest::TYPE_SERVICE, 'name' => 'Service', 'selectable' => true, 'order_kind' => 'SERVICE_ORDER', 'realization_kind' => 'SERVICE_ACCEPTANCE'],
        ['code' => FundRequest::TYPE_REIMBURSE, 'name' => 'Reimburse', 'selectable' => true, 'order_kind' => 'REIMBURSE_ORDER', 'realization_kind' => 'REIMBURSE_PAYMENT'],
        ['code' => FundRequest::TYPE_ASSET, 'name' => 'Asset', 'selectable' => true, 'order_kind' => 'PURCHASE_ORDER', 'realization_kind' => 'GOODS_RECEIPT'],
        ['code' => FundRequest::TYPE_STOCK, 'name' => 'Stock', 'selectable' => true, 'order_kind' => 'PURCHASE_ORDER', 'realization_kind' => 'GOODS_RECEIPT'],
    ];

    public const CHAMBERS = [
        ['code' => 'EXECUTIVE', 'name' => 'Executive'],
        ['code' => 'BRAND', 'name' => 'Brand'],
        ['code' => 'OPERATIONAL', 'name' => 'Operational'],
        ['code' => 'GENERAL_AFFAIR', 'name' => 'General Affair'],
        ['code' => 'FINANCE', 'name' => 'Finance'],
        ['code' => 'HUMAN_RESOURCE', 'name' => 'Human Resource'],
        ['code' => 'OUTLET', 'name' => 'Outlet'],
        ['code' => 'WAREHOUSE', 'name' => 'Warehouse'],
    ];

    public const TAX_MODES = [
        ['code' => 'NO_TAX', 'name' => 'No Tax', 'default_percent' => 0],
        ['code' => 'TAX', 'name' => 'Tax', 'default_percent' => 11],
    ];

    public const STATUSES = [
        ['code' => FundRequest::STATUS_DRAFT, 'name' => 'Draft'],
        ['code' => FundRequest::STATUS_AWAITING_APPROVAL, 'name' => 'Awaiting Request Approval'],
        ['code' => FundRequest::STATUS_APPROVED, 'name' => 'Request Approved'],
        ['code' => FundRequest::STATUS_REJECTED, 'name' => 'Request Rejected'],
    ];

    public const SCOPE_TYPES = [
        ['code' => PurchasingDocumentScopeService::SCOPE_COMPANY, 'name' => 'PT / Company'],
        ['code' => PurchasingDocumentScopeService::SCOPE_OUTLET, 'name' => 'Outlet'],
    ];

    public function __construct(
        private readonly UserAuthContextResolver $authContextResolver,
        private readonly PurchasingDocumentScopeService $scopeService,
    ) {
    }

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        return [
            'request_types' => self::REQUEST_TYPES,
            'chambers' => self::CHAMBERS,
            'scope_types' => self::SCOPE_TYPES,
            'companies' => $this->scopeService->companies(),
            'tax_modes' => self::TAX_MODES,
            'statuses' => self::STATUSES,
            'outlets' => $this->outletsFor($user),
            'skus' => $this->skus(),
            'defaults' => [
                'currency' => 'IDR',
                'tax_percent' => 11,
                'scope_type' => PurchasingDocumentScopeService::SCOPE_COMPANY,
                'marking' => PurchasingDocumentScopeService::DEFAULT_MARKING,
            ],
            'contract' => [
                'version' => 'ERP_V5_I04_FUND_REQUEST_SCOPE',
                'service_request_enabled' => true,
                'company_scope_enabled' => true,
                'outlet_scope_company_is_authoritative_mapping' => true,
            ],
        ];
    }

    /** @return array<int, array<string,mixed>> */
    private function outletsFor(User $user): array
    {
        if (! Schema::hasTable('outlets')) {
            return [];
        }

        $query = Outlet::query()->select(['id', 'code', 'name', 'type'])->orderBy('name');

        if (Schema::hasColumn('outlets', 'is_active')) {
            $query->where(function ($builder): void {
                $builder->whereNull('is_active')->orWhere('is_active', true);
            });
        }

        $context = $this->authContextResolver->resolve($user);
        if ((bool) ($context['scope_locked'] ?? false) && ! empty($context['resolved_outlet_id'])) {
            $query->whereKey((string) $context['resolved_outlet_id']);
        }

        $businessDate = now('Asia/Jakarta')->toDateString();
        $companyNames = Schema::hasTable('finance_companies')
            ? DB::table('finance_companies')->pluck('name', 'code')
            : collect();

        return $query->get()->map(function (Outlet $outlet) use ($businessDate, $companyNames): array {
            $companyCode = $this->scopeService->companyForOutletOrNull((string) $outlet->id, $businessDate);
            return [
                'id' => (string) $outlet->id,
                'code' => $outlet->code,
                'name' => (string) $outlet->name,
                'type' => $outlet->type,
                'company_code' => $companyCode,
                'company_name' => $companyCode ? (string) ($companyNames[$companyCode] ?? $companyCode) : null,
                'company_mapped' => $companyCode !== null,
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function skus(): array
    {
        if (! Schema::hasTable('stk_skus')) {
            return [];
        }

        return StockSku::query()
            ->with('baseUom')
            ->when(Schema::hasColumn('stk_skus', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->limit(2000)
            ->get()
            ->map(fn (StockSku $sku): array => [
                'id' => (string) $sku->id,
                'code' => (string) $sku->sku_code,
                'name' => (string) $sku->name,
                'uom' => (string) ($sku->baseUom?->symbol ?? ''),
            ])->values()->all();
    }
}
