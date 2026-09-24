<?php
namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LegacyDocumentLinkService
{
    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function link(array $data): array
    {
        if (!Schema::hasTable('pur_legacy_document_links')) return $data;
        $sourceSystem = strtoupper(trim((string)($data['source_system'] ?? 'LEGACY')));
        $sourceTable = trim((string)($data['source_table'] ?? ''));
        $sourceId = trim((string)($data['source_id'] ?? ''));
        $canonicalType = strtoupper(trim((string)($data['canonical_type'] ?? 'UNKNOWN')));
        $canonicalId = trim((string)($data['canonical_id'] ?? '')) ?: null;
        $identity = hash('sha256', implode('|', [$sourceSystem, $sourceTable, $sourceId, $canonicalType]));
        $slot = $canonicalId ? strtolower($canonicalType).':'.$canonicalId : null;
        $primary = $canonicalId !== null;
        $status = $canonicalId ? 'LINKED' : 'UNRESOLVED';

        if ($slot && DB::table('pur_legacy_document_links')->where('primary_slot', $slot)->where('identity_key', '<>', $identity)->exists()) {
            $primary = false; $slot = null; $status = 'DUPLICATE';
        }
        $old = DB::table('pur_legacy_document_links')->where('identity_key', $identity)->first();
        $id = (string)($old->id ?? Str::ulid());
        DB::table('pur_legacy_document_links')->updateOrInsert(['identity_key' => $identity], [
            'id' => $id, 'source_system' => $sourceSystem, 'source_table' => $sourceTable,
            'source_id' => $sourceId, 'source_number' => $data['source_number'] ?? null,
            'canonical_type' => $canonicalType, 'canonical_id' => $canonicalId,
            'canonical_number' => $data['canonical_number'] ?? null,
            'root_fund_request_id' => $data['root_fund_request_id'] ?? null,
            'link_status' => $data['link_status'] ?? $status,
            'match_strategy' => $data['match_strategy'] ?? null,
            'confidence' => (float)($data['confidence'] ?? ($canonicalId ? 1 : 0)),
            'primary_slot' => $slot, 'is_primary' => $primary,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
            'linked_at' => $canonicalId ? now() : null,
            'created_at' => $old->created_at ?? now(), 'updated_at' => now(),
        ]);
        return (array) DB::table('pur_legacy_document_links')->where('id', $id)->first();
    }
}
