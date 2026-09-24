<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\StockCancellationRequest;
use App\Models\StockInventory\StockOpname;
use App\Models\StockInventory\StockRequest;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\User;
use App\Services\Purchasing\NonWarehouseStockRequestFundBridgeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCancellationService
{
    public function __construct(
        private readonly NonWarehouseStockRequestFundBridgeService $nonWarehouseFundBridge,
    ) {
    }

    public function requestStockRequestCancellation(
        string $requestId,
        string $outletId,
        string $reason,
        string $userId
    ): array {
        $cancellation = DB::transaction(function () use ($requestId, $outletId, $reason, $userId) {
            /** @var StockRequest $document */
            $document = StockRequest::query()
                ->where('id', $requestId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== StockRequest::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => ['Pembatalan hanya dapat diajukan untuk Request Stock berstatus submitted dan belum diproses Purchasing.'],
                ]);
            }

            $existing = $this->pendingQuery(StockCancellationRequest::TYPE_STOCK_REQUEST, $document->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $cancellation = StockCancellationRequest::query()->create([
                'document_type' => StockCancellationRequest::TYPE_STOCK_REQUEST,
                'document_id' => $document->id,
                'outlet_id' => $document->outlet_id,
                'document_reference' => $document->request_number,
                'document_date' => $document->request_date,
                'reason' => trim($reason),
                'status' => StockCancellationRequest::STATUS_PENDING,
                'requested_by_user_id' => $userId,
                'requested_at' => now(),
                'metadata' => [
                    'document_status' => $document->status,
                    'lock_version' => (int) $document->lock_version,
                ],
            ]);

            StockRequestTimeline::query()->create([
                'stock_request_id' => $document->id,
                'event_code' => 'cancellation_requested',
                'status' => $document->status,
                'message' => 'Pembatalan Request Stock diajukan dan menunggu approval Admin.',
                'metadata' => ['cancellation_request_id' => (string) $cancellation->id, 'reason' => trim($reason)],
                'actor_user_id' => $userId,
            ]);

            return $cancellation;
        });

        return $this->serialize($cancellation);
    }

    public function requestStockOpnameCancellation(
        string $opnameId,
        string $outletId,
        string $reason,
        string $userId
    ): array {
        $cancellation = DB::transaction(function () use ($opnameId, $outletId, $reason, $userId) {
            /** @var StockOpname $document */
            $document = StockOpname::query()
                ->where('id', $opnameId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'status' => ['Pembatalan hanya dapat diajukan untuk Stock Opname berstatus submitted.'],
                ]);
            }

            $blockingRequest = StockRequest::query()
                ->where('source_opname_id', $document->id)
                ->whereIn('status', [
                    StockRequest::STATUS_DRAFT,
                    StockRequest::STATUS_SUBMITTED,
                    StockRequest::STATUS_PARTIALLY_APPROVED,
                    StockRequest::STATUS_APPROVED,
                ])
                ->first();
            if ($blockingRequest) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Stock Opname masih dipakai oleh Request Stock '.$blockingRequest->request_number.'. Hapus draft atau selesaikan pembatalan Request Stock tersebut terlebih dahulu.',
                    ],
                ]);
            }

            $existing = $this->pendingQuery(StockCancellationRequest::TYPE_STOCK_OPNAME, $document->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            return StockCancellationRequest::query()->create([
                'document_type' => StockCancellationRequest::TYPE_STOCK_OPNAME,
                'document_id' => $document->id,
                'outlet_id' => $document->outlet_id,
                'document_reference' => 'OPNAME-'.$document->opname_date?->format('Ymd'),
                'document_date' => $document->opname_date,
                'reason' => trim($reason),
                'status' => StockCancellationRequest::STATUS_PENDING,
                'requested_by_user_id' => $userId,
                'requested_at' => now(),
                'metadata' => ['document_status' => $document->status],
            ]);
        });

        return $this->serialize($cancellation);
    }

    public function decide(string $id, string $decision, ?string $notes, string $userId): array
    {
        $cancellation = DB::transaction(function () use ($id, $decision, $notes, $userId) {
            /** @var StockCancellationRequest $cancellation */
            $cancellation = StockCancellationRequest::query()->lockForUpdate()->findOrFail($id);
            if ($cancellation->status !== StockCancellationRequest::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['Pengajuan pembatalan ini sudah diproses.'],
                ]);
            }

            if ($decision === StockCancellationRequest::STATUS_APPROVED) {
                $this->approveDocument($cancellation, $userId);
            } else {
                $this->recordRejectedTimeline($cancellation, $userId, $notes);
            }

            $cancellation->fill([
                'status' => $decision,
                'decided_by_user_id' => $userId,
                'decided_at' => now(),
                'decision_notes' => filled($notes) ? trim((string) $notes) : null,
            ])->save();

            return $cancellation;
        });

        return $this->serialize($cancellation);
    }

    public function latest(string $documentType, string $documentId): ?array
    {
        $cancellation = StockCancellationRequest::query()
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->latest('requested_at')
            ->latest('created_at')
            ->first();

        return $cancellation ? $this->serialize($cancellation) : null;
    }

    public function hasPending(string $documentType, string $documentId): bool
    {
        return $this->pendingQuery($documentType, $documentId)->exists();
    }

    public function serialize(StockCancellationRequest $cancellation): array
    {
        $cancellation->loadMissing([
            'outlet:id,code,name,timezone',
            'requestedBy:id,name,nisj',
            'decidedBy:id,name,nisj',
        ]);

        return [
            'id' => (string) $cancellation->id,
            'document_type' => (string) $cancellation->document_type,
            'document_id' => (string) $cancellation->document_id,
            'document_reference' => $cancellation->document_reference,
            'document_date' => $cancellation->document_date?->toDateString(),
            'outlet_id' => (string) $cancellation->outlet_id,
            'outlet' => $cancellation->outlet ? [
                'id' => (string) $cancellation->outlet->id,
                'code' => (string) ($cancellation->outlet->code ?? ''),
                'name' => (string) $cancellation->outlet->name,
            ] : null,
            'reason' => (string) $cancellation->reason,
            'status' => (string) $cancellation->status,
            'requested_by' => $this->user($cancellation->requestedBy),
            'requested_at' => $cancellation->requested_at?->toIso8601String(),
            'decided_by' => $this->user($cancellation->decidedBy),
            'decided_at' => $cancellation->decided_at?->toIso8601String(),
            'decision_notes' => $cancellation->decision_notes,
            'metadata' => $cancellation->metadata ?: [],
            'timezone' => (string) ($cancellation->outlet?->timezone ?? config('app.timezone', 'Asia/Jakarta')),
        ];
    }

    private function pendingQuery(string $documentType, string $documentId): Builder
    {
        return StockCancellationRequest::query()
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->where('status', StockCancellationRequest::STATUS_PENDING);
    }

    private function approveDocument(StockCancellationRequest $cancellation, string $userId): void
    {
        if ($cancellation->document_type === StockCancellationRequest::TYPE_STOCK_REQUEST) {
            /** @var StockRequest $document */
            $document = StockRequest::query()->lockForUpdate()->findOrFail($cancellation->document_id);
            if ($document->status !== StockRequest::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => ['Request Stock tidak lagi berstatus submitted sehingga pembatalan tidak dapat disetujui.'],
                ]);
            }

            if ((string) ($document->request_channel ?? '') === NonWarehouseStockRequestFundBridgeService::CHANNEL) {
                /** @var User $actor */
                $actor = User::query()->findOrFail($userId);
                $this->nonWarehouseFundBridge->cancelFromStockRequest($document, $actor, (string) $cancellation->reason);
            }

            $document->items()->update(['status' => StockRequest::STATUS_CANCELLED]);
            $document->forceFill([
                'status' => StockRequest::STATUS_CANCELLED,
                'source_key' => 'cancelled:'.(string) $document->id,
                'purchasing_handoff_status' => (string) ($document->request_channel ?? '') === NonWarehouseStockRequestFundBridgeService::CHANNEL ? 'cancelled' : $document->purchasing_handoff_status,
                'request_approval_status' => (string) ($document->request_channel ?? '') === NonWarehouseStockRequestFundBridgeService::CHANNEL ? 'cancelled' : $document->request_approval_status,
                'lock_version' => (int) $document->lock_version + 1,
                'updated_by_user_id' => $userId,
            ])->save();

            StockRequestTimeline::query()->create([
                'stock_request_id' => $document->id,
                'event_code' => 'cancellation_approved',
                'status' => StockRequest::STATUS_CANCELLED,
                'message' => 'Pembatalan Request Stock disetujui Admin.',
                'metadata' => ['cancellation_request_id' => (string) $cancellation->id],
                'actor_user_id' => $userId,
            ]);

            return;
        }

        /** @var StockOpname $document */
        $document = StockOpname::query()->lockForUpdate()->findOrFail($cancellation->document_id);
        if ($document->status !== 'submitted') {
            throw ValidationException::withMessages([
                'status' => ['Stock Opname tidak lagi berstatus submitted sehingga pembatalan tidak dapat disetujui.'],
            ]);
        }

        $blockingRequest = StockRequest::query()
            ->where('source_opname_id', $document->id)
            ->whereIn('status', [
                StockRequest::STATUS_DRAFT,
                StockRequest::STATUS_SUBMITTED,
                StockRequest::STATUS_PARTIALLY_APPROVED,
                StockRequest::STATUS_APPROVED,
            ])
            ->lockForUpdate()
            ->first();
        if ($blockingRequest) {
            throw ValidationException::withMessages([
                'status' => ['Stock Opname masih dipakai oleh Request Stock '.$blockingRequest->request_number.'.'],
            ]);
        }

        StockRequest::query()
            ->where('source_opname_id', $document->id)
            ->whereIn('status', [StockRequest::STATUS_REJECTED, StockRequest::STATUS_CANCELLED])
            ->whereNotNull('source_key')
            ->get()
            ->each(function (StockRequest $request) {
                $request->forceFill(['source_key' => 'historical:'.(string) $request->id])->save();
            });

        $document->forceFill([
            'status' => 'draft',
            'submitted_by_user_id' => null,
            'submitted_at' => null,
            'updated_by_user_id' => $userId,
        ])->save();
    }

    private function recordRejectedTimeline(StockCancellationRequest $cancellation, string $userId, ?string $notes): void
    {
        if ($cancellation->document_type !== StockCancellationRequest::TYPE_STOCK_REQUEST) {
            return;
        }

        $document = StockRequest::query()->find($cancellation->document_id);
        if (! $document) {
            return;
        }

        StockRequestTimeline::query()->create([
            'stock_request_id' => $document->id,
            'event_code' => 'cancellation_rejected',
            'status' => (string) $document->status,
            'message' => 'Pengajuan pembatalan Request Stock ditolak Admin.',
            'metadata' => [
                'cancellation_request_id' => (string) $cancellation->id,
                'decision_notes' => $notes,
            ],
            'actor_user_id' => $userId,
        ]);
    }

    private function user($user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => (string) $user->id,
            'name' => (string) ($user->name ?? $user->nisj ?? '-'),
            'nisj' => (string) ($user->nisj ?? ''),
        ];
    }
}
