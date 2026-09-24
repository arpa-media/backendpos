<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class HrPunishmentRecapXlsxService
{
    public function download(string $filename, array $preview): Response
    {
        $binary = $this->build($preview);
        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function build(array $preview): string
    {
        $template = storage_path('app/hr/recap-i04/REKAP SP 2026.xlsx');
        if (! is_file($template)) {
            throw new InvalidArgumentException('Template REKAP SP 2026.xlsx Iterasi 04 tidak ditemukan.');
        }

        $files = $this->readPackage($template);
        foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $required) {
            if (! isset($files[$required])) throw new InvalidArgumentException('Struktur template Rekap SP tidak valid: '.$required.' tidak ditemukan.');
        }

        $periodLabel = trim((string) ($preview['period_label'] ?? $preview['period'] ?? ''));
        $files['xl/worksheets/sheet1.xml'] = $this->rewriteSheet(
            $files['xl/worksheets/sheet1.xml'],
            (array) ($preview['active'] ?? []),
            $periodLabel,
            true,
        );
        $files['xl/worksheets/sheet2.xml'] = $this->rewriteSheet(
            $files['xl/worksheets/sheet2.xml'],
            (array) ($preview['inactive'] ?? []),
            $periodLabel,
            false,
        );

        return $this->buildZip($files);
    }

    private function rewriteSheet(string $xml, array $rows, string $periodLabel, bool $active): string
    {
        $headerRows = [];
        for ($i = 1; $i <= 6; $i++) {
            $headerRows[$i] = $this->extractRow($xml, $i) ?? '<row r="'.$i.'"/>';
        }

        $periodStyle = $active ? 6 : 1;
        $headerRows[4] = '<row r="4" ht="14.25" customHeight="1">'.$this->textCell('A4', $periodStyle, 'PERIODE : '.$periodLabel).'</row>';
        if ($active) {
            $headerRows[6] = $this->stripCells($headerRows[6], ['X6']);
        }

        $sheetData = implode('', $headerRows);
        $rowCount = count($rows);
        $lastRow = max(9, 6 + $rowCount);
        for ($excelRow = 7; $excelRow <= $lastRow; $excelRow++) {
            $data = $rows[$excelRow - 7] ?? null;
            $sheetData .= $this->dataRow($excelRow, is_array($data) ? $data : null, $active);
        }

        $replacement = '<sheetData>'.$sheetData.'</sheetData>';
        if (! preg_match('/<(?:[A-Za-z0-9_]+:)?sheetData\b[^>]*>.*?<\/(?:[A-Za-z0-9_]+:)?sheetData>/is', $xml)) {
            throw new InvalidArgumentException('Template Rekap SP tidak memiliki sheetData.');
        }
        $xml = preg_replace('/<(?:[A-Za-z0-9_]+:)?sheetData\b[^>]*>.*?<\/(?:[A-Za-z0-9_]+:)?sheetData>/is', $replacement, $xml, 1) ?? $xml;

        // Template Google Sheets tidak selalu memiliki dimension. Jika ada, sesuaikan agar aplikasi spreadsheet tidak mengira range hanya sampai sample lama.
        $maxColumn = $active ? 'X' : 'R';
        if (preg_match('/<(?:[A-Za-z0-9_]+:)?dimension\b[^>]*ref="[^"]*"[^>]*\/?>/i', $xml)) {
            $xml = preg_replace('/<(?:[A-Za-z0-9_]+:)?dimension\b[^>]*ref="[^"]*"[^>]*\/?>/i', '<dimension ref="A1:'.$maxColumn.$lastRow.'"/>', $xml, 1) ?? $xml;
        }
        return $xml;
    }

    private function dataRow(int $rowNumber, ?array $data, bool $active): string
    {
        $cells = '';
        if ($data) {
            if ($active) {
                $styles = [
                    'A' => 29, 'B' => 24, 'C' => 23, 'D' => 23, 'E' => 23, 'F' => 24, 'G' => 24, 'H' => 23,
                    'I' => 23, 'J' => 23, 'K' => 24, 'L' => 26, 'M' => 23, 'N' => 23, 'O' => 26, 'P' => 26,
                    'Q' => 23, 'R' => 26,
                ];
                $values = [
                    'A' => ['n', $rowNumber - 6],
                    'B' => ['s', $data['letter_no'] ?? ''],
                    'C' => ['s', $data['type'] ?? 'SP'],
                    'D' => ['s', $data['previous_level'] ?? ''],
                    'E' => ['s', $data['current_level'] ?? ''],
                    'F' => ['s', $data['nisj'] ?? ''],
                    'G' => ['s', $data['full_name'] ?? ''],
                    'H' => ['s', $data['division'] ?? ''],
                    'I' => ['s', $data['supervisor_nisj'] ?? ''],
                    'J' => ['s', $data['supervisor_name'] ?? ''],
                    'K' => ['s', $data['mistake'] ?? ''],
                    'L' => ['d', $data['incident_date'] ?? null],
                    'M' => ['s', $data['chamber'] ?? ''],
                    'N' => ['s', $data['approved_by'] ?? ''],
                    'O' => ['d', $data['validity_start'] ?? null],
                    'P' => ['d', $data['validity_end'] ?? null],
                    'Q' => ['s', $data['releaser'] ?? ''],
                    'R' => ['s', $data['status'] ?? 'AKTIF'],
                ];
            } else {
                $styles = [
                    'A' => 27, 'B' => 18, 'C' => 19, 'D' => 19, 'E' => 19, 'F' => 18, 'G' => 18, 'H' => 19,
                    'I' => 19, 'J' => 19, 'K' => 18, 'L' => 25, 'M' => 25, 'N' => 19, 'O' => 25, 'P' => 19,
                    'Q' => 19, 'R' => 19,
                ];
                $values = [
                    'A' => ['n', $rowNumber - 6],
                    'B' => ['s', $data['letter_no'] ?? ''],
                    'C' => ['s', $data['type'] ?? 'SP'],
                    'D' => ['s', $data['previous_level'] ?? ''],
                    'E' => ['s', $data['current_level'] ?? ''],
                    'F' => ['s', $data['nisj'] ?? ''],
                    'G' => ['s', $data['full_name'] ?? ''],
                    'H' => ['s', $data['division'] ?? ''],
                    'I' => ['s', $data['supervisor_nisj'] ?? ''],
                    'J' => ['s', $data['supervisor_name'] ?? ''],
                    'K' => ['s', $data['mistake'] ?? ''],
                    'L' => ['d', $data['validity_start'] ?? null],
                    'M' => ['d', $data['validity_end'] ?? null],
                    'N' => ['s', $data['chamber'] ?? ''],
                    'O' => ['d', $data['incident_date'] ?? null],
                    'P' => ['s', $data['approved_by'] ?? ''],
                    'Q' => ['s', $data['releaser'] ?? ''],
                    'R' => ['s', $data['status'] ?? 'NON AKTIF'],
                ];
            }

            foreach ($values as $column => [$type, $value]) {
                $ref = $column.$rowNumber;
                $style = $styles[$column];
                if ($type === 'n') $cells .= $this->numberCell($ref, $style, $value);
                elseif ($type === 'd') $cells .= $this->dateCell($ref, $style, $value);
                else $cells .= $this->textCell($ref, $style, (string) ($value ?? ''));
            }
        }

        if ($active) {
            $rule = match ($rowNumber) {
                7 => ['SP 2', 60],
                8 => ['SP 3', 90],
                9 => ['PHK', 'ACTIVE DATE'],
                default => null,
            };
            if ($rule) {
                $cells .= $this->textCell('U'.$rowNumber, 21, (string) $rule[0]);
                $cells .= is_numeric($rule[1])
                    ? $this->numberCell('V'.$rowNumber, 21, $rule[1])
                    : $this->textCell('V'.$rowNumber, 33, (string) $rule[1]);
            }
        }

        return '<row r="'.$rowNumber.'" ht="14.25" customHeight="1">'.$cells.'</row>';
    }

    private function extractRow(string $xml, int $rowNumber): ?string
    {
        if (preg_match('/<row\b[^>]*\br="'.$rowNumber.'"[^>]*>.*?<\/row>/is', $xml, $match)) return $match[0];
        if (preg_match('/<row\b[^>]*\br="'.$rowNumber.'"[^>]*\/\s*>/is', $xml, $match)) return $match[0];
        return null;
    }

    private function stripCells(string $rowXml, array $refs): string
    {
        foreach ($refs as $ref) {
            $quoted = preg_quote($ref, '/');
            $rowXml = preg_replace('/<c\b[^>]*\br="'.$quoted.'"[^>]*(?:\/\s*>|>.*?<\/c>)/is', '', $rowXml) ?? $rowXml;
        }
        return $rowXml;
    }

    private function textCell(string $ref, int $style, string $value): string
    {
        $safe = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$safe.'</t></is></c>';
    }

    private function numberCell(string $ref, int $style, int|float|string|null $value): string
    {
        $number = is_numeric($value) ? (string) (0 + $value) : '0';
        return '<c r="'.$ref.'" s="'.$style.'"><v>'.$number.'</v></c>';
    }

    private function dateCell(string $ref, int $style, mixed $value): string
    {
        if (! $value) return $this->textCell($ref, $style, '');
        try {
            $date = Carbon::parse((string) $value, 'Asia/Jakarta')->startOfDay();
            $serial = Carbon::create(1899, 12, 30, 0, 0, 0, 'Asia/Jakarta')->diffInDays($date, false);
            return $this->numberCell($ref, $style, $serial);
        } catch (\Throwable) {
            return $this->textCell($ref, $style, (string) $value);
        }
    }

    /** @return array<string,string> */
    private function readPackage(string $path): array
    {
        $binary = @file_get_contents($path);
        if ($binary === false) throw new InvalidArgumentException('Template XLSX tidak dapat dibaca.');
        $eocdOffset = strrpos($binary, "PK\x05\x06");
        if ($eocdOffset === false) throw new InvalidArgumentException('Struktur ZIP template XLSX tidak valid.');
        $eocd = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLength', substr($binary, $eocdOffset, 22));
        $entries = [];
        $cursor = (int) $eocd['cdOffset'];
        for ($i = 0; $i < (int) $eocd['totalEntries']; $i++) {
            $header = unpack('Vsig/vversionMade/vversionNeeded/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset', substr($binary, $cursor, 46));
            if (($header['sig'] ?? null) !== 0x02014b50) throw new InvalidArgumentException('Central directory template XLSX tidak valid.');
            $name = substr($binary, $cursor + 46, $header['nameLength']);
            $cursor += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
            $local = unpack('Vsig/vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', substr($binary, $header['localOffset'], 30));
            if (($local['sig'] ?? null) !== 0x04034b50) continue;
            $start = $header['localOffset'] + 30 + $local['nameLength'] + $local['extraLength'];
            $compressed = substr($binary, $start, $header['compressedSize']);
            if ((int) $header['method'] === 0) $entries[$name] = $compressed;
            elseif ((int) $header['method'] === 8) {
                $inflated = @gzinflate($compressed);
                if ($inflated === false) throw new InvalidArgumentException('Data template XLSX terkompresi tidak dapat dibaca.');
                $entries[$name] = $inflated;
            }
        }
        return $entries;
    }

    private function buildZip(array $files): string
    {
        $data = '';
        $central = '';
        $offset = 0;
        foreach ($files as $name => $content) {
            $name = str_replace('\\', '/', $name);
            $crc = crc32($content);
            $size = strlen($content);
            $nameLength = strlen($name);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0).$name;
            $data .= $local.$content;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset).$name;
            $offset += strlen($local) + $size;
        }
        return $data.$central.pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }
}
