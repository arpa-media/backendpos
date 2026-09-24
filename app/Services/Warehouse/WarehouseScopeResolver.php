<?php

namespace App\Services\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WarehouseScopeResolver
{
    public const HEADER = 'X-Warehouse-Id';

    public function resolve(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            return $this->emptyPayload();
        }

        $user->loadMissing([
            'outlet',
            'employee.assignment.outlet',
            'accessAssignment.role',
        ]);

        $allWarehouses = Outlet::query()
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['', 'warehouse'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'address', 'timezone', 'is_active']);

        $assignedWarehouse = $this->assignedWarehouse($user);
        $roleCode = strtoupper(trim((string) ($user->accessAssignment?->role?->code ?? '')));
        $isAdmin = $this->isAdministrator($user, $roleCode);
        $isWarehouseRole = $roleCode === 'WAREHOUSE' || $user->hasAnyRole(['warehouse']);

        $locked = ! $isAdmin && ($assignedWarehouse !== null || $isWarehouseRole);
        $allowedWarehouses = $locked
            ? ($assignedWarehouse ? collect([$assignedWarehouse]) : collect())
            : $allWarehouses;

        $requestedId = trim((string) $request->header(self::HEADER, ''));
        $selected = null;

        if ($requestedId !== '') {
            $selected = $allowedWarehouses->firstWhere('id', $requestedId);
            if (! $selected) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ['Warehouse tidak tersedia pada scope akun ini.'],
                ]);
            }
        }

        if (! $selected && $assignedWarehouse && $allowedWarehouses->contains('id', $assignedWarehouse->id)) {
            $selected = $assignedWarehouse;
        }

        if (! $selected) {
            $selected = $allowedWarehouses->first();
        }

        return [
            'selected' => $selected,
            'warehouses' => $allowedWarehouses->values(),
            'all_warehouse_count' => $allWarehouses->count(),
            'scope_locked' => $locked,
            'can_adjust_scope' => ! $locked && $allowedWarehouses->count() > 1,
            'assignment_warehouse_id' => $assignedWarehouse?->id,
            'access_role_code' => $roleCode ?: null,
        ];
    }

    private function assignedWarehouse(User $user): ?Outlet
    {
        $assignmentOutlet = $user->employee?->assignment?->outlet;
        if ($this->isWarehouse($assignmentOutlet)) {
            return $assignmentOutlet;
        }

        if ($this->isWarehouse($user->outlet)) {
            return $user->outlet;
        }

        return null;
    }

    private function isWarehouse(?Outlet $outlet): bool
    {
        return $outlet !== null
            && strtolower(trim((string) $outlet->type)) === 'warehouse'
            && (bool) $outlet->is_active;
    }

    private function isAdministrator(User $user, string $roleCode): bool
    {
        $seedAdminNisj = trim((string) config('pos.seed_admin.nisj', '10012501000'));

        return $roleCode === 'ADMIN'
            || $user->hasAnyRole(['admin', 'administrator', 'superadmin', 'super-admin'])
            || ($seedAdminNisj !== '' && (string) $user->nisj === $seedAdminNisj);
    }

    private function emptyPayload(): array
    {
        return [
            'selected' => null,
            'warehouses' => collect(),
            'all_warehouse_count' => 0,
            'scope_locked' => true,
            'can_adjust_scope' => false,
            'assignment_warehouse_id' => null,
            'access_role_code' => null,
        ];
    }
}
