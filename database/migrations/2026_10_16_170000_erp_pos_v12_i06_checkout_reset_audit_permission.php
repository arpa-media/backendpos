<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_attendance_checkout_reset_logs')) {
            Schema::create('HR_attendance_checkout_reset_logs', function (Blueprint $table): void {
                $table->char('id', 26)->primary();
                $table->char('attendance_id', 26);
                $table->date('business_date')->nullable();
                $table->char('actor_user_id', 26);
                $table->string('actor_name_snapshot', 180)->nullable();
                $table->text('reason');
                $table->json('before_json');
                $table->json('after_json');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['attendance_id', 'created_at'], 'hr_checkout_reset_att_idx');
                $table->index(['actor_user_id', 'created_at'], 'hr_checkout_reset_actor_idx');
            });
        }

        $permission = 'hr.attendance.data.reset_checkout';
        if (Schema::hasTable('permissions')) {
            Permission::findOrCreate($permission, config('auth.defaults.guard', 'web'));
        }

        // Existing menu, NOT a new menu. Edit action controls who can perform reset.
        if (Schema::hasTable('access_menus')) {
            $menu = DB::table('access_menus')->where('code', 'hr-data-attendance')->first();
            if ($menu) {
                DB::table('access_menus')->where('id', $menu->id)->update([
                    'permission_update' => $permission,
                    'updated_at' => now(),
                ]);

                // Initial least-privilege default for existing installations.
                // HR/Administrator with view access can reset. Other roles remain OFF
                // and can be delegated explicitly via Access Matrix -> Edit.
                if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_menu_permissions')) {
                    $ids = DB::table('access_role_menu_permissions as mp')
                        ->join('access_roles as r', 'r.id', '=', 'mp.access_role_id')
                        ->where('mp.menu_id', (string) $menu->id)
                        ->where('mp.can_view', true)
                        ->whereIn(DB::raw('UPPER(TRIM(r.code))'), ['ADMIN', 'ADMINISTRATOR', 'HR', 'HUMAN_RESOURCE'])
                        ->pluck('mp.id')->all();
                    if ($ids !== []) {
                        DB::table('access_role_menu_permissions')->whereIn('id', $ids)
                            ->update(['can_edit' => true, 'updated_at' => now()]);
                    }
                }
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive for financial/payroll audit history and access configuration.
        // Do not remove the log table, permission, or menu mapping on rollback.
    }
};
