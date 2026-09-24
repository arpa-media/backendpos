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
        $this->createSettings();
        $this->createCriteria();
        $this->createReviews();
        $this->createEntries();
        $this->createScores();
        $this->createAudits();
        $this->seedWorkbookNormalization();
        $this->seedAccessMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function createSettings(): void
    {
        if (Schema::hasTable('HR_kpi_settings')) return;
        Schema::create('HR_kpi_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 80)->unique('hr_kpi_setting_code_uq');
            $table->json('value_json');
            $table->text('description')->nullable();
            $table->foreignUlid('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->foreign('updated_by_user_id', 'hr_kpi_setting_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createCriteria(): void
    {
        if (Schema::hasTable('HR_kpi_grooming_criteria')) return;
        Schema::create('HR_kpi_grooming_criteria', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('division_name', 120);
            $table->string('code', 80);
            $table->string('name', 180);
            $table->decimal('max_score', 8, 2)->default(1);
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->foreignUlid('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['division_name', 'code'], 'hr_kpi_crit_div_code_uq');
            $table->index(['division_name', 'is_active', 'sort_order'], 'hr_kpi_crit_div_idx');
            $table->foreign('created_by_user_id', 'hr_kpi_crit_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'hr_kpi_crit_updater_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createReviews(): void
    {
        if (Schema::hasTable('HR_kpi_daily_reviews')) return;
        Schema::create('HR_kpi_daily_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id');
            $table->date('review_date');
            $table->string('status', 20)->default('draft'); // draft|locked
            $table->unsignedInteger('revision')->default(1);
            $table->string('rule_version', 80)->default('KPI_APRIL_2026_NORMALIZED_V1');
            $table->json('criteria_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->foreignUlid('updated_by_user_id')->nullable();
            $table->foreignUlid('locked_by_user_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->foreignUlid('reopened_by_user_id')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'review_date'], 'hr_kpi_review_outlet_date_uq');
            $table->index(['review_date', 'status'], 'hr_kpi_review_date_status_idx');
            $table->foreign('outlet_id', 'hr_kpi_review_outlet_fk')->references('id')->on('outlets')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'hr_kpi_review_creator_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'hr_kpi_review_updater_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('locked_by_user_id', 'hr_kpi_review_locker_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reopened_by_user_id', 'hr_kpi_review_reopen_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createEntries(): void
    {
        if (Schema::hasTable('HR_kpi_daily_entries')) return;
        Schema::create('HR_kpi_daily_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('review_id');
            $table->foreignUlid('employee_id');
            $table->unsignedBigInteger('squad_id')->nullable();
            $table->string('nisj_snapshot', 100)->nullable();
            $table->string('name_snapshot', 180);
            $table->string('division_snapshot', 120);
            $table->decimal('raw_score', 10, 2)->default(0);
            $table->decimal('max_score', 10, 2)->default(0);
            $table->decimal('normalized_percent', 8, 4)->default(0);
            $table->text('note')->nullable();
            $table->foreignUlid('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'employee_id'], 'hr_kpi_entry_review_emp_uq');
            $table->index(['employee_id', 'review_id'], 'hr_kpi_entry_emp_idx');
            $table->foreign('review_id', 'hr_kpi_entry_review_fk')->references('id')->on('HR_kpi_daily_reviews')->cascadeOnDelete();
            $table->foreign('employee_id', 'hr_kpi_entry_employee_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('squad_id', 'hr_kpi_entry_squad_fk')->references('id')->on('HR_squads')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'hr_kpi_entry_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createScores(): void
    {
        if (Schema::hasTable('HR_kpi_daily_scores')) return;
        Schema::create('HR_kpi_daily_scores', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('entry_id');
            $table->foreignUlid('criterion_id')->nullable();
            $table->string('criterion_code_snapshot', 80);
            $table->string('criterion_name_snapshot', 180);
            $table->decimal('max_score_snapshot', 8, 2)->default(1);
            $table->decimal('score', 8, 2)->default(1);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['entry_id', 'criterion_code_snapshot'], 'hr_kpi_score_entry_code_uq');
            $table->foreign('entry_id', 'hr_kpi_score_entry_fk')->references('id')->on('HR_kpi_daily_entries')->cascadeOnDelete();
            $table->foreign('criterion_id', 'hr_kpi_score_criterion_fk')->references('id')->on('HR_kpi_grooming_criteria')->nullOnDelete();
        });
    }

    private function createAudits(): void
    {
        if (Schema::hasTable('HR_kpi_daily_audits')) return;
        Schema::create('HR_kpi_daily_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('review_id');
            $table->string('event_type', 40);
            $table->foreignUlid('actor_user_id')->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['review_id', 'created_at'], 'hr_kpi_audit_review_idx');
            $table->foreign('review_id', 'hr_kpi_audit_review_fk')->references('id')->on('HR_kpi_daily_reviews')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'hr_kpi_audit_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function seedWorkbookNormalization(): void
    {
        if (!Schema::hasTable('HR_kpi_settings') || !Schema::hasTable('HR_kpi_grooming_criteria')) return;
        $now = now();
        $settings = [
            'GROOMING_TARGET_DAYS' => [['days'=>25,'source'=>'KPI APRIL 2026 / SUMMARY: MAX HARIAN x 25'], 'Target hari grooming bulanan mengikuti workbook KPI APRIL 2026.'],
            'GROOMING_COMPONENT_WEIGHT' => [['weight'=>40,'source'=>'KPI APRIL 2026 / SUMMARY: Grooming 40%'], 'Bobot grooming untuk komposisi KPI Iterasi 19.'],
        ];
        foreach ($settings as $code => [$value, $description]) {
            $existing = DB::table('HR_kpi_settings')->where('code',$code)->first();
            DB::table('HR_kpi_settings')->updateOrInsert(['code'=>$code],[
                'id'=>(string)($existing->id ?? Str::ulid()),'value_json'=>json_encode($value, JSON_UNESCAPED_UNICODE),
                'description'=>$description,'created_at'=>$existing->created_at ?? $now,'updated_at'=>$now,
            ]);
        }

        // Workbook columns TOPI/KERUDUNG/BANDANA and APRON FULL/HALF are normalized
        // into one scored requirement each. This keeps the denominator equal to the
        // workbook while avoiding gender-specific double counting.
        $divisionMax = ['BARISTA'=>4,'KITCHEN'=>4,'PIZZA'=>4,'KASIR'=>3,'SERVER'=>4];
        $base = [
            ['code'=>'UNIFORM','name'=>'Seragam Sesuai','sort'=>10,'desc'=>'Normalisasi kolom SERAGAM SESUAI.'],
            ['code'=>'HEADWEAR','name'=>'Penutup Kepala Sesuai','sort'=>20,'desc'=>'Normalisasi Topi Jaya / Kerudung Paris / Bandana Jaya.'],
            ['code'=>'NAME_TAG','name'=>'Name Tag','sort'=>30,'desc'=>'Normalisasi kolom NAME TAG.'],
            ['code'=>'APRON','name'=>'Apron Sesuai','sort'=>40,'desc'=>'Normalisasi Apron Full / Apron Half.'],
        ];
        foreach ($divisionMax as $division => $max) {
            foreach ($base as $index => $criterion) {
                if ($max === 3 && $criterion['code'] === 'APRON') continue;
                $existing = DB::table('HR_kpi_grooming_criteria')->where('division_name',$division)->where('code',$criterion['code'])->first();
                DB::table('HR_kpi_grooming_criteria')->updateOrInsert(
                    ['division_name'=>$division,'code'=>$criterion['code']],
                    ['id'=>(string)($existing->id ?? Str::ulid()),'name'=>$criterion['name'],'max_score'=>1,'sort_order'=>$criterion['sort'],
                     'is_active'=>true,'description'=>$criterion['desc'],'created_at'=>$existing->created_at ?? $now,'updated_at'=>$now]
                );
            }
        }
    }

    private function seedAccessMatrix(): void
    {
        if (!Schema::hasTable('access_portals') || !Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code','report')->first();
        if (!$portal) return;
        $now = now();
        $existing = DB::table('access_menus')->where('code','report-kpi-squad')->first();
        $menuId = (string)($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code'=>'report-kpi-squad'],[
            'id'=>$menuId,'portal_id'=>$portal->id,'name'=>'KPI Squad','path'=>'/report/kpi-squad','sort_order'=>30,
            'permission_view'=>'hr.kpi.squad.view','permission_create'=>'hr.kpi.squad.input','permission_update'=>'hr.kpi.squad.update','permission_delete'=>'hr.kpi.squad.reopen',
            'is_active'=>true,'created_at'=>$existing->created_at ?? $now,'updated_at'=>$now,
        ]);

        $guard = config('auth.defaults.guard','web');
        foreach ([
            'hr.kpi.squad.view','hr.kpi.squad.input','hr.kpi.squad.update','hr.kpi.squad.lock',
            'hr.kpi.squad.reopen','hr.kpi.squad.export','hr.kpi.squad.import','hr.kpi.squad.violation.create',
        ] as $permission) {
            if (Schema::hasTable('permissions')) Permission::findOrCreate($permission,$guard);
        }
    }

    public function down(): void
    {
        // KPI/HR records are audit and payroll source data. Keep rollback non-destructive.
    }
};
