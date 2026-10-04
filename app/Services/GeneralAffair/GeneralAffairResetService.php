<?php

namespace App\Services\GeneralAffair;

use App\Services\Finance\FinanceGeneralPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GeneralAffairResetService
{
    public const CONFIRMATION = 'RESET GA';

    private const EXECUTION_ORDER = ['ticketing','cctv','costing','bill_due_date','inventory_audit','asset','inventory','drive_inventory','masters'];

    private const MODULES = [
        'ticketing' => ['label' => 'Ticketing', 'description' => 'Ticket, workflow, timeline, discussion, dan attachment.', 'default' => true],
        'cctv' => ['label' => 'CCTV Request', 'description' => 'Request CCTV, attachment, dan timeline.', 'default' => true],
        'costing' => ['label' => 'Costing GA', 'description' => 'Costing serta chain Purchasing/Finance yang berasal dari GA_COSTING.', 'default' => true],
        'bill_due_date' => ['label' => 'Bill Due Date', 'description' => 'Seluruh data Bill Due Date GA.', 'default' => true],
        'asset' => ['label' => 'Asset Recap', 'description' => 'Asset recap, saldo lokasi Asset, dan movement Asset.', 'default' => true],
        'inventory' => ['label' => 'Inventory Recap & Logs', 'description' => 'Inventory recap, saldo lokasi, dan movement termasuk PINJAM/KEMBALI.', 'default' => true],
        'inventory_audit' => ['label' => 'Inventory Audit', 'description' => 'Audit bulanan, re-audit, snapshot line, dan foto audit.', 'default' => true],
        'drive_inventory' => ['label' => 'Drive Inventory', 'description' => 'File/folder Profile Inventory beserta mapping dan audit log Drive.', 'default' => true],
        'masters' => ['label' => 'Master GA', 'description' => 'Damage Category, CCTV Category, dan Costing Category. Tidak dipilih otomatis.', 'default' => false],
    ];

    public function __construct(
        private readonly FinanceGeneralPostingService $generalPostings,
        private readonly DriveInventoryStorageService $driveStorage,
    ) {
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return [
            'modules' => collect(self::MODULES)->map(fn (array $meta, string $key) => ['key' => $key] + $meta)->values()->all(),
            'default_selected' => collect(self::MODULES)->filter(fn (array $m) => $m['default'])->keys()->values()->all(),
            'confirmation_text' => self::CONFIRMATION,
            'runs' => $this->recentRuns(),
            'safety' => [
                'preview_required' => true,
                'finance_posted_policy' => 'AUTO_REVERSAL_GENERAL_POSTING',
                'unsafe_realization_policy' => 'FAIL_CLOSED',
                'filesystem_policy' => 'DB_COMMIT_FIRST_ORPHAN_FILE_CLEANUP_RETRY_SAFE',
            ],
        ];
    }

    /** @param array<int,string> $modules @return array<string,mixed> */
    public function preview(array $modules): array
    {
        $selected = $this->normalizeModules($modules);
        $modulePlans = [];
        $blockers = [];
        $warnings = [];

        foreach ($selected as $module) {
            $plan = $this->modulePlan($module);
            $modulePlans[] = $plan;
            foreach ($plan['blockers'] ?? [] as $blocker) $blockers[] = $blocker;
            foreach ($plan['warnings'] ?? [] as $warning) $warnings[] = $warning;
        }

        $warnings[] = 'Nomor dokumen / ga_document_sequences tidak di-reset agar nomor historis tidak dipakai ulang. Audit Trail Reset juga tidak ikut dihapus.';
        $blockers = array_merge($blockers, $this->auditDependencyBlockers($selected));
        if (in_array('masters', $selected, true)) {
            $masterBlockers = $this->masterDependencyBlockers($selected);
            $blockers = array_merge($blockers, $masterBlockers);
        }

        $snapshot = [
            'modules' => $selected,
            'plans' => $modulePlans,
            'blockers' => $blockers,
            'warnings' => array_values(array_unique($warnings)),
        ];
        $hash = $this->snapshotHash($snapshot);

        return $snapshot + [
            'can_execute' => count($blockers) === 0,
            'confirmation_text' => self::CONFIRMATION,
            'preview_token' => $this->makePreviewToken($selected, $hash),
            'preview_expires_in_minutes' => 15,
        ];
    }

    /** @param array<int,string> $modules @return array<string,mixed> */
    public function execute(array $modules, string $previewToken, string $confirmation, bool $acknowledge, ?object $actor): array
    {
        if (trim($confirmation) !== self::CONFIRMATION || ! $acknowledge) {
            throw ValidationException::withMessages(['confirmation' => ['Ketik persis RESET GA dan centang konfirmasi risiko.']]);
        }

        $selected = $this->normalizeModules($modules);
        $this->assertPreviewToken($previewToken, $selected);

        $fresh = $this->preview($selected);
        if (! ($fresh['can_execute'] ?? false)) {
            throw ValidationException::withMessages([
                'reset' => ['Reset diblokir. Preview terbaru menemukan dependency/realization yang wajib direversal dari modul asal terlebih dahulu.'],
            ]);
        }
        $this->assertPreviewTokenMatches($previewToken, $fresh);

        $runId = (string) Str::ulid();
        $resetNumber = 'GARST-'.now('Asia/Jakarta')->format('Ymd-His').'-'.strtoupper(substr($runId, -5));
        $actorId = $actor?->id ? (string) $actor->id : null;
        $actorName = trim((string) ($actor?->name ?? '')) ?: null;
        $actorNisj = trim((string) ($actor?->nisj ?? '')) ?: null;
        $now = now();

        DB::table('ga_reset_runs')->insert([
            'id' => $runId,
            'reset_number' => $resetNumber,
            'status' => 'RUNNING',
            'selected_modules' => $this->json($selected),
            'preview_snapshot' => $this->json($this->withoutToken($fresh)),
            'blockers_json' => $this->json([]),
            'actor_user_id' => $actorId,
            'actor_name_snapshot' => $actorName,
            'actor_nisj_snapshot' => $actorNisj,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($selected as $module) {
            DB::table('ga_reset_run_items')->insert([
                'id' => (string) Str::ulid(), 'reset_run_id' => $runId, 'module_key' => $module,
                'status' => 'PENDING', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $fileCleanup = [];
        $result = [];
        try {
            DB::transaction(function () use ($selected, $previewToken, $runId, $resetNumber, $actorId, &$fileCleanup, &$result): void {
                // The preview token is intentionally checked again under the transaction boundary.
                $lockedFresh = $this->preview($selected);
                $this->assertPreviewTokenMatches($previewToken ?? '', $lockedFresh);
                if (! ($lockedFresh['can_execute'] ?? false)) {
                    throw ValidationException::withMessages(['reset' => ['State berubah setelah preview. Jalankan Preview ulang.']]);
                }

                foreach ($selected as $module) {
                    DB::table('ga_reset_run_items')->where('reset_run_id', $runId)->where('module_key', $module)->update([
                        'status' => 'RUNNING', 'updated_at' => now(),
                    ]);
                    $moduleResult = $this->executeModule($module, $resetNumber, $actorId, $fileCleanup);
                    $result[$module] = $moduleResult;
                    DB::table('ga_reset_run_items')->where('reset_run_id', $runId)->where('module_key', $module)->update([
                        'status' => 'COMPLETED',
                        'affected_rows' => (int) ($moduleResult['affected_rows'] ?? 0),
                        'affected_files' => (int) ($moduleResult['affected_files'] ?? 0),
                        'finance_reversals' => (int) ($moduleResult['finance_reversals'] ?? 0),
                        'purchasing_documents' => (int) ($moduleResult['purchasing_documents'] ?? 0),
                        'detail_json' => $this->json($moduleResult),
                        'message' => (string) ($moduleResult['message'] ?? 'Selesai'),
                        'updated_at' => now(),
                    ]);
                }
            }, 3);
        } catch (Throwable $e) {
            DB::table('ga_reset_runs')->where('id', $runId)->update([
                'status' => 'FAILED', 'failure_message' => mb_substr($e->getMessage(), 0, 5000),
                'completed_at' => now(), 'updated_at' => now(),
            ]);
            throw $e;
        }

        $cleanup = $this->cleanupFiles($fileCleanup, in_array('drive_inventory', $selected, true));
        $payload = [
            'reset_number' => $resetNumber,
            'modules' => $selected,
            'result' => $result,
            'filesystem_cleanup' => $cleanup,
            'performed_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
        DB::table('ga_reset_runs')->where('id', $runId)->update([
            'status' => 'COMPLETED', 'result_json' => $this->json($payload),
            'completed_at' => now(), 'updated_at' => now(),
        ]);

        return $payload + ['message' => 'Reset General Affair selesai. Finance POSTED yang terkait telah direversal; chain unsafe diblokir sebelum eksekusi.'];
    }

    /** @return array<string,mixed> */
    private function modulePlan(string $module): array
    {
        return match ($module) {
            'ticketing' => $this->simplePlan($module, ['ga_tickets','ga_ticket_attachments','ga_ticket_events','ga_ticket_approval_flags','ga_ticket_discussions']),
            'cctv' => $this->simplePlan($module, ['ga_cctv_requests','ga_cctv_request_attachments','ga_cctv_request_events']),
            'costing' => $this->costingPlan(),
            'bill_due_date' => $this->simplePlan($module, ['ga_bill_due_dates'], $this->spreadsheetCount(['ga.bill_due_date'])),
            'asset' => $this->simplePlan($module, ['ga_assets','ga_asset_location_balances'], $this->movementCount('ASSET') + $this->spreadsheetCount(['ga.asset'])),
            'inventory' => $this->simplePlan($module, ['ga_inventory_items','ga_inventory_location_balances'], $this->movementCount('INVENTORY') + $this->spreadsheetCount(['ga.inventory'])),
            'inventory_audit' => $this->simplePlan($module, ['ga_inventory_audits','ga_inventory_audit_lines']),
            'drive_inventory' => $this->drivePlan(),
            'masters' => $this->simplePlan($module, ['ga_damage_categories','ga_cctv_categories','ga_costing_categories']),
            default => throw ValidationException::withMessages(['modules' => ["Module reset tidak dikenal: {$module}"]]),
        };
    }

    private function simplePlan(string $module, array $tables, int $extraRows = 0): array
    {
        $detail = [];
        $rows = $extraRows;
        foreach ($tables as $table) {
            $count = $this->count($table);
            $detail[] = ['table' => $table, 'rows' => $count];
            $rows += $count;
        }
        return ['module' => $module, 'label' => self::MODULES[$module]['label'], 'rows' => $rows, 'tables' => $detail, 'blockers' => [], 'warnings' => []];
    }

    private function drivePlan(): array
    {
        $files = 0;
        try {
            if (Storage::disk('public')->exists(DriveInventoryMasterService::ROOT)) {
                $files = count(Storage::disk('public')->allFiles(DriveInventoryMasterService::ROOT));
            }
        } catch (Throwable) {
            $files = 0;
        }
        return $this->simplePlan('drive_inventory', ['ga_inventory_drive_folder_mappings','ga_inventory_drive_audit_logs']) + ['files' => $files];
    }

    /** @return array<string,mixed> */
    private function costingPlan(): array
    {
        $costingRows = Schema::hasTable('ga_costing_requests')
            ? DB::table('ga_costing_requests')->whereNull('deleted_at')->orderBy('id')->get(['id','request_no','purchasing_fund_request_id','purchasing_order_kind','purchasing_order_id','purchasing_order_number'])
            : collect();
        $blockers = [];
        $orders = 0;
        $financeIds = [];
        $chain = [];

        foreach ($costingRows as $costing) {
            $plan = $this->purchasingPlanForCosting($costing);
            $orders += (int) ($plan['purchasing_documents'] ?? 0);
            foreach ($plan['finance_general_posting_ids'] ?? [] as $gpId) $financeIds[$gpId] = true;
            foreach ($plan['blockers'] ?? [] as $b) $blockers[] = $b;
            if (($plan['purchasing_documents'] ?? 0) > 0 || ($plan['finance_general_posting_ids'] ?? [])) {
                $chain[] = [
                    'costing_id' => (string) $costing->id,
                    'request_no' => (string) $costing->request_no,
                    'purchasing_documents' => (int) ($plan['purchasing_documents'] ?? 0),
                    'finance_postings' => count($plan['finance_general_posting_ids'] ?? []),
                ];
            }
        }

        $posted = 0; $draft = 0;
        foreach (array_keys($financeIds) as $id) {
            $status = strtoupper((string) DB::table('finance_general_postings')->where('id', $id)->value('status'));
            if ($status === 'POSTED') $posted++; elseif ($status === 'DRAFT') $draft++;
            elseif ($status !== '') $blockers[] = ['module' => 'costing', 'code' => 'UNKNOWN_FINANCE_STATE', 'message' => "General Posting {$id} berstatus {$status}; reset fail-closed."];
        }

        return [
            'module' => 'costing', 'label' => self::MODULES['costing']['label'],
            'rows' => $this->count('ga_costing_requests') + $this->count('ga_costing_generated_orders') + $this->count('ga_costing_attachments') + $this->count('ga_costing_request_events'),
            'purchasing_documents' => $orders,
            'finance_posted_to_reverse' => $posted,
            'finance_draft' => $draft,
            'chain_sample' => array_slice($chain, 0, 50),
            'blockers' => $blockers,
            'warnings' => $posted > 0 ? ["{$posted} General Posting POSTED akan dibuat reversal journal otomatis sebelum chain GA disembunyikan."] : [],
        ];
    }

    /** @return array<string,mixed> */
    private function purchasingPlanForCosting(object $costing): array
    {
        $fundId = trim((string) ($costing->purchasing_fund_request_id ?? ''));
        if ($fundId === '' && Schema::hasTable('pur_fund_requests')) {
            $fundId = (string) (DB::table('pur_fund_requests')->where('source_type','GA_COSTING')->where('source_id',(string)$costing->id)->value('id') ?? '');
        }
        $orderKind = strtoupper(trim((string) ($costing->purchasing_order_kind ?? '')));
        $orderId = trim((string) ($costing->purchasing_order_id ?? ''));
        if ($fundId === '') return ['purchasing_documents' => 0, 'finance_general_posting_ids' => [], 'blockers' => []];

        $fr = DB::table('pur_fund_requests')->where('id', $fundId)->first();
        $blockers = [];
        if (! $fr || strtoupper((string) ($fr->source_type ?? '')) !== 'GA_COSTING' || (string) ($fr->source_id ?? '') !== (string) $costing->id) {
            return ['purchasing_documents' => 1, 'finance_general_posting_ids' => [], 'blockers' => [[
                'module' => 'costing', 'code' => 'SOURCE_LINK_MISMATCH',
                'message' => "Costing {$costing->request_no}: linkage Fund Request tidak valid / bukan GA_COSTING. Reset diblokir untuk mencegah penghapusan dokumen lain.",
            ]]];
        }

        if ($orderId === '') {
            foreach ([['PURCHASE_ORDER','pur_purchase_orders'],['SERVICE_ORDER','pur_service_orders'],['REIMBURSE_ORDER','pur_reimburse_orders']] as [$kind,$table]) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table,'fund_request_id')) continue;
                $found = DB::table($table)->where('fund_request_id',$fundId)->when(Schema::hasColumn($table,'deleted_at'), fn($q) => $q->whereNull('deleted_at'))->value('id');
                if ($found) { $orderKind = $kind; $orderId = (string) $found; break; }
            }
        }

        $documents = 1 + ($orderId !== '' ? 1 : 0);
        if ($orderId !== '') {
            foreach ($this->unsafeExecutionRows($orderKind, $orderId) as $unsafe) {
                $blockers[] = ['module'=>'costing','code'=>'OPERATIONAL_REALIZATION_EXISTS','message'=>"Costing {$costing->request_no}: {$unsafe}. Reverse/cancel dari Purchasing/Warehouse/Treasury terlebih dahulu."];
            }
        }

        $invoiceIds = $this->invoiceIdsForOrder($orderKind, $orderId, $fundId);
        if ($invoiceIds && Schema::hasTable('pur_invoice_payments')) {
            $paymentCount = (int) DB::table('pur_invoice_payments')->whereIn('invoice_id',$invoiceIds)
                ->when(Schema::hasColumn('pur_invoice_payments','status'), fn($q) => $q->whereNotIn('status',['VOID','CANCELLED','REVERSED']))->count();
            if ($paymentCount > 0) {
                $blockers[] = ['module'=>'costing','code'=>'PAYMENT_EXISTS','message'=>"Costing {$costing->request_no}: {$paymentCount} payment invoice masih aktif. Reversal pembayaran/Treasury wajib dilakukan dari modul sumber."];
            }
        }
        if ($orderId !== '' && Schema::hasTable('pur_order_ap_lifecycles')) {
            $lifeIds = DB::table('pur_order_ap_lifecycles')->where('order_kind',$orderKind)->where('order_id',$orderId)->pluck('id');
            if ($lifeIds->isNotEmpty() && Schema::hasTable('pur_order_ap_settlements')) {
                $settlements = (int) DB::table('pur_order_ap_settlements')->whereIn('ap_lifecycle_id',$lifeIds)->count();
                if ($settlements > 0) $blockers[] = ['module'=>'costing','code'=>'AP_SETTLEMENT_EXISTS','message'=>"Costing {$costing->request_no}: {$settlements} AP settlement tercatat. Reset fail-closed; reversal settlement harus dari Finance/Treasury."];
            }
        }

        return [
            'purchasing_documents' => $documents + count($invoiceIds),
            'finance_general_posting_ids' => $this->financePostingIds($orderKind, $orderId, $invoiceIds),
            'blockers' => $blockers,
            'fund_request_id' => $fundId, 'order_kind' => $orderKind, 'order_id' => $orderId, 'invoice_ids' => $invoiceIds,
        ];
    }

    /** @return array<int,string> */
    private function unsafeExecutionRows(string $orderKind, string $orderId): array
    {
        if ($orderId === '') return [];
        $checks = [
            ['pur_goods_receipts','Goods Receipt'], ['pur_service_acceptances','Service Acceptance'],
            ['pur_service_entry_sheets','Service Entry Sheet'], ['pur_reimburse_payments','Reimburse Payment'],
        ];
        $unsafe = [];
        foreach ($checks as [$table,$label]) {
            if (! Schema::hasTable($table)) continue;
            $q = DB::table($table);
            if (Schema::hasColumn($table,'order_id')) {
                $q->where('order_id',$orderId);
                if (Schema::hasColumn($table,'order_kind')) $q->where('order_kind',$orderKind);
            } elseif (Schema::hasColumn($table,'purchase_order_id') && $orderKind === 'PURCHASE_ORDER') {
                $q->where('purchase_order_id',$orderId);
            } elseif (Schema::hasColumn($table,'reimburse_order_id') && $orderKind === 'REIMBURSE_ORDER') {
                $q->where('reimburse_order_id',$orderId);
            } else continue;
            if (Schema::hasColumn($table,'deleted_at')) $q->whereNull('deleted_at');
            if (Schema::hasColumn($table,'status')) $q->whereNotIn('status',['DRAFT','REJECTED','CANCELLED','VOID']);
            if ($q->exists()) $unsafe[] = "{$label} sudah mempunyai realization aktif";
        }
        return $unsafe;
    }

    /** @return array<int,string> */
    private function invoiceIdsForOrder(string $kind, string $orderId, string $fundId): array
    {
        if (! Schema::hasTable('pur_invoices')) return [];
        return DB::table('pur_invoices')->where(function ($q) use ($kind,$orderId,$fundId): void {
            if ($orderId !== '') {
                if (Schema::hasColumn('pur_invoices','ap_order_kind') && Schema::hasColumn('pur_invoices','ap_order_id')) {
                    $q->orWhere(fn($w) => $w->where('ap_order_kind',$kind)->where('ap_order_id',$orderId));
                }
                if (Schema::hasColumn('pur_invoices','source_document_kind') && Schema::hasColumn('pur_invoices','source_document_id')) {
                    $q->orWhere(fn($w) => $w->where('source_document_kind',$kind)->where('source_document_id',$orderId));
                }
            }
            if ($fundId !== '' && Schema::hasColumn('pur_invoices','fund_request_id')) $q->orWhere('fund_request_id',$fundId);
        })->when(Schema::hasColumn('pur_invoices','deleted_at'),fn($q)=>$q->whereNull('deleted_at'))->pluck('id')->map(fn($v)=>(string)$v)->all();
    }

    /** @param array<int,string> $invoiceIds @return array<int,string> */
    private function financePostingIds(string $kind, string $orderId, array $invoiceIds): array
    {
        $ids = [];
        if (Schema::hasTable('pur_finance_posting_outbox') && Schema::hasColumn('pur_finance_posting_outbox','general_posting_id')) {
            $q = DB::table('pur_finance_posting_outbox')->whereNotNull('general_posting_id');
            $q->where(function ($w) use ($kind,$orderId,$invoiceIds): void {
                if ($invoiceIds) $w->orWhereIn('aggregate_id',$invoiceIds);
                if ($orderId !== '') $w->orWhere('aggregate_id',$orderId);
                if (Schema::hasColumn('pur_finance_posting_outbox','payload')) {
                    foreach (array_filter([$orderId]) as $needle) $w->orWhere('payload','like','%'.$needle.'%');
                }
            });
            foreach ($q->pluck('general_posting_id') as $id) if ($id) $ids[(string)$id] = true;
        }
        if (Schema::hasTable('finance_purchasing_postings')) {
            $postingQuery = DB::table('finance_purchasing_postings');
            $postingQuery->where(function ($w) use ($orderId, $invoiceIds): void {
                if ($invoiceIds) $w->orWhereIn('invoice_id', $invoiceIds);
                if ($orderId !== '' && Schema::hasColumn('finance_purchasing_postings','source_document_id')) $w->orWhere('source_document_id', $orderId);
            });
            $postingRows = ($invoiceIds || $orderId !== '') ? $postingQuery->get(['id','outbox_id','event_key']) : collect();
            if ($postingRows->isNotEmpty()) {
                if (Schema::hasTable('pur_finance_posting_outbox') && Schema::hasColumn('pur_finance_posting_outbox','general_posting_id')) {
                    foreach (DB::table('pur_finance_posting_outbox')->whereIn('id',$postingRows->pluck('outbox_id')->filter()->all())->whereNotNull('general_posting_id')->pluck('general_posting_id') as $id) if($id)$ids[(string)$id]=true;
                }
                if (Schema::hasTable('finance_general_postings')) {
                    foreach ($postingRows as $posting) {
                        $sourceKey = 'FIN-PUR-OUTBOX:'.(string)$posting->event_key;
                        foreach (DB::table('finance_general_postings')->where('source_key',$sourceKey)->pluck('id') as $id) if($id)$ids[(string)$id]=true;
                    }
                }
                if (Schema::hasTable('finance_purchasing_posting_journals') && Schema::hasTable('finance_general_posting_journals')) {
                    $journalIds = DB::table('finance_purchasing_posting_journals')->whereIn('purchasing_posting_id',$postingRows->pluck('id')->all())->pluck('journal_entry_id');
                    if ($journalIds->isNotEmpty()) foreach(DB::table('finance_general_posting_journals')->whereIn('journal_entry_id',$journalIds)->pluck('general_posting_id') as $id) if($id)$ids[(string)$id]=true;
                }
            }
        }
        if (Schema::hasTable('pur_invoices')) {
            foreach (['canonical_general_posting_id','general_posting_id'] as $column) {
                if (! Schema::hasColumn('pur_invoices',$column) || ! $invoiceIds) continue;
                foreach (DB::table('pur_invoices')->whereIn('id',$invoiceIds)->whereNotNull($column)->pluck($column) as $id) if ($id) $ids[(string)$id] = true;
            }
        }
        if ($orderId !== '') {
            foreach (['pur_goods_receipts','pur_service_acceptances','pur_service_entry_sheets','pur_reimburse_payments'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table,'general_posting_id')) continue;
                $q = DB::table($table)->whereNotNull('general_posting_id');
                if (Schema::hasColumn($table,'order_id')) $q->where('order_id',$orderId); elseif (Schema::hasColumn($table,'purchase_order_id')) $q->where('purchase_order_id',$orderId); else continue;
                foreach ($q->pluck('general_posting_id') as $id) if ($id) $ids[(string)$id] = true;
            }
        }
        $result=array_keys($ids);sort($result,SORT_STRING);return $result;
    }

    /** @return array<int,array<string,string>> */
    private function auditDependencyBlockers(array $selected): array
    {
        if (in_array('inventory_audit', $selected, true) || ! Schema::hasTable('ga_inventory_audit_lines')) return [];
        $blockers = [];
        if (in_array('asset', $selected, true)) {
            $count = (int) DB::table('ga_inventory_audit_lines')->whereNotNull('asset_id')->count();
            if ($count > 0) $blockers[] = ['module'=>'asset','code'=>'AUDIT_HISTORY_REFERENCE','message'=>"Asset masih direferensikan {$count} line Audit Inventory. Pilih juga Inventory Audit agar reset dependency-safe."];
        }
        if (in_array('inventory', $selected, true)) {
            $count = (int) DB::table('ga_inventory_audit_lines')->whereNotNull('inventory_item_id')->count();
            if ($count > 0) $blockers[] = ['module'=>'inventory','code'=>'AUDIT_HISTORY_REFERENCE','message'=>"Inventory masih direferensikan {$count} line Audit Inventory. Pilih juga Inventory Audit agar reset dependency-safe."];
        }
        return $blockers;
    }

    /** @return array<int,array<string,string>> */
    private function masterDependencyBlockers(array $selected): array
    {
        $pairs = [
            ['ticketing','ga_tickets','damage_category_id','Damage Category masih dipakai Ticketing'],
            ['cctv','ga_cctv_requests','cctv_category_id','CCTV Category masih dipakai CCTV Request'],
            ['costing','ga_costing_requests','costing_category_id','Costing Category masih dipakai Costing GA'],
        ];
        $blockers = [];
        foreach ($pairs as [$module,$table,$column,$message]) {
            if (in_array($module,$selected,true) || ! Schema::hasTable($table) || ! Schema::hasColumn($table,$column)) continue;
            $q = DB::table($table)->whereNotNull($column);
            if (Schema::hasColumn($table,'deleted_at')) $q->whereNull('deleted_at');
            $count=(int)$q->count();
            if ($count>0) $blockers[]=['module'=>'masters','code'=>'MASTER_IN_USE','message'=>"{$message} ({$count} data aktif). Pilih juga modul terkait atau jangan reset Master GA."];
        }
        return $blockers;
    }

    /** @param array<int,array{disk:string,path:string}> $fileCleanup @return array<string,mixed> */
    private function executeModule(string $module, string $resetNumber, ?string $actorId, array &$fileCleanup): array
    {
        return match ($module) {
            'ticketing' => $this->resetTicketing($fileCleanup),
            'cctv' => $this->resetCctv($fileCleanup),
            'costing' => $this->resetCosting($resetNumber, $actorId, $fileCleanup),
            'bill_due_date' => $this->resetBillDueDate($fileCleanup),
            'asset' => $this->resetAsset($fileCleanup),
            'inventory' => $this->resetInventory($fileCleanup),
            'inventory_audit' => $this->resetInventoryAudit($fileCleanup),
            'drive_inventory' => $this->resetDriveInventory(),
            'masters' => $this->resetMasters(),
            default => throw new \RuntimeException("Module reset tidak dikenal: {$module}"),
        };
    }

    private function resetTicketing(array &$files): array
    {
        $before=count($files);$this->collectAttachmentFiles('ga_ticket_attachments','disk','path',$files);
        $rows=0;
        foreach (['ga_ticket_discussions','ga_ticket_approval_flags','ga_ticket_events','ga_ticket_attachments','ga_tickets'] as $t) $rows += $this->deleteAll($t);
        return $this->result($rows,count($files)-$before,'Ticketing dibersihkan.');
    }

    private function resetCctv(array &$files): array
    {
        $before=count($files);$this->collectAttachmentFiles('ga_cctv_request_attachments','disk','path',$files);$rows=0;
        foreach (['ga_cctv_request_events','ga_cctv_request_attachments','ga_cctv_requests'] as $t) $rows += $this->deleteAll($t);
        return $this->result($rows,count($files)-$before,'CCTV Request dibersihkan.');
    }

    private function resetCosting(string $resetNumber, ?string $actorId, array &$files): array
    {
        $beforeFiles=count($files); $this->collectAttachmentFiles('ga_costing_attachments','disk','path',$files);
        $costings = DB::table('ga_costing_requests')->whereNull('deleted_at')->lockForUpdate()->get(['id','request_no','purchasing_fund_request_id','purchasing_order_kind','purchasing_order_id','purchasing_order_number']);
        $reversals=0;$purchasing=0;$details=[];
        foreach ($costings as $costing) {
            $plan=$this->purchasingPlanForCosting($costing);
            $this->lockPurchasingChain($plan);
            $plan=$this->purchasingPlanForCosting($costing);
            if ($plan['blockers'] ?? []) throw new \RuntimeException('State Purchasing/Finance berubah setelah preview. Jalankan Preview ulang.');
            $purchasing += (int)($plan['purchasing_documents']??0);
            $reversed=[];
            foreach ($plan['finance_general_posting_ids']??[] as $gpId) {
                $gp=DB::table('finance_general_postings')->where('id',$gpId)->lockForUpdate()->first();
                if (!$gp) continue;
                if (strtoupper((string)$gp->status)==='POSTED') {
                    $this->generalPostings->reopen((string)$gpId,"Auto reversal {$resetNumber} - reset GA Costing {$costing->request_no}",$actorId);
                    $this->mirrorPurchasingReversal((string)$gpId);
                    $reversals++;$reversed[]=(string)$gpId;
                } elseif (strtoupper((string)$gp->status)==='DRAFT') {
                    $hasHistory = Schema::hasTable('finance_general_posting_journals') && DB::table('finance_general_posting_journals')->where('general_posting_id',$gpId)->exists();
                    if (! $hasHistory && (int)($gp->posting_version ?? 0) === 0) $this->generalPostings->destroyDraft((string)$gpId);
                } else {
                    throw new \RuntimeException("General Posting {$gpId} berubah ke state tidak aman.");
                }
            }
            $this->retirePurchasingChain($plan,$resetNumber);
            $details[]=['request_no'=>(string)$costing->request_no,'finance_reversed'=>$reversed,'order_kind'=>$plan['order_kind']??null,'order_id'=>$plan['order_id']??null];
        }
        $costingIds=$costings->pluck('id')->map(fn($v)=>(string)$v)->all();
        $affected=0;
        if ($costingIds) {
            foreach (['ga_costing_request_events','ga_costing_attachments','ga_costing_generated_orders'] as $table) {
                if (Schema::hasTable($table)) $affected += (int) DB::table($table)->whereIn('costing_request_id',$costingIds)->delete();
            }
            $now=now();
            $affected += (int) DB::table('ga_costing_requests')->whereIn('id',$costingIds)->whereNull('deleted_at')->update(['deleted_at'=>$now,'updated_at'=>$now]);
        }
        return $this->result($affected,count($files)-$beforeFiles,'Costing GA dinonaktifkan; chain Purchasing ditutup dan Finance POSTED direversal.',[
            'finance_reversals'=>$reversals,'purchasing_documents'=>$purchasing,'chains'=>$details,
        ]);
    }

    private function lockPurchasingChain(array $plan): void
    {
        $fund=(string)($plan['fund_request_id']??'');
        if($fund!==''&&Schema::hasTable('pur_fund_requests')) DB::table('pur_fund_requests')->where('id',$fund)->lockForUpdate()->first();
        $orderId=(string)($plan['order_id']??'');$table=$this->orderTable((string)($plan['order_kind']??''));
        if($orderId!==''&&$table&&Schema::hasTable($table)) DB::table($table)->where('id',$orderId)->lockForUpdate()->first();
        $invoiceIds=$plan['invoice_ids']??[];
        if($invoiceIds&&Schema::hasTable('pur_invoices')) DB::table('pur_invoices')->whereIn('id',$invoiceIds)->lockForUpdate()->get();
        if($invoiceIds&&Schema::hasTable('pur_invoice_payments')) DB::table('pur_invoice_payments')->whereIn('invoice_id',$invoiceIds)->lockForUpdate()->get();
        if($orderId!==''&&Schema::hasTable('pur_order_ap_lifecycles')) {
            $lifeIds=DB::table('pur_order_ap_lifecycles')->where('order_kind',(string)($plan['order_kind']??''))->where('order_id',$orderId)->lockForUpdate()->pluck('id');
            if($lifeIds->isNotEmpty()&&Schema::hasTable('pur_order_ap_settlements')) DB::table('pur_order_ap_settlements')->whereIn('ap_lifecycle_id',$lifeIds)->lockForUpdate()->get();
        }
    }

    private function mirrorPurchasingReversal(string $generalPostingId): void
    {
        if (! Schema::hasTable('finance_general_posting_journals') || ! Schema::hasTable('finance_purchasing_posting_journals')) return;
        $links = DB::table('finance_general_posting_journals')
            ->where('general_posting_id',$generalPostingId)
            ->whereNotNull('reversal_journal_id')
            ->get(['journal_entry_id','reversal_journal_id','reversal_journal_no','reversed_at']);
        foreach ($links as $link) {
            DB::table('finance_purchasing_posting_journals')->where('journal_entry_id',(string)$link->journal_entry_id)->update([
                'reversal_journal_id' => (string)$link->reversal_journal_id,
                'reversal_journal_no' => $link->reversal_journal_no ? (string)$link->reversal_journal_no : null,
                'reversed_at' => $link->reversed_at ?: now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function retirePurchasingChain(array $plan,string $resetNumber): void
    {
        $invoiceIds=$plan['invoice_ids']??[];
        if ($invoiceIds && Schema::hasTable('pur_invoices')) {
            $payload=['deleted_at'=>now(),'updated_at'=>now()];
            if (Schema::hasColumn('pur_invoices','status')) $payload['status']='CANCELLED';
            if (Schema::hasColumn('pur_invoices','journal_status')) $payload['journal_status']='REVERSED';
            DB::table('pur_invoices')->whereIn('id',$invoiceIds)->whereNull('deleted_at')->update($payload);
        }
        $orderId=(string)($plan['order_id']??'');$kind=(string)($plan['order_kind']??'');
        $table=$this->orderTable($kind);
        if ($orderId!=='' && $table && Schema::hasTable($table)) {
            $payload=['updated_at'=>now()];
            if (Schema::hasColumn($table,'deleted_at')) $payload['deleted_at']=now();
            if (Schema::hasColumn($table,'status')) $payload['status']='CANCELLED';
            DB::table($table)->where('id',$orderId)->update($payload);
        }
        $fund=(string)($plan['fund_request_id']??'');
        if($fund!==''&&Schema::hasTable('pur_fund_requests')) DB::table('pur_fund_requests')->where('id',$fund)->where('source_type','GA_COSTING')->update(['status'=>'CANCELLED','deleted_at'=>now(),'updated_at'=>now()]);

        $gpIds=$plan['finance_general_posting_ids']??[];
        $outboxIds=[];
        if ($gpIds && Schema::hasTable('pur_finance_posting_outbox') && Schema::hasColumn('pur_finance_posting_outbox','general_posting_id')) {
            $outboxIds=DB::table('pur_finance_posting_outbox')->whereIn('general_posting_id',$gpIds)->pluck('id')->map(fn($v)=>(string)$v)->all();
            $p=['status'=>'REVERSED','updated_at'=>now()]; if(Schema::hasColumn('pur_finance_posting_outbox','last_error'))$p['last_error']="Reset GA {$resetNumber}";
            DB::table('pur_finance_posting_outbox')->whereIn('general_posting_id',$gpIds)->update($p);
        }
        if (Schema::hasTable('finance_purchasing_postings') && ($invoiceIds || $outboxIds)) {
            $q=DB::table('finance_purchasing_postings')->where(function($w)use($invoiceIds,$outboxIds):void{if($invoiceIds)$w->orWhereIn('invoice_id',$invoiceIds);if($outboxIds)$w->orWhereIn('outbox_id',$outboxIds);});
            $q->update(['status'=>'REVERSED','reopen_reason'=>"Reset GA {$resetNumber}",'updated_at'=>now()]);
        }
        if ($orderId!==''&&Schema::hasTable('pur_order_ap_lifecycles')) {
            $p=['status'=>'CANCELLED','updated_at'=>now()];
            if(Schema::hasColumn('pur_order_ap_lifecycles','recognition_posting_status'))$p['recognition_posting_status']='REVERSED';
            DB::table('pur_order_ap_lifecycles')->where('order_kind',$kind)->where('order_id',$orderId)->update($p);
        }
    }

    private function resetBillDueDate(array &$files): array
    {
        $rows=$this->deleteAll('ga_bill_due_dates')+$this->deleteSpreadsheetBatches(['ga.bill_due_date'],$files);
        return $this->result($rows,0,'Bill Due Date dibersihkan.');
    }

    private function resetAsset(array &$files): array
    {
        $before=count($files);$this->collectInlineFiles('ga_assets','photo_disk','photo_path',$files);$this->collectMovementFiles('ASSET',$files);
        $rows=$this->deleteMovements('ASSET')+$this->deleteAll('ga_asset_location_balances')+$this->deleteAll('ga_assets')+$this->deleteSpreadsheetBatches(['ga.asset'],$files);
        return $this->result($rows,count($files)-$before,'Asset Recap, balance, dan movement Asset dibersihkan.');
    }

    private function resetInventory(array &$files): array
    {
        $before=count($files);$this->collectInlineFiles('ga_inventory_items','photo_disk','photo_path',$files);$this->collectMovementFiles('INVENTORY',$files);
        $rows=$this->deleteMovements('INVENTORY')+$this->deleteAll('ga_inventory_location_balances')+$this->deleteAll('ga_inventory_items')+$this->deleteSpreadsheetBatches(['ga.inventory'],$files);
        return $this->result($rows,count($files)-$before,'Inventory Recap, balance, PINJAM/KEMBALI, dan movement Inventory dibersihkan.');
    }

    private function resetInventoryAudit(array &$files): array
    {
        $before=count($files);$this->collectInlineFiles('ga_inventory_audit_lines','photo_disk','photo_path',$files);
        $rows=$this->deleteAll('ga_inventory_audit_lines')+$this->deleteAll('ga_inventory_audits');
        return $this->result($rows,count($files)-$before,'Inventory Audit dan Re-Audit dibersihkan.');
    }

    private function resetDriveInventory(): array
    {
        $rows=$this->deleteAll('ga_inventory_drive_audit_logs')+$this->deleteAll('ga_inventory_drive_folder_mappings');
        return $this->result($rows,0,'Metadata Drive Inventory dibersihkan; file Drive dibersihkan setelah commit database.');
    }

    private function resetMasters(): array
    {
        $rows=0;foreach(['ga_damage_categories','ga_cctv_categories','ga_costing_categories'] as $t)$rows+=$this->deleteAll($t);
        return $this->result($rows,0,'Master GA dibersihkan karena dipilih secara eksplisit.');
    }

    private function deleteMovements(string $type): int
    {
        if(!Schema::hasTable('ga_inventory_movements'))return 0;
        if(Schema::hasColumn('ga_inventory_movements','loan_parent_id')) DB::table('ga_inventory_movements')->where('item_type',$type)->update(['loan_parent_id'=>null]);
        return (int)DB::table('ga_inventory_movements')->where('item_type',$type)->delete();
    }

    private function deleteSpreadsheetBatches(array $moduleKeys,array &$files): int
    {
        if(!Schema::hasTable('spreadsheet_transfer_batches')||!Schema::hasColumn('spreadsheet_transfer_batches','module_key'))return 0;
        $ids=DB::table('spreadsheet_transfer_batches')->whereIn('module_key',$moduleKeys)->pluck('id');
        if($ids->isEmpty())return 0;
        foreach(['source_path','output_path','file_path','stored_path','path'] as $column){if(!Schema::hasColumn('spreadsheet_transfer_batches',$column))continue;foreach(DB::table('spreadsheet_transfer_batches')->whereIn('id',$ids)->whereNotNull($column)->pluck($column) as $path)$this->queueFile('local',(string)$path,$files);}
        if(Schema::hasTable('spreadsheet_transfer_rows')&&Schema::hasColumn('spreadsheet_transfer_rows','batch_id'))DB::table('spreadsheet_transfer_rows')->whereIn('batch_id',$ids)->delete();
        return (int)DB::table('spreadsheet_transfer_batches')->whereIn('id',$ids)->delete();
    }

    private function cleanupFiles(array $files,bool $resetDrive): array
    {
        $deleted=0;$failed=[];
        foreach($files as $f){try{$disk=Storage::disk($f['disk']);if($disk->exists($f['path'])&&$disk->delete($f['path']))$deleted++;}catch(Throwable $e){$failed[]=['disk'=>$f['disk'],'path'=>$f['path'],'error'=>$e->getMessage()];}}
        if($resetDrive){try{$disk=Storage::disk('public');if($disk->exists(DriveInventoryMasterService::ROOT))$disk->deleteDirectory(DriveInventoryMasterService::ROOT);$this->driveStorage->ensureInitialStructure();}catch(Throwable $e){$failed[]=['disk'=>'public','path'=>DriveInventoryMasterService::ROOT,'error'=>$e->getMessage()];}}
        return ['requested'=>count($files)+($resetDrive?1:0),'deleted'=>$deleted,'failed'=>array_slice($failed,0,100),'cleanup_retry_required'=>count($failed)>0];
    }

    private function collectAttachmentFiles(string $table,string $diskCol,string $pathCol,array &$files):void
    {if(!Schema::hasTable($table))return;foreach(DB::table($table)->whereNotNull($pathCol)->get([$diskCol,$pathCol]) as $r)$this->queueFile((string)($r->{$diskCol}?:'public'),(string)$r->{$pathCol},$files);}
    private function collectInlineFiles(string $table,string $diskCol,string $pathCol,array &$files):void
    {if(!Schema::hasTable($table)||!Schema::hasColumn($table,$pathCol))return;foreach(DB::table($table)->whereNotNull($pathCol)->get([$diskCol,$pathCol]) as $r)$this->queueFile((string)($r->{$diskCol}?:'public'),(string)$r->{$pathCol},$files);}
    private function collectMovementFiles(string $type,array &$files):void
    {if(!Schema::hasTable('ga_inventory_movements'))return;foreach(DB::table('ga_inventory_movements')->where('item_type',$type)->whereNotNull('photo_path')->get(['photo_disk','photo_path']) as $r)$this->queueFile((string)($r->photo_disk?:'public'),(string)$r->photo_path,$files);}
    private function queueFile(string $disk,string $path,array &$files):void{if(trim($path)==='')return;$files[$disk.'|'.$path]=['disk'=>$disk,'path'=>$path];}
    private function fileCount(array $files):int{return count($files);}

    private function result(int $rows,int $files,string $message,array $extra=[]):array{return ['affected_rows'=>$rows,'affected_files'=>$files,'finance_reversals'=>(int)($extra['finance_reversals']??0),'purchasing_documents'=>(int)($extra['purchasing_documents']??0),'message'=>$message]+$extra;}
    private function deleteAll(string $table):int{return Schema::hasTable($table)?(int)DB::table($table)->delete():0;}
    private function count(string $table):int{return Schema::hasTable($table)?(int)DB::table($table)->count():0;}
    private function movementCount(string $type):int{return Schema::hasTable('ga_inventory_movements')?(int)DB::table('ga_inventory_movements')->where('item_type',$type)->count():0;}
    private function spreadsheetCount(array $keys):int{return Schema::hasTable('spreadsheet_transfer_batches')&&Schema::hasColumn('spreadsheet_transfer_batches','module_key')?(int)DB::table('spreadsheet_transfer_batches')->whereIn('module_key',$keys)->count():0;}
    private function orderTable(string $kind):?string{return match(strtoupper($kind)){'PURCHASE_ORDER'=>'pur_purchase_orders','SERVICE_ORDER'=>'pur_service_orders','REIMBURSE_ORDER'=>'pur_reimburse_orders',default=>null};}

    /** @param array<int,string> $modules @return array<int,string> */
    private function normalizeModules(array $modules):array
    {
        $allowed=array_keys(self::MODULES);$wanted=[];
        foreach($modules as $m){$m=trim((string)$m);if($m!==''&&!in_array($m,$allowed,true))throw ValidationException::withMessages(['modules'=>["Module reset tidak dikenal: {$m}"]]);if($m!=='')$wanted[$m]=true;}
        if(!$wanted)throw ValidationException::withMessages(['modules'=>['Pilih minimal satu module GA untuk di-reset.']]);
        return array_values(array_filter(self::EXECUTION_ORDER,fn(string $key)=>isset($wanted[$key])));
    }

    private function makePreviewToken(array $modules,string $hash):string
    {
        $payload=$this->b64(json_encode(['modules'=>$modules,'hash'=>$hash,'exp'=>now()->addMinutes(15)->timestamp],JSON_UNESCAPED_SLASHES));
        return $payload.'.'.$this->signature($payload);
    }
    private function assertPreviewToken(string $token,array $modules):void
    {
        [$payload,$sig]=array_pad(explode('.',$token,2),2,'');if($payload===''||$sig===''||!hash_equals($this->signature($payload),$sig))throw ValidationException::withMessages(['preview_token'=>['Preview token tidak valid. Jalankan Preview ulang.']]);
        $data=json_decode($this->unb64($payload),true);if(!is_array($data)||(int)($data['exp']??0)<time()||($data['modules']??[])!==$modules)throw ValidationException::withMessages(['preview_token'=>['Preview sudah kedaluwarsa/selection berubah. Jalankan Preview ulang.']]);
    }
    private function assertPreviewTokenMatches(string $token,array $preview):void
    {[$payload]=explode('.',$token,2);$data=json_decode($this->unb64($payload),true);$expected=$this->snapshotHash($this->withoutToken($preview));if(!hash_equals((string)($data['hash']??''),$expected))throw ValidationException::withMessages(['preview_token'=>['Data berubah sejak Preview. Jalankan Preview ulang sebelum reset.']]);}
    private function snapshotHash(array $snapshot):string{return hash('sha256',$this->json($this->withoutToken($snapshot)));}
    private function withoutToken(array $data):array{unset($data['preview_token'],$data['preview_expires_in_minutes'],$data['can_execute'],$data['confirmation_text']);return $data;}
    private function signature(string $payload):string{return hash_hmac('sha256',$payload,$this->appKey());}
    private function appKey():string{$key=(string)config('app.key','');if(str_starts_with($key,'base64:')){$decoded=base64_decode(substr($key,7),true);if($decoded!==false)return $decoded;}return $key;}
    private function b64(string $v):string{return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
    private function unb64(string $v):string{$pad=strlen($v)%4;if($pad)$v.=str_repeat('=',4-$pad);return (string)base64_decode(strtr($v,'-_','+/'),true);}
    private function json(mixed $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)?:'null';}

    /** @return array<int,array<string,mixed>> */
    private function recentRuns():array
    {
        if(!Schema::hasTable('ga_reset_runs'))return[];
        return DB::table('ga_reset_runs')->orderByDesc('started_at')->limit(20)->get(['id','reset_number','status','selected_modules','actor_name_snapshot','actor_nisj_snapshot','started_at','completed_at','failure_message'])->map(function($r){$a=(array)$r;$a['selected_modules']=json_decode((string)($r->selected_modules??'[]'),true)?:[];return $a;})->all();
    }
}
