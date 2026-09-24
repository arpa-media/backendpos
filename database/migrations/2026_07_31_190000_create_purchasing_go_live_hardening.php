<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'purchasing.go_live.view',
        'purchasing.go_live.create',
        'purchasing.go_live.update',
        'purchasing.go_live.delete',
        'purchasing.go_live.run',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('pur_go_live_runs')) {
            Schema::create('pur_go_live_runs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('run_number', 80)->unique();
                $table->string('status', 40)->default('RUNNING')->index();
                $table->boolean('strict_mode')->default(false)->index();
                $table->string('environment', 60)->nullable()->index();
                $table->string('app_timezone', 80)->nullable();
                $table->string('database_timezone', 80)->nullable();
                $table->unsignedInteger('pass_count')->default(0);
                $table->unsignedInteger('warn_count')->default(0);
                $table->unsignedInteger('fail_count')->default(0);
                $table->unsignedInteger('skip_count')->default(0);
                $table->json('summary')->nullable();
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['status', 'started_at'], 'pur_go_live_status_date_idx');
                $table->index(['environment', 'started_at'], 'pur_go_live_env_date_idx');
            });
        }

        if (! Schema::hasTable('pur_go_live_checks')) {
            Schema::create('pur_go_live_checks', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('run_id')->constrained('pur_go_live_runs')->cascadeOnDelete();
                $table->string('code', 120);
                $table->string('category', 40)->index();
                $table->string('status', 20)->index();
                $table->string('severity', 20)->index();
                $table->string('title', 180);
                $table->text('summary');
                $table->json('metrics')->nullable();
                $table->json('details')->nullable();
                $table->text('remediation')->nullable();
                $table->unsignedInteger('duration_ms')->default(0);
                $table->timestamps();

                $table->unique(['run_id', 'code'], 'pur_go_live_run_code_uq');
                $table->index(['run_id', 'category', 'status'], 'pur_go_live_run_category_status_idx');
                $table->index(['run_id', 'severity', 'status'], 'pur_go_live_run_severity_status_idx');
            });
        }

        $guard = (string) config('auth.defaults.guard', 'web');
        if (Schema::hasTable('permissions')) {
            foreach (self::PERMISSIONS as $permission) {
                Permission::findOrCreate($permission, $guard);
            }

            if (Schema::hasTable('roles')) {
                $admins = Role::query()
                    ->where(function ($query): void {
                        $query->whereRaw('LOWER(name) IN (?, ?)', ['admin', 'administrator'])
                            ->orWhereRaw('LOWER(name) LIKE ?', ['%super%admin%']);
                    })
                    ->get();

                foreach ($admins as $admin) {
                    $admin->givePermissionTo(self::PERMISSIONS);
                }
            }
        }

        if (Schema::hasTable('access_portals') && Schema::hasTable('access_menus')) {
            $portal = DB::table('access_portals')->where('code', 'purchasing')->first();
            if ($portal) {
                $existing = DB::table('access_menus')->where('code', 'purchasing-go-live')->first();
                DB::table('access_menus')->updateOrInsert(
                    ['code' => 'purchasing-go-live'],
                    [
                        'id' => (string) ($existing->id ?? Str::ulid()),
                        'portal_id' => $portal->id,
                        'name' => 'Go-Live Gate',
                        'path' => '/purchasing/go-live',
                        'sort_order' => 140,
                        'permission_view' => 'purchasing.go_live.view',
                        'permission_create' => 'purchasing.go_live.create',
                        'permission_update' => 'purchasing.go_live.update',
                        'permission_delete' => 'purchasing.go_live.delete',
                        'is_active' => true,
                        'created_at' => $existing->created_at ?? now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive: histori audit deployment dipertahankan untuk kebutuhan investigasi.
    }
};
