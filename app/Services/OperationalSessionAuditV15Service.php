<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperationalSessionAuditV15Service
{
    public function record(Request $request, string $event, ?User $user, ?int $tokenId, ?User $actor = null, ?string $mode = null, ?string $outletCode = null): void
    {
        if (! Schema::hasTable('operational_session_events')) return;
        $deviceId = trim((string) ($request->header('X-Device-Id') ?: $request->input('device_id', '')));
        $agent = trim((string) ($request->header('X-Cap-User-Agent') ?: $request->userAgent() ?: ''));
        // No raw device identifier, IP address, bearer token, or password in audit.
        DB::table('operational_session_events')->insert([
            'id' => (string) Str::ulid(),
            'sanctum_token_id' => $tokenId,
            'user_id' => $user?->id,
            'actor_user_id' => $actor?->id,
            'event' => strtoupper($event),
            'session_mode' => $mode,
            'outlet_code' => $outletCode ? strtoupper($outletCode) : null,
            'app_variant' => substr((string) $request->header('X-App-Variant', $request->input('app_variant', '')), 0, 64),
            'device_hash' => $deviceId !== '' ? substr(hash('sha256', $deviceId), 0, 32) : null,
            'client_label' => Str::limit($agent, 150, ''),
            'created_at' => now(),
        ]);
    }
}
