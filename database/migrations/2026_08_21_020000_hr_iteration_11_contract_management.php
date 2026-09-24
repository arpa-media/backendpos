<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const MENU_CODE = 'hr-mapping-contract';
    private const MENU_PATH = '/human-resource/mapping-contract';

    public function up(): void
    {
        $this->createTables();
        $this->seedTemplates();
        $this->backfillLegacyContracts();
        $this->seedAccessMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('HR_contracts')) {
            Schema::create('HR_contracts', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->unsignedBigInteger('squad_id')->nullable();
                $table->foreignUlid('employee_id')->nullable();
                $table->foreignUlid('assignment_id')->nullable();
                $table->string('contract_no', 80)->nullable()->unique('hr_contract_no_uq');
                $table->string('contract_type', 80)->nullable();
                $table->string('status', 30)->default('draft');
                $table->date('tmt_date')->nullable();
                $table->date('first_sk_date')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->foreignUlid('outlet_id')->nullable();
                $table->string('assignment_label', 180)->nullable();
                $table->string('division_name', 150)->nullable();
                $table->string('position_name', 150)->nullable();
                $table->text('notes')->nullable();
                $table->string('source', 40)->default('manual');
                $table->char('legacy_sync_hash', 64)->nullable();
                $table->foreignUlid('created_by_user_id')->nullable();
                $table->foreignUlid('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'end_date'], 'hr_contract_status_end_idx');
                $table->index(['employee_id', 'start_date'], 'hr_contract_emp_start_idx');
                $table->index('squad_id', 'hr_contract_squad_idx');
                $table->foreign('squad_id', 'hr_contract_squad_fk')->references('id')->on('HR_squads')->nullOnDelete();
                $table->foreign('employee_id', 'hr_contract_emp_fk')->references('id')->on('employees')->nullOnDelete();
                $table->foreign('assignment_id', 'hr_contract_assignment_fk')->references('id')->on('assignments')->nullOnDelete();
                $table->foreign('outlet_id', 'hr_contract_outlet_fk')->references('id')->on('outlets')->nullOnDelete();
                $table->foreign('created_by_user_id', 'hr_contract_creator_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by_user_id', 'hr_contract_updater_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_contract_document_templates')) {
            Schema::create('HR_contract_document_templates', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('document_type', 40);
                $table->string('name', 150);
                $table->unsignedSmallInteger('version')->default(1);
                $table->string('title_template', 250);
                $table->longText('body_template');
                $table->boolean('is_active')->default(true);
                $table->foreignUlid('created_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['document_type', 'version'], 'hr_contract_tpl_type_ver_uq');
                $table->index(['document_type', 'is_active'], 'hr_contract_tpl_active_idx');
                $table->foreign('created_by_user_id', 'hr_contract_tpl_user_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_contract_documents')) {
            Schema::create('HR_contract_documents', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('contract_id');
                $table->foreignUlid('template_id')->nullable();
                $table->string('document_type', 40);
                $table->string('document_no', 100)->unique('hr_contract_doc_no_uq');
                $table->string('title', 250);
                $table->date('issue_date');
                $table->date('effective_date')->nullable();
                $table->string('status', 30)->default('draft');
                $table->unsignedSmallInteger('template_version')->default(1);
                $table->longText('body_snapshot');
                $table->json('payload_snapshot')->nullable();
                $table->string('effect_status', 30)->default('not_applicable');
                $table->timestamp('effect_applied_at')->nullable();
                $table->foreignUlid('generated_by_user_id')->nullable();
                $table->foreignUlid('submitted_by_user_id')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignUlid('approved_by_user_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->foreignUlid('rejected_by_user_id')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_note')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['contract_id', 'status'], 'hr_contract_doc_status_idx');
                $table->index(['effect_status', 'effective_date'], 'hr_contract_doc_effect_idx');
                $table->foreign('contract_id', 'hr_contract_doc_contract_fk')->references('id')->on('HR_contracts')->cascadeOnDelete();
                $table->foreign('template_id', 'hr_contract_doc_tpl_fk')->references('id')->on('HR_contract_document_templates')->nullOnDelete();
                $table->foreign('generated_by_user_id', 'hr_contract_doc_gen_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('submitted_by_user_id', 'hr_contract_doc_sub_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('approved_by_user_id', 'hr_contract_doc_app_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('rejected_by_user_id', 'hr_contract_doc_rej_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_contract_approvals')) {
            Schema::create('HR_contract_approvals', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('contract_id');
                $table->foreignUlid('document_id');
                $table->unsignedSmallInteger('step_number')->default(1);
                $table->string('status', 30)->default('pending');
                $table->foreignUlid('requested_by_user_id')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->foreignUlid('approver_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->unique(['document_id', 'step_number'], 'hr_contract_app_doc_step_uq');
                $table->index(['status', 'requested_at'], 'hr_contract_app_status_idx');
                $table->foreign('contract_id', 'hr_contract_app_contract_fk')->references('id')->on('HR_contracts')->cascadeOnDelete();
                $table->foreign('document_id', 'hr_contract_app_doc_fk')->references('id')->on('HR_contract_documents')->cascadeOnDelete();
                $table->foreign('requested_by_user_id', 'hr_contract_app_req_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('approver_user_id', 'hr_contract_app_user_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_contract_events')) {
            Schema::create('HR_contract_events', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('contract_id');
                $table->foreignUlid('document_id')->nullable();
                $table->foreignUlid('assignment_id')->nullable();
                $table->string('event_type', 50);
                $table->date('effective_date')->nullable();
                $table->text('note')->nullable();
                $table->json('before_snapshot')->nullable();
                $table->json('after_snapshot')->nullable();
                $table->foreignUlid('actor_user_id')->nullable();
                $table->string('actor_name_snapshot', 180)->nullable();
                $table->timestamp('event_at');
                $table->timestamps();

                $table->index(['contract_id', 'event_at'], 'hr_contract_event_time_idx');
                $table->foreign('contract_id', 'hr_contract_event_contract_fk')->references('id')->on('HR_contracts')->cascadeOnDelete();
                $table->foreign('document_id', 'hr_contract_event_doc_fk')->references('id')->on('HR_contract_documents')->nullOnDelete();
                $table->foreign('assignment_id', 'hr_contract_event_assignment_fk')->references('id')->on('assignments')->nullOnDelete();
                $table->foreign('actor_user_id', 'hr_contract_event_user_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_contract_reminders')) {
            Schema::create('HR_contract_reminders', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('contract_id');
                $table->smallInteger('days_before')->nullable();
                $table->date('remind_on');
                $table->string('label', 120)->nullable();
                $table->string('status', 30)->default('pending');
                $table->boolean('is_default')->default(false);
                $table->timestamp('triggered_at')->nullable();
                $table->foreignUlid('acknowledged_by_user_id')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['status', 'remind_on'], 'hr_contract_reminder_due_idx');
                $table->index(['contract_id', 'remind_on'], 'hr_contract_reminder_contract_idx');
                $table->foreign('contract_id', 'hr_contract_reminder_contract_fk')->references('id')->on('HR_contracts')->cascadeOnDelete();
                $table->foreign('acknowledged_by_user_id', 'hr_contract_reminder_ack_fk')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    private function seedTemplates(): void
    {
        if (! Schema::hasTable('HR_contract_document_templates')) return;
        $templates = [
            'contract' => ['SK Kontrak Kerja', "Menimbang:\n1. Kebutuhan organisasi dan penempatan tenaga kerja.\n2. Data serta ketentuan hubungan kerja yang telah diverifikasi.\n\nMEMUTUSKAN\n\nPERTAMA\nMenetapkan {{full_name}} (NISJ {{nisj}}) dengan jenis kontrak {{contract_type}} terhitung mulai {{start_date}}{{end_clause}}.\n\nKEDUA\nPenugasan: {{assignment}}\nDivisi: {{division}}\nJabatan: {{position}}\nTMT: {{tmt_date}}\n\nKETIGA\nKeputusan ini berlaku sejak tanggal efektif dan dapat ditinjau kembali apabila terdapat kekeliruan atau perubahan kebijakan perusahaan."],
            'extension' => ['SK Perpanjangan Kontrak', "Menimbang evaluasi masa kontrak dan kebutuhan organisasi, dengan ini diputuskan perpanjangan hubungan kerja untuk {{full_name}} (NISJ {{nisj}}).\n\nPERIODE\nKontrak: {{contract_type}}\nMulai: {{start_date}}\nBerakhir baru: {{new_end_date}}\n\nPENUGASAN\n{{assignment}} · {{division}} · {{position}}\n\nKeputusan berlaku sejak {{effective_date}}."],
            'promotion' => ['SK Promosi', "Berdasarkan evaluasi kinerja dan kebutuhan organisasi, Manajemen menetapkan promosi untuk {{full_name}} (NISJ {{nisj}}).\n\nPENETAPAN BARU\nPenugasan: {{new_assignment}}\nDivisi: {{new_division}}\nJabatan: {{new_position}}\nTMT: {{effective_date}}\n\nSeluruh hak, tanggung jawab, dan kewajiban mengikuti penugasan baru sejak TMT tersebut."],
            'transfer' => ['SK Mutasi / Perubahan Penugasan', "Berdasarkan kebutuhan operasional, Manajemen menetapkan perubahan penugasan untuk {{full_name}} (NISJ {{nisj}}).\n\nPENUGASAN BARU\n{{new_assignment}}\nDivisi: {{new_division}}\nJabatan: {{new_position}}\nTMT: {{effective_date}}\n\nKeputusan ini menggantikan penugasan aktif sebelumnya sejak tanggal efektif."],
            'termination' => ['SK Pemutusan Hubungan Kerja', "Berdasarkan hasil evaluasi dan keputusan Manajemen, hubungan kerja {{full_name}} (NISJ {{nisj}}) dinyatakan berakhir efektif {{effective_date}}.\n\nAlasan / dasar keputusan:\n{{reason}}\n\nSejak tanggal efektif, kontrak dan penugasan aktif ditutup sesuai ketentuan serta proses administrasi yang berlaku."],
        ];
        $now = now();
        foreach ($templates as $type => [$name, $body]) {
            if (DB::table('HR_contract_document_templates')->where('document_type', $type)->where('version', 1)->exists()) continue;
            DB::table('HR_contract_document_templates')->insert([
                'id' => (string) Str::ulid(), 'document_type' => $type, 'name' => $name, 'version' => 1,
                'title_template' => $name.' — {{full_name}}', 'body_template' => $body, 'is_active' => true,
                'created_by_user_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function backfillLegacyContracts(): void
    {
        if (! Schema::hasTable('HR_contracts') || ! Schema::hasTable('HR_squads')) return;
        $columns = ['id', 'nisj', 'full_name', 'status', 'contract_type', 'contract_start_date', 'contract_end_date', 'assignment', 'division_name', 'position_name'];
        if (Schema::hasColumn('HR_squads', 'user_id')) $columns[] = 'user_id';

        DB::table('HR_squads')->whereNull('deleted_at')->orderBy('id')->get($columns)->each(function ($squad): void {
            if (DB::table('HR_contracts')->where('squad_id', $squad->id)->whereNull('deleted_at')->exists()) return;
            $employee = null;
            if (property_exists($squad, 'user_id') && filled($squad->user_id)) $employee = DB::table('employees')->where('user_id', (string) $squad->user_id)->first();
            if (! $employee && filled($squad->nisj ?? null)) $employee = DB::table('employees')->whereRaw('LOWER(TRIM(COALESCE(nisj, ?))) = ?', ['', mb_strtolower(trim((string) $squad->nisj))])->first();
            $assignment = $employee && filled($employee->assignment_id ?? null) ? DB::table('assignments')->where('id', $employee->assignment_id)->first() : null;
            if (! $assignment && $employee) $assignment = DB::table('assignments')->where('employee_id', $employee->id)->where('is_primary', true)->orderByDesc('start_date')->first();
            $start = $this->safeDate($squad->contract_start_date ?? null);
            $end = $this->safeDate($squad->contract_end_date ?? null);
            $status = strtolower(trim((string) ($squad->status ?? 'active'))) === 'active' ? 'active' : 'inactive';
            if ($end && $end < now()->toDateString() && $status === 'active') $status = 'expired';
            $payload = [
                'contract_type' => trim((string) ($squad->contract_type ?? '')) ?: 'UNSPECIFIED',
                'start_date' => $start, 'end_date' => $end,
                'assignment_label' => trim((string) ($squad->assignment ?? '')) ?: null,
                'division_name' => trim((string) ($squad->division_name ?? '')) ?: null,
                'position_name' => trim((string) ($squad->position_name ?? '')) ?: null,
            ];
            $id = (string) Str::ulid();
            $now = now();
            DB::table('HR_contracts')->insert([
                'id' => $id, 'squad_id' => $squad->id, 'employee_id' => $employee?->id,
                'assignment_id' => $assignment?->id, 'contract_no' => 'LEGACY-'.$squad->id,
                'contract_type' => $payload['contract_type'], 'status' => $status,
                'tmt_date' => $start, 'first_sk_date' => $start, 'start_date' => $start, 'end_date' => $end,
                'outlet_id' => $assignment?->outlet_id, 'assignment_label' => $payload['assignment_label'],
                'division_name' => $payload['division_name'], 'position_name' => $payload['position_name'],
                'notes' => 'Backfill non-destructive dari Data Squad legacy.', 'source' => 'legacy_backfill',
                'legacy_sync_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
                'created_by_user_id' => null, 'updated_by_user_id' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('HR_contract_events')->insert([
                'id' => (string) Str::ulid(), 'contract_id' => $id, 'document_id' => null, 'assignment_id' => $assignment?->id,
                'event_type' => 'legacy_import', 'effective_date' => $start, 'note' => 'Kontrak dinormalisasi dari kolom Data Squad tanpa menghapus data legacy.',
                'before_snapshot' => null, 'after_snapshot' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'actor_user_id' => null, 'actor_name_snapshot' => 'Migration Iterasi 11', 'event_at' => $start ? Carbon::parse($start)->startOfDay() : $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($end) $this->seedDefaultReminders($id, $end, $now);
        });
    }

    private function seedDefaultReminders(string $contractId, string $endDate, $now): void
    {
        foreach ([90, 60, 30, 14, 7] as $days) {
            $date = Carbon::parse($endDate)->subDays($days)->toDateString();
            DB::table('HR_contract_reminders')->insert([
                'id' => (string) Str::ulid(), 'contract_id' => $contractId, 'days_before' => $days,
                'remind_on' => $date, 'label' => 'H-'.$days.' kontrak berakhir',
                'status' => $date <= now()->toDateString() ? 'due' : 'pending', 'is_default' => true,
                'triggered_at' => $date <= now()->toDateString() ? $now : null,
                'acknowledged_by_user_id' => null, 'acknowledged_at' => null, 'note' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedAccessMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $now = now();
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) return;
        $existing = DB::table('access_menus')->where('code', self::MENU_CODE)->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => self::MENU_CODE], [
            'id' => $menuId, 'portal_id' => (string) $portal->id, 'name' => 'Contract', 'path' => self::MENU_PATH,
            'sort_order' => 32, 'permission_view' => 'hr.contract.view', 'permission_create' => 'hr.contract.create',
            'permission_update' => 'hr.contract.update', 'permission_delete' => 'hr.contract.delete', 'is_active' => true,
            'created_at' => $existing->created_at ?? $now, 'updated_at' => $now,
        ]);
        $guard = config('auth.defaults.guard', 'web');
        foreach (['hr.contract.view','hr.contract.create','hr.contract.update','hr.contract.delete','hr.contract.submit','hr.contract.approve','hr.contract.document.generate','hr.contract.reminder.manage'] as $permission) {
            if (Schema::hasTable('permissions')) Permission::findOrCreate($permission, $guard);
        }
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->map(fn ($v) => (string) $v)->all() : [];
        foreach (DB::table('access_roles')->get(['id','code']) as $role) {
            $code = strtoupper(trim((string) $role->code));
            $isAdmin = $code === 'ADMIN';
            $isManager = $code === 'MANAGER';
            foreach (array_merge([null], $levels) as $levelId) {
                $q = DB::table('access_role_menu_permissions')->where('access_role_id', $role->id)->where('menu_id', $menuId);
                $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $levelId);
                if ($q->exists()) continue;
                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(), 'access_role_id' => $role->id, 'access_level_id' => $levelId, 'menu_id' => $menuId,
                    'can_view' => $isAdmin || $isManager, 'can_create' => $isAdmin, 'can_edit' => $isAdmin, 'can_delete' => $isAdmin,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    private function safeDate(mixed $value): ?string
    {
        if (! $value) return null;
        try { return Carbon::parse($value)->toDateString(); } catch (Throwable) { return null; }
    }

    public function down(): void
    {
        // Non-destructive by design. Contract/SK/history records are HR audit records.
    }
};
