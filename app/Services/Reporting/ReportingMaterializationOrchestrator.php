<?php

namespace App\Services\Reporting;

use App\Jobs\Reporting\ProcessReportingMaterializationChunkJob;
use App\Services\Operational\ReportHourlySummaryService;
use App\Services\ReportDailySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReportingMaterializationOrchestrator
{
    private const ACTIVE_RUN_STATUSES = ['queued','running','pause_requested','paused','cancel_requested','waiting_window'];
    private const TERMINAL_CHUNK_STATUSES = ['completed','skipped','failed','cancelled'];

    public function __construct(
        private readonly ReportDailySummaryService $daily,
        private readonly ReportHourlySummaryService $hourly,
        private readonly ReportMonthlySummaryService $monthly,
        private readonly ReportHybridReadPlanner $hybrid,
        private readonly ReportingRuntimeStatusService $runtimeStatus,
    ) {}

    public function settings(): array
    {
        $row = Schema::hasTable('report_materialization_settings') ? DB::table('report_materialization_settings')->where('id','default')->first() : null;
        return [
            'auto_enabled'=>(bool)($row->auto_enabled ?? true),
            'rolling_days'=>(int)($row->rolling_days ?? 370),
            'outlet_chunk'=>(int)($row->outlet_chunk ?? 6),
            'date_chunk'=>(int)($row->date_chunk ?? 14),
            'window_start'=>substr((string)($row->window_start ?? '01:00:00'),0,5),
            'window_end'=>substr((string)($row->window_end ?? '05:00:00'),0,5),
            'timezone'=>(string)($row->timezone ?? 'Asia/Jakarta'),
            'daily_enabled'=>(bool)($row->daily_enabled ?? true),
            'hourly_enabled'=>(bool)($row->hourly_enabled ?? true),
            'monthly_enabled'=>(bool)($row->monthly_enabled ?? true),
            'worker_lease_seconds'=>(int)($row->worker_lease_seconds ?? 3600),
            'max_attempts'=>(int)($row->max_attempts ?? 3),
            'retry_backoff_seconds'=>(int)($row->retry_backoff_seconds ?? 60),
            'dispatch_batch'=>(int)($row->dispatch_batch ?? 4),
            'last_auto_enqueued_at'=>$row->last_auto_enqueued_at ?? null,
            'last_tick_at'=>$row->last_tick_at ?? null,
            'last_dispatch_at'=>$row->last_dispatch_at ?? null,
            'last_worker_heartbeat_at'=>$row->last_worker_heartbeat_at ?? null,
            'last_recovery_at'=>$row->last_recovery_at ?? null,
        ];
    }

    public function updateSettings(array $data): array
    {
        $existing = DB::table('report_materialization_settings')->where('id','default')->first();
        DB::table('report_materialization_settings')->updateOrInsert(['id'=>'default'], [
            'auto_enabled'=>(bool)($data['auto_enabled'] ?? true),
            'rolling_days'=>max(1,min(730,(int)($data['rolling_days'] ?? 370))),
            'outlet_chunk'=>max(1,min(25,(int)($data['outlet_chunk'] ?? 6))),
            'date_chunk'=>max(1,min(31,(int)($data['date_chunk'] ?? 14))),
            'window_start'=>($data['window_start'] ?? '01:00').':00',
            'window_end'=>($data['window_end'] ?? '05:00').':00',
            'timezone'=>(string)($data['timezone'] ?? 'Asia/Jakarta'),
            'daily_enabled'=>(bool)($data['daily_enabled'] ?? true),
            'hourly_enabled'=>(bool)($data['hourly_enabled'] ?? true),
            'monthly_enabled'=>(bool)($data['monthly_enabled'] ?? true),
            'updated_at'=>now(),
            'created_at'=>$existing->created_at ?? now(),
        ]);
        return $this->settings();
    }

    public function preview(array $params): array
    {
        [$from,$to]=$this->resolveRange($params);
        $outlets=$this->resolveOutletIds($params['outlet_ids'] ?? null);
        $settings=$this->settings();
        $outletChunk=max(1,min(25,(int)($params['outlet_chunk'] ?? $settings['outlet_chunk'])));
        $dateChunk=max(1,min(31,(int)($params['date_chunk'] ?? $settings['date_chunk'])));
        $daily=$this->dailyCoverage($outlets,$from,$to);
        $hourly=$this->hourlyCoverage($outlets,$from,$to);
        $monthly=$this->monthlyCoverage($outlets,$from,$to);
        $dateCount=CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to))+1;
        $estimatedDailyChunks=(int)ceil(count($outlets)/$outletChunk)*(int)ceil($dateCount/$dateChunk);
        $hybrid=$this->hybrid->plan($outlets,$from,$to);
        return compact('from','to','outlets','outletChunk','dateChunk','daily','hourly','monthly','estimatedDailyChunks','hybrid') + ['outlet_count'=>count($outlets),'range_days'=>$dateCount];
    }

    public function startRun(array $params, ?string $userId=null, string $trigger='manual'): array
    {
        $runId = DB::transaction(function () use ($params,$userId,$trigger): string {
            $this->lockOrchestratorRow();
            if ($this->activeRun()) throw new \RuntimeException('Masih ada Reporting Engine run yang aktif. Pause/Cancel/selesaikan run tersebut terlebih dahulu.');
            return $this->createRunUnlocked($params,$userId,$trigger);
        }, 3);
        return $this->runDetail($runId);
    }

    public function requestPause(string $runId): array
    {
        DB::transaction(function () use ($runId): void {
            $this->lockOrchestratorRow();
            DB::table('report_materialization_runs')->where('id',$runId)->whereIn('status',['queued','running','waiting_window'])->update(['status'=>'pause_requested','pause_requested_at'=>now(),'updated_at'=>now()]);
            DB::table('report_materialization_run_chunks')->where('run_id',$runId)->where('status','dispatched')->update([
                'status'=>'queued','dispatch_token'=>null,'dispatched_at'=>null,'lease_expires_at'=>null,'available_at'=>now(),'updated_at'=>now(),
            ]);
            if(!$this->hasRunningChunks($runId)) DB::table('report_materialization_runs')->where('id',$runId)->where('status','pause_requested')->update(['status'=>'paused','current_label'=>null,'updated_at'=>now()]);
        },3);
        return $this->runDetail($runId);
    }

    public function resume(string $runId): array
    {
        DB::transaction(function () use ($runId): void {
            $this->lockOrchestratorRow();
            DB::table('report_materialization_runs')->where('id',$runId)->whereIn('status',['paused','pause_requested','waiting_window'])->update(['status'=>'queued','pause_requested_at'=>null,'updated_at'=>now()]);
        },3);
        return $this->runDetail($runId);
    }

    public function requestCancel(string $runId): array
    {
        DB::transaction(function () use ($runId): void {
            $this->lockOrchestratorRow();
            DB::table('report_materialization_runs')->where('id',$runId)->whereNotIn('status',['completed','completed_with_errors','cancelled','failed'])->update(['status'=>'cancel_requested','cancel_requested_at'=>now(),'updated_at'=>now()]);
            DB::table('report_materialization_run_chunks')->where('run_id',$runId)->whereIn('status',['queued','pending_stage','dispatched'])->update([
                'status'=>'cancelled','dispatch_token'=>null,'worker_token'=>null,'lease_expires_at'=>null,'finished_at'=>now(),'updated_at'=>now(),
            ]);
            if(!$this->hasRunningChunks($runId)) {
                DB::table('report_materialization_runs')->where('id',$runId)->where('status','cancel_requested')->update(['status'=>'cancelled','progress_percent'=>100,'finished_at'=>now(),'current_label'=>null,'estimated_seconds_remaining'=>0,'updated_at'=>now()]);
                $this->releaseRecoveryRequestsForCancelledRun($runId);
                $this->syncRunProgress($runId);
            }
        },3);
        return $this->runDetail($runId);
    }

    public function retryFailed(string $runId): array
    {
        DB::transaction(function () use ($runId): void {
            $this->lockOrchestratorRow();
            $active=$this->activeRun();
            if($active && (string)$active->id!==$runId) throw new \RuntimeException('Tidak dapat Retry Failed karena masih ada Reporting Engine run lain yang aktif.');
            $first=DB::table('report_materialization_run_chunks')->where('run_id',$runId)->where('status','failed')->orderBy('sequence')->first();
            if (!$first) return;
            DB::table('report_materialization_run_chunks')->where('run_id',$runId)->where('status','failed')->update([
                'status'=>'queued','last_error'=>null,'available_at'=>now(),'dispatch_token'=>null,'worker_token'=>null,'dispatched_at'=>null,'claimed_at'=>null,'heartbeat_at'=>null,'lease_expires_at'=>null,'finished_at'=>null,'updated_at'=>now(),
            ]);
            $downstream = $first->stage==='daily' ? ['hourly','monthly'] : ($first->stage==='hourly' ? ['monthly'] : []);
            if($downstream!==[]) DB::table('report_materialization_run_chunks')->where('run_id',$runId)->whereIn('stage',$downstream)->where('status','cancelled')->update(['status'=>'pending_stage','finished_at'=>null,'updated_at'=>now()]);
            DB::table('report_materialization_runs')->where('id',$runId)->update(['status'=>'queued','current_stage'=>$first->stage,'finished_at'=>null,'last_error'=>null,'updated_at'=>now()]);
            $this->syncRunProgress($runId);
        },3);
        return $this->runDetail($runId);
    }

    /**
     * Scheduler-side orchestration only. Heavy materialization is dispatched to
     * the dedicated reporting queue and never executed inside schedule:run.
     */
    public function tick(int $maxDispatch=4): array
    {
        $claims = DB::transaction(function () use ($maxDispatch): array {
            $this->lockOrchestratorRow();
            DB::table('report_materialization_settings')->where('id','default')->update(['last_tick_at'=>now(),'updated_at'=>now()]);
            $recovered=$this->recoverStaleLeasesLocked();
            $this->maybeCreateDemandRunUnlocked();
            $this->maybeCreateAutoRunUnlocked();
            $run=$this->activeRun();
            if(!$run) return ['claims'=>[],'processed'=>0,'recovered'=>$recovered,'state'=>'idle','run_id'=>null];

            if($run->status==='pause_requested') {
                DB::table('report_materialization_run_chunks')->where('run_id',$run->id)->where('status','dispatched')->update([
                    'status'=>'queued','dispatch_token'=>null,'dispatched_at'=>null,'lease_expires_at'=>null,'available_at'=>now(),'updated_at'=>now(),
                ]);
                if(!$this->hasRunningChunks((string)$run->id)) DB::table('report_materialization_runs')->where('id',$run->id)->update(['status'=>'paused','current_label'=>null,'updated_at'=>now()]);
                return ['claims'=>[],'processed'=>0,'recovered'=>$recovered,'state'=>(string)(DB::table('report_materialization_runs')->where('id',$run->id)->value('status') ?? 'paused'),'run_id'=>(string)$run->id];
            }

            if($run->status==='cancel_requested') {
                DB::table('report_materialization_run_chunks')->where('run_id',$run->id)->whereIn('status',['queued','pending_stage','dispatched'])->update([
                    'status'=>'cancelled','dispatch_token'=>null,'worker_token'=>null,'lease_expires_at'=>null,'finished_at'=>now(),'updated_at'=>now(),
                ]);
                if(!$this->hasRunningChunks((string)$run->id)) {
                    DB::table('report_materialization_runs')->where('id',$run->id)->update(['status'=>'cancelled','progress_percent'=>100,'finished_at'=>now(),'current_label'=>null,'estimated_seconds_remaining'=>0,'updated_at'=>now()]);
                    $this->releaseRecoveryRequestsForCancelledRun((string)$run->id);
                    $this->syncRunProgress((string)$run->id);
                }
                return ['claims'=>[],'processed'=>0,'recovered'=>$recovered,'state'=>(string)(DB::table('report_materialization_runs')->where('id',$run->id)->value('status') ?? 'cancelled'),'run_id'=>(string)$run->id];
            }

            if(!$this->insideAllowedWindow($run)) {
                if($run->trigger==='auto') DB::table('report_materialization_runs')->where('id',$run->id)->update(['status'=>'waiting_window','updated_at'=>now()]);
                return ['claims'=>[],'processed'=>0,'recovered'=>$recovered,'state'=>'waiting_window','run_id'=>(string)$run->id];
            }
            if($run->status==='waiting_window') DB::table('report_materialization_runs')->where('id',$run->id)->update(['status'=>'queued','updated_at'=>now()]);

            $run=$this->advanceUntilDispatchableLocked((string)$run->id);
            if(!$run || in_array($run->status,['completed','completed_with_errors','cancelled','failed'],true)) {
                return ['claims'=>[],'processed'=>0,'recovered'=>$recovered,'state'=>$run?->status ?? 'completed','run_id'=>$run?->id];
            }

            $settings=$this->settings();
            $batch=max(1,min(20,$maxDispatch > 0 ? $maxDispatch : $settings['dispatch_batch']));
            $claims=$this->claimDispatchableChunksLocked($run,$batch,$settings['worker_lease_seconds']);
            if($claims!==[]) {
                DB::table('report_materialization_runs')->where('id',$run->id)->update(['status'=>'running','started_at'=>$run->started_at ?: now(),'updated_at'=>now()]);
                DB::table('report_materialization_settings')->where('id','default')->update(['last_dispatch_at'=>now(),'updated_at'=>now()]);
            }
            $state=$claims!==[]?'dispatched':($this->hasRunningOrDispatchedChunks((string)$run->id)?'worker_active':'retry_wait');
            return ['claims'=>$claims,'processed'=>count($claims),'recovered'=>$recovered,'state'=>$state,'run_id'=>(string)$run->id];
        },3);

        $dispatched=0;
        foreach($claims['claims'] as $claim) {
            try {
                ProcessReportingMaterializationChunkJob::dispatch($claim['chunk_id'],$claim['dispatch_token']);
                $dispatched++;
            } catch(\Throwable $e) {
                DB::table('report_materialization_run_chunks')->where('id',$claim['chunk_id'])->where('dispatch_token',$claim['dispatch_token'])->where('status','dispatched')->update([
                    'status'=>'queued','dispatch_token'=>null,'dispatched_at'=>null,'lease_expires_at'=>null,'available_at'=>now()->addSeconds(30),'last_error'=>'Queue dispatch failed: '.mb_substr($e->getMessage(),0,4500),'updated_at'=>now(),
                ]);
                DB::table('report_materialization_runs')->where('id',$claim['run_id'])->update(['last_error'=>'Queue dispatch failed: '.mb_substr($e->getMessage(),0,4500),'updated_at'=>now()]);
            }
        }
        unset($claims['claims']);
        $claims['dispatched']=$dispatched;
        return $claims;
    }

    /** Execute exactly one persisted chunk from the dedicated reporting worker. */
    public function executeQueuedChunk(string $chunkId,string $dispatchToken): void
    {
        $context=DB::transaction(function () use ($chunkId,$dispatchToken): ?array {
            $chunk=DB::table('report_materialization_run_chunks')->where('id',$chunkId)->lockForUpdate()->first();
            if(!$chunk || $chunk->status!=='dispatched' || !hash_equals((string)$chunk->dispatch_token,$dispatchToken)) return null;
            $run=DB::table('report_materialization_runs')->where('id',$chunk->run_id)->first();
            if(!$run) return null;
            if($run->status==='cancel_requested') {
                DB::table('report_materialization_run_chunks')->where('id',$chunkId)->update(['status'=>'cancelled','finished_at'=>now(),'dispatch_token'=>null,'lease_expires_at'=>null,'updated_at'=>now()]);
                return null;
            }
            if(in_array($run->status,['pause_requested','paused','waiting_window'],true)) {
                DB::table('report_materialization_run_chunks')->where('id',$chunkId)->update(['status'=>'queued','available_at'=>now(),'dispatch_token'=>null,'dispatched_at'=>null,'lease_expires_at'=>null,'updated_at'=>now()]);
                return null;
            }
            $settings=$this->settings();
            $workerToken=(gethostname() ?: 'worker').':'.getmypid().':'.Str::ulid();
            DB::table('report_materialization_run_chunks')->where('id',$chunkId)->update([
                'status'=>'running','attempts'=>DB::raw('attempts + 1'),'worker_token'=>$workerToken,'claimed_at'=>now(),'heartbeat_at'=>now(),'lease_expires_at'=>now()->addSeconds($settings['worker_lease_seconds']),'started_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('report_materialization_settings')->where('id','default')->update(['last_worker_heartbeat_at'=>now(),'updated_at'=>now()]);
            DB::table('report_materialization_runs')->where('id',$run->id)->update(['status'=>'running','current_stage'=>$chunk->stage,'current_label'=>strtoupper($chunk->stage).' '.$chunk->date_from.' → '.$chunk->date_to,'started_at'=>$run->started_at ?: now(),'updated_at'=>now()]);
            $freshChunk=DB::table('report_materialization_run_chunks')->where('id',$chunkId)->first();
            return ['run'=>$run,'chunk'=>$freshChunk,'settings'=>$settings,'worker_token'=>$workerToken];
        },3);
        if(!$context) return;

        $run=$context['run']; $chunk=$context['chunk']; $settings=$context['settings']; $workerToken=$context['worker_token']; $started=microtime(true);
        try {
            $this->performChunkWork($run,$chunk,$workerToken,(int)$settings['worker_lease_seconds']);
            DB::table('report_materialization_run_chunks')->where('id',$chunk->id)->where('worker_token',$workerToken)->where('status','running')->update([
                'status'=>'completed','duration_ms'=>(int)round((microtime(true)-$started)*1000),'heartbeat_at'=>now(),'lease_expires_at'=>null,'dispatch_token'=>null,'worker_token'=>null,'finished_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('report_materialization_settings')->where('id','default')->update(['last_worker_heartbeat_at'=>now(),'updated_at'=>now()]);
        } catch(\Throwable $e) {
            $this->handleChunkFailure($run,$chunk,$workerToken,$e,(int)$settings['max_attempts'],(int)$settings['retry_backoff_seconds'],(int)round((microtime(true)-$started)*1000));
        } finally {
            $this->syncRunProgress((string)$run->id);
            $this->settleRequestedRunState((string)$run->id);
        }
    }

    /** Lightweight status for UI polling. Avoids rolling coverage/calendar scans. */
    public function liveStatus(): array
    {
        $settings = $this->settings();
        $active = $this->activeRun();
        $activeRun = null;
        if ($active) {
            $activeRun = $this->formatRun($active);
            $activeRun['stage_progress'] = $this->stageProgress((string) $active->id);
        }

        $engineHealth = $this->engineHealth($settings, $active);

        return [
            'settings' => $settings,
            'engine_health' => $engineHealth,
            'runtime' => $this->runtimeStatus->snapshot($settings, $engineHealth),
            'active_run' => $activeRun,
            'recovery_queue' => Schema::hasTable('report_materialization_recovery_requests')
                ? [
                    'queued' => DB::table('report_materialization_recovery_requests')->where('status', 'queued')->count(),
                    'attached' => DB::table('report_materialization_recovery_requests')->where('status', 'attached')->count(),
                ]
                : ['queued' => 0, 'attached' => 0],
            'polled_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Demand recovery requested from a blocked report screen.
     * "Override" means priority missing-only recovery, never force rebuild.
     */
    public function requestCoverageRecovery(array $params, ?string $userId = null): array
    {
        if (! Schema::hasTable('report_materialization_recovery_requests')) {
            throw new \RuntimeException('Migration Materialization Recovery belum dijalankan. Jalankan php artisan migrate terlebih dahulu.');
        }

        $pipeline = strtolower((string) ($params['pipeline'] ?? 'daily'));
        if (! in_array($pipeline, ['daily', 'hourly'], true)) {
            throw new \InvalidArgumentException('Pipeline recovery harus daily atau hourly.');
        }

        [$from, $to] = $this->resolveRange($params);
        $ids = $this->resolveOutletIds($params['outlet_ids'] ?? null);
        sort($ids);
        if ($ids === []) throw new \InvalidArgumentException('Scope outlet untuk materialisasi kosong.');

        $coverage = $this->recoveryCoverage($pipeline, $ids, $from, $to);
        if (($coverage['ready'] ?? false) === true) {
            return ['state' => 'ready', 'coverage' => $coverage, 'request' => null, 'run' => null];
        }

        $requestId = DB::transaction(function () use ($pipeline, $ids, $from, $to, $userId): string {
            $this->lockOrchestratorRow();
            $scopeJson = json_encode(array_values($ids));

            $existing = DB::table('report_materialization_recovery_requests')
                ->where('pipeline', $pipeline)
                ->where('date_from', $from)
                ->where('date_to', $to)
                ->whereIn('status', ['queued', 'attached'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->first(function ($row) use ($ids): bool {
                    $rowIds = json_decode((string) ($row->outlet_ids ?? '[]'), true) ?: [];
                    $rowIds = array_values(array_unique(array_map('strval', $rowIds)));
                    sort($rowIds);
                    return $rowIds === $ids;
                });

            if ($existing) {
                if ((string) $existing->status === 'attached' && ! empty($existing->run_id)) {
                    $this->prioritizeRunCoverageLocked((string) $existing->run_id, $pipeline, $ids, $from, $to);
                }
                return (string) $existing->id;
            }

            $id = (string) Str::ulid();
            DB::table('report_materialization_recovery_requests')->insert([
                'id' => $id,
                'requested_by' => $userId ?: null,
                'pipeline' => $pipeline,
                'outlet_ids' => $scopeJson,
                'date_from' => $from,
                'date_to' => $to,
                'status' => 'queued',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $active = $this->activeRun();
            if ($active && (string) $active->trigger === 'auto' && (string) $active->status === 'waiting_window') {
                $this->preemptWaitingAutoRunForDemandLocked($active);
                $active = $this->activeRun();
            }
            if ($active && $this->runCanAbsorbRecovery($active, $pipeline, $ids, $from, $to)) {
                $this->prioritizeRunCoverageLocked((string) $active->id, $pipeline, $ids, $from, $to);
                DB::table('report_materialization_recovery_requests')->where('id', $id)->update([
                    'status' => 'attached', 'run_id' => (string) $active->id, 'attached_at' => now(), 'updated_at' => now(),
                ]);
            } elseif (! $active) {
                $runId = $this->createRunUnlocked([
                    'date_from' => $from,
                    'date_to' => $to,
                    'outlet_ids' => $ids,
                    'pipeline' => $pipeline,
                    'mode' => 'missing_only',
                ], $userId, 'demand');
                $this->prioritizeRunCoverageLocked($runId, $pipeline, $ids, $from, $to);
                DB::table('report_materialization_recovery_requests')->where('id', $id)->update([
                    'status' => 'attached', 'run_id' => $runId, 'attached_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $id;
        }, 3);

        // Dispatch immediately when worker capacity exists; scheduler remains the fallback.
        $settings = $this->settings();
        $this->tick((int) ($settings['dispatch_batch'] ?? 4));

        return $this->recoveryRequestDetail($requestId, $pipeline, $ids, $from, $to);
    }

    public function recoveryStatus(array $params): array
    {
        $pipeline = strtolower((string) ($params['pipeline'] ?? 'daily'));
        if (! in_array($pipeline, ['daily', 'hourly'], true)) throw new \InvalidArgumentException('Pipeline recovery harus daily atau hourly.');
        [$from, $to] = $this->resolveRange($params);
        $ids = $this->resolveOutletIds($params['outlet_ids'] ?? null);
        sort($ids);
        $coverage = $this->recoveryCoverage($pipeline, $ids, $from, $to);
        $request = null;
        if (Schema::hasTable('report_materialization_recovery_requests')) {
            $candidates = DB::table('report_materialization_recovery_requests')
                ->where('pipeline', $pipeline)->where('date_from', $from)->where('date_to', $to)
                ->orderByDesc('created_at')->limit(20)->get();
            foreach ($candidates as $candidate) {
                $candidateIds = json_decode((string) ($candidate->outlet_ids ?? '[]'), true) ?: [];
                $candidateIds = array_values(array_unique(array_map('strval', $candidateIds))); sort($candidateIds);
                if ($candidateIds === $ids) { $request = $candidate; break; }
            }
        }
        if (($coverage['ready'] ?? false) && $request && ! in_array((string) $request->status, ['completed', 'failed'], true)) {
            DB::table('report_materialization_recovery_requests')->where('id', $request->id)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
            $request = DB::table('report_materialization_recovery_requests')->where('id', $request->id)->first();
        } elseif (! ($coverage['ready'] ?? false) && $request && (string) $request->status === 'completed') {
            // Coverage can become stale again after a successful run. Re-open the same
            // recovery request instead of leaving the UI in a false completed state.
            DB::table('report_materialization_recovery_requests')->where('id', $request->id)->update([
                'status'=>'queued','run_id'=>null,'attached_at'=>null,'completed_at'=>null,'updated_at'=>now(),
            ]);
            $request = DB::table('report_materialization_recovery_requests')->where('id', $request->id)->first();
        }
        return [
            'state' => ($coverage['ready'] ?? false) ? 'ready' : ($request ? (string) $request->status : 'waiting'),
            'coverage' => $coverage,
            'request' => $request ? $this->formatRecoveryRequest($request) : null,
            'run' => ($request && ! empty($request->run_id) && DB::table('report_materialization_runs')->where('id', $request->run_id)->exists())
                ? $this->runDetail((string) $request->run_id)
                : null,
        ];
    }

    public function dashboard(): array
    {
        $settings=$this->settings();
        $clock=CarbonImmutable::now($settings['timezone']);
        $to=$clock->toDateString();
        $from=$clock->subDays($settings['rolling_days']-1)->toDateString();
        $outlets=$this->resolveOutletIds(null);
        $active=$this->activeRun();
        $engineHealth=$this->engineHealth($settings,$active);
        return [
            'settings'=>$settings,
            'engine_health'=>$engineHealth,
            'runtime'=>$this->runtimeStatus->snapshot($settings,$engineHealth),
            'target'=>['date_from'=>$from,'date_to'=>$to,'days'=>$settings['rolling_days'],'outlet_count'=>count($outlets)],
            'coverage'=>['daily'=>$this->dailyCoverage($outlets,$from,$to),'hourly'=>$this->hourlyCoverage($outlets,$from,$to),'monthly'=>$this->monthlyCoverage($outlets,$from,$to)],
            'active_run'=>$active ? $this->runDetail((string)$active->id) : null,
            'history'=>$this->history(),
            'calendar'=>$this->calendarCoverage($outlets, $clock->subMonths(14)->startOfMonth()->startOfDay()->toDateString(), $to),
            'outlets'=>DB::table('outlets')->whereRaw("LOWER(COALESCE(type,'outlet'))='outlet'")->orderBy('name')->get(['id','code','name'])->map(fn($o)=>['id'=>(string)$o->id,'code'=>(string)($o->code??''),'name'=>(string)($o->name??'-')])->all(),
        ];
    }

    public function history(int $limit=20): array
    {
        return DB::table('report_materialization_runs')->orderByDesc('created_at')->limit($limit)->get()->map(fn($r)=>$this->formatRun($r))->all();
    }

    public function runDetail(string $id): array
    {
        $run=DB::table('report_materialization_runs')->where('id',$id)->first();
        if(!$run) throw new \RuntimeException('Run tidak ditemukan.');
        if(in_array((string)$run->status,['cancel_requested','pause_requested'],true)) {
            $this->settleRequestedRunState($id);
            $run=DB::table('report_materialization_runs')->where('id',$id)->first();
        }
        $data=$this->formatRun($run);
        $data['stage_progress']=$this->stageProgress($id);
        $data['recent_chunks']=DB::table('report_materialization_run_chunks')->where('run_id',$id)->orderByDesc('updated_at')->limit(30)->get()->map(fn($c)=>[
            'id'=>(string)$c->id,'stage'=>(string)$c->stage,'sequence'=>(int)$c->sequence,'date_from'=>(string)$c->date_from,'date_to'=>(string)$c->date_to,'business_month'=>$c->business_month,'status'=>(string)$c->status,'attempts'=>(int)$c->attempts,'recovery_count'=>(int)($c->recovery_count??0),'duration_ms'=>$c->duration_ms,'available_at'=>$c->available_at ?? null,'lease_expires_at'=>$c->lease_expires_at ?? null,'last_error'=>$c->last_error,
        ])->all();
        return $data;
    }

    private function createRunUnlocked(array $params,?string $userId,string $trigger): string
    {
        // Starting a run must be cheap. Do not call preview(): preview calculates daily,
        // hourly, monthly and hybrid coverage and is intended for explicit UI preview only.
        [$from, $to] = $this->resolveRange($params);
        $outlets = $this->resolveOutletIds($params['outlet_ids'] ?? null);
        if ($outlets === []) throw new \RuntimeException('Tidak ada outlet aktif dalam scope Reporting Engine.');
        $settings = $this->settings();
        $outletChunk = max(1, min(25, (int) ($params['outlet_chunk'] ?? $settings['outlet_chunk'])));
        $dateChunk = max(1, min(31, (int) ($params['date_chunk'] ?? $settings['date_chunk'])));
        $runId=(string)Str::ulid(); $mode=(string)($params['mode'] ?? 'missing_only'); $pipeline=(string)($params['pipeline'] ?? 'full');
        DB::table('report_materialization_runs')->insert([
            'id'=>$runId,'trigger'=>$trigger,'mode'=>$mode,'pipeline'=>$pipeline,'date_from'=>$from,'date_to'=>$to,'target_days'=>$params['days'] ?? null,'outlet_ids'=>json_encode($outlets),'outlet_chunk'=>$outletChunk,'date_chunk'=>$dateChunk,'status'=>'queued','current_stage'=>'daily','requested_by'=>$userId?:null,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->planDailyChunks($runId,$outlets,$from,$to,$outletChunk,$dateChunk,$mode);
        if(in_array($pipeline,['full','hourly'],true)) $this->planHourlyChunks($runId,$outlets,$from,$to,$outletChunk,$dateChunk,'pending_stage');
        if(in_array($pipeline,['full','monthly'],true)) $this->planMonthlyChunks($runId,$outlets,$from,$to,$outletChunk,'pending_stage');
        $this->syncRunProgress($runId);
        return $runId;
    }

    private function performChunkWork(object $run,object $chunk,string $workerToken,int $leaseSeconds): void
    {
        $ids=json_decode((string)$chunk->outlet_ids,true) ?: [];
        $this->touchLease((string)$chunk->id,$workerToken,$leaseSeconds);
        if($chunk->stage==='daily') {
            $options=['outlet_chunk'=>max(1,count($ids)),'date_chunk_days'=>max(1,CarbonImmutable::parse($chunk->date_from)->diffInDays(CarbonImmutable::parse($chunk->date_to))+1)];
            if($run->mode==='force_rebuild') {
                $this->daily->refreshExactCoverage($ids,$chunk->date_from,$chunk->date_to,config('app.timezone','Asia/Jakarta'),$options);
            } else {
                $status=$this->daily->readContractStatus($ids,$chunk->date_from,$chunk->date_to,config('app.timezone','Asia/Jakarta'));
                if((int)($status['pending_refresh_rows']??0)>0) $this->daily->refreshExactCoverage($ids,$chunk->date_from,$chunk->date_to,config('app.timezone','Asia/Jakarta'),$options);
                else $this->daily->ensureCoverage($ids,$chunk->date_from,$chunk->date_to,config('app.timezone','Asia/Jakarta'),$options);
            }
        } elseif($chunk->stage==='hourly') {
            for($d=CarbonImmutable::parse($chunk->date_from);$d->lte(CarbonImmutable::parse($chunk->date_to));$d=$d->addDay()) {
                $this->touchLease((string)$chunk->id,$workerToken,$leaseSeconds);
                if($run->mode!=='force_rebuild' && ($this->hourly->readContractStatus($ids,$d->toDateString())['ready']??false)) continue;
                $this->hourly->refreshDate($ids,$d->toDateString());
            }
        } elseif($chunk->stage==='monthly') {
            $this->monthly->refreshMonth($ids,(string)$chunk->business_month);
        }
        $this->touchLease((string)$chunk->id,$workerToken,$leaseSeconds);
    }

    private function handleChunkFailure(object $run,object $chunk,string $workerToken,\Throwable $e,int $maxAttempts,int $baseBackoff,int $durationMs): void
    {
        $fresh=DB::table('report_materialization_run_chunks')->where('id',$chunk->id)->first();
        $attempts=(int)($fresh->attempts ?? 1); $message=mb_substr($e->getMessage(),0,5000);
        if($attempts < max(1,$maxAttempts)) {
            $backoff=min(1800,max(5,$baseBackoff)*(2 ** max(0,$attempts-1)));
            DB::table('report_materialization_run_chunks')->where('id',$chunk->id)->where('worker_token',$workerToken)->update([
                'status'=>'queued','duration_ms'=>$durationMs,'last_error'=>$message,'available_at'=>now()->addSeconds($backoff),'dispatch_token'=>null,'worker_token'=>null,'lease_expires_at'=>null,'heartbeat_at'=>now(),'updated_at'=>now(),
            ]);
        } else {
            DB::table('report_materialization_run_chunks')->where('id',$chunk->id)->where('worker_token',$workerToken)->update([
                'status'=>'failed','duration_ms'=>$durationMs,'last_error'=>$message,'dispatch_token'=>null,'worker_token'=>null,'lease_expires_at'=>null,'heartbeat_at'=>now(),'finished_at'=>now(),'updated_at'=>now(),
            ]);
        }
        DB::table('report_materialization_runs')->where('id',$run->id)->update(['last_error'=>$message,'updated_at'=>now()]);
        DB::table('report_materialization_settings')->where('id','default')->update(['last_worker_heartbeat_at'=>now(),'updated_at'=>now()]);
    }

    private function touchLease(string $chunkId,string $workerToken,int $leaseSeconds): void
    {
        DB::table('report_materialization_run_chunks')->where('id',$chunkId)->where('worker_token',$workerToken)->where('status','running')->update(['heartbeat_at'=>now(),'lease_expires_at'=>now()->addSeconds($leaseSeconds),'updated_at'=>now()]);
        DB::table('report_materialization_settings')->where('id','default')->update(['last_worker_heartbeat_at'=>now(),'updated_at'=>now()]);
    }

    private function advanceUntilDispatchableLocked(string $runId): ?object
    {
        for($guard=0;$guard<5;$guard++) {
            $run=DB::table('report_materialization_runs')->where('id',$runId)->first(); if(!$run) return null;
            $stage=(string)($run->current_stage ?: 'daily');
            $failed=DB::table('report_materialization_run_chunks')->where('run_id',$runId)->where('stage',$stage)->where('status','failed')->count();
            $active=DB::table('report_materialization_run_chunks')->where('run_id',$runId)->where('stage',$stage)->whereIn('status',['queued','dispatched','running'])->count();
            if($failed>0 && $active===0) { $this->finishRun($runId); return DB::table('report_materialization_runs')->where('id',$runId)->first(); }
            if($active>0) return $run;
            if($this->advanceStageLocked($runId)) continue;
            $this->finishRun($runId); return DB::table('report_materialization_runs')->where('id',$runId)->first();
        }
        return DB::table('report_materialization_runs')->where('id',$runId)->first();
    }

    private function advanceStageLocked(string $runId): bool
    {
        $run=DB::table('report_materialization_runs')->where('id',$runId)->first(); if(!$run) return false;
        $next=null;
        if($run->current_stage==='daily') {
            if(in_array($run->pipeline,['full','hourly'],true)) $next='hourly';
            elseif($run->pipeline==='monthly') $next='monthly';
        } elseif($run->current_stage==='hourly' && $run->pipeline==='full') $next='monthly';
        if(!$next) return false;
        // Backward compatibility for an I09 run that was already active during deploy: I09
        // planned downstream stages lazily, while I11 plans them up-front.
        if(!DB::table('report_materialization_run_chunks')->where('run_id',$runId)->where('stage',$next)->exists()) {
            $ids=json_decode((string)$run->outlet_ids,true) ?: [];
            if($next==='hourly') $this->planHourlyChunks($runId,$ids,(string)$run->date_from,(string)$run->date_to,(int)$run->outlet_chunk,(int)$run->date_chunk,'pending_stage');
            else $this->planMonthlyChunks($runId,$ids,(string)$run->date_from,(string)$run->date_to,(int)$run->outlet_chunk,'pending_stage');
        }
        $this->activateStageLocked($run,$next);
        DB::table('report_materialization_runs')->where('id',$runId)->update(['current_stage'=>$next,'current_label'=>null,'updated_at'=>now()]);
        $this->syncRunProgress($runId);
        return true;
    }

    private function activateStageLocked(object $run,string $stage): void
    {
        $chunks=DB::table('report_materialization_run_chunks')->where('run_id',$run->id)->where('stage',$stage)->where('status','pending_stage')->orderBy('sequence')->get();
        foreach($chunks as $chunk) {
            $ids=json_decode((string)$chunk->outlet_ids,true) ?: []; $skip=false;
            if($run->mode!=='force_rebuild') {
                if($stage==='hourly') $skip=$this->hourlyChunkReady($ids,(string)$chunk->date_from,(string)$chunk->date_to);
                elseif($stage==='monthly') $skip=(bool)($this->monthly->readContractStatus($ids,(string)$chunk->business_month)['ready']??false);
            }
            DB::table('report_materialization_run_chunks')->where('id',$chunk->id)->update(['status'=>$skip?'skipped':'queued','available_at'=>$skip?null:now(),'finished_at'=>$skip?now():null,'updated_at'=>now()]);
        }
    }

    private function hourlyChunkReady(array $ids,string $from,string $to): bool
    {
        for($d=CarbonImmutable::parse($from);$d->lte(CarbonImmutable::parse($to));$d=$d->addDay()) if(!($this->hourly->readContractStatus($ids,$d->toDateString())['ready']??false)) return false;
        return true;
    }

    private function claimDispatchableChunksLocked(object $run,int $limit,int $leaseSeconds): array
    {
        $now=now();
        $inFlight=(int)DB::table('report_materialization_run_chunks')->where('run_id',$run->id)->where('stage',$run->current_stage)->whereIn('status',['dispatched','running'])->count();
        $capacity=max(0,$limit-$inFlight);
        if($capacity===0) return [];
        $rows=DB::table('report_materialization_run_chunks')->where('run_id',$run->id)->where('stage',$run->current_stage)->where('status','queued')
            ->where(function($q) use($now): void { $q->whereNull('available_at')->orWhere('available_at','<=',$now); });
        if (Schema::hasColumn('report_materialization_run_chunks', 'priority')) $rows->orderByDesc('priority');
        $rows=$rows->orderBy('sequence')->limit($capacity)->lockForUpdate()->get();
        $claims=[];
        foreach($rows as $chunk) {
            $token=(string)Str::ulid();
            DB::table('report_materialization_run_chunks')->where('id',$chunk->id)->where('status','queued')->update([
                'status'=>'dispatched','dispatch_token'=>$token,'dispatched_at'=>$now,'lease_expires_at'=>$now->copy()->addSeconds($leaseSeconds),'updated_at'=>$now,
            ]);
            $claims[]=['run_id'=>(string)$run->id,'chunk_id'=>(string)$chunk->id,'dispatch_token'=>$token];
        }
        return $claims;
    }

    private function recoverStaleLeasesLocked(): int
    {
        if(!Schema::hasColumn('report_materialization_run_chunks','lease_expires_at')) return 0;
        $settings=$this->settings(); $now=now(); $recovered=0;
        $rows=DB::table('report_materialization_run_chunks')->whereIn('status',['dispatched','running'])->whereNotNull('lease_expires_at')->where('lease_expires_at','<',$now)->orderBy('lease_expires_at')->limit(100)->lockForUpdate()->get();
        foreach($rows as $chunk) {
            $attempts=(int)$chunk->attempts; $nextRecovery=(int)($chunk->recovery_count??0)+1;
            // A worker outage can leave a job dispatched but never claimed (attempts=0).
            // Count lease recoveries toward the same retry budget so queue growth remains bounded.
            $effectiveFailures=max($attempts,$nextRecovery);
            $canRetry=$effectiveFailures < max(1,$settings['max_attempts']);
            DB::table('report_materialization_run_chunks')->where('id',$chunk->id)->update([
                'status'=>$canRetry?'queued':'failed','available_at'=>$canRetry?$now->copy()->addSeconds(max(5,$settings['retry_backoff_seconds'])):null,'dispatch_token'=>null,'worker_token'=>null,'dispatched_at'=>null,'lease_expires_at'=>null,'heartbeat_at'=>$now,'recovery_count'=>$nextRecovery,'last_error'=>trim(((string)($chunk->last_error??''))."\nI11 stale lease recovered at ".$now->toDateTimeString()),'finished_at'=>$canRetry?null:$now,'updated_at'=>$now,
            ]);
            DB::table('report_materialization_runs')->where('id',$chunk->run_id)->update(['recovery_count'=>DB::raw('recovery_count + 1'),'last_error'=>'Stale reporting worker lease recovered for chunk '.(string)$chunk->id,'updated_at'=>$now]);
            $recovered++;
        }
        if($recovered>0) DB::table('report_materialization_settings')->where('id','default')->update(['last_recovery_at'=>$now,'updated_at'=>$now]);
        return $recovered;
    }

    private function planDailyChunks(string $runId,array $ids,string $from,string $to,int $outletChunk,int $dateChunk,string $mode): void
    {
        $seq=1; $d=CarbonImmutable::parse($from); $end=CarbonImmutable::parse($to);
        while($d->lte($end)) {
            $dTo=$d->addDays($dateChunk-1); if($dTo->gt($end)) $dTo=$end;
            foreach(array_chunk($ids,$outletChunk) as $chunkIds) {
                $status=$this->daily->readContractStatus($chunkIds,$d->toDateString(),$dTo->toDateString());
                $skip=$mode!=='force_rebuild' && ($status['ready']??false) && (int)($status['pending_refresh_rows']??0)===0;
                $this->insertChunk($runId,$seq++,'daily',$chunkIds,$d->toDateString(),$dTo->toDateString(),null,$skip?'skipped':'queued');
            }
            $d=$dTo->addDay();
        }
    }

    private function planHourlyChunks(string $runId,array $ids,string $from,string $to,int $outletChunk,int $dateChunk,string $status='pending_stage'): void
    {
        $seq=(int)(DB::table('report_materialization_run_chunks')->where('run_id',$runId)->max('sequence')??0)+1; $d=CarbonImmutable::parse($from); $end=CarbonImmutable::parse($to);
        while($d->lte($end)) { $dTo=$d->addDays($dateChunk-1); if($dTo->gt($end))$dTo=$end; foreach(array_chunk($ids,$outletChunk) as $chunkIds) $this->insertChunk($runId,$seq++,'hourly',$chunkIds,$d->toDateString(),$dTo->toDateString(),null,$status); $d=$dTo->addDay(); }
    }

    private function planMonthlyChunks(string $runId,array $ids,string $from,string $to,int $outletChunk,string $status='pending_stage'): void
    {
        $seq=(int)(DB::table('report_materialization_run_chunks')->where('run_id',$runId)->max('sequence')??0)+1;
        foreach($this->fullMonths($from,$to) as $month) {
            $m=CarbonImmutable::parse($month);
            foreach(array_chunk($ids,$outletChunk) as $chunkIds) $this->insertChunk($runId,$seq++,'monthly',$chunkIds,$m->toDateString(),$m->endOfMonth()->toDateString(),$m->toDateString(),$status);
        }
    }

    private function insertChunk(string $runId,int $seq,string $stage,array $ids,string $from,string $to,?string $month=null,string $status='queued'): void
    {
        DB::table('report_materialization_run_chunks')->insert(['id'=>(string)Str::ulid(),'run_id'=>$runId,'stage'=>$stage,'sequence'=>$seq,'outlet_ids'=>json_encode(array_values($ids)),'date_from'=>$from,'date_to'=>$to,'business_month'=>$month,'status'=>$status,'available_at'=>$status==='queued'?now():null,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function finishRun(string $id): void
    {
        $failed=DB::table('report_materialization_run_chunks')->where('run_id',$id)->where('status','failed')->count();
        if($failed>0) DB::table('report_materialization_run_chunks')->where('run_id',$id)->where('status','pending_stage')->update(['status'=>'cancelled','finished_at'=>now(),'updated_at'=>now()]);
        $finalStatus=$failed>0?'completed_with_errors':'completed';
        DB::table('report_materialization_runs')->where('id',$id)->update(['status'=>$finalStatus,'progress_percent'=>100,'finished_at'=>now(),'current_label'=>null,'estimated_seconds_remaining'=>0,'updated_at'=>now()]);
        if (Schema::hasTable('report_materialization_recovery_requests')) {
            DB::table('report_materialization_recovery_requests')->where('run_id',$id)->where('status','attached')->update([
                'status'=>$failed>0?'failed':'completed','completed_at'=>now(),'updated_at'=>now(),
            ]);
        }
        $this->syncRunProgress($id);
    }

    private function syncRunProgress(string $id): void
    {
        $counts=DB::table('report_materialization_run_chunks')->where('run_id',$id)->selectRaw('COUNT(*) total')->selectRaw("SUM(status='completed') completed")->selectRaw("SUM(status='skipped') skipped")->selectRaw("SUM(status='failed') failed")->selectRaw("SUM(status='cancelled') cancelled")->first();
        $total=(int)($counts->total??0); $done=(int)($counts->completed??0)+(int)($counts->skipped??0)+(int)($counts->failed??0)+(int)($counts->cancelled??0);
        $avg=(float)(DB::table('report_materialization_run_chunks')->where('run_id',$id)->where('status','completed')->whereNotNull('duration_ms')->avg('duration_ms')??0);
        DB::table('report_materialization_runs')->where('id',$id)->update(['total_chunks'=>$total,'completed_chunks'=>(int)($counts->completed??0),'skipped_chunks'=>(int)($counts->skipped??0),'failed_chunks'=>(int)($counts->failed??0),'progress_percent'=>$total?min(100,(int)floor($done/$total*100)):0,'estimated_seconds_remaining'=>$avg>0?(int)ceil(max(0,$total-$done)*$avg/1000):null,'updated_at'=>now()]);
    }

    private function stageProgress(string $runId): array
    {
        $rows=DB::table('report_materialization_run_chunks')->where('run_id',$runId)->select('stage','status',DB::raw('COUNT(*) as aggregate'))->groupBy('stage','status')->get();
        $result=[];
        foreach(['daily','hourly','monthly'] as $stage) {
            $map=[]; foreach($rows->where('stage',$stage) as $r) $map[(string)$r->status]=(int)$r->aggregate;
            $total=array_sum($map); $done=($map['completed']??0)+($map['skipped']??0)+($map['failed']??0)+($map['cancelled']??0);
            $result[$stage]=['total'=>$total,'completed'=>$map['completed']??0,'skipped'=>$map['skipped']??0,'failed'=>$map['failed']??0,'cancelled'=>$map['cancelled']??0,'queued'=>$map['queued']??0,'pending_stage'=>$map['pending_stage']??0,'dispatched'=>$map['dispatched']??0,'running'=>$map['running']??0,'percent'=>$total?min(100,(int)floor($done/$total*100)):100];
        }
        return $result;
    }

    private function activeRun(): ?object
    {
        return Schema::hasTable('report_materialization_runs') ? DB::table('report_materialization_runs')->whereIn('status',self::ACTIVE_RUN_STATUSES)->orderBy('created_at')->first() : null;
    }

    private function releaseRecoveryRequestsForCancelledRun(string $runId): void
    {
        if (! Schema::hasTable('report_materialization_recovery_requests')) return;
        DB::table('report_materialization_recovery_requests')->where('run_id',$runId)->where('status','attached')->update([
            'status'=>'queued','run_id'=>null,'attached_at'=>null,'completed_at'=>null,'updated_at'=>now(),
        ]);
    }

    private function maybeCreateDemandRunUnlocked(): void
    {
        if (! Schema::hasTable('report_materialization_recovery_requests') || $this->activeRun()) return;
        $request = DB::table('report_materialization_recovery_requests')->where('status','queued')->orderBy('created_at')->lockForUpdate()->first();
        if (! $request) return;
        $ids = json_decode((string) $request->outlet_ids, true) ?: [];
        $runId = $this->createRunUnlocked([
            'date_from' => (string) $request->date_from,
            'date_to' => (string) $request->date_to,
            'outlet_ids' => $ids,
            'pipeline' => (string) $request->pipeline,
            'mode' => 'missing_only',
        ], $request->requested_by ? (string) $request->requested_by : null, 'demand');
        $this->prioritizeRunCoverageLocked($runId, (string) $request->pipeline, $ids, (string) $request->date_from, (string) $request->date_to);
        DB::table('report_materialization_recovery_requests')->where('id',$request->id)->update([
            'status'=>'attached','run_id'=>$runId,'attached_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function preemptWaitingAutoRunForDemandLocked(object $run): void
    {
        if ((string) $run->trigger !== 'auto' || (string) $run->status !== 'waiting_window') return;
        DB::table('report_materialization_run_chunks')->where('run_id',$run->id)->whereIn('status',['queued','pending_stage','dispatched'])->update([
            'status'=>'cancelled','dispatch_token'=>null,'worker_token'=>null,'lease_expires_at'=>null,'finished_at'=>now(),'updated_at'=>now(),
        ]);
        if ($this->hasRunningChunks((string) $run->id)) {
            DB::table('report_materialization_runs')->where('id',$run->id)->update(['status'=>'cancel_requested','cancel_requested_at'=>now(),'updated_at'=>now()]);
            return;
        }
        DB::table('report_materialization_runs')->where('id',$run->id)->update([
            'status'=>'cancelled','progress_percent'=>100,'finished_at'=>now(),'current_label'=>null,'estimated_seconds_remaining'=>0,'updated_at'=>now(),
        ]);
        $this->syncRunProgress((string) $run->id);
    }

    private function runCanAbsorbRecovery(object $run,string $pipeline,array $ids,string $from,string $to): bool
    {
        if ((string) $run->date_from > $from || (string) $run->date_to < $to) return false;
        if (in_array((string) $run->status, ['paused','pause_requested','cancel_requested'], true)) return false;
        $runIds = json_decode((string) ($run->outlet_ids ?? '[]'), true) ?: [];
        $runMap = array_flip(array_map('strval', $runIds));
        foreach ($ids as $id) if (! isset($runMap[(string) $id])) return false;
        $stage = (string) ($run->current_stage ?: 'daily');
        // A recovery may only attach before its required stage has passed.
        if ($pipeline === 'daily' && $stage !== 'daily') return false;
        if ($pipeline === 'hourly') {
            if (! in_array((string) $run->pipeline, ['hourly','full'], true)) return false;
            if (! in_array($stage, ['daily','hourly'], true)) return false;
        }
        return true;
    }

    private function prioritizeRunCoverageLocked(string $runId,string $pipeline,array $ids,string $from,string $to): int
    {
        if (! Schema::hasColumn('report_materialization_run_chunks','priority')) return 0;
        $stages = $pipeline === 'hourly' ? ['daily','hourly'] : ['daily'];
        $wanted = array_flip(array_map('strval', $ids));
        $rows = DB::table('report_materialization_run_chunks')
            ->where('run_id',$runId)->whereIn('stage',$stages)->whereIn('status',['queued','pending_stage'])
            ->where('date_from','<=',$to)->where('date_to','>=',$from)->lockForUpdate()->get(['id','outlet_ids']);
        $updated=0;
        foreach($rows as $chunk) {
            $chunkIds=json_decode((string)$chunk->outlet_ids,true) ?: [];
            $intersects=false; foreach($chunkIds as $id) { if(isset($wanted[(string)$id])) { $intersects=true; break; } }
            if(!$intersects) continue;
            $updated += DB::table('report_materialization_run_chunks')->where('id',$chunk->id)->update(['priority'=>100,'available_at'=>now(),'updated_at'=>now()]);
        }
        return $updated;
    }

    private function recoveryCoverage(string $pipeline,array $ids,string $from,string $to): array
    {
        if ($pipeline === 'daily') return $this->daily->readContractStatus($ids,$from,$to,config('app.timezone','Asia/Jakarta'));
        $coverage=$this->hourlyCoverage($ids,$from,$to);
        return $coverage + [
            'contract'=>'erp_finance_v8_i08_hourly_materialized',
            'source'=>'report_hourly_sales_summaries + report_hourly_product_summaries',
            'date_from'=>$from,'date_to'=>$to,
            'range_days'=>CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to))+1,
            'outlet_count'=>count($ids),'outlet_ids'=>array_values($ids),
            'timezone'=>(string)config('app.timezone','Asia/Jakarta'),'recovery_pipeline'=>'hourly',
        ];
    }

    private function recoveryRequestDetail(string $requestId,string $pipeline,array $ids,string $from,string $to): array
    {
        $request=DB::table('report_materialization_recovery_requests')->where('id',$requestId)->first();
        $coverage=$this->recoveryCoverage($pipeline,$ids,$from,$to);
        return [
            'state'=>($coverage['ready']??false)?'ready':(string)($request->status??'queued'),
            'coverage'=>$coverage,
            'request'=>$request?$this->formatRecoveryRequest($request):null,
            'run'=>($request && !empty($request->run_id) && DB::table('report_materialization_runs')->where('id',$request->run_id)->exists())?$this->runDetail((string)$request->run_id):null,
        ];
    }

    private function formatRecoveryRequest(object $r): array
    {
        return [
            'id'=>(string)$r->id,'pipeline'=>(string)$r->pipeline,'date_from'=>(string)$r->date_from,'date_to'=>(string)$r->date_to,
            'status'=>(string)$r->status,'run_id'=>$r->run_id?(string)$r->run_id:null,'requested_at'=>$r->created_at,
            'attached_at'=>$r->attached_at??null,'completed_at'=>$r->completed_at??null,
        ];
    }

    private function maybeCreateAutoRunUnlocked(): void
    {
        $s=$this->settings(); if(!$s['auto_enabled'] || $this->activeRun() || !$this->insideWindowNow($s)) return;
        $today=now($s['timezone'])->toDateString(); $last=$s['last_auto_enqueued_at']?CarbonImmutable::parse($s['last_auto_enqueued_at'])->setTimezone($s['timezone'])->toDateString():null; if($last===$today) return;
        $pipeline=$s['hourly_enabled']&&$s['monthly_enabled']?'full':($s['hourly_enabled']?'hourly':($s['monthly_enabled']?'monthly':'daily'));
        if(!$s['daily_enabled']&&!$s['hourly_enabled']&&!$s['monthly_enabled']) return;
        $this->createRunUnlocked(['days'=>$s['rolling_days'],'outlet_chunk'=>$s['outlet_chunk'],'date_chunk'=>$s['date_chunk'],'mode'=>'missing_only','pipeline'=>$pipeline],null,'auto');
        DB::table('report_materialization_settings')->where('id','default')->update(['last_auto_enqueued_at'=>now(),'updated_at'=>now()]);
    }

    private function engineHealth(array $settings,?object $active): array
    {
        $now=now();
        $tickAge=$settings['last_tick_at'] ? CarbonImmutable::parse($settings['last_tick_at'])->diffInSeconds($now) : null;
        $workerAge=$settings['last_worker_heartbeat_at'] ? CarbonImmutable::parse($settings['last_worker_heartbeat_at'])->diffInSeconds($now) : null;
        $running=Schema::hasTable('report_materialization_run_chunks') ? DB::table('report_materialization_run_chunks')->where('status','running')->count() : 0;
        $dispatched=Schema::hasTable('report_materialization_run_chunks') ? DB::table('report_materialization_run_chunks')->where('status','dispatched')->count() : 0;
        $stale=Schema::hasTable('report_materialization_run_chunks') && Schema::hasColumn('report_materialization_run_chunks','lease_expires_at') ? DB::table('report_materialization_run_chunks')->whereIn('status',['running','dispatched'])->where('lease_expires_at','<',$now)->count() : 0;
        $queueDepth=Schema::hasTable('jobs') ? DB::table('jobs')->where('queue',config('queue.connections.reporting.queue','reporting'))->count() : null;
        $failed24h=Schema::hasTable('report_materialization_run_chunks') ? DB::table('report_materialization_run_chunks')->where('status','failed')->where('updated_at','>=',$now->copy()->subDay())->count() : 0;
        $schedulerState=$tickAge===null?'UNKNOWN':($tickAge<=180?'HEALTHY':'STALE');
        $workerState='WAITING_WORKER';
        if($stale>0) $workerState='RECOVERY_REQUIRED';
        elseif($running>0) $workerState='BUSY';
        elseif($workerAge!==null && $workerAge<=180) $workerState='HEALTHY';
        elseif($dispatched>0) $workerState='WAITING_WORKER';
        return ['scheduler_state'=>$schedulerState,'scheduler_tick_age_seconds'=>$tickAge,'worker_state'=>$workerState,'worker_last_activity_age_seconds'=>$workerAge,'running_chunks'=>$running,'dispatched_chunks'=>$dispatched,'stale_leases'=>$stale,'failed_chunks_24h'=>$failed24h,'queue_depth'=>$queueDepth,'queue_connection'=>'reporting','queue_name'=>(string)config('queue.connections.reporting.queue','reporting'),'worker_lease_seconds'=>$settings['worker_lease_seconds'],'max_attempts'=>$settings['max_attempts']];
    }


    /** Finalize pause/cancel as soon as the last running chunk has stopped. */
    private function settleRequestedRunState(string $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $this->lockOrchestratorRow();
            $run=DB::table('report_materialization_runs')->where('id',$runId)->first();
            if(!$run) return;

            if($run->status==='cancel_requested') {
                DB::table('report_materialization_run_chunks')->where('run_id',$runId)->whereIn('status',['queued','pending_stage','dispatched'])->update([
                    'status'=>'cancelled','dispatch_token'=>null,'worker_token'=>null,'lease_expires_at'=>null,'finished_at'=>now(),'updated_at'=>now(),
                ]);
                // If a worker disappeared, an expired lease is sufficient proof that this
                // running chunk is no longer owned. Cancel it so UI recovery does not depend
                // on a scheduler tick. An active (non-expired) SQL chunk is never force-killed.
                DB::table('report_materialization_run_chunks')->where('run_id',$runId)->where('status','running')->whereNotNull('lease_expires_at')->where('lease_expires_at','<',now())->update([
                    'status'=>'cancelled','worker_token'=>null,'dispatch_token'=>null,'lease_expires_at'=>null,'finished_at'=>now(),'last_error'=>DB::raw("COALESCE(last_error, 'Cancelled after worker lease expired')"),'updated_at'=>now(),
                ]);
                if(!$this->hasRunningChunks($runId)) {
                    DB::table('report_materialization_runs')->where('id',$runId)->update([
                        'status'=>'cancelled','finished_at'=>now(),'current_label'=>null,'estimated_seconds_remaining'=>0,'updated_at'=>now(),
                    ]);
                    $this->releaseRecoveryRequestsForCancelledRun($runId);
                    $this->syncRunProgress($runId);
                }
                return;
            }

            if($run->status==='pause_requested') {
                DB::table('report_materialization_run_chunks')->where('run_id',$runId)->where('status','dispatched')->update([
                    'status'=>'queued','dispatch_token'=>null,'dispatched_at'=>null,'lease_expires_at'=>null,'available_at'=>now(),'updated_at'=>now(),
                ]);
                if(!$this->hasRunningChunks($runId)) {
                    DB::table('report_materialization_runs')->where('id',$runId)->update(['status'=>'paused','current_label'=>null,'updated_at'=>now()]);
                }
            }
        },3);
    }

    private function hasRunningChunks(string $runId): bool { return DB::table('report_materialization_run_chunks')->where('run_id',$runId)->where('status','running')->exists(); }
    private function hasRunningOrDispatchedChunks(string $runId): bool { return DB::table('report_materialization_run_chunks')->where('run_id',$runId)->whereIn('status',['running','dispatched'])->exists(); }
    private function lockOrchestratorRow(): void { DB::table('report_materialization_settings')->where('id','default')->lockForUpdate()->first(); }
    private function insideAllowedWindow(object $run): bool { if($run->trigger!=='auto') return true; return $this->insideWindowNow($this->settings()); }
    private function insideWindowNow(array $s): bool { $now=now($s['timezone'])->format('H:i'); $start=$s['window_start']; $end=$s['window_end']; return $start<=$end ? ($now>=$start && $now<=$end) : ($now>=$start || $now<=$end); }

    private function dailyCoverage(array $ids,string $from,string $to): array { return $this->daily->readContractStatus($ids,$from,$to,config('app.timezone','Asia/Jakarta')); }
    private function hourlyCoverage(array $ids,string $from,string $to): array
    {
        $expected=count($ids)*(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to))+1);
        if(!Schema::hasTable('report_hourly_summary_coverage') || !Schema::hasTable('report_daily_summary_coverage')) return ['ready'=>false,'expected_rows'=>$expected,'ready_rows'=>0,'missing_rows'=>$expected,'coverage_percent'=>0.0];
        $ready=DB::table('report_daily_summary_coverage as d')->leftJoin('report_hourly_summary_coverage as h',function($j): void {$j->on('h.outlet_id','=','d.outlet_id')->on('h.business_date','=','d.business_date');})
            ->whereIn('d.outlet_id',$ids)->whereBetween('d.business_date',[$from,$to])->whereNotNull('h.outlet_id')->whereNotNull('h.source_daily_synced_at')->whereColumn('h.source_daily_synced_at','>=','d.synced_at')->count();
        return ['ready'=>$ready===$expected,'expected_rows'=>$expected,'ready_rows'=>$ready,'missing_rows'=>max(0,$expected-$ready),'coverage_percent'=>$expected?round($ready/$expected*100,2):100.0];
    }
    private function monthlyCoverage(array $ids,string $from,string $to): array
    {
        $months=$this->fullMonths($from,$to); $expected=count($ids)*count($months);
        if($expected===0) return ['ready'=>true,'expected_rows'=>0,'ready_rows'=>0,'missing_rows'=>0,'coverage_percent'=>100.0];
        if(!Schema::hasTable('report_monthly_summary_coverage')) return ['ready'=>false,'expected_rows'=>$expected,'ready_rows'=>0,'missing_rows'=>$expected,'coverage_percent'=>0.0];
        $ready=0; foreach($months as $month) $ready+=(int)($this->monthly->readContractStatus($ids,$month)['ready_rows']??0);
        return ['ready'=>$ready===$expected,'expected_rows'=>$expected,'ready_rows'=>$ready,'missing_rows'=>max(0,$expected-$ready),'coverage_percent'=>$expected?round($ready/$expected*100,2):100.0];
    }
    private function calendarCoverage(array $ids,string $from,string $to): array
    {
        $rows=[];
        $today=CarbonImmutable::parse($to);
        for($m=CarbonImmutable::parse($from)->startOfMonth();$m->lte($today->startOfMonth());$m=$m->addMonth()) {
            $start=$m->toDateString();
            $closed=$m->endOfMonth()->lt($today);
            $end=$closed ? $m->endOfMonth()->toDateString() : $today->toDateString();
            $daily=$this->dailyCoverage($ids,$start,$end);
            $dailyPercent=(float)($daily['coverage_percent']??0);
            $monthlyReady=$closed ? (bool)($this->monthly->readContractStatus($ids,$start)['ready']??false) : false;
            $status=$closed ? (($dailyPercent>=100 && $monthlyReady)?'READY':'NEEDS_PREPARATION') : 'LIVE';
            $overall=$closed ? round(($dailyPercent + ($monthlyReady?100:0))/2,2) : $dailyPercent;
            $rows[]=[
                'month'=>$m->format('Y-m'),'label'=>$m->locale('id')->translatedFormat('F Y'),'is_current'=>!$closed,
                'status'=>$status,'overall_percent'=>$overall,'daily_percent'=>$dailyPercent,
                'monthly_percent'=>$closed?($monthlyReady?100.0:0.0):null,'monthly_ready'=>$monthlyReady,
                'date_from'=>$start,'date_to'=>$end,
            ];
        }
        return array_reverse($rows);
    }

    public function monthReadiness(string $month, ?array $requestedOutletIds=null): array
    {
        if(!preg_match('/^\d{4}-\d{2}$/',$month)) throw new \InvalidArgumentException('Format bulan harus YYYY-MM.');
        $settings=$this->settings();
        $m=CarbonImmutable::parse($month.'-01',$settings['timezone'])->startOfMonth()->startOfDay();
        $today=now($settings['timezone'])->startOfDay();
        if($m->gt($today->startOfMonth())) throw new \InvalidArgumentException('Bulan masa depan belum dapat disiapkan.');
        $closed=$m->endOfMonth()->lt($today);
        $from=$m->toDateString();
        $to=$closed?$m->endOfMonth()->toDateString():$today->toDateString();
        $ids=$this->resolveOutletIds($requestedOutletIds);
        $daily=$this->dailyCoverage($ids,$from,$to);
        $hourly=$this->hourlyCoverage($ids,$from,$to);
        $monthly=$closed?$this->monthlyCoverage($ids,$from,$to):['ready'=>false,'expected_rows'=>0,'ready_rows'=>0,'missing_rows'=>0,'coverage_percent'=>null];

        $days=CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to))+1;
        $outletMap=DB::table('outlets')->whereIn('id',$ids)->get(['id','code','name'])->keyBy(fn($o)=>(string)$o->id);
        $dailyByOutlet=Schema::hasTable('report_daily_summary_coverage')
            ? DB::table('report_daily_summary_coverage')->whereIn('outlet_id',$ids)->whereBetween('business_date',[$from,$to])
                ->select('outlet_id')->selectRaw('COUNT(*) ready_rows')->selectRaw('MAX(synced_at) max_synced_at')
                ->when(Schema::hasColumn('report_daily_summary_coverage','generation_ulid'),fn($q)=>$q->selectRaw('MAX(generation_ulid) max_generation_ulid'))
                ->groupBy('outlet_id')->get()->keyBy(fn($r)=>(string)$r->outlet_id)
            : collect();
        $hourlyByOutlet=(Schema::hasTable('report_hourly_summary_coverage') && Schema::hasTable('report_daily_summary_coverage'))
            ? DB::table('report_daily_summary_coverage as d')->leftJoin('report_hourly_summary_coverage as h',function($j): void {$j->on('h.outlet_id','=','d.outlet_id')->on('h.business_date','=','d.business_date');})
                ->whereIn('d.outlet_id',$ids)->whereBetween('d.business_date',[$from,$to])
                ->whereNotNull('h.outlet_id')->whereNotNull('h.source_daily_synced_at')->whereColumn('h.source_daily_synced_at','>=','d.synced_at')
                ->select('d.outlet_id')->selectRaw('COUNT(*) ready_rows')->groupBy('d.outlet_id')->get()->keyBy(fn($r)=>(string)$r->outlet_id)
            : collect();
        $outletRows=[];
        foreach($ids as $id) {
            $d=$dailyByOutlet->get($id); $h=$hourlyByOutlet->get($id);
            $dailyRows=(int)($d->ready_rows??0); $hourlyRows=(int)($h->ready_rows??0);
            $dailyPercent=$days?round(min($days,$dailyRows)/$days*100,2):100.0;
            $hourlyPercent=$days?round(min($days,$hourlyRows)/$days*100,2):100.0;

            // I01: use the exact same monthly readiness contract for the aggregate card
            // and every outlet row. The previous duplicated generation/timestamp logic
            // could disagree with ReportMonthlySummaryService (especially mixed legacy
            // coverage rows created before generation_ulid existed), showing global 100%
            // while each outlet still displayed 0%.
            $monthlyStatus=$closed
                ? $this->monthly->readContractStatus([(string)$id],$from)
                : null;
            $monthlyReady=$closed && (bool)($monthlyStatus['ready']??false);
            $monthlyPercent=$closed?(float)($monthlyStatus['coverage_percent']??0.0):null;
            $ready=$closed ? ($dailyPercent>=100 && $monthlyReady) : $dailyPercent>=100;
            $o=$outletMap->get($id);
            $outletRows[]=[
                'id'=>$id,'code'=>(string)($o->code??''),'name'=>(string)($o->name??$id),
                'daily_percent'=>$dailyPercent,'hourly_percent'=>$hourlyPercent,'monthly_percent'=>$monthlyPercent,
                'status'=>$closed?($ready?'READY':'NEEDS_PREPARATION'):($ready?'LIVE':'LIVE_SYNCING'),
            ];
        }
        $active=$this->activeRun();
        $activeForMonth=$active && (string)$active->date_from <= $to && (string)$active->date_to >= $from;
        $dailyPercent=(float)($daily['coverage_percent']??0);
        $monthlyPercent=$closed?(float)($monthly['coverage_percent']??0):null;
        $ready=$closed?($dailyPercent>=100 && $monthlyPercent>=100):$dailyPercent>=100;
        return [
            'month'=>$month,'label'=>$m->locale('id')->translatedFormat('F Y'),'is_current'=>!$closed,'date_from'=>$from,'date_to'=>$to,
            'status'=>$activeForMonth?'PROCESSING':($closed?($ready?'READY':'NEEDS_PREPARATION'):($ready?'LIVE':'LIVE_SYNCING')),
            'daily'=>$daily,'hourly'=>$hourly,'monthly'=>$monthly,
            'overall_percent'=>$closed?round(($dailyPercent+($monthlyPercent??0))/2,2):$dailyPercent,
            'recommended_pipeline'=>$closed?'full':'hourly','can_monthly'=>$closed,
            'outlets'=>$outletRows,'active_run'=>$activeForMonth?$this->runDetail((string)$active->id):null,
        ];
    }

    private function fullMonths(string $from,string $to): array
    {
        $rangeFrom=CarbonImmutable::parse($from); $rangeTo=CarbonImmutable::parse($to); $months=[]; $m=$rangeFrom->startOfMonth(); if($rangeFrom->gt($m)) $m=$m->addMonth();
        while($m->endOfMonth()->lte($rangeTo)) { $months[]=$m->toDateString(); $m=$m->addMonth(); }
        return $months;
    }

    private function resolveRange(array $params): array { if(!empty($params['date_from'])&&!empty($params['date_to'])) { $a=CarbonImmutable::parse($params['date_from']);$b=CarbonImmutable::parse($params['date_to']);if($b->lt($a))[$a,$b]=[$b,$a];return[$a->toDateString(),$b->toDateString()]; } $days=max(1,min(730,(int)($params['days']??370)));return[now()->subDays($days-1)->toDateString(),now()->toDateString()]; }
    private function resolveOutletIds($requested): array { $all=DB::table('outlets')->whereRaw("LOWER(COALESCE(type,'outlet'))='outlet'")->pluck('id')->map(fn($v)=>(string)$v)->filter()->values()->all(); if(!is_array($requested)||$requested===[]) return $all; $allow=array_flip($all); return array_values(array_filter(array_unique(array_map('strval',$requested)),fn($id)=>isset($allow[$id]))); }
    private function formatRun(object $r): array { return ['id'=>(string)$r->id,'trigger'=>(string)$r->trigger,'mode'=>(string)$r->mode,'pipeline'=>(string)$r->pipeline,'date_from'=>(string)$r->date_from,'date_to'=>(string)$r->date_to,'status'=>(string)$r->status,'current_stage'=>$r->current_stage,'total_chunks'=>(int)$r->total_chunks,'completed_chunks'=>(int)$r->completed_chunks,'skipped_chunks'=>(int)$r->skipped_chunks,'failed_chunks'=>(int)$r->failed_chunks,'recovery_count'=>(int)($r->recovery_count??0),'progress_percent'=>(int)$r->progress_percent,'current_label'=>$r->current_label,'estimated_seconds_remaining'=>$r->estimated_seconds_remaining,'started_at'=>$r->started_at,'finished_at'=>$r->finished_at,'created_at'=>$r->created_at,'last_error'=>$r->last_error]; }
}
