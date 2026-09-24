<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\FundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class OrderManagementService
{
    public function __construct(
        private readonly OrderWorkflowService $orders,
        private readonly PurchasingOwnershipScopeService $ownershipScope,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function tabs(User $user): array
    {
        $result = [];
        foreach ($this->definitions() as $key => $definition) {
            $result[$key] = $this->summarize($user, $definition);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function overview(User $user, ?string $tab = null): array
    {
        $tabs = $this->tabs($user);
        $active = $tab && isset($tabs[$tab]) ? $tab : 'purchase-order';

        return [
            'active_tab' => $active,
            'visibility_scope' => $this->ownershipScope->descriptor($user),
            'tabs' => $tabs,
            'cards' => $tabs[$active]['cards'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            'purchase-order' => [
                'label' => 'Purchase Order',
                'order_kind' => 'purchase-order',
                'request_types' => ['PURCHASE', 'STOCK'],
                'execution_tables' => [['table'=>'pur_service_entry_sheets','types'=>['PURCHASE']],['table'=>'pur_goods_receipts','types'=>['STOCK']]],
            ],
            'service-order' => [
                'label' => 'Service Order',
                'order_kind' => 'service-order',
                'request_types' => ['SERVICE'],
                'execution_tables' => [['table'=>'pur_service_acceptances','types'=>['SERVICE']]],
            ],
            'reimburse-order' => [
                'label' => 'Reimburse Order',
                'order_kind' => 'reimburse-order',
                'request_types' => ['REIMBURSE'],
                'execution_tables' => [['table'=>'pur_reimburse_payments','types'=>['REIMBURSE']]],
            ],
            'asset-purchase-order' => [
                'label' => 'Aktiva Purchase Order',
                'order_kind' => 'purchase-order',
                'request_types' => ['ASSET'],
                'execution_tables' => [['table'=>'pur_goods_receipts','types'=>['ASSET']]],
            ],
        ];
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private function summarize(User $user, array $definition): array
    {
        $query = $this->query($user, $definition);
        $requestSubquery = (clone $query)->select('fund_request_id')->whereNotNull('fund_request_id');
        $orderIdSubquery = (clone $query)->select('id');

        $requested = (float) FundRequest::query()
            ->whereIn('id', $requestSubquery)
            ->sum('grand_total');

        $approved = (float) (clone $query)
            ->whereIn('status', ['APPROVED', 'PARTIALLY_EXECUTED', 'EXECUTED'])
            ->sum('total_amount');

        $realized = 0.0;
        foreach ((array) ($definition['execution_tables'] ?? []) as $execution) {
            $executionTable = (string) ($execution['table'] ?? '');
            if ($executionTable === '' || ! Schema::hasTable($executionTable)) continue;
            $q = DB::table($executionTable.' as x')
                ->join('pur_fund_requests as f', 'f.id', '=', 'x.fund_request_id')
                ->whereNull('x.deleted_at')
                ->whereIn('x.order_id', clone $orderIdSubquery)
                ->whereIn('x.status', ['POSTED', 'APPROVED']);
            $types = (array) ($execution['types'] ?? []);
            if ($types) $q->whereIn('f.request_type', $types);
            $realized += (float) $q->sum(DB::raw('COALESCE(x.actual_total_amount, x.total_amount)'));
        }

        $pending1 = (int) (clone $query)->where('status', 'AWAITING_FINANCE_APPROVAL_1')->count();
        $pending2 = (int) (clone $query)->where('status', 'AWAITING_FINANCE_APPROVAL_2')->count();

        return [
            'label' => $definition['label'],
            'order_kind' => $definition['order_kind'],
            'request_types' => $definition['request_types'],
            'pending_approval_1' => $pending1,
            'pending_approval_2' => $pending2,
            'pending_approval' => $pending1 + $pending2,
            'total_documents' => (int) (clone $query)->count(),
            'cards' => [
                'requested_amount' => round($requested, 2),
                'approved_amount' => round($approved, 2),
                'realized_amount' => round($realized, 2),
            ],
        ];
    }

    /** @param array<string, mixed> $definition */
    private function query(User $user, array $definition): Builder
    {
        $kind = (string) ($definition['order_kind'] ?? '');
        if ($kind === '') {
            throw new InvalidArgumentException('Order kind tidak tersedia.');
        }

        return $this->orders->visibleQuery($kind, $user)
            ->whereHas('fundRequest', fn (Builder $request) => $request
                ->whereIn('request_type', (array) $definition['request_types']));
    }
}
