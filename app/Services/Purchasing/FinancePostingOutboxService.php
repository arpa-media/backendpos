<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FinancePostingOutboxService
{
    /** @param array<string, mixed> $payload */
    public function enqueue(string $eventKey, string $eventType, string $aggregateType, string $aggregateId, array $payload): void
    {
        if (! Schema::hasTable('pur_finance_posting_outbox')) {
            return;
        }

        $normalizedPayload = $this->normalize($payload);
        $fingerprint = hash('sha256', json_encode([
            'event_key' => $eventKey,
            'event_type' => strtoupper($eventType),
            'aggregate_type' => strtoupper($aggregateType),
            'aggregate_id' => $aggregateId,
            'payload' => $normalizedPayload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $row = [
            'id' => (string) Str::ulid(),
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => json_encode($normalizedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'PENDING',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('pur_finance_posting_outbox', 'source_fingerprint')) {
            $row['source_fingerprint'] = $fingerprint;
        }

        DB::table('pur_finance_posting_outbox')->insertOrIgnore($row);

        // Event key is immutable. On idempotent replay only fill a missing fingerprint;
        // never mutate payload/status of an already staged economic event.
        if (Schema::hasColumn('pur_finance_posting_outbox', 'source_fingerprint')) {
            DB::table('pur_finance_posting_outbox')
                ->where('event_key', $eventKey)
                ->whereNull('source_fingerprint')
                ->update(['source_fingerprint' => $fingerprint, 'updated_at' => now()]);
        }
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn ($item) => $this->normalize($item), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->normalize($item);
        return $value;
    }
}
