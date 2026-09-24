<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('HR_squad_deletion_audits')) {
            Schema::create('HR_squad_deletion_audits', function (Blueprint $table) {
                $table->char('id', 26)->primary();
                $table->string('squad_id_snapshot', 64);
                $table->char('user_id_snapshot', 26)->nullable();
                $table->char('employee_id_snapshot', 26)->nullable();
                $table->char('identity_hash', 64);
                $table->string('delete_mode', 80);
                $table->json('deleted_counts')->nullable();
                $table->json('external_blockers')->nullable();
                $table->char('deleted_by_user_id', 26)->nullable();
                $table->timestamp('deleted_at');
                $table->timestamps();
                $table->index(['deleted_at', 'delete_mode'], 'hr_sq_del_audit_date_mode_idx');
            });
        }

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            Permission::findOrCreate('hr.squad.delete', $guard);
            Role::query()->where('guard_name', $guard)
                ->whereIn(DB::raw('LOWER(name)'), ['admin','administrator','superadmin','super-admin'])
                ->get()->each(fn (Role $role) => $role->givePermissionTo('hr.squad.delete'));
        }
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')->where(function ($q) {
                $q->where('code', 'hr-data-squad')->orWhere('path', '/human-resource/data-squad');
            })->update(['permission_delete' => 'hr.squad.delete', 'updated_at' => now()]);
        }

        if (Schema::hasTable('HR_contracts') && Schema::hasColumn('HR_contracts', 'assignment_label')) {
            DB::table('HR_contracts as c')->join('outlets as o', 'o.id', '=', 'c.outlet_id')->where('o.type', 'warehouse')->update(['c.assignment_label' => 'WAREHOUSE']);
            DB::table('HR_contracts as c')->join('outlets as o', 'o.id', '=', 'c.outlet_id')->whereIn('o.type', ['headquarter','management'])->update(['c.assignment_label' => 'MANAGEMENT']);
            DB::table('HR_contracts as c')->join('outlets as o', 'o.id', '=', 'c.outlet_id')->where('o.type', 'outlet')->update(['c.assignment_label' => 'OUTLET']);
            DB::table('HR_contracts')->whereRaw("UPPER(TRIM(COALESCE(assignment_label,''))) LIKE '%WAREHOUSE%'")->update(['assignment_label' => 'WAREHOUSE']);
            DB::table('HR_contracts')->where(function ($q) {
                $q->whereRaw("UPPER(TRIM(COALESCE(assignment_label,''))) LIKE '%MANAGEMENT%'")
                    ->orWhereRaw("UPPER(TRIM(COALESCE(assignment_label,''))) LIKE '%HEADQUARTER%'")
                    ->orWhereRaw("UPPER(TRIM(COALESCE(assignment_label,''))) = 'HQ'");
            })->update(['assignment_label' => 'MANAGEMENT']);
            DB::table('HR_contracts')->whereRaw("UPPER(TRIM(COALESCE(assignment_label,''))) = 'OUTLET'")->update(['assignment_label' => 'OUTLET']);
            DB::table('HR_contracts')->whereNull('assignment_label')->orWhereRaw("TRIM(assignment_label) = ''")->update(['assignment_label' => 'OUTLET']);
            DB::table('HR_contracts')->whereNotIn('assignment_label', ['OUTLET','MANAGEMENT','WAREHOUSE'])->update(['assignment_label' => 'OUTLET']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('HR_squad_deletion_audits');
    }
};
