<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        $this->addBadgeLogoColumns();
        $this->hardenAchievementType();
        $this->createProgramEvents();
        $this->seedPermissions();
        $this->repairAccessMatrix();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function addBadgeLogoColumns(): void
    {
        if (! Schema::hasTable('HR_developments')) return;

        Schema::table('HR_developments', function (Blueprint $table): void {
            if (! Schema::hasColumn('HR_developments', 'badge_logo_path')) {
                $table->string('badge_logo_path', 500)->nullable()->after('badge_description');
            }
            if (! Schema::hasColumn('HR_developments', 'badge_logo_mime')) {
                $table->string('badge_logo_mime', 120)->nullable()->after('badge_logo_path');
            }
            if (! Schema::hasColumn('HR_developments', 'badge_logo_original_name')) {
                $table->string('badge_logo_original_name', 240)->nullable()->after('badge_logo_mime');
            }
            if (! Schema::hasColumn('HR_developments', 'badge_logo_size_bytes')) {
                $table->unsignedBigInteger('badge_logo_size_bytes')->nullable()->after('badge_logo_original_name');
            }
            if (! Schema::hasColumn('HR_developments', 'badge_logo_updated_at')) {
                $table->timestamp('badge_logo_updated_at')->nullable()->after('badge_logo_size_bytes');
            }
        });
    }

    private function hardenAchievementType(): void
    {
        if (! Schema::hasTable('HR_development_achievements') || ! Schema::hasColumn('HR_development_achievements', 'type')) return;

        DB::table('HR_development_achievements')
            ->whereNull('type')
            ->orWhereRaw("TRIM(type) = ''")
            ->update(['type' => 'badge']);

        // Production baseline is MySQL/MariaDB. The service also always supplies `type`;
        // this default is defense-in-depth for older/custom integration paths.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `HR_development_achievements` MODIFY `type` VARCHAR(24) NOT NULL DEFAULT 'badge'");
        }
    }

    private function createProgramEvents(): void
    {
        if (Schema::hasTable('HR_development_program_events')) return;

        Schema::create('HR_development_program_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('development_id');
            $table->string('event_type', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->json('snapshot')->nullable();
            $table->foreignUlid('actor_user_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['development_id', 'created_at'], 'hr_dev_evt_dev_date_idx');
            $table->index(['event_type', 'created_at'], 'hr_dev_evt_type_date_idx');
            $table->foreign('development_id', 'hr_dev_evt_dev_fk')
                ->references('id')->on('HR_developments')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'hr_dev_evt_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('permissions')) return;

        $guard = config('auth.defaults.guard', 'web');
        $permissions = [
            'hr.development.unpublish',
            'hr.development.badge.manage',
        ];
        foreach ($permissions as $name) Permission::findOrCreate($name, $guard);

        // Access Matrix Edit remains the primary authorization path. Explicit action
        // permissions are granted to administrator roles as a compatible Spatie fallback.
        Role::query()
            ->where('guard_name', $guard)
            ->whereIn(DB::raw('LOWER(name)'), ['admin', 'administrator', 'superadmin', 'super-admin'])
            ->get()
            ->each(function (Role $role) use ($permissions): void {
                foreach ($permissions as $permission) $role->givePermissionTo($permission);
            });
    }

    private function repairAccessMatrix(): void
    {
        if (! Schema::hasTable('access_menus')) return;

        DB::table('access_menus')
            ->where(function ($query): void {
                $query->where('code', 'hr-development')
                    ->orWhere('path', '/human-resource/development');
            })
            ->update([
                'permission_view' => 'hr.development.view',
                'permission_create' => 'hr.development.create',
                'permission_update' => 'hr.development.update',
                'permission_delete' => 'hr.development.delete',
                'updated_at' => now(),
            ]);

        DB::table('access_menus')
            ->where(function ($query): void {
                $query->where('code', 'hr-announcement')
                    ->orWhere('path', '/human-resource/announcement');
            })
            ->update([
                'permission_view' => 'hr.announcement.view',
                'permission_create' => 'hr.announcement.create',
                'permission_update' => 'hr.announcement.update',
                'permission_delete' => 'hr.announcement.delete',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Non-destructive rollback policy: badge metadata and lifecycle audit are HR records.
        // Application rollback can ignore these additive fields/tables safely.
    }
};
