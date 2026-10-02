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
        if (! Schema::hasTable('ga_ticket_approval_flags')) {
            Schema::create('ga_ticket_approval_flags', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('ticket_id')->constrained('ga_tickets')->cascadeOnDelete();
                $table->string('audience', 24);
                $table->boolean('needs_approval')->default(false);
                $table->boolean('needs_discussion')->default(false);
                $table->string('approval_status', 24)->default('NOT_REQUIRED');
                $table->text('decision_note')->nullable();
                $table->foreignUlid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('decided_by_name_snapshot', 180)->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->foreignUlid('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('configured_at')->nullable();
                $table->timestamps();

                $table->unique(['ticket_id', 'audience'], 'ga_ticket_approval_audience_uq');
                $table->index(['audience', 'approval_status'], 'ga_ticket_approval_status_idx');
            });
        }

        if (! Schema::hasTable('ga_ticket_discussions')) {
            Schema::create('ga_ticket_discussions', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('ticket_id')->constrained('ga_tickets')->cascadeOnDelete();
                $table->foreignUlid('parent_id')->nullable();
                $table->string('audience', 24)->default('GENERAL')->index();
                $table->string('kind', 24)->default('QUESTION')->index();
                $table->text('message');
                $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name_snapshot', 180)->nullable();
                $table->dateTime('created_at')->index();

                $table->index(['ticket_id', 'created_at'], 'ga_ticket_discussion_timeline_idx');
                $table->foreign('parent_id', 'ga_ticket_discussion_parent_fk')
                    ->references('id')->on('ga_ticket_discussions')->nullOnDelete();
            });
        }

        $this->seedApprovalRowsForExistingTickets();
    }

    public function down(): void
    {
        // Non-destructive by design. I05+ reuses workflow/discussion history.
    }

    private function seedApprovalRowsForExistingTickets(): void
    {
        if (! Schema::hasTable('ga_tickets') || ! Schema::hasTable('ga_ticket_approval_flags')) return;

        DB::table('ga_tickets')
            ->select('id')
            ->orderBy('id')
            ->chunk(250, function ($tickets): void {
                $now = now();
                $rows = [];
                foreach ($tickets as $ticket) {
                    foreach (['CEO', 'EXECUTIVE'] as $audience) {
                        $rows[] = [
                            'id' => (string) Str::ulid(),
                            'ticket_id' => (string) $ticket->id,
                            'audience' => $audience,
                            'needs_approval' => false,
                            'needs_discussion' => false,
                            'approval_status' => 'NOT_REQUIRED',
                            'decision_note' => null,
                            'decided_by_user_id' => null,
                            'decided_by_name_snapshot' => null,
                            'decided_at' => null,
                            'configured_by_user_id' => null,
                            'configured_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                if ($rows !== []) {
                    DB::table('ga_ticket_approval_flags')->insertOrIgnore($rows);
                }
            });
    }
};
