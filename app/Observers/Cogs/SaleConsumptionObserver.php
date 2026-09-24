<?php

namespace App\Observers\Cogs;

use App\Models\Sale;
use App\Services\Cogs\SaleConsumptionService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

class SaleConsumptionObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Sale $sale): void
    {
        $this->reconcile($sale, 'sale_created');
    }

    public function updated(Sale $sale): void
    {
        if ($sale->wasChanged(['status', 'deleted_at'])) {
            $this->reconcile($sale, 'sale_status_updated');
        }
    }

    public function deleted(Sale $sale): void
    {
        $this->reconcile($sale, 'sale_deleted');
    }

    private function reconcile(Sale $sale, string $reason): void
    {
        try {
            app(SaleConsumptionService::class)->reconcileSale((string) $sale->id, null, $reason);
        } catch (Throwable $exception) {
            Log::error('Automatic sale consumption observer failed.', [
                'sale_id' => (string) $sale->id,
                'reason' => $reason,
                'error' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }
}
