<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrCareerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HrCareerAccountAdminI02Service
{
    public function deleteAccount(string $accountId, ?string $reason, ?User $actor): array
    {
        return DB::transaction(function () use ($accountId, $reason, $actor): array {
            $account = HrCareerAccount::query()->whereKey($accountId)->lockForUpdate()->first();
            abort_unless($account, 404, 'Career Account tidak ditemukan.');

            $alreadyDeleted = ! (bool) $account->is_active
                && str_starts_with((string) $account->nik, 'DELETED-')
                && str_starts_with((string) $account->username, 'deleted_');

            if ($alreadyDeleted) {
                return [
                    'career_account_id' => (string) $account->id,
                    'status' => 'already_deleted',
                    'deleted' => false,
                ];
            }

            $now = now();
            $reason = trim((string) $reason);
            $reason = $reason !== '' ? $reason : 'Dihapus melalui Applicant Register.';
            $snapshot = [
                'nik' => trim((string) $account->nik),
                'full_name' => (string) $account->full_name,
                'phone' => (string) $account->phone,
                'email' => $account->email ? (string) $account->email : null,
                'application_blocked_at' => $account->application_blocked_at?->toISOString(),
                'application_block_reason' => $account->application_block_reason ? (string) $account->application_block_reason : null,
            ];
            $originalNik = $snapshot['nik'];
            $registrationRequestId = $account->registration_request_id
                ? (string) $account->registration_request_id
                : null;

            $applicationIds = Schema::hasTable('HR_applications')
                ? DB::table('HR_applications')->where('career_account_id', $account->id)->pluck('id')->map(fn ($id) => (string) $id)->all()
                : [];

            $closedWindows = 0;
            if ($applicationIds !== [] && Schema::hasTable('HR_recruitment_presence_windows')) {
                $openWindows = DB::table('HR_recruitment_presence_windows')
                    ->whereIn('application_id', $applicationIds)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->get(['id', 'note']);

                foreach ($openWindows as $window) {
                    $existingNote = trim((string) ($window->note ?? ''));
                    $autoNote = 'Closed automatically: Career Account deleted from Applicant Register.';
                    DB::table('HR_recruitment_presence_windows')->where('id', $window->id)->update([
                        'status' => 'closed',
                        'closed_by_user_id' => $actor?->id,
                        'closed_at' => $now,
                        'note' => $existingNote !== '' ? $existingNote."\n".$autoNote : $autoNote,
                        'updated_at' => $now,
                    ]);
                    $closedWindows++;
                }
            }

            $rejectedPasswordResets = 0;
            if (Schema::hasTable('HR_career_password_reset_requests')) {
                $rejectedPasswordResets = DB::table('HR_career_password_reset_requests')
                    ->where('career_account_id', $account->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'rejected',
                        'reviewed_by_user_id' => $actor?->id,
                        'reviewed_at' => $now,
                        'review_notes' => 'Ditolak otomatis karena Career Account dihapus dari Applicant Register.',
                        'updated_at' => $now,
                    ]);

                // Request history remains, but no longer exposes this operational account
                // as an active account from Applicant Register after refresh.
                DB::table('HR_career_password_reset_requests')
                    ->where('career_account_id', $account->id)
                    ->update(['career_account_id' => null, 'updated_at' => $now]);
            }

            if (Schema::hasTable('HR_career_registration_requests')) {
                $regQuery = DB::table('HR_career_registration_requests')
                    ->where(function ($q) use ($account, $registrationRequestId): void {
                        $q->where('career_account_id', $account->id);
                        if ($registrationRequestId) $q->orWhere('id', $registrationRequestId);
                    });

                $linkedRegistrations = (clone $regQuery)->whereIn('status', ['pending', 'approved'])->lockForUpdate()->get(['id', 'review_notes']);
                foreach ($linkedRegistrations as $registration) {
                    $existingReviewNote = trim((string) ($registration->review_notes ?? ''));
                    $deleteNote = 'Approval dicabut karena Career Account dihapus dari Applicant Register. '.$reason;
                    DB::table('HR_career_registration_requests')->where('id', $registration->id)->update([
                        'status' => 'rejected',
                        'review_notes' => $existingReviewNote !== '' ? $existingReviewNote."\n".$deleteNote : $deleteNote,
                        'updated_at' => $now,
                    ]);
                }

                $regQuery->update(['career_account_id' => null, 'updated_at' => $now]);
            }

            // Revoke all Sanctum tokens before tombstoning login identity.
            $account->tokens()->delete();

            $tombstone = 'DELETED-'.strtoupper((string) $account->id);
            $account->forceFill([
                'nik' => Str::limit($tombstone, 40, ''),
                'username' => Str::limit('deleted_'.strtolower((string) $account->id), 60, ''),
                'email' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'is_active' => false,
                'must_change_password' => true,
                'application_blocked_at' => $now,
                'application_block_reason' => 'Career Account dihapus dari Applicant Register. '.$reason,
            ])->save();

            $logId = null;
            if (Schema::hasTable('HR_career_account_deletion_logs')) {
                $logId = (string) Str::ulid();
                DB::table('HR_career_account_deletion_logs')->insert([
                    'id' => $logId,
                    'career_account_id' => $account->id,
                    'registration_request_id' => $registrationRequestId,
                    'nik_snapshot' => $originalNik !== '' ? $originalNik : null,
                    'full_name_snapshot' => $snapshot['full_name'] !== '' ? $snapshot['full_name'] : null,
                    'phone_snapshot' => $snapshot['phone'] !== '' ? $snapshot['phone'] : null,
                    'email_snapshot' => $snapshot['email'],
                    'deleted_by_user_id' => $actor?->id,
                    'reason' => $reason,
                    'closed_presence_windows' => $closedWindows,
                    'rejected_password_reset_requests' => $rejectedPasswordResets,
                    'metadata' => json_encode([
                        'application_count' => count($applicationIds),
                        'previous_application_blocked_at' => $snapshot['application_blocked_at'],
                        'previous_application_block_reason' => $snapshot['application_block_reason'],
                        'mode' => 'logical_account_delete_preserve_recruitment_history',
                    ], JSON_UNESCAPED_UNICODE),
                    'deleted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'career_account_id' => (string) $account->id,
                'status' => 'deleted',
                'deleted' => true,
                'deletion_log_id' => $logId,
                'closed_presence_windows' => $closedWindows,
                'rejected_password_reset_requests' => $rejectedPasswordResets,
                'recruitment_history_preserved' => true,
            ];
        });
    }

    public function bulkDelete(array $accountIds, ?string $reason, ?User $actor): array
    {
        $unique = array_values(array_unique(array_map(fn ($id) => trim((string) $id), $accountIds)));

        return DB::transaction(function () use ($unique, $reason, $actor): array {
            $items = [];
            $deleted = 0;
            $skipped = 0;

            foreach ($unique as $accountId) {
                $result = $this->deleteAccount($accountId, $reason, $actor);
                $items[] = $result;
                $result['deleted'] ? $deleted++ : $skipped++;
            }

            return [
                'requested_count' => count($unique),
                'deleted_count' => $deleted,
                'skipped_count' => $skipped,
                'items' => $items,
            ];
        });
    }
}
