<?php

namespace Database\Seeders;

use App\Models\AccessLevel;
use App\Models\AccessRole;
use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use App\Models\UserAccessAssignment;
use App\Services\UserManagementService;
use App\Support\MenuImport\MenuWorkbookReader;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KutaSquadSeeder extends Seeder
{
    public function run(): void
    {
        $file = database_path('data-imports/kuta-squad/DATA SQUAD TKJ KUTA.xlsx');
        if (! is_file($file)) {
            $this->command?->warn('KutaSquadSeeder skipped: workbook not found.');
            return;
        }

        /** @var UserManagementService $userManagement */
        $userManagement = app(UserManagementService::class);
        $userManagement->ensureMasters();

        $outlet = Outlet::query()->where('code', 'KTA')->first();
        if (! $outlet) {
            $this->command?->warn('KutaSquadSeeder skipped: outlet KTA not found.');
            return;
        }

        $reader = app(MenuWorkbookReader::class);
        $rows = $reader->readFirstSheet($file);
        if ($rows === []) {
            $this->command?->warn('KutaSquadSeeder skipped: workbook has no data rows.');
            return;
        }

        $headers = array_keys($rows[0]);
        $nisjKey = $this->resolveHeader($headers, ['NISJ']);
        $nameKey = $this->resolveHeader($headers, ['NAMA LENGKAP', 'NAMA']);
        $nicknameKey = $this->resolveHeader($headers, ['NAMA', 'NICKNAME']);

        if (! $nisjKey || ! $nameKey) {
            $this->command?->warn('KutaSquadSeeder skipped: required columns NISJ/NAMA not found.');
            return;
        }

        $accessRole = AccessRole::query()->where('code', 'CASHIER')->first()
            ?? AccessRole::query()->where('code', 'SQUAD_DEFAULT')->first();
        $accessLevel = AccessLevel::query()->where('code', 'DEFAULT')->first()
            ?? AccessLevel::query()->first();

        $summary = [
            'users_created' => 0,
            'users_updated' => 0,
            'employees_created' => 0,
            'employees_updated' => 0,
            'assignments_created' => 0,
            'assignments_updated' => 0,
            'access_assignments_created' => 0,
            'processed' => 0,
            'skipped' => 0,
        ];

        DB::transaction(function () use ($rows, $nisjKey, $nameKey, $nicknameKey, $outlet, $accessRole, $accessLevel, $userManagement, &$summary): void {
            foreach ($rows as $row) {
                $nisj = $this->normalizeNisj($row[$nisjKey] ?? null);
                $fullName = $this->normalizeText($row[$nameKey] ?? null);
                $nickname = $nicknameKey ? $this->normalizeText($row[$nicknameKey] ?? null) : null;

                if (! $nisj || ! $fullName) {
                    $summary['skipped']++;
                    continue;
                }

                $summary['processed']++;

                $user = User::query()->firstOrNew(['nisj' => $nisj]);
                $isNewUser = ! $user->exists;
                $user->forceFill([
                    'nisj' => $nisj,
                    'name' => $fullName,
                    'email' => $this->resolveEmail($user, $nisj),
                    'password' => 'password123',
                    'outlet_id' => (string) $outlet->id,
                    'is_active' => true,
                ])->save();
                $summary[$isNewUser ? 'users_created' : 'users_updated']++;

                $employee = Employee::query()->firstOrNew(['user_id' => $user->id]);
                $isNewEmployee = ! $employee->exists;
                $employee->forceFill([
                    'user_id' => $user->id,
                    'nisj' => $nisj,
                    'full_name' => $fullName,
                    'nickname' => $nickname ?: $fullName,
                    'employment_status' => 'active',
                ])->save();
                $summary[$isNewEmployee ? 'employees_created' : 'employees_updated']++;

                Assignment::query()
                    ->where('employee_id', $employee->id)
                    ->where('is_primary', true)
                    ->where('outlet_id', '!=', (string) $outlet->id)
                    ->update([
                        'is_primary' => false,
                        'updated_at' => now(),
                    ]);

                $assignment = Assignment::query()->firstOrNew([
                    'employee_id' => $employee->id,
                    'outlet_id' => (string) $outlet->id,
                ]);
                $isNewAssignment = ! $assignment->exists;
                $assignment->forceFill([
                    'employee_id' => $employee->id,
                    'outlet_id' => (string) $outlet->id,
                    'role_title' => 'Cashier',
                    'start_date' => $assignment->start_date?->toDateString() ?: now()->toDateString(),
                    'end_date' => null,
                    'is_primary' => true,
                    'status' => 'active',
                ])->save();
                $summary[$isNewAssignment ? 'assignments_created' : 'assignments_updated']++;

                if ((string) $employee->assignment_id !== (string) $assignment->id) {
                    $employee->forceFill(['assignment_id' => (string) $assignment->id])->save();
                }

                $accessAssignment = UserAccessAssignment::query()->firstOrNew(['user_id' => $user->id]);
                if (! $accessAssignment->exists) {
                    $summary['access_assignments_created']++;
                }
                $accessAssignment->forceFill([
                    'user_id' => $user->id,
                    'access_role_id' => $accessRole?->id,
                    'access_level_id' => $accessLevel?->id,
                    'assigned_by_user_id' => null,
                ])->save();

                $userManagement->syncUserPermissions($user->fresh(['roles', 'permissions']));
            }
        });

        $this->command?->info(sprintf(
            'KutaSquadSeeder done: processed=%d skipped=%d users(created=%d updated=%d) employees(created=%d updated=%d) assignments(created=%d updated=%d) access(role=%s level=%s) default_password=%s',
            $summary['processed'],
            $summary['skipped'],
            $summary['users_created'],
            $summary['users_updated'],
            $summary['employees_created'],
            $summary['employees_updated'],
            $summary['assignments_created'],
            $summary['assignments_updated'],
            (string) ($accessRole?->code ?? 'N/A'),
            (string) ($accessLevel?->code ?? 'N/A'),
            'password123',
        ));
    }

    /**
     * @param array<int, string> $headers
     */
    private function resolveHeader(array $headers, array $candidates): ?string
    {
        $map = [];
        foreach ($headers as $header) {
            $normalized = $this->normalizeHeader($header);
            $map[$normalized] = $header;
        }

        foreach ($candidates as $candidate) {
            $normalizedCandidate = $this->normalizeHeader($candidate);
            if (isset($map[$normalizedCandidate])) {
                return $map[$normalizedCandidate];
            }
        }

        return null;
    }

    private function normalizeHeader(string $value): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }

    private function normalizeText(mixed $value): ?string
    {
        $text = trim((string) $value);
        return $text !== '' ? $text : null;
    }

    private function normalizeNisj(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (str_ends_with($raw, '.0')) {
            $raw = substr($raw, 0, -2);
        }

        $digits = preg_replace('/\D+/', '', $raw);
        return $digits !== '' ? $digits : null;
    }

    private function resolveEmail(User $user, string $nisj): string
    {
        $existingEmail = trim((string) ($user->email ?? ''));
        if ($existingEmail !== '') {
            return $existingEmail;
        }

        return sprintf('%s@kta.local', $nisj);
    }
}
