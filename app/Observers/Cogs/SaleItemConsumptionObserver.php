<?php

namespace App\Observers\Cogs;

use App\Models\SaleItem;
use App\Services\Cogs\SaleConsumptionService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

class SaleItemConsumptionObserver implements ShouldHandleEventsAfterCommit
{
    public function created(SaleItem $item): void
    {
        $this->reconcile($item, 'sale_item_created');
    }

    public function updated(SaleItem $item): void
    {
        if ($item->wasChanged(['qty', 'variant_id', 'voided_at'])) {
            $this->reconcile($item, 'sale_item_updated');
        }
    }

    public function deleted(SaleItem $item): void
    {
        $this->reconcile($item, 'sale_item_deleted');
    }

    private function reconcile(SaleItem $item, string $reason): void
    {
        try {
            app(SaleConsumptionService::class)->reconcileSaleItem($item, null, $reason);
        } catch (Throwable $exception) {
            Log::error('Automatic Item Sold consumption observer failed.', [
                'sale_item_id' => (string) $item->id,
                'reason' => $reason,
                'error' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }
}
