<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('HR_attendance_approvals')) {
            Schema::create('HR_attendance_approvals', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('attendance_id', 26)->unique();
                $table->string('approval_type', 32)->index(); // attendance_exception|field_duty
                $table->json('exception_snapshot')->nullable();

                $table->string('spv_status', 16)->default('pending')->index();
                $table->char('spv_user_id', 26)->nullable()->index();
                $table->dateTime('spv_decided_at')->nullable();
                $table->text('spv_note')->nullable();

                $table->string('hrd_status', 16)->default('pending')->index();
                $table->char('hrd_user_id', 26)->nullable()->index();
                $table->dateTime('hrd_decided_at')->nullable();
                $table->text('hrd_note')->nullable();

                $table->string('final_status', 24)->default('pending')->index();
                $table->dateTime('finalized_at')->nullable();
                $table->timestamps();

                $table->index(['approval_type', 'final_status', 'created_at'], 'hr_att_apr_type_final_idx');
                $table->foreign('attendance_id', 'hr_att_apr_att_fk')->references('id')->on('HR_attendances')->cascadeOnDelete();
                $table->foreign('spv_user_id', 'hr_att_apr_spv_user_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('hrd_user_id', 'hr_att_apr_hrd_user_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('HR_attendance_approval_logs')) {
            Schema::create('HR_attendance_approval_logs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('approval_id', 26)->index();
                $table->char('attendance_id', 26)->index();
                $table->string('stage', 32)->index();
                $table->string('action', 32)->index();
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24)->nullable();
                $table->char('actor_user_id', 26)->nullable()->index();
                $table->string('actor_name_snapshot', 180)->nullable();
                $table->text('note')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['attendance_id', 'created_at'], 'hr_att_apr_log_att_idx');
                $table->foreign('approval_id', 'hr_att_apr_log_apr_fk')->references('id')->on('HR_attendance_approvals')->cascadeOnDelete();
                $table->foreign('attendance_id', 'hr_att_apr_log_att_fk')->references('id')->on('HR_attendances')->cascadeOnDelete();
                $table->foreign('actor_user_id', 'hr_att_apr_log_user_fk')->references('id')->on('users')->nullOnDelete();
            });
        }

        $this->backfillExistingExceptions();
    }

    private function backfillExistingExceptions(): void
    {
        if (! Schema::hasTable('HR_attendances') || ! Schema::hasTable('HR_attendance_approvals')) {
            return;
        }

        DB::table('HR_attendances')
            ->where('approval_required', true)
            ->orderBy('created_at')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    if (DB::table('HR_attendance_approvals')->where('attendance_id', $row->id)->exists()) {
                        continue;
                    }

                    $flags = json_decode((string) ($row->exception_flags ?? '[]'), true);
                    $flags = is_array($flags) ? array_values(array_filter(array_map('strval', $flags))) : [];
                    $isDuty = collect($flags)->contains(fn ($flag) => str_contains($flag, 'field_duty'));
                    $aggregate = strtolower(trim((string) ($row->approval_status ?? 'pending')));
                    $approved = $aggregate === 'approved';
                    $rejected = $aggregate === 'rejected';

                    DB::table('HR_attendance_approvals')->insert([
                        'id' => (string) Str::ulid(),
                        'attendance_id' => (string) $row->id,
                        'approval_type' => $isDuty ? 'field_duty' : 'attendance_exception',
                        'exception_snapshot' => json_encode($flags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'spv_status' => $approved ? 'approved' : ($rejected ? 'rejected' : 'pending'),
                        'hrd_status' => $approved ? 'approved' : ($rejected ? 'rejected' : 'pending'),
                        'final_status' => $approved ? 'approved' : ($rejected ? 'rejected' : 'pending'),
                        'finalized_at' => ($approved || $rejected) ? ($row->updated_at ?? now()) : null,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => now(),
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Non-destructive HR audit domain. Approval history is intentionally preserved.
    }
};
