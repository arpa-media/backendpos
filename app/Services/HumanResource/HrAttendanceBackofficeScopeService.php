<?php

namespace App\Services\HumanResource;

use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HrAttendanceBackofficeScopeService
{
    public function __construct(private readonly UserAuthContextResolver $resolver) {}

    public function allowedOutletQuery(Request $request): Builder
    {
        $ctx = $this->resolver->resolve($request->user());
        $query = DB::table('outlets')
            ->whereIn(DB::raw("LOWER(COALESCE(type, 'outlet'))"), ['outlet', 'headquarter', 'warehouse'])
            ->where('is_active', true);

        if (($ctx['scope_mode'] ?? 'NONE') === 'ONE' && filled($ctx['resolved_outlet_id'] ?? null)) {
            $query->where('id', (string) $ctx['resolved_outlet_id']);
        } elseif (($ctx['scope_mode'] ?? 'NONE') === 'NONE') {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function allowedOutletIds(Request $request): array
    {
        return $this->allowedOutletQuery($request)
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public function isOutletAllowed(Request $request, string $outletId): bool
    {
        return (clone $this->allowedOutletQuery($request))->where('id', $outletId)->exists();
    }

    public function options(Request $request): array
    {
        return $this->allowedOutletQuery($request)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'timezone'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) ($row->code ?? ''),
                'name' => (string) ($row->name ?? '-'),
                'type' => strtolower(trim((string) ($row->type ?? 'outlet'))) ?: 'outlet',
                'timezone' => $this->safeTimezone((string) ($row->timezone ?? '')),
            ])->values()->all();
    }

    public function applyAttendanceScope(Builder $query, Request $request, ?string $requestedOutletId = null): Builder
    {
        $allowed = $this->allowedOutletIds($request);
        if ($allowed === []) return $query->whereRaw('1 = 0');

        $scopeExpression = "COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)";
        $query->whereIn(DB::raw($scopeExpression), $allowed);

        $requestedOutletId = trim((string) $requestedOutletId);
        if ($requestedOutletId !== '') {
            if (! in_array($requestedOutletId, $allowed, true)) return $query->whereRaw('1 = 0');
            $query->whereRaw("{$scopeExpression} = ?", [$requestedOutletId]);
        }

        return $query;
    }

    private function safeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'Asia/Jakarta';
    }
}
