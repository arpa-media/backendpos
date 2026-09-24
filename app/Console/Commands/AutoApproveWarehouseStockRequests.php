<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Warehouse\WarehouseStockRequest;
use App\Services\Purchasing\StockRequestDraftPoBridgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AutoApproveWarehouseStockRequests extends Command
{
    protected $signature = 'warehouse:stock-request-auto-approve {--date= : Business date Asia/Jakarta (YYYY-MM-DD)} {--limit=300 : Maximum requests per run} {--dry-run : Show candidates without approving}';

    protected $description = 'Auto approve Stock Requests still pending SPV at 06:00 next business day and generate canonical PR/PO.';

    public function __construct(private readonly StockRequestDraftPoBridgeService $bridge)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $businessDate = $this->option('date')
            ? Carbon::parse((string) $this->option('date'), 'Asia/Jakarta')->startOfDay()
            : now('Asia/Jakarta')->startOfDay();
        // Request submitted any time before the current business day is eligible
        // when this command runs at 06:00 Asia/Jakarta.
        $cutoffUtc = $businessDate->clone()->utc();
        $limit = max(1, min((int) $this->option('limit'), 1000));

        $candidates = WarehouseStockRequest::query()
            ->with(['items.sku', 'outlet'])
            ->where('request_channel', 'warehouse_operations')
            ->where('status', WarehouseStockRequest::STATUS_SUBMITTED)
            ->where('request_approval_status', 'awaiting_approval1')
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '<', $cutoffUtc)
            ->orderBy('submitted_at')
            ->limit($limit)
            ->get();

        $this->info(sprintf('Business date %s: %d pending-SPV Stock Request candidate(s).', $businessDate->toDateString(), $candidates->count()));
        if ($this->option('dry-run')) {
            foreach ($candidates as $request) {
                $this->line(sprintf('%s | %s | submitted %s', $request->id, $request->request_number, optional($request->submitted_at)->toDateTimeString()));
            }
            return self::SUCCESS;
        }

        $actor = $this->systemActor();
        $approved = 0;
        $failed = 0;

        foreach ($candidates as $request) {
            try {
                $didApprove = DB::transaction(function () use ($request, $businessDate, $actor): bool {
                    /** @var WarehouseStockRequest|null $locked */
                    $locked = WarehouseStockRequest::query()
                        ->with(['items.sku', 'outlet'])
                        ->lockForUpdate()
                        ->find($request->id);
                    if (! $locked
                        || $locked->status !== WarehouseStockRequest::STATUS_SUBMITTED
                        || $locked->request_approval_status !== 'awaiting_approval1'
                        || ! $locked->submitted_at
                        || $locked->submitted_at->greaterThanOrEqualTo($businessDate->clone()->utc())) {
                        return false;
                    }

                    $note = sprintf(
                        'SYSTEM_AUTO_APPROVAL 06:00 Asia/Jakarta: Stock Request masih pending SPV sampai business date %s.',
                        $businessDate->toDateString(),
                    );
                    $this->bridge->approveStockRequest($locked, $actor, $note);

                    if (Schema::hasTable('stk_request_timelines')) {
                        $alreadyLogged = DB::table('stk_request_timelines')
                            ->where('stock_request_id', (string) $locked->id)
                            ->where('event_code', 'system_auto_approved_spv_0600')
                            ->exists();
                        if (! $alreadyLogged) {
                            DB::table('stk_request_timelines')->insert([
                                'id' => (string) Str::ulid(),
                                'stock_request_id' => (string) $locked->id,
                                'event_code' => 'system_auto_approved_spv_0600',
                                'status' => 'requested',
                                'message' => 'Stock Request otomatis di-approve sistem pada 06:00 hari berikutnya karena masih pending SPV; PR/PO canonical digenerate melalui bridge Purchasing.',
                                'metadata' => json_encode([
                                    'business_date' => $businessDate->toDateString(),
                                    'timezone' => 'Asia/Jakarta',
                                    'auto_approval' => true,
                                    'actor_type' => 'SYSTEM_AUTO_APPROVAL',
                                    'manual_spv_actor' => false,
                                ]),
                                'actor_user_id' => (string) $actor->id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }

                    return true;
                }, 3);
                if ($didApprove) $approved++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('ERP POS FINAL I04 auto approval Stock Request failed', [
                    'stock_request_id' => (string) $request->id,
                    'request_number' => (string) $request->request_number,
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('%s gagal: %s', $request->request_number, $e->getMessage()));
            }
        }

        $this->info(sprintf('Selesai. Approved=%d Failed=%d.', $approved, $failed));
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function systemActor(): User
    {
        $email = 'system-auto-approval@pos.local.invalid';
        $user = User::query()->where('email', $email)->first();
        if ($user) {
            if ((string) $user->name !== 'SYSTEM_AUTO_APPROVAL' || (bool) $user->is_active !== false) {
                $user->forceFill(['name' => 'SYSTEM_AUTO_APPROVAL', 'is_active' => false])->save();
            }
            return $user;
        }

        $payload = [
            'name' => 'SYSTEM_AUTO_APPROVAL',
            'email' => $email,
            'password' => Hash::make(Str::random(64)),
            'is_active' => false,
        ];
        if (Schema::hasColumn('users', 'username')) $payload['username'] = 'system_auto_approval';
        if (Schema::hasColumn('users', 'nisj')) $payload['nisj'] = null;

        return User::query()->create($payload);
    }
}
