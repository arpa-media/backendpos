<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class HrUniformI10XlsxService
{
    private const TEMPLATE = 'hr/templates/i10/TEMPLATE REKAP SERAGAM & ATRIBUT TKJ.xlsx';
    private const STOCK_SHEET = 'xl/worksheets/sheet1.xml';
    private const INBOUND_SHEET = 'xl/worksheets/sheet2.xml';

    public function download(string $filename, array $stockRows, array $inboundRows): Response
    {
        return response($this->build($stockRows, $inboundRows), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function build(array $stockRows, array $inboundRows): string
    {
        $template = storage_path('app/'.self::TEMPLATE);
        if (! is_file($template)) throw new InvalidArgumentException('Template Rekap Seragam & Atribut I10 tidak ditemukan.');
        $files = $this->readPackage($template);
        if (! isset($files[self::STOCK_SHEET], $files[self::INBOUND_SHEET])) {
            throw new InvalidArgumentException('Sheet Rekap UPDATE / Barang MASUK pada template I10 tidak ditemukan.');
        }

        $files[self::STOCK_SHEET] = $this->writeStockSheet($files[self::STOCK_SHEET], $stockRows);
        $files[self::INBOUND_SHEET] = $this->writeInboundSheet($files[self::INBOUND_SHEET], $inboundRows);
        return $this->buildZip($files);
    }

    private function writeStockSheet(string $xml, array $rows): string
    {
        $sheet = '<sheetData>';
        $sheet .= '<row r="1" ht="22" customHeight="1">'.$this->textCell('A1', 4, 'REKAP UPDATE STOK').'</row>';
        $sheet .= $this->stockHeaderRow(4);
        $cursor = 5;

        $groups = [];
        foreach ($rows as $row) {
            $kind = strtoupper((string) ($row['item_kind'] ?? 'UNIFORM'));
            $company = strtoupper((string) ($row['company_code'] ?? ''));
            $groups[$kind][$company][] = $row;
        }

        foreach (['UNIFORM', 'ATTRIBUTE'] as $kind) {
            $companies = $groups[$kind] ?? [];
            if ($kind === 'ATTRIBUTE' && $companies !== []) {
                $cursor++;
                $sheet .= $this->stockHeaderRow($cursor++);
            }
            $firstCompany = true;
            foreach (['MDMF', 'BKJB'] as $company) {
                $companyRows = $companies[$company] ?? [];
                if ($companyRows === []) continue;
                if (! $firstCompany) {
                    $cursor++;
                    $sheet .= $this->stockHeaderRow($cursor++);
                }
                foreach (array_values($companyRows) as $index => $row) {
                    $label = $index === 0 ? ($kind === 'UNIFORM' ? implode(' ', str_split($company)) : $company) : '';
                    $category = $index === 0 ? ($kind === 'UNIFORM' ? 'SERAGAM TKJ' : 'LANYARD / ATRIBUT JAYA') : '';
                    $sheet .= $this->stockDataRow($cursor++, $row, $label, $category);
                }
                $firstCompany = false;
            }
        }

        // Any non-canonical company is still exported safely at the end.
        foreach ($groups as $kind => $companies) {
            foreach ($companies as $company => $companyRows) {
                if (in_array($company, ['MDMF', 'BKJB'], true)) continue;
                $cursor++;
                $sheet .= $this->stockHeaderRow($cursor++);
                foreach (array_values($companyRows) as $index => $row) {
                    $sheet .= $this->stockDataRow($cursor++, $row, $index === 0 ? $company : '', $index === 0 ? $kind : '');
                }
            }
        }

        $sheet .= '</sheetData>';
        $xml = $this->replaceSheetData($xml, $sheet);
        $lastRow = max(4, $cursor - 1);
        return $this->replaceDimension($xml, 'A1:H'.$lastRow);
    }

    private function writeInboundSheet(string $xml, array $rows): string
    {
        $sheet = '<sheetData>';
        $sheet .= '<row r="1" ht="22" customHeight="1">'.$this->textCell('A1', 3, 'REKAP SERAGAM MASUK').'</row>';
        $sheet .= '<row r="5" ht="36" customHeight="1">'
            .$this->textCell('A5', 14, 'Tanggal Barang Masuk')
            .$this->textCell('B5', 14, 'Kode Barang')
            .$this->textCell('C5', 15, 'UKURAN')
            .$this->textCell('D5', 14, 'Nama Barang')
            .$this->textCell('E5', 16, 'Jumlah Barang Masuk')
            .$this->textCell('F5', 16, 'PT')
            .$this->textCell('G5', 16, 'PENERIMA')
            .$this->textCell('H5', 16, "INPUT DATA\nOLEH")
            .'</row>';

        $r = 6;
        foreach ($rows as $row) {
            $sheet .= '<row r="'.$r.'" ht="18" customHeight="1">'
                .$this->dateCell('A'.$r, 17, $row['inbound_date'] ?? null)
                .$this->textCell('B'.$r, 19, (string) ($row['code'] ?? ''))
                .$this->textCell('C'.$r, 23, (string) ($row['size'] ?? '-'))
                .$this->textCell('D'.$r, 24, (string) ($row['name'] ?? ''))
                .$this->numberCell('E'.$r, 25, (int) ($row['quantity'] ?? 0))
                .$this->textCell('F'.$r, 1, (string) ($row['company_code'] ?? ''))
                .$this->textCell('G'.$r, 28, (string) ($row['receiver_name'] ?? ''))
                .$this->textCell('H'.$r, 28, (string) ($row['input_by_name'] ?? '-'))
                .'</row>';
            $r++;
        }
        $sheet .= '</sheetData>';
        $xml = $this->replaceSheetData($xml, $sheet);
        return $this->replaceDimension($xml, 'A1:H'.max(5, $r - 1));
    }

    private function stockHeaderRow(int $r): string
    {
        return '<row r="'.$r.'" ht="30" customHeight="1">'
            .$this->textCell('B'.$r, 20, 'Kode Barang')
            .$this->textCell('C'.$r, 20, 'Nama Barang')
            .$this->textCell('D'.$r, 20, 'Stock Awal')
            .$this->textCell('E'.$r, 20, 'Barang Masuk')
            .$this->textCell('F'.$r, 20, 'Barang Keluar')
            .$this->textCell('G'.$r, 20, 'Stok Akhir')
            .'</row>';
    }

    private function stockDataRow(int $r, array $row, string $companyLabel, string $categoryLabel): string
    {
        return '<row r="'.$r.'" ht="18" customHeight="1">'
            .($companyLabel !== '' ? $this->textCell('A'.$r, 115, $companyLabel) : '')
            .$this->textCell('B'.$r, 27, (string) ($row['code'] ?? ''))
            .$this->textCell('C'.$r, 27, (string) ($row['name'] ?? ''))
            .$this->numberCell('D'.$r, 29, (int) ($row['opening_qty'] ?? 0))
            .$this->numberCell('E'.$r, 30, (int) ($row['inbound_qty'] ?? 0))
            .$this->numberCell('F'.$r, 30, (int) ($row['outbound_qty'] ?? 0))
            .$this->numberCell('G'.$r, 30, (int) ($row['current_qty'] ?? 0))
            .($categoryLabel !== '' ? $this->textCell('H'.$r, 117, $categoryLabel) : '')
            .'</row>';
    }

    private function textCell(string $ref, int $style, string $value): string
    {
        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->esc($value).'</t></is></c>';
    }

    private function numberCell(string $ref, int $style, int|float $value): string
    {
        return '<c r="'.$ref.'" s="'.$style.'"><v>'.(0 + $value).'</v></c>';
    }

    private function dateCell(string $ref, int $style, mixed $value): string
    {
        if (! $value) return $this->textCell($ref, $style, '');
        try {
            $date = Carbon::parse((string) $value, 'Asia/Jakarta')->startOfDay();
            $base = Carbon::create(1899, 12, 30, 0, 0, 0, 'Asia/Jakarta');
            return $this->numberCell($ref, $style, $base->diffInDays($date, false));
        } catch (\Throwable) {
            return $this->textCell($ref, $style, (string) $value);
        }
    }

    private function replaceSheetData(string $xml, string $replacement): string
    {
        if (! preg_match('/<(?:[A-Za-z0-9_]+:)?sheetData\b[^>]*>.*?<\/(?:[A-Za-z0-9_]+:)?sheetData>/is', $xml)) {
            throw new InvalidArgumentException('Struktur sheetData template Uniform I10 tidak valid.');
        }
        return preg_replace('/<(?:[A-Za-z0-9_]+:)?sheetData\b[^>]*>.*?<\/(?:[A-Za-z0-9_]+:)?sheetData>/is', $replacement, $xml, 1) ?? $xml;
    }

    private function replaceDimension(string $xml, string $range): string
    {
        if (preg_match('/<(?:[A-Za-z0-9_]+:)?dimension\b[^>]*ref="[^"]*"[^>]*\/?>/i', $xml)) {
            return preg_replace('/<(?:[A-Za-z0-9_]+:)?dimension\b[^>]*ref="[^"]*"[^>]*\/?>/i', '<dimension ref="'.$range.'"/>', $xml, 1) ?? $xml;
        }
        return $xml;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /** @return array<string,string> */
    private function readPackage(string $path): array
    {
        $binary = @file_get_contents($path);
        if ($binary === false) throw new InvalidArgumentException('Template Uniform I10 tidak dapat dibaca.');
        $eocdOffset = strrpos($binary, "PK\x05\x06");
        if ($eocdOffset === false) throw new InvalidArgumentException('Struktur ZIP template Uniform I10 tidak valid.');
        $eocd = unpack('Vsig/vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLength', substr($binary, $eocdOffset, 22));
        if (! is_array($eocd)) throw new InvalidArgumentException('EOCD template Uniform I10 tidak valid.');

        $entries = [];
        $cursor = (int) $eocd['cdOffset'];
        for ($i = 0; $i < (int) $eocd['totalEntries']; $i++) {
            $header = unpack('Vsig/vversionMade/vversionNeeded/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset', substr($binary, $cursor, 46));
            if (! is_array($header) || ($header['sig'] ?? null) !== 0x02014b50) throw new InvalidArgumentException('Central directory template Uniform I10 tidak valid.');
            $name = substr($binary, $cursor + 46, $header['nameLength']);
            $cursor += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
            $local = unpack('Vsig/vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength', substr($binary, $header['localOffset'], 30));
            if (! is_array($local) || ($local['sig'] ?? null) !== 0x04034b50) continue;
            $start = $header['localOffset'] + 30 + $local['nameLength'] + $local['extraLength'];
            $compressed = substr($binary, $start, $header['compressedSize']);
            if ((int) $header['method'] === 0) $entries[$name] = $compressed;
            elseif ((int) $header['method'] === 8) {
                $inflated = @gzinflate($compressed);
                if ($inflated === false) throw new InvalidArgumentException('Data template Uniform I10 tidak dapat di-inflate.');
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
