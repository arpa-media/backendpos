<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\FundRequest;
use App\Models\User;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FundRequestApprovalPolicy
{
    public function __construct(private readonly UserAuthContextResolver $authContextResolver)
    {
    }

    /** @return array<string, mixed> */
    public function evaluate(User $user, FundRequest $request): array
    {
        $identity = $this->identity($user);
        $isAdmin = $this->isAdministrator($user);
        $allowed = false;
        $reason = null;

        if ($isAdmin) {
            $allowed = true;
        } elseif ($request->approval_route === FundRequest::APPROVAL_EXECUTIVE) {
            $actorChamber = $this->normalizeChamber((string) ($identity['chamber_name'] ?? ''));
            $requestChamber = $this->normalizeChamber((string) $request->chamber_code);
            $allowed = $user->can('purchasing.fund_request.approve_chamber')
                || $user->can('purchasing.fund_request.approve_executive') // legacy override
                || ($actorChamber !== '' && $actorChamber === $requestChamber);
            if (! $allowed) {
                $reason = 'Approval Chamber hanya dapat dilakukan oleh user pada Chamber yang sama dengan pengajuan.';
            }
        } elseif ($request->approval_route === FundRequest::APPROVAL_SPV_OUTLET) {
            $context = $this->authContextResolver->resolve($user);
            $resolvedOutletId = (string) ($context['resolved_outlet_id'] ?? '');
            $isMatchingOutlet = $resolvedOutletId !== '' && $resolvedOutletId === (string) $request->outlet_id;
            $isSpv = $this->isSpv($user, $identity);

            $allowed = $isSpv && $isMatchingOutlet;
            if (! $allowed) {
                $reason = ! $isSpv
                    ? 'Approval Stock Request hanya untuk SPV outlet.'
                    : 'SPV hanya dapat menyetujui Stock Request untuk outlet penugasannya.';
            }
        } else {
            $reason = 'Approval route dokumen tidak dikenali.';
        }

        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'route' => (string) $request->approval_route,
            'requirement_label' => $request->approval_route === FundRequest::APPROVAL_SPV_OUTLET
                ? 'SPV Outlet'
                : ('Chamber ' . $this->chamberLabel((string) $request->chamber_code)),
            'actor_identity' => $identity,
        ];
    }

    /** @return array<string, mixed> */
    public function identity(User $user): array
    {
        $user->loadMissing(['outlet', 'employee.assignment.outlet', 'accessAssignment.role', 'accessAssignment.level']);
        $hr = null;

        if (Schema::hasTable('HR_squads')) {
            $hr = DB::table('HR_squads')
                ->whereNull('deleted_at')
                ->where(function ($query) use ($user): void {
                    $hasCondition = false;
                    foreach (['nisj' => $user->nisj, 'username' => $user->username, 'email' => $user->email] as $column => $value) {
                        $value = trim((string) $value);
                        if ($value === '') {
                            continue;
                        }
                        $hasCondition ? $query->orWhere($column, $value) : $query->where($column, $value);
                        $hasCondition = true;
                    }
                    if (! $hasCondition) {
                        $query->whereRaw('1 = 0');
                    }
                })
                ->first();
        }

        return [
            'user_id' => (string) $user->id,
            'name' => (string) $user->name,
            'nisj' => $user->nisj,
            'access_role' => $user->accessAssignment?->role?->code,
            'access_level' => $user->accessAssignment?->level?->code,
            'role_title' => $user->employee?->assignment?->role_title,
            'outlet_id' => $user->employee?->assignment?->outlet_id ?: $user->outlet_id,
            'outlet_name' => $user->employee?->assignment?->outlet?->name ?: $user->outlet?->name,
            'chamber_name' => $hr?->chamber_name,
            'division_name' => $hr?->division_name,
            'position_name' => $hr?->position_name,
        ];
    }


    private function normalizeChamber(string $value): string
    {
        $code = strtoupper((string) preg_replace('/[^A-Z0-9]+/', '_', trim($value)));
        return trim($code, '_');
    }

    private function chamberLabel(string $value): string
    {
        $code = $this->normalizeChamber($value);
        return $code === '' ? '-' : ucwords(strtolower(str_replace('_', ' ', $code)));
    }

    /** @param array<string, mixed> $identity */
    private function isSpv(User $user, array $identity): bool
    {
        if ($user->can('purchasing.fund_request.approve_stock')) {
            return true;
        }

        $haystack = strtoupper(implode(' ', array_filter([
            (string) ($identity['division_name'] ?? ''),
            (string) ($identity['position_name'] ?? ''),
            (string) ($identity['role_title'] ?? ''),
        ])));

        return preg_match('/(^|\s)SPV(\s|$)/', $haystack) === 1
            || str_contains($haystack, 'SUPERVISOR');
    }

    private function isAdministrator(User $user): bool
    {
        $seedAdmin = trim((string) config('pos.seed_admin.nisj', '10012501000'));
        if ($seedAdmin !== '' && (string) $user->nisj === $seedAdmin) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'administrator', 'super-admin', 'superadmin']);
    }
}
