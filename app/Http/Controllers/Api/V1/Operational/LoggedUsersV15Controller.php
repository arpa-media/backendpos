<?php

namespace App\Http\Controllers\Api\V1\Operational;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\User;
use App\Services\OperationalSessionAuditV15Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

class LoggedUsersV15Controller extends Controller
{
    private function rejectPos(Request $request): bool
    {
        $name = strtolower((string) $request->user()?->currentAccessToken()?->name);
        return $name === 'pos' || str_starts_with($name, 'pos:');
    }

    public function index(Request $request)
    {
        if ($this->rejectPos($request)) return ApiResponse::error('Backoffice session required.', 'BACKOFFICE_REQUIRED', 403);
        $v = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'mode' => ['nullable', 'in:ALL,POS,BACKOFFICE'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $mode = strtoupper($v['mode'] ?? 'ALL');
        $search = trim((string) ($v['search'] ?? ''));
        $tokens = PersonalAccessToken::query()->where('tokenable_type', (new User())->getMorphClass())
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->where(function ($q) use ($mode) {
                if ($mode === 'POS') $q->where(fn ($inner) => $inner->where('name', 'pos')->orWhere('name', 'like', 'pos:%'));
                elseif ($mode === 'BACKOFFICE') $q->where(fn ($inner) => $inner->where('name', 'backoffice')->orWhere('name', 'like', 'backoffice:%'));
                else $q->where(fn ($inner) => $inner->whereIn('name', ['pos', 'backoffice'])->orWhere('name', 'like', 'pos:%')->orWhere('name', 'like', 'backoffice:%'));
            });
        if ($search !== '') {
            $users = User::query()->where(fn ($q) => $q
                ->where('name', 'like', '%' . $search . '%')
                ->orWhere('nisj', 'like', '%' . $search . '%')
                ->orWhere('username', 'like', '%' . $search . '%'))
                ->select('id');
            $tokens->whereIn('tokenable_id', $users);
        }
        $result = $tokens->orderByDesc('last_used_at')->orderByDesc('id')->paginate((int) ($v['per_page'] ?? 25));
        $ids = $result->getCollection()->pluck('id')->all();
        $tokenUsers = User::query()->whereIn('id', $result->getCollection()->pluck('tokenable_id')->all())
            ->get(['id', 'name', 'username', 'nisj', 'outlet_id'])->keyBy('id');
        $loginEvents = Schema::hasTable('operational_session_events') && $ids
            ? DB::table('operational_session_events')->whereIn('sanctum_token_id', $ids)
                ->where('event', 'LOGIN')->orderByDesc('created_at')->get()
                ->unique('sanctum_token_id')->keyBy('sanctum_token_id')
            : collect();
        $rows = $result->getCollection()->map(function ($token) use ($tokenUsers, $loginEvents, $request) {
            $name = strtolower((string) $token->name);
            $mode = ($name === 'pos' || str_starts_with($name, 'pos:')) ? 'POS' : 'BACKOFFICE';
            $parts = explode(':', (string) $token->name);
            $meta = $loginEvents->get($token->id);
            $user = $tokenUsers->get($token->tokenable_id);
            return [
                'id' => (int) $token->id,
                'user_id' => $user?->id,
                'name' => $user?->name,
                'nisj' => $user?->nisj,
                'username' => $user?->username,
                'mode' => $mode,
                'outlet_code' => $meta?->outlet_code ?: ($mode === 'POS' ? ($parts[1] ?? null) : null),
                'app_variant' => $meta?->app_variant ?: null,
                'client_label' => $meta?->client_label ?: 'Legacy client (detail unavailable)',
                'device_hint' => $meta?->device_hash ? substr($meta->device_hash, 0, 10) : null,
                'issued_at' => $token->created_at?->toIso8601String(),
                'last_seen_at' => $token->last_used_at?->toIso8601String(),
                'last_seen_status' => $token->last_used_at && $token->last_used_at->gt(now()->subMinutes(5)) ? 'RECENT' : 'UNKNOWN',
                'expires_at' => $token->expires_at?->toIso8601String(),
                'is_current' => (int) $request->user()?->currentAccessToken()?->id === (int) $token->id,
            ];
        });
        return ApiResponse::ok([
            'items' => $rows->values(), 'page' => $result->currentPage(),
            'last_page' => $result->lastPage(), 'total' => $result->total(),
            'per_page' => $result->perPage(),
            'note' => 'Recent = token digunakan dalam 5 menit terakhir, bukan jaminan perangkat online. Device-sync token tidak dicabut melalui kick sesi.',
        ]);
    }

    public function kick(Request $request, int $id, OperationalSessionAuditV15Service $audits)
    {
        if ($this->rejectPos($request)) return ApiResponse::error('Backoffice session required.', 'BACKOFFICE_REQUIRED', 403);
        $request->validate(['reason' => ['nullable', 'string', 'max:250']]);
        $current = $request->user()?->currentAccessToken();
        if ($current && (int) $current->id === $id) return ApiResponse::error('Cannot kick your current session.', 'SELF_KICK_FORBIDDEN', 422);
        // Lock the exact token row, never mass-revoke a user's sessions.
        $outcome = DB::transaction(function () use ($request, $id, $audits) {
            $token = PersonalAccessToken::query()->lockForUpdate()->find($id);
            if (! $token || $token->tokenable_type !== (new User())->getMorphClass()) return 'NOT_FOUND';
            $mode = (strtolower($token->name) === 'pos' || str_starts_with(strtolower($token->name), 'pos:')) ? 'POS' : 'BACKOFFICE';
            $user = User::query()->find($token->tokenable_id);
            $parts = explode(':', (string) $token->name);
            $audits->record($request, 'KICK', $user, $id, $request->user(), $mode, $mode === 'POS' ? ($parts[1] ?? null) : null);
            $token->delete();
            // Intentionally preserve pos_device_tokens: already-created offline sales remain syncable.
            return 'KICKED';
        });
        if ($outcome !== 'KICKED') return ApiResponse::error('Session no longer exists.', 'SESSION_NOT_FOUND', 404);
        return ApiResponse::ok(['token_id' => $id, 'device_sync_preserved' => true], 'Session revoked. Newer POS clients will prompt re-login when online.');
    }

    public function events(Request $request)
    {
        if ($this->rejectPos($request)) return ApiResponse::error('Backoffice session required.', 'BACKOFFICE_REQUIRED', 403);
        if (! Schema::hasTable('operational_session_events')) return ApiResponse::ok(['items' => []]);
        $rows = DB::table('operational_session_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('users as a', 'a.id', '=', 'e.actor_user_id')
            ->orderByDesc('e.created_at')->limit(60)
            ->get(['e.id','e.event','e.session_mode','e.outlet_code','e.created_at','u.name as user_name','u.nisj','a.name as actor_name']);
        return ApiResponse::ok(['items' => $rows]);
    }
}
