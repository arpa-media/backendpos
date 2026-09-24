<?php

namespace App\Services\HumanResource;

use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HrAssignmentScopeService
{
    public function __construct(private readonly UserAuthContextResolver $resolver) {}

    public function allowedOutletQuery(Request $request): Builder
    {
        $user = $request->user();
        $user->loadMissing('accessAssignment.role');
        $roleCode = strtoupper(trim((string) ($user->accessAssignment?->role?->code ?? '')));
        $query = DB::table('outlets')->where('is_active', true);

        // HR administration is global for ADMIN/MANAGER when the Access Matrix grants
        // this menu. Squad-like accounts remain locked to their operational outlet.
        if (in_array($roleCode, ['ADMIN', 'MANAGER'], true)) {
            return $query;
        }

        $ctx = $this->resolver->resolve($user);
        $mode = (string) ($ctx['scope_mode'] ?? 'NONE');
        if ($mode === 'ONE' && filled($ctx['resolved_outlet_id'] ?? null)) {
            $query->where('id', (string) $ctx['resolved_outlet_id']);
        } elseif ($mode === 'NONE') {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /** @return list<string> */
    public function allowedOutletIds(Request $request): array
    {
        return $this->allowedOutletQuery($request)->pluck('id')->map(fn ($v) => (string) $v)->values()->all();
    }

    public function assertOutlet(Request $request, string $outletId): void
    {
        if (! (clone $this->allowedOutletQuery($request))->where('id', $outletId)->exists()) {
            abort(403, 'Outlet tidak termasuk scope assignment akun ini.');
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function outletOptions(Request $request): array
    {
        return $this->allowedOutletQuery($request)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'timezone'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) ($row->code ?? ''),
                'name' => (string) ($row->name ?? '-'),
                'type' => (string) ($row->type ?? 'outlet'),
                'timezone' => (string) ($row->timezone ?? 'Asia/Jakarta'),
            ])->values()->all();
    }
}
