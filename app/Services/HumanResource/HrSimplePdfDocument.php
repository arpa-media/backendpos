<?php

namespace App\Services\HumanResource;

use RuntimeException;

/**
 * Lightweight one-page PDF writer used by HR salary slips.
 * No external PDF package is required. It supports Helvetica text,
 * vector rules/boxes and 8-bit RGB/RGBA PNG images (including alpha mask).
 */
final class HrSimplePdfDocument
{
    private float $width = 595.28;
    private float $height = 841.89;
    private string $content = '';
    private array $images = [];

    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false): self
    {
        $font = $bold ? 'F2' : 'F1';
        $encoded = $this->pdfText($text);
        $this->content .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $font, $size, $x, $y, $encoded);
        return $this;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $lineWidth = 0.6): self
    {
        $this->content .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $lineWidth, $x1, $y1, $x2, $y2);
        return $this;
    }

    public function rect(float $x, float $y, float $w, float $h, float $lineWidth = 0.6): self
    {
        $this->content .= sprintf("%.2F w %.2F %.2F %.2F %.2F re S\n", $lineWidth, $x, $y, $w, $h);
        return $this;
    }

    public function imagePng(string $path, float $x, float $y, float $w, ?float $h = null): self
    {
        $image = $this->decodePng($path);
        $h ??= $w * ($image['height'] / $image['width']);
        $name = 'Im'.(count($this->images) + 1);
        $image['name'] = $name;
        $this->images[] = $image;
        $this->content .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $y, $name);
        return $this;
    }

    public function build(): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $nextId = 6;
        $xObjects = [];
        foreach ($this->images as $image) {
            $alphaId = null;
            if ($image['alpha'] !== null) {
                $alphaId = $nextId++;
                $alphaStream = gzcompress($image['alpha'], 9);
                $objects[$alphaId] = $this->streamObject(
                    sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>', $image['width'], $image['height'], strlen($alphaStream)),
                    $alphaStream
                );
            }

            $imageId = $nextId++;
            $rgbStream = gzcompress($image['rgb'], 9);
            $smask = $alphaId ? " /SMask {$alphaId} 0 R" : '';
            $objects[$imageId] = $this->streamObject(
                sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode%s /Length %d >>', $image['width'], $image['height'], $smask, strlen($rgbStream)),
                $rgbStream
            );
            $xObjects[] = '/'.$image['name'].' '.$imageId.' 0 R';
        }

        $contentId = $nextId++;
        $objects[$contentId] = $this->streamObject('<< /Length '.strlen($this->content).' >>', $this->content);
        $resource = '<< /Font << /F1 4 0 R /F2 5 0 R >>';
        if ($xObjects !== []) $resource .= ' /XObject << '.implode(' ', $xObjects).' >>';
        $resource .= ' >>';
        $objects[3] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>', $this->width, $this->height, $resource, $contentId);

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 ".($maxId + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size ".($maxId + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }

    private function streamObject(string $dictionary, string $stream): string
    {
        return $dictionary."\nstream\n".$stream."\nendstream";
    }

    private function pdfText(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($encoded === false) $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }

    private function decodePng(string $path): array
    {
        if (! is_file($path)) throw new RuntimeException('PNG asset tidak ditemukan: '.$path);
        $data = file_get_contents($path);
        if ($data === false || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") throw new RuntimeException('Asset bukan PNG valid.');

        $offset = 8;
        $width = $height = $bitDepth = $colorType = null;
        $idat = '';
        $interlace = 0;
        $lengthData = strlen($data);
        while ($offset + 12 <= $lengthData) {
            $len = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            $chunk = substr($data, $offset + 8, $len);
            $offset += 12 + $len;
            if ($type === 'IHDR') {
                $info = unpack('Nwidth/Nheight/Cbit/Ccolor/Ccompression/Cfilter/Cinterlace', $chunk);
                $width = (int) $info['width']; $height = (int) $info['height'];
                $bitDepth = (int) $info['bit']; $colorType = (int) $info['color']; $interlace = (int) $info['interlace'];
            } elseif ($type === 'IDAT') {
                $idat .= $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
        }

        if ($width === null || $height === null || $bitDepth !== 8 || $interlace !== 0 || ! in_array($colorType, [2, 6], true)) {
            throw new RuntimeException('PNG HR harus 8-bit RGB/RGBA non-interlaced.');
        }
        $raw = zlib_decode($idat);
        if ($raw === false) throw new RuntimeException('Gagal decode PNG HR.');

        $bpp = $colorType === 6 ? 4 : 3;
        $stride = $width * $bpp;
        $pos = 0;
        $previous = array_fill(0, $stride, 0);
        $rgb = '';
        $alpha = $colorType === 6 ? '' : null;

        for ($row = 0; $row < $height; $row++) {
            if ($pos >= strlen($raw)) throw new RuntimeException('Data PNG terpotong.');
            $filter = ord($raw[$pos++]);
            $scan = array_values(unpack('C*', substr($raw, $pos, $stride)) ?: []);
            $pos += $stride;
            if (count($scan) !== $stride) throw new RuntimeException('Scanline PNG tidak lengkap.');
            $recon = [];
            for ($i = 0; $i < $stride; $i++) {
                $x = $scan[$i];
                $a = $i >= $bpp ? $recon[$i - $bpp] : 0;
                $b = $previous[$i] ?? 0;
                $c = $i >= $bpp ? ($previous[$i - $bpp] ?? 0) : 0;
                $value = match ($filter) {
                    0 => $x,
                    1 => ($x + $a) & 0xFF,
                    2 => ($x + $b) & 0xFF,
                    3 => ($x + intdiv($a + $b, 2)) & 0xFF,
                    4 => ($x + $this->paeth($a, $b, $c)) & 0xFF,
                    default => throw new RuntimeException('Filter PNG tidak didukung.'),
                };
                $recon[$i] = $value;
            }
            for ($i = 0; $i < $stride; $i += $bpp) {
                $rgb .= chr($recon[$i]).chr($recon[$i + 1]).chr($recon[$i + 2]);
                if ($alpha !== null) $alpha .= chr($recon[$i + 3]);
            }
            $previous = $recon;
        }

        return compact('width', 'height', 'rgb', 'alpha');
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) return $a;
        return $pb <= $pc ? $b : $c;
    }
}
