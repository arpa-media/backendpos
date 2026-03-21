<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (!config('pos_sync.seeders.enable_demo_users')) {
            $this->command?->warn('DemoUserSeeder skipped: POS_ENABLE_DEMO_USERS=false');
            return;
        }

        $allowDemoOutlets = (bool) config('pos_sync.seeders.enable_demo_outlets');
        $sht = Outlet::query()->where('code', 'SHT')->first();
        $dpn = Outlet::query()->where('code', 'DPN')->first();

        if (!$allowDemoOutlets && (!$sht || !$dpn)) {
            $this->command?->warn('DemoUserSeeder skipped: demo outlets are disabled and HR outlets SHT/DPN do not exist yet.');
            return;
        }

        $admin = User::query()->firstOrCreate(
            ['nisj' => 'DEMO-ADMIN-001'],
            [
                'name' => 'Demo Admin',
                'email' => 'admin@tokokopijaya.com',
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );
        $admin->forceFill([
            'name' => 'Demo Admin',
            'email' => 'admin@tokokopijaya.com',
            'password' => Hash::make('password123'),
            'outlet_id' => null,
            'is_active' => true,
        ])->save();
        $admin->syncRoles(['admin']);

        $this->seedSquadDemoUser('DEMO-SHT-001', 'Soehat (Demo)', 'soehat@tokokopijaya.com', $sht?->id, 'cashier');
        $this->seedSquadDemoUser('DEMO-DPN-001', 'Bali (Demo)', 'bali@tokokopijaya.com', $dpn?->id, 'cashier');
    }

    private function seedSquadDemoUser(string $nisj, string $name, string $email, ?string $outletId, string $role): void
    {
        $user = User::query()->firstOrCreate(
            ['nisj' => $nisj],
            [
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password123'),
                'is_active' => true,
            ]
        );

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password123'),
            'outlet_id' => $outletId,
            'is_active' => true,
        ])->save();

        $user->syncRoles([$role]);

        $employee = Employee::query()->firstOrNew(['user_id' => $user->id]);
        $employee->forceFill([
            'user_id' => $user->id,
            'nisj' => $nisj,
            'full_name' => $name,
            'employment_status' => 'active',
        ])->save();

        if ($outletId) {
            $assignment = Assignment::query()->firstOrNew([
                'employee_id' => $employee->id,
                'outlet_id' => $outletId,
            ]);
            $assignment->forceFill([
                'employee_id' => $employee->id,
                'outlet_id' => $outletId,
                'role_title' => ucfirst($role),
                'start_date' => now()->toDateString(),
                'is_primary' => true,
                'status' => 'active',
            ])->save();

            if ($employee->assignment_id !== $assignment->id) {
                $employee->forceFill(['assignment_id' => $assignment->id])->save();
            }
        }
    }
}
