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
    private const PORTAL = 'console';
    private const MENU = 'console-control-center';
    private const PERMISSIONS = [
        'console.control_center.view',
        'console.control_center.run',
        'console.control_center.configure',
        'console.control_center.force_rebuild',
    ];

    public function up(): void
    {
        $this->createMonthlyFacts();
        $this->createOrchestrationTables();
        $this->registerAccess();
    }

    public function down(): void
    {
        // Non-destructive rollback: reporting facts/run history are operational audit data.
    }

    private function createMonthlyFacts(): void
    {
        if (!Schema::hasTable('report_monthly_summary_coverage')) {
            Schema::create('report_monthly_summary_coverage', function (Blueprint $t): void {
                $t->char('outlet_id', 26);
                $t->date('business_month');
                $t->dateTime('source_daily_max_synced_at')->nullable();
                $t->dateTime('synced_at');
                $t->timestamps();
                $t->primary(['outlet_id', 'business_month'], 'rmscov_pk');
                $t->index(['business_month', 'outlet_id', 'source_daily_max_synced_at'], 'rmscov_month_out_sync_idx');
            });
        }

        if (!Schema::hasTable('report_monthly_sales_summaries')) {
            Schema::create('report_monthly_sales_summaries', function (Blueprint $t): void {
                $t->char('outlet_id', 26); $t->date('business_month'); $t->string('business_timezone', 64)->nullable();
                $t->unsignedInteger('trx_count')->default(0); $t->unsignedInteger('marked_trx_count')->default(0); $t->unsignedInteger('discounted_trx_count')->default(0); $t->unsignedInteger('rounding_trx_count')->default(0); $t->unsignedInteger('marked_discounted_trx_count')->default(0); $t->unsignedInteger('marked_rounding_trx_count')->default(0);
                foreach (['subtotal_sales','marked_subtotal_sales','grand_sales','marked_grand_sales','discount_total','marked_discount_total','tax_total','marked_tax_total','service_charge_total','marked_service_charge_total','item_qty_sold','marked_item_qty_sold'] as $c) $t->unsignedBigInteger($c)->default(0);
                $t->bigInteger('rounding_total')->default(0); $t->bigInteger('marked_rounding_total')->default(0); $t->unsignedBigInteger('rounding_up_total')->default(0); $t->unsignedBigInteger('rounding_down_total')->default(0); $t->unsignedBigInteger('marked_rounding_up_total')->default(0); $t->unsignedBigInteger('marked_rounding_down_total')->default(0); $t->timestamps();
                $t->primary(['outlet_id','business_month'], 'rmss_pk'); $t->index(['business_month','outlet_id'], 'rmss_month_out_idx');
            });
        }

        if (!Schema::hasTable('report_monthly_payment_summaries')) {
            Schema::create('report_monthly_payment_summaries', function (Blueprint $t): void {
                $t->char('outlet_id',26); $t->date('business_month'); $t->string('business_timezone',64)->nullable(); $t->string('payment_method_name',120); $t->string('payment_method_type',60)->default('');
                $t->unsignedInteger('trx_count')->default(0); $t->unsignedInteger('marked_trx_count')->default(0); $t->unsignedBigInteger('gross_sales')->default(0); $t->unsignedBigInteger('marked_gross_sales')->default(0); $t->timestamps();
                $t->primary(['outlet_id','business_month','payment_method_name','payment_method_type'], 'rmps_pk'); $t->index(['business_month','outlet_id'], 'rmps_month_out_idx');
            });
        }

        if (!Schema::hasTable('report_monthly_channel_summaries')) {
            Schema::create('report_monthly_channel_summaries', function (Blueprint $t): void {
                $t->char('outlet_id',26); $t->date('business_month'); $t->string('business_timezone',64)->nullable(); $t->string('display_channel',120);
                $t->unsignedInteger('trx_count')->default(0); $t->unsignedInteger('marked_trx_count')->default(0); $t->unsignedBigInteger('gross_sales')->default(0); $t->unsignedBigInteger('marked_gross_sales')->default(0); $t->timestamps();
                $t->primary(['outlet_id','business_month','display_channel'], 'rmcs_pk'); $t->index(['business_month','outlet_id'], 'rmcs_month_out_idx');
            });
        }

        if (!Schema::hasTable('report_monthly_category_summaries')) {
            Schema::create('report_monthly_category_summaries', function (Blueprint $t): void {
                $t->char('outlet_id',26); $t->date('business_month'); $t->string('business_timezone',64)->nullable(); $t->char('category_id',26)->default(''); $t->string('category_name',191)->default('Uncategorized'); $t->string('category_kind',30)->default('');
                $t->unsignedBigInteger('item_sold')->default(0); $t->unsignedBigInteger('marked_item_sold')->default(0); $t->unsignedBigInteger('gross_sales')->default(0); $t->unsignedBigInteger('marked_gross_sales')->default(0); $t->decimal('discount_basis',20,6)->default(0); $t->decimal('marked_discount_basis',20,6)->default(0); $t->timestamps();
                $t->primary(['outlet_id','business_month','category_id','category_name'], 'rmcat_pk'); $t->index(['business_month','outlet_id'], 'rmcat_month_out_idx');
            });
        }

        if (!Schema::hasTable('report_monthly_product_summaries')) {
            Schema::create('report_monthly_product_summaries', function (Blueprint $t): void {
                $t->char('outlet_id',26); $t->date('business_month'); $t->string('business_timezone',64)->nullable(); $t->char('product_id',26)->default(''); $t->string('product_name',191)->default('-'); $t->char('category_id',26)->default(''); $t->string('category_name',191)->default('Uncategorized'); $t->string('category_kind',30)->default('');
                $t->unsignedBigInteger('item_sold')->default(0); $t->unsignedBigInteger('marked_item_sold')->default(0); $t->unsignedBigInteger('gross_sales')->default(0); $t->unsignedBigInteger('marked_gross_sales')->default(0); $t->decimal('discount_basis',20,6)->default(0); $t->decimal('marked_discount_basis',20,6)->default(0); $t->timestamps();
                $t->primary(['outlet_id','business_month','product_id','product_name'], 'rmprod_pk'); $t->index(['business_month','outlet_id'], 'rmprod_month_out_idx');
            });
        }

        if (!Schema::hasTable('report_monthly_variant_summaries')) {
            Schema::create('report_monthly_variant_summaries', function (Blueprint $t): void {
                $t->char('outlet_id',26); $t->date('business_month'); $t->string('business_timezone',64)->nullable(); $t->char('product_id',26)->default(''); $t->char('variant_id',26)->default(''); $t->string('product_name',191)->default('-'); $t->string('variant_name',191)->default(''); $t->char('category_id',26)->default(''); $t->string('category_name',191)->default('Uncategorized'); $t->string('category_kind',30)->default('');
                foreach (['line_count','marked_line_count','unit_price_sum','marked_unit_price_sum','item_sold','marked_item_sold','gross_sales','marked_gross_sales'] as $c) $t->unsignedBigInteger($c)->default(0);
                $t->decimal('discount_basis',20,6)->default(0); $t->decimal('marked_discount_basis',20,6)->default(0); $t->timestamps();
                $t->primary(['outlet_id','business_month','product_id','variant_id','product_name','variant_name'], 'rmvar_pk'); $t->index(['business_month','outlet_id'], 'rmvar_month_out_idx');
            });
        }
    }

    private function createOrchestrationTables(): void
    {
        if (!Schema::hasTable('report_materialization_settings')) {
            Schema::create('report_materialization_settings', function (Blueprint $t): void {
                $t->string('id',40)->primary(); $t->boolean('auto_enabled')->default(true); $t->unsignedSmallInteger('rolling_days')->default(370); $t->unsignedTinyInteger('outlet_chunk')->default(6); $t->unsignedTinyInteger('date_chunk')->default(14);
                $t->time('window_start')->default('01:00:00'); $t->time('window_end')->default('05:00:00'); $t->string('timezone',64)->default('Asia/Jakarta');
                $t->boolean('daily_enabled')->default(true); $t->boolean('hourly_enabled')->default(true); $t->boolean('monthly_enabled')->default(true); $t->timestamp('last_auto_enqueued_at')->nullable(); $t->timestamp('last_tick_at')->nullable(); $t->timestamps();
            });
            DB::table('report_materialization_settings')->insert(['id'=>'default','auto_enabled'=>true,'rolling_days'=>370,'outlet_chunk'=>6,'date_chunk'=>14,'window_start'=>'01:00:00','window_end'=>'05:00:00','timezone'=>'Asia/Jakarta','daily_enabled'=>true,'hourly_enabled'=>true,'monthly_enabled'=>true,'created_at'=>now(),'updated_at'=>now()]);
        }

        if (!Schema::hasTable('report_materialization_runs')) {
            Schema::create('report_materialization_runs', function (Blueprint $t): void {
                $t->ulid('id')->primary(); $t->string('trigger',20)->default('manual'); $t->string('mode',24)->default('missing_only'); $t->string('pipeline',24)->default('full');
                $t->date('date_from'); $t->date('date_to'); $t->unsignedSmallInteger('target_days')->nullable(); $t->json('outlet_ids')->nullable(); $t->unsignedTinyInteger('outlet_chunk')->default(6); $t->unsignedTinyInteger('date_chunk')->default(14);
                $t->string('status',24)->default('queued'); $t->string('current_stage',20)->nullable(); $t->unsignedInteger('total_chunks')->default(0); $t->unsignedInteger('completed_chunks')->default(0); $t->unsignedInteger('skipped_chunks')->default(0); $t->unsignedInteger('failed_chunks')->default(0);
                $t->string('current_label',191)->nullable(); $t->unsignedTinyInteger('progress_percent')->default(0); $t->unsignedInteger('estimated_seconds_remaining')->nullable();
                $t->ulid('requested_by')->nullable(); $t->timestamp('started_at')->nullable(); $t->timestamp('finished_at')->nullable(); $t->timestamp('pause_requested_at')->nullable(); $t->timestamp('cancel_requested_at')->nullable(); $t->text('last_error')->nullable(); $t->timestamps();
                $t->index(['status','created_at'], 'rmruns_status_created_idx');
            });
        }

        if (!Schema::hasTable('report_materialization_run_chunks')) {
            Schema::create('report_materialization_run_chunks', function (Blueprint $t): void {
                $t->ulid('id')->primary(); $t->foreignUlid('run_id')->constrained('report_materialization_runs')->cascadeOnDelete(); $t->string('stage',20); $t->unsignedInteger('sequence'); $t->json('outlet_ids'); $t->date('date_from'); $t->date('date_to'); $t->date('business_month')->nullable();
                $t->string('status',20)->default('queued'); $t->unsignedTinyInteger('attempts')->default(0); $t->unsignedInteger('duration_ms')->nullable(); $t->text('last_error')->nullable(); $t->timestamp('started_at')->nullable(); $t->timestamp('finished_at')->nullable(); $t->timestamps();
                $t->unique(['run_id','sequence'], 'rmchunks_run_seq_uq'); $t->index(['run_id','status','sequence'], 'rmchunks_run_status_seq_idx');
            });
        }
    }

    private function registerAccess(): void
    {
        foreach (self::PERMISSIONS as $p) Permission::findOrCreate($p, 'web');
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) return;
        $now = now();
        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        $portalId = (string)($portal->id ?? Str::ulid());
        DB::table('access_portals')->updateOrInsert(['code'=>self::PORTAL], ['id'=>$portalId,'name'=>'Console','description'=>'System console untuk observability dan kontrol reporting engine.','sort_order'=>95,'is_active'=>true,'created_at'=>$portal->created_at ?? $now,'updated_at'=>$now]);
        $menu = DB::table('access_menus')->where('code', self::MENU)->first(); $menuId=(string)($menu->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code'=>self::MENU], ['id'=>$menuId,'portal_id'=>$portalId,'name'=>'Control Center','path'=>'/console/control-center','sort_order'=>10,'permission_view'=>self::PERMISSIONS[0],'permission_create'=>self::PERMISSIONS[1],'permission_update'=>self::PERMISSIONS[2],'permission_delete'=>self::PERMISSIONS[3],'is_active'=>true,'created_at'=>$menu->created_at ?? $now,'updated_at'=>$now]);

        if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_portal_permissions') && Schema::hasTable('access_role_menu_permissions')) {
            $adminRoleIds = DB::table('access_roles')->where(function($q): void { $q->whereRaw("UPPER(COALESCE(code,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')")->orWhereRaw("UPPER(COALESCE(name,'')) IN ('ADMIN','ADMINISTRATOR','SUPERADMIN','SUPER-ADMIN')"); })->pluck('id');
            foreach ($adminRoleIds as $roleId) {
                if (!DB::table('access_role_portal_permissions')->where('access_role_id',$roleId)->whereNull('access_level_id')->where('portal_id',$portalId)->exists()) DB::table('access_role_portal_permissions')->insert(['id'=>(string)Str::ulid(),'access_role_id'=>$roleId,'access_level_id'=>null,'portal_id'=>$portalId,'can_view'=>true,'created_at'=>$now,'updated_at'=>$now]);
                if (!DB::table('access_role_menu_permissions')->where('access_role_id',$roleId)->whereNull('access_level_id')->where('menu_id',$menuId)->exists()) DB::table('access_role_menu_permissions')->insert(['id'=>(string)Str::ulid(),'access_role_id'=>$roleId,'access_level_id'=>null,'menu_id'=>$menuId,'can_view'=>true,'can_create'=>true,'can_edit'=>true,'can_delete'=>true,'created_at'=>$now,'updated_at'=>$now]);
            }
        }
        Role::query()->where('guard_name','web')->get()->each(function(Role $role): void { if (in_array(strtolower(trim($role->name)),['admin','administrator','superadmin','super-admin'],true)) $role->givePermissionTo(self::PERMISSIONS); });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
