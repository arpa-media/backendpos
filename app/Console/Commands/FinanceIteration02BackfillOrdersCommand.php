<?php

namespace App\Console\Commands;

use App\Models\Purchasing\FundRequest;
use App\Models\User;
use App\Services\Purchasing\OrderWorkflowService;
use Illuminate\Console\Command;
use Throwable;

class FinanceIteration02BackfillOrdersCommand extends Command
{
    protected $signature = 'finance:iteration-02-backfill-orders {--dry-run : Hanya hitung request yang perlu diproses}';

    protected $description = 'Generate satu Draft PO/SO/Reimburse Order canonical untuk Fund Request lama yang sudah approved.';

    public function handle(OrderWorkflowService $orders): int
    {
        $query = FundRequest::query()
            ->where('status', FundRequest::STATUS_APPROVED)
            ->whereIn('request_type', [
                FundRequest::TYPE_PURCHASE,
                FundRequest::TYPE_ASSET,
                FundRequest::TYPE_SERVICE,
                FundRequest::TYPE_REIMBURSE,
            ]);

        $total = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->info("Approved non-Stock Fund Request yang akan dipastikan Order-nya: {$total}");
            return self::SUCCESS;
        }

        $createdOrExisting = 0;
        $skipped = 0;
        $failed = 0;

        $query->chunkById(100, function ($requests) use ($orders, &$createdOrExisting, &$skipped, &$failed): void {
            foreach ($requests as $fundRequest) {
                $actorId = (string) ($fundRequest->approved_by_user_id ?: $fundRequest->created_by_user_id ?: '');
                $actor = $actorId !== '' ? User::query()->find($actorId) : null;
                if (! $actor) {
                    $skipped++;
                    $this->warn("SKIP {$fundRequest->request_number}: actor approval/pemohon tidak ditemukan.");
                    continue;
                }

                try {
                    $order = $orders->ensureDraftFromApprovedFundRequest($fundRequest, $actor);
                    $createdOrExisting++;
                    $this->line(sprintf(
                        'OK %s -> %s',
                        $fundRequest->request_number,
                        $order ? (string) ($order->po_number ?? $order->service_order_number ?? $order->reimburse_order_number ?? $order->id) : 'N/A'
                    ));
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error("FAILED {$fundRequest->request_number}: {$exception->getMessage()}");
                }
            }
        }, 'id');

        $this->table(
            ['Metric', 'Jumlah'],
            [
                ['Approved non-Stock', $total],
                ['Order dipastikan', $createdOrExisting],
                ['Skipped actor missing', $skipped],
                ['Failed', $failed],
            ]
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
