<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Support\OutletScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * V15-I06 read-only acknowledgement probe for POS journal IDs.
 * Only returns identities visible inside the authenticated, middleware-resolved outlet.
 * No checkout retry, no status updates, no sync token rotation.
 */
class CashierSyncIdentityV15I06Controller extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_sync_ids' => ['required', 'array', 'min:1', 'max:200'],
            'client_sync_ids.*' => ['required', 'string', 'min:1', 'max:128'],
        ]);
        $outletId = (string) (OutletScope::id($request) ?: '');
        if ($outletId === '') {
            return response()->json(['message' => 'POS outlet scope tidak tersedia.'], 403);
        }
        $ids = array_values(array_unique(array_map('strval', $validated['client_sync_ids'])));
        $matched = Sale::withTrashed()
            ->where('outlet_id', $outletId)
            ->whereIn('client_sync_id', $ids)
            ->get(['id', 'client_sync_id', 'status', 'grand_total', 'deleted_at'])
            ->map(fn (Sale $sale) => [
                'client_sync_id' => (string) $sale->client_sync_id,
                'status' => (string) $sale->status,
                'grand_total' => (int) $sale->grand_total,
                'deleted' => $sale->deleted_at !== null,
            ])->values()->all();

        return response()->json(['data' => [
            'matched' => $matched,
            'scope' => 'authenticated_outlet',
            'verified' => true,
        ]])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
