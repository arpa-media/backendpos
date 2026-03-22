<?php

use App\Models\AccessMenu;
use App\Models\UserAccessAssignment;
use App\Services\UserManagementService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            AccessMenu::query()
                ->where(function ($query) {
                    $query->whereIn('code', ['sales-cancel', 'cancel-bill'])
                        ->orWhere('path', '/cancel-requests');
                })
                ->update([
                    'permission_view' => 'sale.cancel.approve',
                    'permission_create' => 'sale.cancel.request',
                    'permission_update' => 'sale.cancel.approve',
                    'permission_delete' => 'sale.cancel.approve',
                    'updated_at' => now(),
                ]);
        });

        $this->resyncAssignments();
    }

    public function down(): void
    {
        DB::transaction(function () {
            AccessMenu::query()
                ->where(function ($query) {
                    $query->whereIn('code', ['sales-cancel', 'cancel-bill'])
                        ->orWhere('path', '/cancel-requests');
                })
                ->update([
                    'permission_view' => 'sale.cancel.approve',
                    'permission_create' => null,
                    'permission_update' => null,
                    'permission_delete' => null,
                    'updated_at' => now(),
                ]);
        });

        $this->resyncAssignments();
    }

    protected function resyncAssignments(): void
    {
        $service = app(UserManagementService::class);

        UserAccessAssignment::query()
            ->with('user.roles')
            ->get()
            ->each(function (UserAccessAssignment $assignment) use ($service) {
                if ($assignment->user) {
                    $service->syncUserPermissions($assignment->user);
                }
            });
    }
};
