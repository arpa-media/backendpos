<?php

namespace App\Http\Controllers;

use App\Support\PosAuthContextSnapshotBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosAuthContextExportController extends Controller
{
    public function __invoke(Request $request, PosAuthContextSnapshotBuilder $builder): JsonResponse
    {
        $expectedToken = (string) config('services.pos_sync.shared_token', '');
        $providedToken = (string) $request->header('X-POS-SYNC-TOKEN', '');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized POS sync export request.',
            ], 401);
        }

        $snapshot = $builder->build([
            'active_only' => filter_var((string) $request->query('active_only', '0'), FILTER_VALIDATE_BOOL),
            'since' => $request->query('since'),
        ]);

        return response()->json($snapshot, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
