<?php

namespace App\Support\HumanResource;

/** V13 I06: pure XLSX layout; datetimes are already localized by HrAttendancePresentationService. */
final class HrAttendanceV13I06ExportFormatter
{
    public static function headings(): array
    {
        return [
            'Tanggal', 'Nama Squad', 'NISJ', 'Tipe Penugasan', 'Kode Penugasan', 'Penugasan',
            'Kode Shift', 'Tanggal Datang', 'Jam Datang', 'Foto Datang', 'Mode Datang',
            'Lokasi Datang', 'Jarak Datang (m)', 'Kamera Datang',
            'Tanggal Pulang', 'Jam Pulang', 'Foto Pulang', 'Mode Pulang',
            'Lokasi Pulang', 'Jarak Pulang (m)', 'Kamera Pulang',
            'Status Record', 'Approval', 'Terlambat (menit)', 'Jam Kerja (menit)',
            'Status Kalkulasi', 'Exception',
        ];
    }

    public static function row(array $row): array
    {
        [$checkinDate, $checkinTime] = self::splitLocalDatetime(data_get($row, 'checkin.at'));
        [$checkoutDate, $checkoutTime] = self::splitLocalDatetime(data_get($row, 'checkout.at'));

        return [
            $row['business_date'] ?? '',
            data_get($row, 'employee.full_name', ''),
            data_get($row, 'employee.nisj', ''),
            strtoupper((string) data_get($row, 'assignment_outlet.type', 'OUTLET')),
            data_get($row, 'assignment_outlet.code', ''),
            data_get($row, 'assignment_outlet.name', ''),
            $row['shift_code'] ?? '-',
            $checkinDate,
            $checkinTime,
            data_get($row, 'checkin.photo_url', ''),
            data_get($row, 'checkin.mode', ''),
            data_get($row, 'checkin.location_status', ''),
            data_get($row, 'checkin.distance_m', ''),
            data_get($row, 'checkin.camera_status', ''),
            $checkoutDate,
            $checkoutTime,
            data_get($row, 'checkout.photo_url', ''),
            data_get($row, 'checkout.mode', ''),
            data_get($row, 'checkout.location_status', ''),
            data_get($row, 'checkout.distance_m', ''),
            data_get($row, 'checkout.camera_status', ''),
            $row['record_status'] ?? '',
            $row['approval_status'] ?? '',
            $row['late_minutes'] ?? '',
            $row['work_minutes'] ?? '',
            $row['calculation_status'] ?? '',
            implode(', ', $row['exception_flags'] ?? []),
        ];
    }

    /** No timezone conversion here: it was done once by the presentation service. */
    public static function splitLocalDatetime(mixed $local): array
    {
        if (! is_string($local) || ! preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/D', trim($local), $m)) {
            return ['', ''];
        }
        return [$m[1], $m[2]];
    }
}
