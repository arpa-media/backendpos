<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\FundRequest;
use App\Models\User;

class FundRequestSourceBridgeService
{
    public function __construct(private readonly FundRequestService $service)
    {
    }

    /**
     * Stable public contract for automatic request sources.
     *
     * Required payload: source_key, source_type, request_type, chamber_code,
     * request_date, needed_date, items[]. Replaying the same source_key updates
     * the draft but never creates a duplicate request.
     *
     * @param array<string, mixed> $payload
     */
    public function syncDraft(array $payload, ?User $actor = null): FundRequest
    {
        return $this->service->upsertAutomaticDraft($payload, $actor);
    }
}
