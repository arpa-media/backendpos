<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class HrContractLifecycleXlsxService
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
        $template = storage_path('app/hr/contract-i05/REKAP KONTRAK SPT PKWT 2026.xlsx');
        if (! is_file($template)) throw new InvalidArgumentException('Template REKAP KONTRAK SPT PKWT 2026.xlsx Iterasi 05 tidak ditemukan.');

        $files = $this->readPackage($template);
        if (! isset($files['xl/worksheets/sheet1.xml'])) throw new InvalidArgumentException('Worksheet 2026 tidak ditemukan pada template kontrak.');

        $rows = (array) ($preview['rows'] ?? []);
        $lastRow = max(11, 10 + count($rows));
        $files['xl/worksheets/sheet1.xml'] = $this->rewriteSheet($files['xl/worksheets/sheet1.xml'], $rows, $lastRow);
        if (isset($files['xl/workbook.xml'])) {
            $files['xl/workbook.xml'] = str_replace("'2026'!\$A\$10:\$R\$600", "'2026'!\$A\$10:\$AZ\$".$lastRow, $files['xl/workbook.xml']);
        }
        return $this->buildZip($files);
    }

    private function rewriteSheet(string $xml, array $rows, int $lastRow): string
    {
        $sheetData = '<sheetData>'.$this->headerRows();
        foreach ($rows as $index => $row) {
            $sheetData .= $this->dataRow(11 + $index, $index + 1, is_array($row) ? $row : []);
        }
        if ($rows === []) $sheetData .= '<row r="11" ht="15" customHeight="1"/>';
        $sheetData .= '</sheetData>';

        $xml = $this->replaceXmlBlock($xml, 'sheetData', $sheetData, required: true);

        $mergeXml = '<mergeCells count="7">'
            .'<mergeCell ref="F1:L9"/><mergeCell ref="M1:S9"/><mergeCell ref="T1:Z9"/>'
            .'<mergeCell ref="AA1:AG9"/><mergeCell ref="AH1:AN9"/><mergeCell ref="AO1:AU9"/><mergeCell ref="AV1:AZ9"/>'
            .'</mergeCells>';
        $replacedMerge = $this->replaceXmlBlock($xml, 'mergeCells', $mergeXml, required: false);
        if ($replacedMerge === $xml) $xml = str_replace('</worksheet>', $mergeXml.'</worksheet>', $xml);
        else $xml = $replacedMerge;

        $filter = '<autoFilter ref="$A$10:$AZ$'.$lastRow.'"/>';
        $xml = $this->replaceXmlBlock($xml, 'autoFilter', $filter, required: false, allowSelfClosing: true);

        if (str_contains($xml, '</cols>') && ! preg_match('/<col\b[^>]*\bmax="5[12]"/i', $xml)) {
            $xml = str_replace('</cols>', '<col customWidth="1" min="51" max="52" width="29.43"/></cols>', $xml);
        }
        if (preg_match('/<(?:[A-Za-z0-9_]+:)?dimension\b[^>]*ref="[^"]*"[^>]*\/?>/i', $xml)) {
            $xml = preg_replace('/<(?:[A-Za-z0-9_]+:)?dimension\b[^>]*ref="[^"]*"[^>]*\/?>/i', '<dimension ref="A1:AZ'.$lastRow.'"/>', $xml, 1) ?? $xml;
        }
        return $xml;
    }


    private function replaceXmlBlock(string $xml, string $tag, string $replacement, bool $required = false, bool $allowSelfClosing = false): string
    {
        $start = strpos($xml, '<'.$tag);
        if ($start === false) {
            if ($required) throw new InvalidArgumentException('Template kontrak tidak memiliki '.$tag.'.');
            return $xml;
        }
        $openEnd = strpos($xml, '>', $start);
        if ($openEnd === false) {
            if ($required) throw new InvalidArgumentException('Elemen '.$tag.' pada template kontrak tidak valid.');
            return $xml;
        }
        if ($allowSelfClosing && substr($xml, max($start, $openEnd - 1), 2) === '/>') {
            return substr($xml, 0, $start).$replacement.substr($xml, $openEnd + 1);
        }
        $close = '</'.$tag.'>';
        $end = strpos($xml, $close, $openEnd + 1);
        if ($end === false) {
            if ($required) throw new InvalidArgumentException('Penutup '.$tag.' pada template kontrak tidak ditemukan.');
            return $xml;
        }
        $end += strlen($close);
        return substr($xml, 0, $start).$replacement.substr($xml, $end);
    }

    private function headerRows(): string
    {
        $rows = '';
        $rows .= '<row r="1" ht="15" customHeight="1">'
            .$this->textCell('A1', 1, 'HARI').$this->textCell('B1', 1, 'KET').$this->textCell('C1', 1, 'ISI')
            .$this->textCell('F1', 3, 'SPT').$this->textCell('M1', 4, 'PKWT 1').$this->textCell('T1', 5, 'PKWT 2')
            .$this->textCell('AA1', 6, 'PKWT 3').$this->textCell('AH1', 7, 'PKWT 4').$this->textCell('AO1', 7, 'PKWT 5').$this->textCell('AV1', 8, 'PKWTT')
            .'</row>';
        $rows .= '<row r="2" ht="15" customHeight="1">'.$this->numberCell('A2', 10, 61).$this->textCell('B2', 11, 'DURASI SPT SQUAD 2 BULAN (hari)').$this->textCell('C2', 12, 'SPT SQUAD').'</row>';
        $rows .= '<row r="3" ht="15" customHeight="1">'.$this->numberCell('A3', 13, 92).$this->textCell('B3', 11, 'DURASI SPT MANAGEMENT 3 BULAN (hari)').$this->textCell('C3', 12, 'SPT MGMT').'</row>';
        $rows .= '<row r="4" ht="15" customHeight="1">'.$this->numberCell('A4', 13, 184).$this->textCell('B4', 11, 'DURASI PKWT FINANCE 6 BULAN (hari)').$this->textCell('C4', 12, 'PKWT FIN').'</row>';
        $rows .= '<row r="5" ht="15" customHeight="1">'.$this->numberCell('A5', 10, 365).$this->textCell('B5', 11, 'DURASI PKWT - 1 TAHUN (hari)').$this->textCell('C5', 12, 'PKWT').'</row>';
        $rows .= '<row r="6" ht="15" customHeight="1"/>';
        $rows .= '<row r="7" ht="15" customHeight="1">'.$this->textCell('A7', 15, '- yang diisikan disini adalah to-do-list kontrak').'</row>';
        $rows .= '<row r="8" ht="15" customHeight="1">'.$this->textCell('A8', 15, '- diupdate setiap bulannya u/ sheet bulan berikutnya').'</row>';
        $rows .= '<row r="9" ht="15" customHeight="1"/>';

        $headers = [
            'A' => [16, 'NO'], 'B' => [17, 'JABATAN'], 'C' => [16, 'OUTLET'], 'D' => [16, 'NAMA LENGKAP'], 'E' => [16, 'DIVISI'],
        ];
        foreach ($headers as $col => [$style, $label]) $cells[] = $this->textCell($col.'10', $style, $label);
        $cells = $cells ?? [];
        $this->appendFiniteHeader($cells, ['F','G','H','I','J','K','L'], [18,18,18,18,17,19,20], true);
        $this->appendFiniteHeader($cells, ['M','N','O','P','Q','R','S'], [21,22,22,21,17,19,20], false);
        $this->appendFiniteHeader($cells, ['T','U','V','W','X','Y','Z'], [23,24,24,23,17,19,20], false);
        $this->appendFiniteHeader($cells, ['AA','AB','AC','AD','AE','AF','AG'], [25,26,26,25,17,19,20], false);
        $this->appendFiniteHeader($cells, ['AH','AI','AJ','AK','AL','AM','AN'], [25,26,26,25,17,19,20], false);
        $this->appendFiniteHeader($cells, ['AO','AP','AQ','AR','AS','AT','AU'], [25,26,26,25,17,19,20], false);
        foreach ([
            ['AV',25,'JENIS KONTRAK'], ['AW',26,'START KONTRAK'], ['AX',17,'LV / GAJI'], ['AY',19,'NOMINAL FEE'], ['AZ',20,'STATUS'],
        ] as [$col,$style,$label]) $cells[] = $this->textCell($col.'10', $style, $label);
        $rows .= '<row r="10" ht="33" customHeight="1">'.implode('', $cells).'</row>';
        return $rows;
    }

    private function appendFiniteHeader(array &$cells, array $cols, array $styles, bool $spt): void
    {
        $labels = $spt
            ? ['JENIS KONTRAK', 'JOIN', 'SELESAI SPT', 'PERBARUAN KONTRAK', 'LV / GAJI', 'NOMINAL FEE', 'STATUS']
            : ['JENIS KONTRAK', 'START KONTRAK', 'SELESAI KONTRAK', 'PERBARUAN KONTRAK', 'LV / GAJI', 'NOMINAL FEE', 'STATUS'];
        foreach ($cols as $i => $col) $cells[] = $this->textCell($col.'10', $styles[$i], $labels[$i]);
    }

    private function dataRow(int $excelRow, int $number, array $row): string
    {
        $cells = '';
        $cells .= $this->numberCell('A'.$excelRow, 27, $number);
        $assignment = strtoupper(trim((string) ($row['assignment_label'] ?? '')));
        $jabatan = match ($assignment) {
            'OUTLET' => 'SQUAD',
            'MANAGEMENT' => 'MANAGEMENT',
            'WAREHOUSE' => 'WAREHOUSE',
            default => (string) ($row['lifecycle_group'] ?? $assignment),
        };
        // Ikuti workbook referensi: kolom B berisi kelompok penempatan
        // (SQUAD/MANAGEMENT/WAREHOUSE), sedangkan kolom E berisi jabatan/divisi operasional.
        $cells .= $this->textCell('B'.$excelRow, 28, $jabatan);
        $cells .= $this->textCell('C'.$excelRow, 28, (string) ($row['outlet_name'] ?? ''));
        $cells .= $this->textCell('D'.$excelRow, 29, (string) ($row['full_name'] ?? ''));
        $cells .= $this->textCell('E'.$excelRow, 31, (string) (($row['position_name'] ?? '') ?: ($row['division_name'] ?? '')));

        $stages = (array) ($row['stages'] ?? []);
        $this->appendFiniteStage($cells, $excelRow, ['F','G','H','I','J','K','L'], $stages['SPT'] ?? null);
        $this->appendFiniteStage($cells, $excelRow, ['M','N','O','P','Q','R','S'], $stages['PKWT1'] ?? null);
        $this->appendFiniteStage($cells, $excelRow, ['T','U','V','W','X','Y','Z'], $stages['PKWT2'] ?? null);
        $this->appendFiniteStage($cells, $excelRow, ['AA','AB','AC','AD','AE','AF','AG'], $stages['PKWT3'] ?? null);
        $this->appendFiniteStage($cells, $excelRow, ['AH','AI','AJ','AK','AL','AM','AN'], $stages['PKWT4'] ?? null);
        $this->appendFiniteStage($cells, $excelRow, ['AO','AP','AQ','AR','AS','AT','AU'], $stages['PKWT5'] ?? null);
        $this->appendPermanentStage($cells, $excelRow, $stages['PKWTT'] ?? null);

        return '<row r="'.$excelRow.'" ht="18" customHeight="1">'.$cells.'</row>';
    }

    private function appendFiniteStage(string &$cells, int $row, array $cols, mixed $data): void
    {
        $data = is_array($data) ? $data : [];
        $cells .= $this->textCell($cols[0].$row, 31, (string) ($data['contract_kind'] ?? ''));
        $cells .= $this->dateCell($cols[1].$row, 33, $data['start_date'] ?? null);
        $cells .= $this->dateCell($cols[2].$row, 37, $data['end_date'] ?? null);
        $cells .= $this->dateCell($cols[3].$row, 37, $data['renewal_date'] ?? null);
        $cells .= $this->textCell($cols[4].$row, 31, (string) ($data['salary_tier_name'] ?? ''));
        $cells .= $this->numberCell($cols[5].$row, 38, $data['nominal_fee'] ?? 0);
        $cells .= $this->textCell($cols[6].$row, 31, (string) ($data['status'] ?? ''));
    }

    private function appendPermanentStage(string &$cells, int $row, mixed $data): void
    {
        $data = is_array($data) ? $data : [];
        $cells .= $this->textCell('AV'.$row, 31, (string) ($data['contract_kind'] ?? ''));
        $cells .= $this->dateCell('AW'.$row, 33, $data['start_date'] ?? null);
        $cells .= $this->textCell('AX'.$row, 31, (string) ($data['salary_tier_name'] ?? ''));
        $cells .= $this->numberCell('AY'.$row, 38, $data['nominal_fee'] ?? 0);
        $cells .= $this->textCell('AZ'.$row, 31, (string) ($data['status'] ?? ''));
    }

    private function textCell(string $ref, int $style, string $value): string
    {
        $safe = htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$safe.'</t></is></c>';
    }

    private function numberCell(string $ref, int $style, int|float|string|null $value): string
    {
        if ($value === null || $value === '') return $this->textCell($ref, $style, '');
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
