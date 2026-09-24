<?php

namespace App\Observers\Cogs;

use App\Models\StockInventory\StockOpname;
use App\Services\Cogs\StockVarianceService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

class StockOpnameVarianceObserver implements ShouldHandleEventsAfterCommit
{
    public function created(StockOpname $opname): void
    {
        if ($opname->status === 'submitted') {
            $this->calculate($opname, 'stock_opname_created_submitted');
        }
    }

    public function updated(StockOpname $opname): void
    {
        if (! $opname->wasChanged('status')) {
            return;
        }

        if ($opname->status === 'submitted') {
            $this->calculate($opname, 'stock_opname_submitted');
            return;
        }

        try {
            app(StockVarianceService::class)->cancelForOpname($opname, 'stock_opname_status_changed_to_'.$opname->status);
        } catch (Throwable $exception) {
            Log::error('Automatic Stock Variance cancellation failed.', [
                'stock_opname_id' => (string) $opname->id,
                'status' => (string) $opname->status,
                'error' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }

    private function calculate(StockOpname $opname, string $reason): void
    {
        try {
            app(StockVarianceService::class)->calculateForOpname($opname, null, $reason);
        } catch (Throwable $exception) {
            Log::error('Automatic Stock Variance calculation failed.', [
                'stock_opname_id' => (string) $opname->id,
                'reason' => $reason,
                'error' => $exception->getMessage(),
            ]);
            report($exception);
        }
    }
}
