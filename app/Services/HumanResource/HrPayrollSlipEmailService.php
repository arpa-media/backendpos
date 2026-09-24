<?php

namespace App\Services\HumanResource;

use App\Models\HrPayrollCutoff;
use App\Models\HrPayrollSlip;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class HrPayrollSlipEmailService
{
    private const SOURCE_PAYROLL = 'PAYROLL';
    private const SOURCE_BONUS = 'BONUS';

    public function __construct(
        private readonly HrPayrollSlipPdfService $pdf,
        private readonly HrAttendanceBackofficeScopeService $scope,
    ) {}

    public function payrollStatuses(HrPayrollCutoff $cutoff): array
    {
        $slips = $cutoff->slips()->get(['id','employee_id','user_id','nisj_snapshot']);
        $logs = $this->logs(self::SOURCE_PAYROLL, $slips->pluck('id')->map(fn ($v) => (string) $v)->all());
        return [
            'items' => $slips->map(function (HrPayrollSlip $slip) use ($logs, $cutoff) {
                $log = $logs->get((string) $slip->id);
                return $this->statusPayload((string) $slip->id, $log, $this->recipientForSlip($slip), (string) $cutoff->status !== 'draft');
            })->values()->all(),
            'smtp' => $this->smtpContext(),
        ];
    }

    public function bonusStatuses(Request $request, string $projectionId): array
    {
        $projection = $this->projection($request, $projectionId);
        $lines = DB::table('HR_bonus_projection_lines')->where('projection_id', $projectionId)->get(['id','employee_id','squad_id','nisj_snapshot']);
        $logs = $this->logs(self::SOURCE_BONUS, $lines->pluck('id')->map(fn ($v) => (string) $v)->all());
        return [
            'items' => $lines->map(function ($line) use ($logs, $projection) {
                $log = $logs->get((string) $line->id);
                return $this->statusPayload((string) $line->id, $log, $this->recipientForBonusLine($line), (string) $projection->status !== 'draft');
            })->values()->all(),
            'smtp' => $this->smtpContext(),
        ];
    }

    public function diagnostics(): array
    {
        $smtp = $this->smtpContext();
        $started = microtime(true);

        if (! $smtp['password_configured']) {
            return [
                'success' => false,
                'status' => 'failed',
                'stage' => 'configuration',
                'error_code' => 'password_missing',
                'message' => 'HR_MAIL_PASSWORD belum diisi pada environment backend.',
                'safe_error' => 'Credential SMTP HR belum lengkap: password belum dikonfigurasi.',
                'smtp' => $smtp,
                'checked_at' => now()->toIso8601String(),
                'latency_ms' => 0,
            ];
        }

        try {
            $this->configureMailer();
            $manager = app('mail.manager');
            $mailer = $manager->mailer('hr');
            $transport = method_exists($mailer, 'getSymfonyTransport') ? $mailer->getSymfonyTransport() : null;

            if (! $transport || ! method_exists($transport, 'start')) {
                throw new RuntimeException('Transport SMTP HR tidak menyediakan connection probe yang didukung.');
            }

            $transport->start();
            if (method_exists($transport, 'stop')) $transport->stop();

            return [
                'success' => true,
                'status' => 'ok',
                'stage' => 'smtp_authentication',
                'error_code' => null,
                'message' => 'Koneksi TLS dan autentikasi SMTP HR berhasil.',
                'safe_error' => null,
                'smtp' => $smtp,
                'checked_at' => now()->toIso8601String(),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable $e) {
            $failure = $this->transportFailure($e);
            return [
                'success' => false,
                'status' => 'failed',
                'stage' => $failure['stage'],
                'error_code' => $failure['error_code'],
                'message' => $failure['message'],
                'safe_error' => $failure['safe_error'],
                'smtp' => $smtp,
                'checked_at' => now()->toIso8601String(),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } finally {
            $this->purgeMailer();
        }
    }

    public function payrollPdf(HrPayrollCutoff $cutoff, HrPayrollSlip $slip): array
    {
        $this->assertSlip($cutoff, $slip);
        return $this->pdf->payroll($slip);
    }

    public function bonusPdf(Request $request, string $projectionId, string $lineId): array
    {
        [$projection, $line, $slip] = $this->bonusContext($request, $projectionId, $lineId);
        return $this->pdf->bonus($slip, $projection, $line);
    }

    public function sendPayroll(HrPayrollCutoff $cutoff, HrPayrollSlip $slip, ?User $actor): array
    {
        $this->assertSlip($cutoff, $slip);
        if ((string) $cutoff->status === 'draft') $this->invalid('cutoff', 'Ajukan Cutoff Gaji terlebih dahulu sebelum mengirim slip melalui email.');
        $recipient = $this->recipientForSlip($slip);
        if (! $recipient) $this->invalid('email', 'Email Squad belum tersedia/valid pada Data Squad atau akun user.');
        $document = $this->pdf->payroll($slip);
        $key = 'PAYROLL:'.$cutoff->id.':'.$slip->id;
        $claim = $this->claim($key, self::SOURCE_PAYROLL, (string) $slip->id, [
            'payroll_cutoff_id' => (string) $cutoff->id,
            'payroll_slip_id' => (string) $slip->id,
            'employee_id' => (string) $slip->employee_id,
        ], $recipient, $document, $actor);
        if ($claim['already_sent']) return $this->deliveryPayload($claim['row'], true);

        return $this->deliver($claim['row'], $recipient, $slip->full_name_snapshot ?: 'Squad', 'Slip Gaji '.$document['period'], $document, $actor);
    }

    public function sendBonus(Request $request, string $projectionId, string $lineId, ?User $actor): array
    {
        [$projection, $line, $slip] = $this->bonusContext($request, $projectionId, $lineId);
        if ((string) $projection->status === 'draft') $this->invalid('projection', 'Ajukan Cutoff Bonus terlebih dahulu sebelum mengirim slip melalui email.');
        $recipient = $this->recipientForBonusLine($line) ?: ($slip ? $this->recipientForSlip($slip) : null);
        if (! $recipient) $this->invalid('email', 'Email Squad belum tersedia/valid pada Data Squad atau akun user.');
        $document = $this->pdf->bonus($slip, $projection, $line);
        $key = 'BONUS:'.$projectionId.':'.$lineId;
        $claim = $this->claim($key, self::SOURCE_BONUS, $lineId, [
            'payroll_cutoff_id' => $slip?->cutoff_id ? (string) $slip->cutoff_id : null,
            'payroll_slip_id' => $slip?->id ? (string) $slip->id : null,
            'bonus_projection_id' => $projectionId,
            'bonus_line_id' => $lineId,
            'employee_id' => (string) $line->employee_id,
        ], $recipient, $document, $actor);
        if ($claim['already_sent']) return $this->deliveryPayload($claim['row'], true);

        return $this->deliver($claim['row'], $recipient, (string) $line->name_snapshot, 'Slip Bonus KPI '.$document['period'], $document, $actor);
    }

    private function deliver(object $log, string $recipient, string $name, string $subject, array $document, ?User $actor): array
    {
        try {
            $this->configureMailer();
            $body = "Yth. {$name},\n\nTerlampir slip Human Resource Toko Kopi Jaya untuk periode {$document['period']}.\n\nDokumen ini bersifat pribadi. Mohon tidak meneruskan lampiran kepada pihak yang tidak berkepentingan.\n\nHuman Resource Chambers\nToko Kopi Jaya";
            Mail::mailer('hr')->raw($body, function ($message) use ($recipient, $subject, $document): void {
                $message->from((string) config('hr_mail.from_address'), (string) config('hr_mail.from_name'));
                $message->to($recipient)->subject($subject);
                $message->attachData($document['pdf'], $document['filename'], ['mime' => 'application/pdf']);
            });

            $metadata = $this->mergeMetadata($log, [
                'smtp' => $this->smtpContext(),
                'delivery' => ['result' => 'sent', 'error_code' => null, 'safe_error' => null],
            ]);
            DB::table('HR_payroll_slip_email_logs')->where('id', $log->id)->update([
                'status' => 'sent', 'sent_at' => now(), 'error_message' => null,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'sending_token' => null, 'sending_started_at' => null,
                'sent_by_user_id' => $actor?->id, 'updated_at' => now(),
            ]);
            return $this->deliveryPayload(DB::table('HR_payroll_slip_email_logs')->where('id', $log->id)->first(), true);
        } catch (Throwable $e) {
            $failure = $this->transportFailure($e);
            $metadata = $this->mergeMetadata($log, [
                'smtp' => $this->smtpContext(),
                'delivery' => ['result' => 'failed', 'error_code' => $failure['error_code'], 'stage' => $failure['stage'], 'safe_error' => $failure['safe_error']],
            ]);
            DB::table('HR_payroll_slip_email_logs')->where('id', $log->id)->update([
                'status' => 'failed', 'error_message' => $failure['safe_error'],
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'sending_token' => null, 'sending_started_at' => null, 'updated_at' => now(),
            ]);
            return $this->deliveryPayload(DB::table('HR_payroll_slip_email_logs')->where('id', $log->id)->first(), false, $failure);
        } finally {
            $this->purgeMailer();
        }
    }

    private function claim(string $documentKey, string $sourceType, string $sourceId, array $references, string $recipient, array $document, ?User $actor): array
    {
        $token = (string) Str::ulid();
        try {
            DB::table('HR_payroll_slip_email_logs')->insert(array_merge([
                'id' => (string) Str::ulid(), 'document_key' => $documentKey, 'source_type' => $sourceType,
                'source_id' => $sourceId, 'recipient_email' => $recipient,
                'attachment_name' => $document['filename'], 'attachment_sha256' => $document['sha256'],
                'status' => 'pending', 'attempts' => 0, 'requested_by_user_id' => $actor?->id,
                'metadata' => json_encode(['smtp' => $this->smtpContext()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ], $references));
        } catch (QueryException $e) {
            if (! $this->isDuplicate($e)) throw $e;
        }

        return DB::transaction(function () use ($documentKey, $recipient, $document, $token, $actor): array {
            $row = DB::table('HR_payroll_slip_email_logs')->where('document_key', $documentKey)->lockForUpdate()->first();
            if (! $row) throw new RuntimeException('Email log tidak dapat dibuat.');
            if ((string) $row->status === 'sent') return ['already_sent' => true, 'row' => $row];
            if ((string) $row->status === 'sending' && $row->sending_started_at && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($row->sending_started_at), true) < 5) {
                $this->invalid('email', 'Slip sedang dikirim oleh proses lain. Tunggu beberapa saat lalu refresh.');
            }
            $metadata = $this->mergeMetadata($row, ['smtp' => $this->smtpContext(), 'delivery' => ['result' => 'sending']]);
            DB::table('HR_payroll_slip_email_logs')->where('id', $row->id)->update([
                'recipient_email' => $recipient, 'attachment_name' => $document['filename'],
                'attachment_sha256' => $document['sha256'], 'status' => 'sending',
                'attempts' => (int) $row->attempts + 1, 'last_attempt_at' => now(),
                'sending_token' => $token, 'sending_started_at' => now(),
                'requested_by_user_id' => $actor?->id, 'error_message' => null,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
            return ['already_sent' => false, 'row' => DB::table('HR_payroll_slip_email_logs')->where('id', $row->id)->first()];
        });
    }

    private function bonusContext(Request $request, string $projectionId, string $lineId): array
    {
        $projection = $this->projection($request, $projectionId);
        $line = DB::table('HR_bonus_projection_lines')->where('projection_id', $projectionId)->where('id', $lineId)->first();
        if (! $line) abort(404);
        $slip = $this->matchingPayrollSlip($projection, $line);
        $this->enrichBonusLineContext($projection, $line, $slip);
        return [$projection, $line, $slip];
    }


    private function enrichBonusLineContext(object $projection, object $line, ?HrPayrollSlip $slip): void
    {
        if ($slip) {
            $slip->loadMissing('cutoff');
            $line->company_code_snapshot = $slip->company_code_snapshot ?: $slip->cutoff?->company_code;
            $line->outlet_name_snapshot = $slip->outlet_name_snapshot;
            $line->position_snapshot = $slip->position_snapshot;
            return;
        }

        $line->outlet_name_snapshot = DB::table('outlets')->where('id', (string) $projection->outlet_id)->value('name') ?: '-';
        $line->company_code_snapshot = Schema::hasTable('finance_outlet_company_mappings')
            ? DB::table('finance_outlet_company_mappings')->where('outlet_id', (string) $projection->outlet_id)->where('is_active', true)->value('company_code')
            : null;

        $position = DB::table('assignments')
            ->where('employee_id', (string) $line->employee_id)
            ->where('is_primary', true)
            ->where(function ($q): void {
                $q->whereNull('status')->orWhereNotIn(DB::raw('LOWER(status)'), ['inactive', 'ended']);
            })
            ->orderByDesc('updated_at')
            ->value('role_title');
        $line->position_snapshot = $position ?: '-';

        // Cutoff Bonus yang sudah diajukan semestinya selalu memiliki Mapping PT Outlet.
        // Fallback BKJB hanya menjaga compatibility untuk proyeksi legacy sebelum rule mapping diwajibkan.
        $line->company_code_snapshot = strtoupper(trim((string) ($line->company_code_snapshot ?: 'BKJB')));
    }

    private function matchingPayrollSlip(object $projection, object $line): ?HrPayrollSlip
    {
        $id = DB::table('HR_payroll_slips as s')->join('HR_payroll_cutoffs as c', 'c.id', '=', 's.cutoff_id')
            ->where('s.employee_id', (string) $line->employee_id)
            ->whereDate('c.period_from', (string) $projection->period_from)
            ->whereDate('c.period_to', (string) $projection->period_to)
            ->where(function ($q) use ($projection): void {
                $q->where('s.outlet_id_snapshot', (string) $projection->outlet_id)->orWhere('c.outlet_id', (string) $projection->outlet_id);
            })
            ->orderByRaw("CASE c.status WHEN 'finalized' THEN 1 WHEN 'finance_processing' THEN 2 WHEN 'submitted' THEN 3 WHEN 'draft' THEN 4 ELSE 5 END")
            ->orderByDesc('c.created_at')->value('s.id');
        return $id ? HrPayrollSlip::with('cutoff')->find((string) $id) : null;
    }

    private function projection(Request $request, string $projectionId): object
    {
        $projection = DB::table('HR_bonus_projections')->where('id', $projectionId)->first();
        if (! $projection) abort(404);
        $allowed = $this->scope->allowedOutletIds($request);
        if (! in_array((string) $projection->outlet_id, $allowed, true)) abort(403, 'Outlet berada di luar scope Human Resource user.');
        return $projection;
    }

    private function assertSlip(HrPayrollCutoff $cutoff, HrPayrollSlip $slip): void
    {
        if ((string) $slip->cutoff_id !== (string) $cutoff->id) abort(404);
    }

    private function logs(string $sourceType, array $sourceIds)
    {
        if ($sourceIds === [] || ! Schema::hasTable('HR_payroll_slip_email_logs')) return collect();
        return DB::table('HR_payroll_slip_email_logs')->where('source_type', $sourceType)->whereIn('source_id', $sourceIds)->get()->keyBy('source_id');
    }

    private function statusPayload(string $sourceId, ?object $log, ?string $recipient, bool $eligible): array
    {
        $metadata = $this->metadata($log);
        return [
            'source_id' => $sourceId,
            'status' => (string) ($log->status ?? 'pending'),
            'recipient_email' => $log->recipient_email ?? $recipient,
            'sent_at' => $log->sent_at ?? null,
            'last_attempt_at' => $log->last_attempt_at ?? null,
            'attempts' => (int) ($log->attempts ?? 0),
            'attachment_name' => $log->attachment_name ?? null,
            'error_code' => $metadata['delivery']['error_code'] ?? null,
            'error_message' => $log ? $this->safeTransportText((string) ($log->error_message ?? '')) : null,
            'smtp' => $metadata['smtp'] ?? $this->smtpContext(),
            'can_send' => $eligible && (string) ($log->status ?? '') !== 'sent',
            'can_retry' => $eligible && (string) ($log->status ?? '') === 'failed',
        ];
    }

    private function deliveryPayload(object $row, bool $success, ?array $failure = null): array
    {
        $metadata = $this->metadata($row);
        $smtp = $metadata['smtp'] ?? $this->smtpContext();
        $safeError = $failure['safe_error'] ?? ($row->error_message ?? null);
        $errorCode = $failure['error_code'] ?? ($metadata['delivery']['error_code'] ?? null);
        $stage = $failure['stage'] ?? ($metadata['delivery']['stage'] ?? ($success ? 'completed' : 'smtp_transport'));

        return [
            'success' => $success,
            'id' => (string) $row->id,
            'source_id' => (string) $row->source_id,
            'status' => (string) $row->status,
            'recipient_email' => (string) $row->recipient_email,
            'sent_at' => $row->sent_at,
            'last_attempt_at' => $row->last_attempt_at,
            'attempts' => (int) $row->attempts,
            'attachment_name' => $row->attachment_name,
            'attachment_sha256' => $row->attachment_sha256,
            'smtp' => $smtp,
            'stage' => $stage,
            'error_code' => $errorCode,
            'error_message' => $safeError ? $this->safeTransportText((string) $safeError) : null,
            'message' => $success
                ? 'Email slip berhasil diterima oleh server SMTP untuk dikirim.'
                : ($failure['message'] ?? 'SMTP HR gagal mengirim slip.'),
            'can_retry' => ! $success,
        ];
    }

    private function recipientForSlip(HrPayrollSlip $slip): ?string
    {
        $email = null;
        if (Schema::hasTable('HR_squads')) {
            $q = DB::table('HR_squads')->whereNull('deleted_at');
            if ($slip->user_id && Schema::hasColumn('HR_squads', 'user_id')) {
                $email = (clone $q)->where('user_id', (string) $slip->user_id)->value('email');
            }
            if (! $email && $slip->nisj_snapshot) {
                $email = (clone $q)->whereRaw('LOWER(TRIM(nisj)) = ?', [mb_strtolower(trim((string) $slip->nisj_snapshot))])->value('email');
            }
        }
        if (! $email && $slip->user_id) $email = DB::table('users')->where('id', (string) $slip->user_id)->value('email');
        return $this->validEmail($email);
    }

    private function recipientForBonusLine(object $line): ?string
    {
        $email = null; $userId = null;
        if (Schema::hasTable('HR_squads')) {
            $squad = $line->squad_id ? DB::table('HR_squads')->whereNull('deleted_at')->where('id', $line->squad_id)->first(['email', ...(Schema::hasColumn('HR_squads', 'user_id') ? ['user_id'] : [])]) : null;
            if ($squad) { $email = $squad->email ?? null; $userId = $squad->user_id ?? null; }
            if (! $email && $line->nisj_snapshot) {
                $squad = DB::table('HR_squads')->whereNull('deleted_at')->whereRaw('LOWER(TRIM(nisj)) = ?', [mb_strtolower(trim((string) $line->nisj_snapshot))])->first(['email', ...(Schema::hasColumn('HR_squads', 'user_id') ? ['user_id'] : [])]);
                if ($squad) { $email = $squad->email ?? null; $userId = $squad->user_id ?? $userId; }
            }
        }
        if (! $email && $userId) $email = DB::table('users')->where('id', (string) $userId)->value('email');
        if (! $email) {
            $userId = DB::table('employees')->where('id', (string) $line->employee_id)->value('user_id');
            if ($userId) $email = DB::table('users')->where('id', (string) $userId)->value('email');
        }
        return $this->validEmail($email);
    }

    private function validEmail(mixed $email): ?string
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if ($domain === '' || str_ends_with($domain, '.local')) return null;
        return $email;
    }

    private function configureMailer(): void
    {
        $smtp = $this->smtpContext();
        if (! $smtp['password_configured']) throw new RuntimeException('HR_MAIL_PASSWORD belum diisi pada environment backend.');

        config([
            'mail.mailers.hr' => [
                'transport' => 'smtp',
                'scheme' => $smtp['scheme'],
                'host' => $smtp['host'],
                'port' => $smtp['port'],
                'username' => $smtp['username'],
                'password' => (string) config('hr_mail.password'),
                'timeout' => $smtp['timeout'],
                'local_domain' => $smtp['local_domain'],
            ],
        ]);

        // Laravel MailManager caches mailers. I13 always purges the HR mailer after
        // writing runtime config so changes in scheme/host/credential are effective.
        $this->purgeMailer();
    }

    private function smtpContext(): array
    {
        $host = trim((string) config('hr_mail.host', 'mail.tokokopijaya.com'));
        $port = max(1, (int) config('hr_mail.port', 465));
        $rawScheme = strtolower(trim((string) config('hr_mail.scheme', 'smtps')));
        $scheme = $this->normalizeScheme($rawScheme, $port);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $localDomain = is_string($appHost) && $appHost !== '' ? $appHost : 'localhost';

        return [
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'security' => $scheme === 'smtps' ? 'Implicit TLS' : 'SMTP / STARTTLS',
            'username' => trim((string) config('hr_mail.username')),
            'from_address' => trim((string) config('hr_mail.from_address')),
            'timeout' => max(1, (int) config('hr_mail.timeout', 30)),
            'local_domain' => $localDomain,
            'password_configured' => trim((string) config('hr_mail.password')) !== '',
        ];
    }

    private function normalizeScheme(string $scheme, int $port): string
    {
        if ($scheme === 'ssl') return 'smtps';
        if ($scheme === 'tls') return 'smtp';
        if ($port === 465 && ($scheme === '' || $scheme === 'smtp')) return 'smtps';
        return in_array($scheme, ['smtp', 'smtps'], true) ? $scheme : ($port === 465 ? 'smtps' : 'smtp');
    }

    private function purgeMailer(): void
    {
        try {
            $manager = app('mail.manager');
            if (method_exists($manager, 'purge')) $manager->purge('hr');
        } catch (Throwable) {
            // Diagnostic hardening only; never mask the actual SMTP result.
        }
    }

    private function transportFailure(Throwable $e): array
    {
        $safe = $this->safeThrowable($e);
        $lower = strtolower($safe);
        $code = 'smtp_transport_error';
        $stage = 'smtp_transport';
        $message = 'SMTP HR gagal mengirim slip.';

        if (str_contains($lower, 'hr_mail_password') || str_contains($lower, 'password belum') || str_contains($lower, 'credential smtp hr belum lengkap')) {
            $code = 'password_missing'; $stage = 'configuration'; $message = 'Credential SMTP HR belum lengkap.';
        } elseif (str_contains($lower, 'authentication') || str_contains($lower, 'authenticator') || str_contains($lower, '535') || str_contains($lower, 'credentials')) {
            $code = 'smtp_authentication_failed'; $stage = 'authentication'; $message = 'Autentikasi SMTP ditolak oleh mail server.';
        } elseif (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            $code = 'smtp_timeout'; $stage = 'connection'; $message = 'Koneksi SMTP mengalami timeout.';
        } elseif (str_contains($lower, 'getaddrinfo') || str_contains($lower, 'name or service not known') || str_contains($lower, 'could not resolve')) {
            $code = 'smtp_dns_failed'; $stage = 'dns'; $message = 'Hostname SMTP tidak dapat di-resolve dari server aplikasi.';
        } elseif (str_contains($lower, 'connection refused') || str_contains($lower, 'could not connect') || str_contains($lower, 'connection could not be established')) {
            $code = 'smtp_connection_failed'; $stage = 'connection'; $message = 'Server aplikasi tidak dapat membuka koneksi ke SMTP HR.';
        } elseif (str_contains($lower, 'ssl') || str_contains($lower, 'tls') || str_contains($lower, 'certificate') || str_contains($lower, 'crypto')) {
            $code = 'smtp_tls_failed'; $stage = 'tls'; $message = 'Negosiasi TLS SMTP gagal.';
        } elseif (str_contains($lower, '550') || str_contains($lower, 'recipient') || str_contains($lower, 'mailbox')) {
            $code = 'smtp_recipient_rejected'; $stage = 'recipient'; $message = 'Mail server menolak alamat penerima.';
        }

        return ['error_code' => $code, 'stage' => $stage, 'message' => $message, 'safe_error' => $safe];
    }

    private function safeThrowable(Throwable $e): string
    {
        $messages = [];
        $cursor = $e;
        for ($i = 0; $i < 4 && $cursor; $i++) {
            $message = trim($cursor->getMessage());
            if ($message !== '' && ! in_array($message, $messages, true)) $messages[] = $message;
            $cursor = $cursor->getPrevious();
        }
        return $this->safeTransportText(implode(' | ', $messages));
    }

    private function safeTransportText(string $message): string
    {
        $message = trim($message);
        $password = (string) config('hr_mail.password');
        if ($password !== '') $message = str_replace($password, '[REDACTED]', $message);
        $message = preg_replace('#(smtp[s]?://[^:\s/@]+:)[^@\s/]+@#i', '$1[REDACTED]@', $message) ?? $message;
        $message = preg_replace('/\b(password|passwd|pwd)(\s*[=:]\s*)[^\s,;]+/i', '$1$2[REDACTED]', $message) ?? $message;
        $message = preg_replace('/\bAUTH\s+(PLAIN|LOGIN)\s+[^\s]+/i', 'AUTH $1 [REDACTED]', $message) ?? $message;
        return Str::limit($message, 1800);
    }

    private function metadata(?object $row): array
    {
        if (! $row || empty($row->metadata)) return [];
        $decoded = json_decode((string) $row->metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function mergeMetadata(?object $row, array $patch): array
    {
        return array_replace_recursive($this->metadata($row), $patch);
    }

    private function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains(strtolower($e->getMessage()), 'duplicate entry');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
