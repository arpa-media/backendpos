<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Purchasing\FundRequestService;
use App\Services\Purchasing\OrderWorkflowService;
use App\Services\Purchasing\PurchasingOwnershipScopeService;
use App\Services\Purchasing\RealizationOrderService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ErpPosFinalI06PurchasingPrivacyCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i06-purchasing-privacy-check
        {--users=5 : Maximum ordinary users sampled for privacy leakage checks}
        {--skip-realization-query : Skip PURCHASE/SERVICE/REIMBURSE/ASSET/STOCK query regression}';

    protected $description = 'Validate I06 Purchasing ownership privacy and Realization request-type SQL hardening.';

    public function handle(
        PurchasingOwnershipScopeService $ownership,
        FundRequestService $fundRequests,
        OrderWorkflowService $orders,
        RealizationOrderService $realizations,
    ): int {
        $failed = false;
        $this->info('ERP POS FINAL I06 - Purchasing Privacy + Realization SQL Check');

        foreach (['pur_fund_requests', 'pur_purchase_orders', 'pur_service_orders', 'pur_reimburse_orders'] as $table) {
            $ok = Schema::hasTable($table);
            $this->line(sprintf('%-34s : %s', $table, $ok ? 'OK' : 'MISSING'));
            $failed = $failed || ! $ok;
        }

        if ($failed) {
            $this->error('Required Purchasing tables are missing.');
            return self::FAILURE;
        }

        $ordinaryUsers = User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => ! $ownership->canViewAll($user))
            ->take(max(1, (int) $this->option('users')))
            ->values();

        $privacyLeakCount = 0;
        foreach ($ordinaryUsers as $user) {
            $fundLeak = (clone $fundRequests->visibleQuery($user))
                ->where(function (Builder $query) use ($user): void {
                    $query->whereNull('created_by_user_id')
                        ->orWhere('created_by_user_id', '!=', (string) $user->id);
                })
                ->count();
            $privacyLeakCount += $fundLeak;

            foreach (['purchase-order', 'service-order', 'reimburse-order'] as $kind) {
                $orderLeak = (clone $orders->visibleQuery($kind, $user))
                    ->whereHas('fundRequest', function (Builder $request) use ($user): void {
                        $request->whereNull('created_by_user_id')
                            ->orWhere('created_by_user_id', '!=', (string) $user->id);
                    })
                    ->count();
                $privacyLeakCount += $orderLeak;
            }
        }

        $this->line(sprintf(
            '%-34s : %d (expected 0; sampled users %d)',
            'Ordinary-user privacy leaks',
            $privacyLeakCount,
            $ordinaryUsers->count(),
        ));
        if ($privacyLeakCount > 0) {
            $failed = true;
        }

        $globalUser = User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (User $user): bool => $ownership->canViewAll($user));
        $this->line(sprintf(
            '%-34s : %s',
            'Finance/Admin sample actor',
            $globalUser ? ((string) ($globalUser->name ?: $globalUser->id)) : 'NOT FOUND',
        ));

        if (! $this->option('skip-realization-query')) {
            $queryActor = $globalUser ?: $ordinaryUsers->first() ?: User::query()->where('is_active', true)->first();
            if (! $queryActor) {
                $this->warn('No active user found; Realization query regression skipped.');
            } else {
                foreach (['PURCHASE', 'SERVICE', 'REIMBURSE', 'ASSET', 'STOCK'] as $type) {
                    try {
                        $result = $realizations->overview($queryActor, ['type' => $type]);
                        $this->line(sprintf(
                            '%-34s : OK (%d rows)',
                            'Realization filter ' . $type,
                            count((array) ($result['items'] ?? [])),
                        ));
                    } catch (Throwable $e) {
                        $failed = true;
                        $this->error('Realization filter ' . $type . ' : FAIL - ' . $e->getMessage());
                    }
                }
            }
        }

        $serviceOrderHasOrderType = Schema::hasColumn('pur_service_orders', 'order_type');
        $reimburseOrderHasOrderType = Schema::hasColumn('pur_reimburse_orders', 'order_type');
        $this->line(sprintf('%-34s : %s', 'service_orders.order_type', $serviceOrderHasOrderType ? 'PRESENT' : 'ABSENT (supported)'));
        $this->line(sprintf('%-34s : %s', 'reimburse_orders.order_type', $reimburseOrderHasOrderType ? 'PRESENT' : 'ABSENT (supported)'));

        if ($failed) {
            $this->error('ERP POS FINAL I06 validation FAIL.');
            return self::FAILURE;
        }

        $this->info('ERP POS FINAL I06 validation PASS.');
        return self::SUCCESS;
    }
}
