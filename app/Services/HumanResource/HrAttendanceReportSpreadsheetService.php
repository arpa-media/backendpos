<?php

namespace App\Services\HumanResource;

use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\ValidationException;

class HrAttendanceReportSpreadsheetService
{
    private const EXPORT_LIMIT = 50000;

    public function __construct(
        private readonly HrAttendanceReportService $reports,
        private readonly SimpleXlsxService $xlsx,
    ) {}

    public function daily(Request $request): Response
    {
        $payload = $this->all($request, 'daily');
        $items = collect($payload['items'] ?? []);
        $this->guardLimit($items->count());

        $rows = [[
            'Tanggal', 'Nama Squad', 'NISJ', 'PT', 'Penugasan', 'Jabatan',
            'Schedule', 'Shift', 'Start', 'End', 'Datang', 'Pulang',
            'Method Lokasi', 'Keterangan Lokasi', 'Terlambat (menit)',
            'Jam Kerja (menit)', 'Jam Kerja', 'Lembur (menit)', 'Lembur', 'Status Lembur',
            'Status Kalkulasi', 'Approval',
        ]];

        foreach ($items as $row) {
            $rows[] = [
                data_get($row, 'work_date', ''),
                data_get($row, 'full_name', ''),
                data_get($row, 'nisj', ''),
                data_get($row, 'company_code', ''),
                data_get($row, 'outlet_name', ''),
                data_get($row, 'position', ''),
                data_get($row, 'schedule_label', ''),
                data_get($row, 'shift_name', ''),
                data_get($row, 'start_time', ''),
                data_get($row, 'end_time', ''),
                data_get($row, 'checkin_time', ''),
                data_get($row, 'checkout_time', ''),
                data_get($row, 'location_method', ''),
                data_get($row, 'location_detail', ''),
                data_get($row, 'late_minutes', 0),
                data_get($row, 'work_minutes', 0),
                data_get($row, 'work_hours_label', ''),
                data_get($row, 'overtime_minutes', 0),
                data_get($row, 'overtime_hours_label', ''),
                data_get($row, 'overtime_status', ''),
                data_get($row, 'calculation_status_label', ''),
                data_get($row, 'approval_status', ''),
            ];
        }

        $date = preg_replace('/[^0-9-]/', '', (string) $request->query('date')) ?: now()->toDateString();
        return $this->xlsx->download("HR_DAILY_REPORT_{$date}.xlsx", 'DAILY_REPORT', $rows);
    }

    public function late(Request $request): Response
    {
        $payload = $this->all($request, 'late');
        $items = collect($payload['items'] ?? []);
        $this->guardLimit($items->count());

        $rows = [[
            'Tanggal', 'Nama Squad', 'NISJ', 'PT', 'Penugasan', 'Jabatan',
            'Shift', 'Start', 'Datang', 'Terlambat (menit)', 'Method Lokasi',
            'Keterangan Lokasi', 'Status Kalkulasi', 'Approval',
        ]];

        foreach ($items as $row) {
            $rows[] = [
                data_get($row, 'work_date', ''),
                data_get($row, 'full_name', ''),
                data_get($row, 'nisj', ''),
                data_get($row, 'company_code', ''),
                data_get($row, 'outlet_name', ''),
                data_get($row, 'position', ''),
                data_get($row, 'shift_name', ''),
                data_get($row, 'start_time', ''),
                data_get($row, 'checkin_time', ''),
                data_get($row, 'late_minutes', 0),
                data_get($row, 'location_method', ''),
                data_get($row, 'location_detail', ''),
                data_get($row, 'calculation_status_label', ''),
                data_get($row, 'approval_status', ''),
            ];
        }

        [$from, $to] = $this->rangeLabel($request);
        return $this->xlsx->download("HR_DATA_TERLAMBAT_{$from}_{$to}.xlsx", 'DATA_TERLAMBAT', $rows);
    }

    public function recap(Request $request): Response
    {
        $payload = $this->all($request, 'recap');
        $items = collect($payload['items'] ?? []);
        $this->guardLimit($items->count());
        $summary = (array) ($payload['summary'] ?? []);

        $rows = [[
            'Nama Squad', 'NISJ', 'PT', 'Penugasan', 'Jabatan', 'Jadwal Shift',
            'Hari Kerja', 'Jam Kerja (menit)', 'Jam Kerja', 'Lembur (menit)', 'Lembur', 'Terlambat (menit)',
            'Alpha', 'Unmapped', 'Pending Approval', 'Rejected', 'Belum Pulang',
            'Absen Hari OFF', 'Checkout Recovery',
        ]];

        foreach ($items as $row) {
            $rows[] = [
                data_get($row, 'full_name', ''),
                data_get($row, 'nisj', ''),
                data_get($row, 'company_code', ''),
                data_get($row, 'outlet_name', ''),
                data_get($row, 'position', ''),
                data_get($row, 'scheduled_shift_days', 0),
                data_get($row, 'work_days', 0),
                data_get($row, 'work_minutes', 0),
                data_get($row, 'work_hours_label', ''),
                data_get($row, 'overtime_minutes', 0),
                data_get($row, 'overtime_hours_label', ''),
                data_get($row, 'late_minutes', 0),
                data_get($row, 'alpha_days', 0),
                data_get($row, 'unmapped_attendance_days', 0),
                data_get($row, 'pending_exception_days', 0),
                data_get($row, 'rejected_exception_days', 0),
                data_get($row, 'incomplete_days', 0),
                data_get($row, 'off_attendance_days', 0),
                data_get($row, 'recovered_checkout_days', 0),
            ];
        }

        $summaryRows = [
            ['Metric', 'Nilai'],
            ['Squad', $summary['employees'] ?? 0],
            ['Baris Rekap', $summary['rows'] ?? $items->count()],
            ['Hari Kerja', $summary['work_days'] ?? 0],
            ['Jam Kerja (menit)', $summary['work_minutes'] ?? 0],
            ['Jam Kerja', $summary['work_hours_label'] ?? '0j 0m'],
            ['Lembur (menit)', $summary['overtime_minutes'] ?? 0],
            ['Lembur', $summary['overtime_hours_label'] ?? '0j 0m'],
            ['Terlambat (menit)', $summary['late_minutes'] ?? 0],
            ['Alpha', $summary['alpha_days'] ?? 0],
            ['Unmapped', $summary['unmapped_attendance_days'] ?? 0],
            ['Pending Approval', $summary['pending_exception_days'] ?? 0],
        ];

        [$from, $to] = $this->rangeLabel($request);
        return $this->xlsx->downloadWorkbook("HR_REKAP_ABSENSI_{$from}_{$to}.xlsx", [
            ['name' => 'REKAP_ABSENSI', 'rows' => $rows],
            ['name' => 'SUMMARY', 'rows' => $summaryRows],
        ]);
    }

    private function all(Request $request, string $method): array
    {
        $exportRequest = clone $request;
        $exportRequest->attributes->set('hr_attendance_report_export_all', true);
        return $this->reports->{$method}($exportRequest);
    }

    private function guardLimit(int $total): void
    {
        if ($total <= self::EXPORT_LIMIT) return;
        throw ValidationException::withMessages([
            'export' => ["Hasil export {$total} baris melebihi batas ".self::EXPORT_LIMIT.'. Persempit filter.'],
        ]);
    }

    private function rangeLabel(Request $request): array
    {
        $clean = fn (string $value): string => preg_replace('/[^0-9-]/', '', $value) ?: now()->toDateString();
        return [$clean((string) $request->query('from')), $clean((string) $request->query('to'))];
    }
}
