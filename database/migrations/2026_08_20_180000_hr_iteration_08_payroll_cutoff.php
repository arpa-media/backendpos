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
    public function up(): void
    {
        $this->createCutoffs(); $this->createSlips(); $this->seedAccess();
    }

    private function createCutoffs(): void
    {
        if (Schema::hasTable('HR_payroll_cutoffs')) return;
        Schema::create('HR_payroll_cutoffs', function (Blueprint $table): void {
            $table->char('id',26)->primary(); $table->string('code',40)->unique();
            $table->date('period_from'); $table->date('period_to'); $table->string('company_code',16)->nullable();
            $table->char('outlet_id',26)->nullable(); $table->string('description',500)->nullable();
            $table->string('status',20)->default('draft'); $table->char('created_by',26)->nullable();
            $table->char('finalized_by',26)->nullable(); $table->timestamp('finalized_at')->nullable();
            $table->unsignedInteger('snapshot_count')->default(0); $table->decimal('snapshot_total_net',18,2)->default(0); $table->timestamps();
            $table->index(['period_from','period_to'],'hr_pay_cut_period_idx'); $table->index(['company_code','status'],'hr_pay_cut_company_idx');
            $table->foreign('outlet_id','hr_pay_cut_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
            $table->foreign('created_by','hr_pay_cut_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('finalized_by','hr_pay_cut_final_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createSlips(): void
    {
        if (Schema::hasTable('HR_payroll_slips')) return;
        Schema::create('HR_payroll_slips', function (Blueprint $table): void {
            $table->char('id',26)->primary(); $table->char('cutoff_id',26); $table->char('employee_id',26); $table->char('user_id',26)->nullable();
            $table->string('nisj_snapshot',100)->nullable(); $table->string('full_name_snapshot',255); $table->string('company_code_snapshot',16)->nullable();
            $table->char('outlet_id_snapshot',26)->nullable(); $table->string('outlet_name_snapshot',255)->nullable(); $table->string('position_snapshot',180)->nullable();
            $table->string('salary_tier_snapshot',150)->nullable(); $table->decimal('daily_rate',15,2)->default(0); $table->decimal('basic_salary_snapshot',15,2)->default(0);
            $table->decimal('minute_deduction',15,2)->default(0); $table->decimal('overtime_rate',15,2)->default(0); $table->decimal('bonus_amount',15,2)->default(0);
            $table->decimal('family_allowance',15,2)->default(0); $table->decimal('position_allowance',15,2)->default(0); $table->decimal('cashbon',15,2)->default(0);
            $table->decimal('other_deduction',15,2)->default(0); $table->decimal('field_duty_bonus',15,2)->default(0); $table->decimal('manual_adjustment',15,2)->default(0);
            $table->string('manual_note',500)->nullable(); $table->unsignedSmallInteger('work_days')->default(0); $table->unsignedInteger('work_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0); $table->unsignedSmallInteger('alpha_days')->default(0); $table->unsignedSmallInteger('unmapped_days')->default(0);
            $table->unsignedSmallInteger('pending_exception_days')->default(0); $table->unsignedSmallInteger('rejected_exception_days')->default(0);
            $table->unsignedSmallInteger('incomplete_days')->default(0); $table->unsignedSmallInteger('field_duty_days')->default(0); $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('overtime_hours_override',8,2)->nullable(); $table->decimal('gross_wage',18,2)->default(0); $table->decimal('overtime_pay',18,2)->default(0);
            $table->decimal('late_deduction',18,2)->default(0); $table->decimal('total_non_wage',18,2)->default(0); $table->decimal('total_deduction',18,2)->default(0);
            $table->decimal('total_net',18,2)->default(0); $table->string('status',20)->default('draft'); $table->timestamps();
            $table->unique(['cutoff_id','employee_id','outlet_id_snapshot'],'hr_pay_slip_cut_emp_uq');
            $table->index(['employee_id','status'],'hr_pay_slip_emp_idx'); $table->index(['company_code_snapshot','outlet_id_snapshot'],'hr_pay_slip_scope_idx');
            $table->foreign('cutoff_id','hr_pay_slip_cutoff_fk')->references('id')->on('HR_payroll_cutoffs')->cascadeOnDelete();
            $table->foreign('employee_id','hr_pay_slip_employee_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('user_id','hr_pay_slip_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('outlet_id_snapshot','hr_pay_slip_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
        });
    }

    private function seedAccess(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) return;
        $now=now(); $hr=$this->portal('human-resource','Human Resource',20,$now); $attendance=$this->portal('attendance','Absensi Squad',21,$now);
        $projection=$this->menu($hr,'hr-payroll-projection','Proyeksi Payroll','/human-resource/cutoff-proyeksi-payroll',51,'hr.payroll.projection.view',null,null,null,$now);
        $cutoff=$this->menu($hr,'hr-payroll-cutoff','Cutoff Gaji','/human-resource/cutoff-gaji',53,'hr.payroll.cutoff.view','hr.payroll.cutoff.create','hr.payroll.cutoff.update','hr.payroll.cutoff.delete',$now);
        $self=$this->menu($attendance,'hr-self-payroll-slip','Slip Gaji','/user-dashboard',40,'hr.payroll.self.view',null,null,null,$now);
        $guard=config('auth.defaults.guard','web');
        foreach(['hr.payroll.projection.view','hr.payroll.cutoff.view','hr.payroll.cutoff.create','hr.payroll.cutoff.update','hr.payroll.cutoff.delete','hr.payroll.cutoff.finalize','hr.payroll.self.view'] as $p) if(Schema::hasTable('permissions')) Permission::findOrCreate($p,$guard);
        $this->seedReportMatrix($projection,$hr,$now); $this->seedCutoffMatrix($cutoff,$hr,$now); $this->seedSelfMatrix($self,$now);
        if(app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function portal(string $code,string $name,int $sort,$now): string
    { $x=DB::table('access_portals')->where('code',$code)->first(); $id=(string)($x->id??Str::ulid()); DB::table('access_portals')->updateOrInsert(['code'=>$code],['id'=>$id,'name'=>$name,'description'=>'Portal '.$name,'sort_order'=>$sort,'is_active'=>true,'created_at'=>$x->created_at??$now,'updated_at'=>$now]); return $id; }
    private function menu(string $portal,string $code,string $name,string $path,int $sort,?string $v,?string $c,?string $u,?string $d,$now): string
    { $x=DB::table('access_menus')->where('code',$code)->first(); $id=(string)($x->id??Str::ulid()); DB::table('access_menus')->updateOrInsert(['code'=>$code],['id'=>$id,'portal_id'=>$portal,'name'=>$name,'path'=>$path,'sort_order'=>$sort,'permission_view'=>$v,'permission_create'=>$c,'permission_update'=>$u,'permission_delete'=>$d,'is_active'=>true,'created_at'=>$x->created_at??$now,'updated_at'=>$now]); return $id; }
    private function levels(): array { return Schema::hasTable('access_levels')?DB::table('access_levels')->pluck('id')->map(fn($v)=>(string)$v)->all():[]; }
    private function portalCanView($role,$level,string $portal,bool $fallback): bool
    { if(!Schema::hasTable('access_role_portal_permissions')) return $fallback; $base=DB::table('access_role_portal_permissions')->where('access_role_id',$role)->where('portal_id',$portal)->whereNull('access_level_id')->first(); $exact=$level===null?null:DB::table('access_role_portal_permissions')->where('access_role_id',$role)->where('portal_id',$portal)->where('access_level_id',$level)->first(); $e=$exact?:$base; return $fallback||($e?(bool)$e->can_view:false); }
    private function insertMatrix($role,$level,string $menu,bool $v,bool $c,bool $e,bool $d,$now): void
    { $q=DB::table('access_role_menu_permissions')->where('access_role_id',$role)->where('menu_id',$menu); $level===null?$q->whereNull('access_level_id'):$q->where('access_level_id',$level); if($q->exists()) return; DB::table('access_role_menu_permissions')->insert(['id'=>(string)Str::ulid(),'access_role_id'=>$role,'access_level_id'=>$level,'menu_id'=>$menu,'can_view'=>$v,'can_create'=>$c,'can_edit'=>$e,'can_delete'=>$d,'created_at'=>$now,'updated_at'=>$now]); }
    private function seedReportMatrix(string $menu,string $portal,$now): void
    { if(!Schema::hasTable('access_roles')||!Schema::hasTable('access_role_menu_permissions')) return; foreach(DB::table('access_roles')->get(['id','code']) as $r){$code=strtoupper(trim((string)$r->code));$fallback=in_array($code,['ADMIN','MANAGER'],true);foreach(array_merge([null],$this->levels()) as $l){$v=$this->portalCanView($r->id,$l,$portal,$fallback);$this->insertMatrix($r->id,$l,$menu,$v,false,false,false,$now);}} }
    private function seedCutoffMatrix(string $menu,string $portal,$now): void
    { if(!Schema::hasTable('access_roles')||!Schema::hasTable('access_role_menu_permissions')) return; foreach(DB::table('access_roles')->get(['id','code']) as $r){$code=strtoupper(trim((string)$r->code));$admin=$code==='ADMIN';$manager=$code==='MANAGER';foreach(array_merge([null],$this->levels()) as $l){$v=$this->portalCanView($r->id,$l,$portal,$admin||$manager);$this->insertMatrix($r->id,$l,$menu,$v,$admin,$admin,$admin,$now);}} }
    private function seedSelfMatrix(string $menu,$now): void
    { if(!Schema::hasTable('access_roles')||!Schema::hasTable('access_role_menu_permissions')) return; foreach(DB::table('access_roles')->get(['id','code']) as $r){$allow=!in_array(strtoupper(trim((string)$r->code)),['STAKEHOLDER','OBSERVER'],true);foreach(array_merge([null],$this->levels()) as $l)$this->insertMatrix($r->id,$l,$menu,$allow,false,false,false,$now);} }

    public function down(): void { /* payroll snapshots are audit data; intentionally non-destructive */ }
};
