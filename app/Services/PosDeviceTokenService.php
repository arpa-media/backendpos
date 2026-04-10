<?php

namespace App\Services;

use App\Models\PosDeviceToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PosDeviceTokenService
{
    public function issueForPosSession(User $user, array $context, Request $request, ?string $requestedOutletCode = null): array
    {
        $outletCode = strtoupper(trim((string) ($requestedOutletCode ?: ($context['resolved_outlet_code'] ?? ''))));
        $outletId = trim((string) ($context['resolved_outlet_id'] ?? $user->outlet_id ?? '')) ?: null;
        $fingerprint = $this->resolveDeviceFingerprint($request, $outletCode);
        $plainToken = 'pds_' . Str::random(80);
        $now = now();
        $expiresAt = $now->copy()->addDays((int) config('pos_sync.device_token_ttl_days', 45));

        PosDeviceToken::query()
            ->where('outlet_code', $outletCode)
            ->where('device_fingerprint', $fingerprint)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);

        $record = PosDeviceToken::query()->create([
            'user_id' => $user->id,
            'outlet_id' => $outletId,
            'outlet_code' => $outletCode,
            'token_hash' => hash('sha256', $plainToken),
            'device_fingerprint' => $fingerprint,
            'app_variant' => $this->resolveAppVariant($request),
            'user_agent' => $this->resolveUserAgent($request),
            'abilities' => ['pos.checkout', 'pos.offline_sync'],
            'last_user_nisj' => (string) ($user->nisj ?? ''),
            'issued_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $expiresAt,
        ]);

        return $this->toPayload($record, $plainToken);
    }

    public function resolveFromRequest(Request $request): ?array
    {
        $provided = trim((string) ($request->header('X-POS-Device-Token', $request->header('x-pos-device-token', ''))));
        if ($provided === '') {
            return null;
        }

        $record = PosDeviceToken::query()
            ->with(['user.roles', 'user.permissions', 'user.employee.assignment.outlet', 'user.outlet', 'user.reportOutletAssignments.outlet'])
            ->where('token_hash', hash('sha256', $provided))
            ->first();

        if (!$record || $record->isRevoked() || $record->isExpired()) {
            return null;
        }

        $user = $record->user;
        if (!$user || !($user->is_active ?? true)) {
            return null;
        }

        $requestedOutletId = trim((string) ($request->header('X-Outlet-Id', $request->input('outlet_id', ''))));
        if ($requestedOutletId !== '' && $record->outlet_id && $requestedOutletId !== (string) $record->outlet_id) {
            return null;
        }

        $record->forceFill([
            'last_seen_at' => now(),
            'last_user_nisj' => (string) ($user->nisj ?? ''),
        ])->save();

        return [
            'record' => $record,
            'user' => $user,
        ];
    }

    public function toPayload(PosDeviceToken $record, ?string $plainToken = null): array
    {
        return [
            'token' => $plainToken,
            'token_type' => 'DeviceSync',
            'id' => (string) $record->id,
            'outlet_id' => $record->outlet_id ? (string) $record->outlet_id : null,
            'outlet_code' => (string) $record->outlet_code,
            'last_user_nisj' => (string) ($record->last_user_nisj ?? ''),
            'abilities' => array_values(array_filter(Arr::wrap($record->abilities))),
            'device_fingerprint' => (string) ($record->device_fingerprint ?? ''),
            'issued_at' => optional($record->issued_at)->toIso8601String(),
            'last_seen_at' => optional($record->last_seen_at)->toIso8601String(),
            'expires_at' => optional($record->expires_at)->toIso8601String(),
        ];
    }

    private function resolveDeviceFingerprint(Request $request, string $outletCode): string
    {
        $deviceId = trim((string) ($request->header('X-Device-Id') ?: $request->input('device_id', '')));
        $variant = $this->resolveAppVariant($request);
        $agent = $this->resolveUserAgent($request);

        return substr(hash('sha256', implode('|', [
            strtoupper($outletCode ?: 'UNKNOWN'),
            Str::lower($deviceId ?: 'no-device-id'),
            Str::lower($variant ?: 'default'),
            Str::lower($agent ?: 'unknown-client'),
        ])), 0, 64);
    }

    private function resolveAppVariant(Request $request): string
    {
        return strtoupper(trim((string) ($request->header('X-App-Variant') ?: $request->input('app_variant', ''))));
    }

    private function resolveUserAgent(Request $request): string
    {
        return trim((string) ($request->header('X-Cap-User-Agent') ?: $request->userAgent() ?: 'unknown-client'));
    }
}
