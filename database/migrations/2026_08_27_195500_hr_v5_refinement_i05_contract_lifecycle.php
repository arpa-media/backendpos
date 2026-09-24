<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendContracts();
        $this->createLifecycleEvents();
        $this->normalizeExistingContracts();
        $this->seedAccessMatrix();
    }

    public function down(): void
    {
        // Non-destructive by design. Lifecycle columns/history are HR audit data.
    }

    private function extendContracts(): void
    {
        if (! Schema::hasTable('HR_contracts')) return;

        Schema::table('HR_contracts', function (Blueprint $table): void {
            if (! Schema::hasColumn('HR_contracts', 'lifecycle_stage')) {
                $table->string('lifecycle_stage', 20)->nullable()->after('contract_type');
            }
            if (! Schema::hasColumn('HR_contracts', 'lifecycle_group')) {
                $table->string('lifecycle_group', 20)->nullable()->after('lifecycle_stage');
            }
            if (! Schema::hasColumn('HR_contracts', 'lifecycle_review_status')) {
                $table->string('lifecycle_review_status', 24)->default('uninitialized')->after('lifecycle_group');
            }
            if (! Schema::hasColumn('HR_contracts', 'lifecycle_review_note')) {
                $table->string('lifecycle_review_note', 500)->nullable()->after('lifecycle_review_status');
            }
            if (! Schema::hasColumn('HR_contracts', 'next_contract_due_at')) {
                $table->date('next_contract_due_at')->nullable()->after('end_date');
            }
            if (! Schema::hasColumn('HR_contracts', 'lifecycle_version')) {
                $table->unsignedSmallInteger('lifecycle_version')->default(0)->after('next_contract_due_at');
            }
            if (! Schema::hasColumn('HR_contracts', 'lifecycle_initialized_at')) {
                $table->timestamp('lifecycle_initialized_at')->nullable()->after('lifecycle_version');
            }
        });

        // Migration name is unique, so these concise indexes are created only once.
        Schema::table('HR_contracts', function (Blueprint $table): void {
            $table->index(['lifecycle_stage', 'next_contract_due_at'], 'hr_ctr_life_due_idx');
            $table->index(['lifecycle_review_status', 'status'], 'hr_ctr_life_review_idx');
        });
    }

    private function createLifecycleEvents(): void
    {
        if (Schema::hasTable('HR_contract_lifecycle_events')) return;

        Schema::create('HR_contract_lifecycle_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('contract_id');
            $table->unsignedBigInteger('squad_id')->nullable();
            $table->string('event_type', 40);
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20)->nullable();
            $table->string('group_code', 20)->nullable();
            $table->string('contract_kind', 30)->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->date('stage_start_date')->nullable();
            $table->date('stage_end_date')->nullable();
            $table->date('next_due_at')->nullable();
            $table->string('contract_no_snapshot', 80)->nullable();
            $table->string('contract_type_snapshot', 80)->nullable();
            $table->foreignUlid('outlet_id')->nullable();
            $table->string('outlet_name_snapshot', 180)->nullable();
            $table->string('division_name_snapshot', 150)->nullable();
            $table->string('position_name_snapshot', 150)->nullable();
            $table->string('salary_tier_snapshot', 150)->nullable();
            $table->decimal('nominal_fee_snapshot', 15, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->foreignUlid('actor_user_id')->nullable();
            $table->timestamp('event_at');
            $table->timestamps();

            $table->index(['contract_id', 'event_at'], 'hr_ctr_life_evt_contract_idx');
            $table->index(['squad_id', 'event_at'], 'hr_ctr_life_evt_squad_idx');
            $table->index(['event_type', 'from_stage'], 'hr_ctr_life_evt_type_idx');
            $table->foreign('contract_id', 'hr_ctr_life_evt_ctr_fk')->references('id')->on('HR_contracts')->cascadeOnDelete();
            $table->foreign('actor_user_id', 'hr_ctr_life_evt_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }


    private function seedAccessMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;

        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        if (! $portal) return;

        $now = now();
        $code = 'hr-mapping-contract-lifecycle';
        $path = '/human-resource/mapping-contract/lifecycle';
        $existing = DB::table('access_menus')->where('code', $code)->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        $parent = DB::table('access_menus')->where('code', 'hr-mapping-contract')->first();

        DB::table('access_menus')->updateOrInsert(['code' => $code], [
            'id' => $menuId,
            'portal_id' => (string) $portal->id,
            'name' => 'Contract Lifecycle & Rekap',
            'path' => $path,
            'sort_order' => 33,
            'permission_view' => 'hr.contract.view',
            'permission_create' => 'hr.contract.create',
            'permission_update' => 'hr.contract.update',
            'permission_delete' => null,
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        // New sidebar menu must have its own Access Matrix row. Initial grants mirror
        // Contract so applying I05 does not silently change who can see Contract.
        if (! $parent || ! Schema::hasTable('access_role_menu_permissions')) return;
        $sourceRows = DB::table('access_role_menu_permissions')->where('menu_id', $parent->id)->get();
        foreach ($sourceRows as $source) {
            $query = DB::table('access_role_menu_permissions')
                ->where('access_role_id', $source->access_role_id)
                ->where('menu_id', $menuId);
            $source->access_level_id === null
                ? $query->whereNull('access_level_id')
                : $query->where('access_level_id', $source->access_level_id);
            if ($query->exists()) continue;

            DB::table('access_role_menu_permissions')->insert([
                'id' => (string) Str::ulid(),
                'access_role_id' => $source->access_role_id,
                'access_level_id' => $source->access_level_id,
                'menu_id' => $menuId,
                'can_view' => (bool) $source->can_view,
                'can_create' => (bool) $source->can_create,
                'can_edit' => (bool) $source->can_edit,
                'can_delete' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function normalizeExistingContracts(): void
    {
        if (! Schema::hasTable('HR_contracts') || ! Schema::hasColumn('HR_contracts', 'lifecycle_stage')) return;

        $rows = DB::table('HR_contracts')
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereNull('lifecycle_stage')
                    ->orWhereNull('lifecycle_group')
                    ->orWhereIn('lifecycle_review_status', ['uninitialized', '']);
            })
            ->get(['id', 'contract_type', 'assignment_label', 'division_name', 'position_name', 'end_date', 'lifecycle_stage', 'lifecycle_group', 'lifecycle_review_status']);

        foreach ($rows as $row) {
            $stage = $row->lifecycle_stage ?: $this->inferStage((string) ($row->contract_type ?? ''));
            $group = $row->lifecycle_group ?: $this->inferGroup($row);
            $type = strtoupper(trim((string) ($row->contract_type ?? '')));
            $review = $stage ? 'resolved' : ($type !== '' ? 'needs_review' : 'uninitialized');
            $note = null;
            if (! $stage && $type !== '') {
                $note = 'Jenis kontrak legacy "'.$type.'" tidak cukup untuk menentukan urutan SPT/PKWT. Tetapkan stage secara manual dari Contract Lifecycle.';
            }

            $nextDue = null;
            if ($stage && $stage !== 'PKWTT' && $row->end_date) {
                try { $nextDue = Carbon::parse($row->end_date)->addDay()->toDateString(); } catch (\Throwable) { $nextDue = null; }
            }

            DB::table('HR_contracts')->where('id', $row->id)->update([
                'lifecycle_stage' => $stage,
                'lifecycle_group' => $group,
                'lifecycle_review_status' => $review,
                'lifecycle_review_note' => $note,
                'next_contract_due_at' => $nextDue,
                'lifecycle_initialized_at' => $stage ? now() : null,
                'updated_at' => now(),
            ]);
        }
    }

    private function inferStage(string $contractType): ?string
    {
        $value = strtoupper(preg_replace('/\s+/', '', trim($contractType)) ?: '');
        if ($value === '') return null;
        if (str_starts_with($value, 'SPT')) return 'SPT';
        if (in_array($value, ['PKWTT', 'TETAP', 'PERMANENT'], true)) return 'PKWTT';
        foreach ([1, 2, 3, 4, 5] as $number) {
            if (in_array($value, ['PKWT'.$number, 'PKWT-'.$number, 'PKWT_'.$number], true)) return 'PKWT'.$number;
        }
        // Plain PKWT is intentionally ambiguous: do not guess its sequence.
        return null;
    }

    private function inferGroup(object $row): string
    {
        $text = strtoupper(trim(implode(' ', [
            (string) ($row->division_name ?? ''),
            (string) ($row->position_name ?? ''),
        ])));
        if (str_contains($text, 'FINANCE') || str_contains($text, 'FINANS')) return 'FINANCE';

        $assignment = strtoupper(trim((string) ($row->assignment_label ?? '')));
        if (in_array($assignment, ['MANAGEMENT', 'WAREHOUSE'], true)) return 'MANAGEMENT';
        return 'SQUAD';
    }
};
