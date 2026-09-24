<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const MENU_CODE = 'hr-announcement';
    private const MENU_PATH = '/human-resource/announcement';

    public function up(): void
    {
        $this->createTables();
        $this->seedAccessMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('HR_announcements')) {
            Schema::create('HR_announcements', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('title', 200);
                $table->text('body');
                $table->string('type', 20)->default('info');
                $table->string('status', 20)->default('draft');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('expired_at')->nullable();
                $table->ulid('published_by_user_id')->nullable();
                $table->ulid('created_by_user_id')->nullable();
                $table->ulid('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'starts_at', 'ends_at'], 'hr_ann_lifecycle_idx');
                $table->index('created_by_user_id', 'hr_ann_creator_idx');
                $table->foreign('published_by_user_id', 'hr_ann_publisher_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('created_by_user_id', 'hr_ann_creator_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by_user_id', 'hr_ann_updater_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_announcement_targets')) {
            Schema::create('HR_announcement_targets', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('announcement_id');
                $table->string('target_type', 30);
                $table->string('target_value', 191);
                $table->string('target_label', 191)->nullable();
                $table->timestamps();

                $table->unique(['announcement_id', 'target_type', 'target_value'], 'hr_ann_tgt_unique');
                $table->index(['target_type', 'target_value'], 'hr_ann_tgt_lookup_idx');
                $table->foreign('announcement_id', 'hr_ann_tgt_ann_fk')->references('id')->on('HR_announcements')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('HR_announcement_attachments')) {
            Schema::create('HR_announcement_attachments', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('announcement_id');
                $table->string('original_name', 255);
                $table->string('mime_type', 150);
                $table->string('extension', 20)->nullable();
                $table->string('storage_disk', 50)->default('local');
                $table->string('storage_path', 500);
                $table->string('compression_method', 30)->default('gzip');
                $table->unsignedBigInteger('source_size_bytes')->default(0);
                $table->unsignedBigInteger('normalized_size_bytes')->default(0);
                $table->unsignedBigInteger('compressed_size_bytes')->default(0);
                $table->char('sha256', 64);
                $table->ulid('uploaded_by_user_id')->nullable();
                $table->timestamp('purged_at')->nullable();
                $table->string('purge_reason', 120)->nullable();
                $table->unsignedBigInteger('stored_bytes_before_purge')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['announcement_id', 'purged_at'], 'hr_ann_att_purge_idx');
                $table->foreign('announcement_id', 'hr_ann_att_ann_fk')->references('id')->on('HR_announcements')->cascadeOnDelete();
                $table->foreign('uploaded_by_user_id', 'hr_ann_att_user_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_announcement_polls')) {
            Schema::create('HR_announcement_polls', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('announcement_id')->unique('hr_ann_poll_ann_unique');
                $table->text('question');
                $table->string('selection_mode', 20)->default('single');
                $table->unsignedSmallInteger('max_choices')->nullable();
                $table->string('result_visibility', 30)->default('after_vote');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->ulid('created_by_user_id')->nullable();
                $table->ulid('updated_by_user_id')->nullable();
                $table->timestamps();

                $table->foreign('announcement_id', 'hr_ann_poll_ann_fk')->references('id')->on('HR_announcements')->cascadeOnDelete();
                $table->foreign('created_by_user_id', 'hr_ann_poll_creator_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by_user_id', 'hr_ann_poll_updater_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_announcement_poll_options')) {
            Schema::create('HR_announcement_poll_options', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('poll_id');
                $table->string('option_text', 500);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['poll_id', 'sort_order'], 'hr_ann_poll_opt_sort_idx');
                $table->foreign('poll_id', 'hr_ann_poll_opt_fk')->references('id')->on('HR_announcement_polls')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('HR_announcement_poll_votes')) {
            Schema::create('HR_announcement_poll_votes', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->ulid('poll_id');
                $table->ulid('option_id');
                $table->ulid('user_id')->nullable();
                $table->string('voter_nisj', 80)->nullable();
                $table->string('voter_name', 180)->nullable();
                $table->timestamp('voted_at');
                $table->timestamps();

                $table->unique(['poll_id', 'option_id', 'user_id'], 'hr_ann_poll_vote_unique');
                $table->index(['poll_id', 'user_id'], 'hr_ann_poll_vote_user_idx');
                $table->foreign('poll_id', 'hr_ann_poll_vote_poll_fk')->references('id')->on('HR_announcement_polls')->cascadeOnDelete();
                $table->foreign('option_id', 'hr_ann_poll_vote_opt_fk')->references('id')->on('HR_announcement_poll_options')->cascadeOnDelete();
                $table->foreign('user_id', 'hr_ann_poll_vote_user_fk')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    private function seedAccessMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) {
            return;
        }

        $now = now();
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) {
            return;
        }

        $existing = DB::table('access_menus')->where('code', self::MENU_CODE)->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(
            ['code' => self::MENU_CODE],
            [
                'id' => $menuId,
                'portal_id' => (string) $portal->id,
                'name' => 'Announcement',
                'path' => self::MENU_PATH,
                'sort_order' => 24,
                'permission_view' => 'hr.announcement.view',
                'permission_create' => 'hr.announcement.create',
                'permission_update' => 'hr.announcement.update',
                'permission_delete' => 'hr.announcement.delete',
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $guard = config('auth.defaults.guard', 'web');
        $permissions = [
            'hr.announcement.view',
            'hr.announcement.create',
            'hr.announcement.update',
            'hr.announcement.delete',
            'hr.announcement.publish',
            'hr.announcement.results',
            'hr.announcement.export',
        ];

        if (Schema::hasTable('permissions')) {
            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) {
            return;
        }

        $levelIds = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];

        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $code = strtoupper(trim((string) $role->code));
            foreach (array_merge([null], $levelIds) as $levelId) {
                $isAdmin = $code === 'ADMIN';
                $isManager = $code === 'MANAGER';
                $canViewPortal = $this->portalCanView((string) $role->id, $levelId, (string) $portal->id, $isAdmin || $isManager);
                $canView = $canViewPortal && ($isAdmin || $isManager);

                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                if ($query->exists()) {
                    continue;
                }

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => (string) $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $menuId,
                    'can_view' => $canView,
                    'can_create' => $canView,
                    'can_edit' => $canView,
                    'can_delete' => $canView && $isAdmin,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function portalCanView(string $roleId, ?string $levelId, string $portalId, bool $fallback): bool
    {
        if (! Schema::hasTable('access_role_portal_permissions')) {
            return $fallback;
        }

        $base = DB::table('access_role_portal_permissions')
            ->where('access_role_id', $roleId)
            ->where('portal_id', $portalId)
            ->whereNull('access_level_id')
            ->first();
        $exact = $levelId === null ? null : DB::table('access_role_portal_permissions')
            ->where('access_role_id', $roleId)
            ->where('portal_id', $portalId)
            ->where('access_level_id', $levelId)
            ->first();

        $effective = $exact ?: $base;
        return $effective ? (bool) $effective->can_view : $fallback;
    }

    public function down(): void
    {
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where('code', self::MENU_CODE)->delete();
        }

        Schema::dropIfExists('HR_announcement_poll_votes');
        Schema::dropIfExists('HR_announcement_poll_options');
        Schema::dropIfExists('HR_announcement_polls');
        Schema::dropIfExists('HR_announcement_attachments');
        Schema::dropIfExists('HR_announcement_targets');
        Schema::dropIfExists('HR_announcements');
    }
};
