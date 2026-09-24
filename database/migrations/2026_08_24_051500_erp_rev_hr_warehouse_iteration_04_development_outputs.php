<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->seedPermissions();
        $this->repairDevelopmentAccessMatrix();
        $this->backfillCompletedOutputs();
        $this->forgetPermissionCache();
    }

    public function down(): void
    {
        // Non-destructive by design. Badges/certificates are employee records and must
        // not disappear merely because application code is rolled back.
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('permissions')) return;
        $guard = config('auth.defaults.guard', 'web');
        foreach ([
            'hr.development.view',
            'hr.development.create',
            'hr.development.update',
            'hr.development.delete',
            'hr.development.badge.manage',
            'hr.development.result.publish',
        ] as $name) Permission::findOrCreate($name, $guard);
    }

    private function repairDevelopmentAccessMatrix(): void
    {
        if (! Schema::hasTable('access_menus')) return;
        $menu = DB::table('access_menus')->where(function ($query): void {
            $query->where('code', 'hr-development')
                ->orWhere('path', '/human-resource/development');
        })->first();
        if (! $menu) return;

        $payload = [
            'permission_view' => 'hr.development.view',
            'permission_create' => 'hr.development.create',
            'permission_update' => 'hr.development.update',
            'permission_delete' => 'hr.development.delete',
            'is_active' => true,
        ];
        if (Schema::hasColumn('access_menus', 'updated_at')) $payload['updated_at'] = now();
        DB::table('access_menus')->where('id', $menu->id)->update($payload);
    }

    private function backfillCompletedOutputs(): void
    {
        foreach (['HR_developments', 'HR_development_participants', 'HR_development_achievements', 'employees', 'HR_development_batches'] as $table) {
            if (! Schema::hasTable($table)) return;
        }

        DB::table('HR_development_participants as p')
            ->join('HR_developments as d', 'd.id', '=', 'p.development_id')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->leftJoin('HR_development_batches as b', 'b.id', '=', 'p.batch_id')
            ->where('p.status', 'completed')
            ->whereNotNull('p.completed_at')
            ->whereNull('d.deleted_at')
            ->where(function ($query): void {
                $query->where('d.output_badge', true)->orWhere('d.output_certificate', true);
            })
            ->select([
                'p.id as participant_id', 'p.development_id', 'p.employee_id', 'p.completed_at',
                'p.score_final', 'p.result_label', 'p.result_published_at',
                'd.code as development_code', 'd.name as development_name', 'd.output_badge', 'd.badge_name',
                'd.output_certificate', 'd.certificate_title',
                'e.full_name', 'e.nisj', 'b.name as batch_name',
            ])
            ->orderBy('p.id')
            ->chunk(250, function ($rows): void {
                foreach ($rows as $row) $this->backfillParticipant($row);
            });
    }

    private function backfillParticipant(object $row): void
    {
        $snapshot = [
            'name' => $row->full_name,
            'nisj' => $row->nisj,
            'development' => $row->development_name,
            'development_code' => $row->development_code,
            'batch' => $row->batch_name,
            'result' => $row->result_label ?: 'Completed',
            'score' => $row->score_final === null ? null : (float) $row->score_final,
            'completed_date' => (string) $row->completed_at,
            'published_at' => $row->result_published_at ? (string) $row->result_published_at : null,
        ];

        if ((bool) $row->output_badge) {
            $this->upsertAchievement(
                $row,
                'badge',
                trim((string) $row->badge_name) ?: ((string) $row->development_name . ' Badge'),
                $snapshot,
                $this->badgeCode((string) $row->development_code, (string) $row->employee_id),
            );
        }

        if ((bool) $row->output_certificate) {
            $existing = DB::table('HR_development_achievements')
                ->where('participant_id', $row->participant_id)
                ->where('type', 'certificate')
                ->first();
            $certificateNo = (string) ($existing?->achievement_code ?: $this->certificateCode($row));
            $certificateSnapshot = $snapshot + [
                'certificate_no' => $certificateNo,
                'template' => $this->activeTemplate((string) $row->development_id),
            ];
            $this->upsertAchievement(
                $row,
                'certificate',
                trim((string) $row->certificate_title) ?: 'Certificate of Completion',
                $certificateSnapshot,
                $certificateNo,
            );
        }
    }

    private function upsertAchievement(object $row, string $type, string $title, array $snapshot, string $code): void
    {
        $existing = DB::table('HR_development_achievements')
            ->where('participant_id', $row->participant_id)
            ->where('type', $type)
            ->first();

        if ($existing?->revoked_at) return; // Preserve explicit historical revocation.

        $payload = [
            'development_id' => $row->development_id,
            'employee_id' => $row->employee_id,
            'type' => $type,
            'achievement_code' => substr($code, 0, 100),
            'title' => $title,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'issued_at' => $existing?->issued_at ?: $row->completed_at,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
        ];
        if (Schema::hasColumn('HR_development_achievements', 'updated_at')) $payload['updated_at'] = now();

        if ($existing) {
            DB::table('HR_development_achievements')->where('id', $existing->id)->update($payload);
            return;
        }

        $payload['id'] = (string) Str::ulid();
        $payload['participant_id'] = $row->participant_id;
        $payload['issued_by_user_id'] = null;
        if (Schema::hasColumn('HR_development_achievements', 'created_at')) $payload['created_at'] = now();
        DB::table('HR_development_achievements')->insert($payload);
    }

    private function badgeCode(string $developmentCode, string $employeeId): string
    {
        $dev = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($developmentCode)), 0, 40);
        $emp = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($employeeId)), -12);
        return substr('BADGE-' . $dev . '-' . $emp, 0, 100);
    }

    private function certificateCode(object $row): string
    {
        $timestamp = strtotime((string) $row->completed_at);
        $year = $timestamp ? date('Y', $timestamp) : date('Y');
        $dev = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $row->development_code)), 0, 36);
        $personSource = trim((string) $row->nisj) !== '' ? (string) $row->nisj : substr((string) $row->employee_id, -12);
        $person = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($personSource)), 0, 24);
        return substr('CERT/' . $year . '/' . $dev . '/' . $person, 0, 100);
    }

    private function activeTemplate(string $developmentId): ?array
    {
        if (! Schema::hasTable('HR_development_certificate_templates')) return null;
        $row = DB::table('HR_development_certificate_templates')
            ->where('development_id', $developmentId)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();
        if (! $row) return null;
        $merge = json_decode((string) ($row->merge_fields ?? ''), true);
        return [
            'id' => (string) $row->id,
            'version' => (int) $row->version,
            'name' => (string) $row->name,
            'original_name' => (string) $row->original_name,
            'mime_type' => (string) $row->mime_type,
            'source_size_bytes' => (int) $row->source_size_bytes,
            'merge_fields' => is_array($merge) ? $merge : [],
            'is_active' => (bool) $row->is_active,
        ];
    }

    private function forgetPermissionCache(): void
    {
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
