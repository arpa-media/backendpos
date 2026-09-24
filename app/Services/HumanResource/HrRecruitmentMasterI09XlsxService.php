<?php

namespace App\Services\HumanResource;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class HrRecruitmentMasterI09XlsxService
{
    private const TEMPLATE = 'hr/templates/i09/Template_REKRUTMEN_Toko Kopi Jaya.xlsx';
    private const MASTER_SHEET = 'xl/worksheets/sheet3.xml';
    private const HEADERS = [
        'NO','BULAN','NAMA LENGKAP','BERJAYA DI','INTERVIEW','NO HP','POSISI KERJA YG DILAMAR',
        'UNDANG INTERVIEW TGL BERAPA','KEHADIRAN 1','TGL RESCHEDULE','KEHADIRAN 2','KEHADIRAN FINAL','HASIL INTERVIEW','DAPAT INFO LOKER DARI MANA',
        'UNDANG PRACTICAL TGL BERAPA','KEHADIRAN 1','TGL RESCHEDULE','KEHADIRAN 2','KEHADIRAN FINAL','HASIL PRACTICAL','PENEMPATAN','JOIN DATE',
        'TGL ON BOARDING','KEHADIRAN 1','TGL RESCHEDULE','KEHADIRAN 2','KEHADIRAN FINAL ON BOARDING','NEW SQUAD SELAMA 2 BULAN','KETERANGAN',
    ];

    public function download(string $filename, array $rows): Response
    {
        $binary = $this->build($rows);
        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function build(array $rows): string
    {
        $template = storage_path('app/'.self::TEMPLATE);
        if (! is_file($template)) throw new InvalidArgumentException('Template Recruitment I09 tidak ditemukan.');

        $files = $this->readPackage($template);
        if (! isset($files[self::MASTER_SHEET])) throw new InvalidArgumentException('Sheet master Recruitment I09 tidak ditemukan.');

        $xml = $files[self::MASTER_SHEET];
        $styles = $this->styleMap($xml);
        $sheetData = $this->sheetData($rows, $styles);
        $xml = $this->replaceSheetData($xml, $sheetData);
        $lastRow = max(1, count($rows) + 1);
        $xml = preg_replace('/<dimension\s+ref="[^"]+"\s*\/>/', '<dimension ref="A1:AC'.$lastRow.'"/>', $xml, 1) ?: $xml;
        $xml = preg_replace('/<autoFilter\s+ref="[^"]+"([^>]*)\/>/', '<autoFilter ref="A1:AC'.$lastRow.'"$1/>', $xml, 1) ?: $xml;
        $files[self::MASTER_SHEET] = $xml;

        foreach (['xl/pivotCache/pivotCacheDefinition1.xml', 'xl/pivotCache/pivotCacheDefinition2.xml'] as $entry) {
            if (isset($files[$entry])) $files[$entry] = $this->refreshPivot($files[$entry], $lastRow, count($rows));
        }
        foreach (['xl/pivotCache/pivotCacheRecords1.xml', 'xl/pivotCache/pivotCacheRecords2.xml'] as $entry) {
            if (isset($files[$entry])) $files[$entry] = $this->refreshPivotRecords($files[$entry]);
        }
        if (isset($files['xl/workbook.xml'])) $files['xl/workbook.xml'] = $this->updateWorkbookDefinedName($files['xl/workbook.xml'], $lastRow);

        return $this->buildZip($files);
    }

    private function sheetData(array $rows, array $styles): string
    {
        $out = '<sheetData>';
        $out .= '<row r="1" spans="1:29">';
        foreach (self::HEADERS as $i => $value) {
            $out .= $this->inlineCell($this->col($i + 1).'1', $value, $styles['header'][$i + 1] ?? null);
        }
        $out .= '</row>';

        foreach (array_values($rows) as $idx => $row) {
            $r = $idx + 2;
            $values = [
                $idx + 1,
                $row['month'] ?? '-', $row['name'] ?? '-', $row['region'] ?? '-', $row['interview_mode'] ?? '-', $row['phone'] ?? '-', $row['position'] ?? '-',
                $row['interview_invite_1'] ?? null, $row['interview_presence_1'] ?? '-', $row['interview_reschedule'] ?? null, $row['interview_presence_2'] ?? '-', $row['interview_presence_final'] ?? '-', $row['interview_result'] ?? '-', $row['job_source'] ?? '-',
                $row['practical_invite_1'] ?? null, $row['practical_presence_1'] ?? '-', $row['practical_reschedule'] ?? null, $row['practical_presence_2'] ?? '-', $row['practical_presence_final'] ?? '-', $row['practical_result'] ?? '-', $row['placement'] ?? '-', $row['join_date'] ?? null,
                $row['onboarding_date_1'] ?? null, $row['onboarding_presence_1'] ?? '-', $row['onboarding_reschedule'] ?? null, $row['onboarding_presence_2'] ?? '-', $row['onboarding_presence_final'] ?? '-', $row['new_squad_2_months'] ?? '-', $row['notes'] ?? '-',
            ];
            $out .= '<row r="'.$r.'" spans="1:29">';
            foreach ($values as $i => $value) {
                $column = $i + 1;
                $ref = $this->col($column).$r;
                $style = $styles['data'][$column] ?? null;
                if ($column === 1) $out .= $this->numberCell($ref, (int) $value, $style);
                elseif (in_array($column, [8,10,15,17,22,23,25], true)) $out .= $this->dateCell($ref, $value, $style);
                else $out .= $this->inlineCell($ref, (string) ($value ?? ''), $style);
            }
            $out .= '</row>';
        }
        return $out.'</sheetData>';
    }

    private function styleMap(string $xml): array
    {
        $maps = ['header' => [], 'data' => []];
        foreach ([1 => 'header', 2 => 'data'] as $row => $bucket) {
            if (! preg_match('/<row\b[^>]*\br="'.$row.'"[^>]*>(.*?)<\/row>/s', $xml, $match)) continue;
            if (! preg_match_all('/<c\b([^>]*)\br="([A-Z]+)'.$row.'"([^>]*)>.*?<\/c>/s', $match[1], $cells, PREG_SET_ORDER)) continue;
            foreach ($cells as $cell) {
                $attrs = $cell[1].' '.$cell[3];
                if (! preg_match('/\bs="(\d+)"/', $attrs, $styleMatch)) continue;
                $maps[$bucket][$this->colNumber($cell[2])] = (int) $styleMatch[1];
            }
        }
        return $maps;
    }

    private function inlineCell(string $ref, string $value, ?int $style): string
    {
        $styleAttribute = $style !== null ? ' s="'.$style.'"' : '';
        return '<c r="'.$ref.'"'.$styleAttribute.' t="inlineStr"><is><t xml:space="preserve">'.$this->esc($value).'</t></is></c>';
    }

    private function numberCell(string $ref, int|float $value, ?int $style): string
    {
        $styleAttribute = $style !== null ? ' s="'.$style.'"' : '';
        return '<c r="'.$ref.'"'.$styleAttribute.'><v>'.$value.'</v></c>';
    }

    private function dateCell(string $ref, mixed $value, ?int $style): string
    {
        if (! is_string($value) || trim($value) === '') return $this->inlineCell($ref, '', $style);
        try {
            $timezone = new \DateTimeZone('Asia/Jakarta');
            $date = (new \DateTimeImmutable($value, $timezone))->setTime(0, 0, 0);
            $base = new \DateTimeImmutable('1899-12-30 00:00:00', $timezone);
            $serial = (int) $base->diff($date)->format('%r%a');
            return $this->numberCell($ref, $serial, $style);
        } catch (\Throwable) {
            return $this->inlineCell($ref, (string) $value, $style);
        }
    }

    private function refreshPivot(string $xml, int $lastRow, int $recordCount): string
    {
        $xml = preg_replace('/worksheetSource\s+ref="A1:AC\d+"/', 'worksheetSource ref="A1:AC'.$lastRow.'"', $xml) ?: $xml;
        $xml = preg_replace('/recordCount="\d+"/', 'recordCount="'.$recordCount.'"', $xml, 1) ?: $xml;
        if (str_contains($xml, 'refreshOnLoad=')) {
            $xml = preg_replace('/refreshOnLoad="[01]"/', 'refreshOnLoad="1"', $xml, 1) ?: $xml;
        } else {
            $xml = preg_replace('/<pivotCacheDefinition\b/', '<pivotCacheDefinition refreshOnLoad="1"', $xml, 1) ?: $xml;
        }
        return $xml;
    }

    private function refreshPivotRecords(string $xml): string
    {
        if (! preg_match('/<pivotCacheRecords\b([^>]*)>/s', $xml, $match)) return $xml;
        $attributes = preg_replace('/\s+count="\d+"/', '', $match[1]) ?? $match[1];
        $open = '<pivotCacheRecords'.$attributes.' count="0">';
        return preg_replace('/<pivotCacheRecords\b[^>]*>.*<\/pivotCacheRecords>/s', $open.'</pivotCacheRecords>', $xml, 1) ?: $xml;
    }

    private function updateWorkbookDefinedName(string $xml, int $lastRow): string
    {
        $pattern = "~('MASTER DATA REKRUTMEN INTW\\+PRAC'!\\\$A\\\$1:)\\\$FV\\\$\\d+~";
        return preg_replace($pattern, '$1\\$AC\\$'.$lastRow, $xml, 1) ?: $xml;
    }

    private function replaceSheetData(string $xml, string $replacement): string
    {
        if (! preg_match('/<(?:[A-Za-z0-9_]+:)?sheetData\b[^>]*>.*?<\/(?:[A-Za-z0-9_]+:)?sheetData>/is', $xml)) {
            throw new InvalidArgumentException('Struktur sheetData template Recruitment I09 tidak valid.');
        }
        return preg_replace('/<(?:[A-Za-z0-9_]+:)?sheetData\b[^>]*>.*?<\/(?:[A-Za-z0-9_]+:)?sheetData>/is', $replacement, $xml, 1) ?? $xml;
    }

    /** @return array<string,string> */
    private function readPackage(string $path): array
    {
        $binary = @file_get_contents($path);
        if ($binary === false) throw new InvalidArgumentException('Template Recruitment I09 tidak dapat dibaca.');
        $eocdOffset = strrpos($binary, "PK\x05\x06");
        if ($eocdOffset === false) throw new InvalidArgumentException('Struktur ZIP template Recruitment I09 tidak valid.');
        $eocd = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLength', substr($binary, $eocdOffset, 22));
        if (! is_array($eocd)) throw new InvalidArgumentException('EOCD template Recruitment I09 tidak valid.');

        $entries = [];
        $cursor = (int) $eocd['cdOffset'];
        for ($i = 0; $i < (int) $eocd['totalEntries']; $i++) {
            $header = unpack('Vsig/vversionMade/vversionNeeded/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset', substr($binary, $cursor, 46));
            if (! is_array($header) || ($header['sig'] ?? null) !== 0x02014b50) throw new InvalidArgumentException('Central directory template Recruitment I09 tidak valid.');
            $name = substr($binary, $cursor + 46, $header['nameLength']);
            $cursor += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
            $local = unpack('Vsig/vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', substr($binary, $header['localOffset'], 30));
            if (! is_array($local) || ($local['sig'] ?? null) !== 0x04034b50) continue;
            $start = $header['localOffset'] + 30 + $local['nameLength'] + $local['extraLength'];
            $compressed = substr($binary, $start, $header['compressedSize']);
            if ((int) $header['method'] === 0) $entries[$name] = $compressed;
            elseif ((int) $header['method'] === 8) {
                $inflated = @gzinflate($compressed);
                if ($inflated === false) throw new InvalidArgumentException('Data template Recruitment I09 terkompresi tidak dapat dibaca.');
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

    private function col(int $number): string
    {
        $result = '';
        while ($number > 0) {
            $number--;
            $result = chr(65 + ($number % 26)).$result;
            $number = intdiv($number, 26);
        }
        return $result;
    }

    private function colNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $char) $number = $number * 26 + (ord($char) - 64);
        return $number;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
