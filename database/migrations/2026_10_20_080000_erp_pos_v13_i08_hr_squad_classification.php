<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('HR_squads')) return;
        if (! Schema::hasColumn('HR_squads', 'hr_is_non_squad')) {
            Schema::table('HR_squads', function (Blueprint $table): void {
                $table->boolean('hr_is_non_squad')->default(false)->index('hr_v13_i08_non_squad_idx');
            });
        }
        if (! Schema::hasColumn('HR_squads', 'hr_classified_at')) {
            Schema::table('HR_squads', function (Blueprint $table): void {
                $table->timestamp('hr_classified_at')->nullable();
            });
        }
        if (! Schema::hasTable('hr_v13_i08_squad_classification_audits')) {
            Schema::create('hr_v13_i08_squad_classification_audits', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('squad_id')->index();
                $table->string('actor_user_id', 36)->nullable()->index();
                $table->string('from_classification', 20);
                $table->string('to_classification', 20);
                $table->string('source', 40)->default('data_squad');
                $table->timestamps();
            });
        }
        // Register the existing menu's UPDATE action in Access Matrix.
        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            Permission::findOrCreate('hr.squad.update', $guard);
            if (Schema::hasTable('roles')) {
                Role::query()->where('guard_name', $guard)
                    ->whereIn(DB::raw('LOWER(name)'), ['admin', 'administrator', 'superadmin', 'super-admin'])
                    ->get()->each(fn (Role $role) => $role->givePermissionTo('hr.squad.update'));
            }
        }
        if (Schema::hasTable('access_menus') && Schema::hasColumn('access_menus', 'permission_update')) {
            DB::table('access_menus')->where(function ($q) {
                $q->where('code', 'hr-data-squad')->orWhere('path', '/human-resource/data-squad');
            })->update(['permission_update' => 'hr.squad.update', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Fail-safe: do not silently erase classification/audit history on rollback.
        // Reverting code without dropping the added columns is supported.
    }
};
