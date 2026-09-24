<?php

namespace App\Services\Warehouse;

use App\Models\Assignment;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WarehouseOutletRequestScopeResolver
{
    public const HEADER = 'X-Outlet-Id';

    public function resolve(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            throw ValidationException::withMessages(['user' => ['User login tidak ditemukan.']]);
        }

        $user->loadMissing(['outlet', 'employee.assignment.outlet', 'accessAssignment.role']);
        $roleCode = strtoupper(trim((string) ($user->accessAssignment?->role?->code ?? '')));
        $isAdmin = $this->isAdministrator($user, $roleCode);
        $assignment = $this->currentAssignment($user);
        $assignedOutlet = $assignment?->outlet;
        if (! $this->isOutlet($assignedOutlet)) {
            $assignedOutlet = $this->isOutlet($user->outlet) ? $user->outlet : null;
        }

        $requestedId = trim((string) $request->header(self::HEADER, $request->query('outlet_id', '')));
        $selected = $assignedOutlet;

        if ($requestedId !== '') {
            if (! $isAdmin && (! $assignedOutlet || (string) $assignedOutlet->id !== $requestedId)) {
                throw ValidationException::withMessages([
                    'outlet_id' => ['Stock Request wajib dibuat atas nama outlet assignment akun.'],
                ]);
            }
            $selected = Outlet::query()
                ->whereKey($requestedId)
                ->where('is_active', true)
                ->whereRaw('LOWER(COALESCE(type, ?)) <> ?', ['', 'warehouse'])
                ->first();
        }

        if (! $selected && $isAdmin) {
            $selected = Outlet::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(COALESCE(type, ?)) <> ?', ['', 'warehouse'])
                ->orderBy('name')
                ->first();
        }

        if (! $selected) {
            throw ValidationException::withMessages([
                'outlet_id' => ['Akun tidak memiliki assignment outlet aktif untuk membuat Stock Request.'],
            ]);
        }

        return [
            'selected' => $selected,
            'assignment_id' => $assignment?->id,
            'scope_locked' => ! $isAdmin,
            'can_adjust_scope' => $isAdmin,
            'access_role_code' => $roleCode ?: null,
        ];
    }

    private function currentAssignment(User $user): ?Assignment
    {
        $assignment = $user->employee?->assignment;
        if ($assignment && $this->assignmentIsCurrent($assignment) && $this->isOutlet($assignment->outlet)) {
            return $assignment;
        }

        if (! $user->employee?->id) {
            return null;
        }

        return Assignment::query()
            ->with('outlet')
            ->where('employee_id', $user->employee->id)
            ->where('is_primary', true)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhereIn('status', ['active', 'ACTIVE']);
            })
            ->where(function ($query): void {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', now()->toDateString());
            })
            ->where(function ($query): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->latest('start_date')
            ->first();
    }

    private function assignmentIsCurrent(Assignment $assignment): bool
    {
        $status = strtolower(trim((string) ($assignment->status ?? 'active')));
        if ($status !== '' && $status !== 'active') {
            return false;
        }
        $today = now()->toDateString();
        if ($assignment->start_date && $assignment->start_date->toDateString() > $today) {
            return false;
        }
        if ($assignment->end_date && $assignment->end_date->toDateString() < $today) {
            return false;
        }
        return true;
    }

    private function isOutlet(?Outlet $outlet): bool
    {
        return $outlet !== null
            && strtolower(trim((string) $outlet->type)) !== 'warehouse'
            && (bool) $outlet->is_active;
    }

    private function isAdministrator(User $user, string $roleCode): bool
    {
        $seedAdminNisj = trim((string) config('pos.seed_admin.nisj', '10012501000'));

        return $roleCode === 'ADMIN'
            || $user->hasAnyRole(['admin', 'administrator', 'superadmin', 'super-admin'])
            || ($seedAdminNisj !== '' && (string) $user->nisj === $seedAdminNisj);
    }
}
