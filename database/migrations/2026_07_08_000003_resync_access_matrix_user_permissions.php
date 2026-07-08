<?php

use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('user_access_assignments')) {
            return;
        }

        /** @var UserManagementService $service */
        $service = app(UserManagementService::class);

        User::query()
            ->whereHas('accessAssignment')
            ->chunkById(100, function ($users) use ($service) {
                foreach ($users as $user) {
                    $service->syncUserPermissions($user);
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Data-only resync migration. Tidak perlu rollback supaya permission user tetap konsisten.
    }
};
