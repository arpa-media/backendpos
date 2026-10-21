<?php

namespace App\Services\Warehouse\PurchasingV3;

use App\Services\Warehouse\Documents\WarehouseCanonicalDocumentService;
use App\Services\Warehouse\Documents\WarehouseCanonicalPdfRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * V13 I17: one source of truth for PO Completed PDF preview, print and download.
 * Fails closed when original supplier attachments cannot be included verbatim.
 */
final class WarehousePurchaseOrderDossierV13Service
{
    public function __construct(
        private readonly WarehousePurchasingV3Service $purchasing,
        private readonly WarehouseCanonicalDocumentService $canonical,
        private readonly WarehouseCanonicalPdfRenderer $renderer,
    ) {}

    public function render(string $warehouseId, string $orderId): array
    {
        $order = $this->purchasing->showOrder($orderId, $warehouseId);
        if (($order['status'] ?? '') !== 'completed') {
            throw ValidationException::withMessages(['po' => ['Berkas lengkap hanya tersedia setelah PO completed.']]);
        }
        $prId = DB::table('wh_supplier_purchase_orders')
            ->where('warehouse_id', $warehouseId)->where('id', $orderId)
            ->value('purchase_request_id');
        if (! $prId) throw ValidationException::withMessages(['pr' => ['Referensi Purchase Request tidak ditemukan.']]);
        $receipt = $order['stock_in']['receipt_document']['snapshot'] ?? null;
        if (! is_array($receipt) || empty($receipt['items'])) {
            // Completed PO legacy can predate the Stock In minutes snapshot.
            // Reconstruct a clearly-labelled READ-ONLY copy from *posted* stock-in rows.
            $receipt = $this->postedReceiptFallback($warehouseId, $orderId, $order);
        }
        // First render canonical PR and PO. Approved commercial PO values must not
        // be overwritten by actual received cost.
        $pr = $this->canonical->purchaseRequest($warehouseId, (string) $prId);
        $po = $this->canonical->purchaseOrder($warehouseId, $orderId);
        $timeline = $this->timelineDocument($po, $order);
        $ba = $this->minutesDocument($po, $receipt, $order);

        $working = storage_path('app/erp-v13-i17-dossier/'.bin2hex(random_bytes(12)));
        if (! is_dir($working) && ! mkdir($working, 0700, true) && ! is_dir($working)) throw new \RuntimeException('Tidak bisa membuat workspace dokumen sementara.');
        $files = [];
        try {
            $invoices = DB::table('wh_purchase_invoices')
                ->where('purchase_order_id', $orderId)->orderBy('uploaded_at')->orderBy('id')->get();
            if ($invoices->count() > 20) throw ValidationException::withMessages(['invoices' => ['Maksimal 20 lampiran per berkas lengkap.']]);
            // No original invoices to merge: the canonical PDF renderer already
            // supports multiple documents, so Ghostscript is NOT required.
            if ($invoices->isEmpty()) {
                $content = $this->renderer->render([$pr, $po, $timeline, $ba]);
                if (! str_starts_with($content, '%PDF-')) throw new \RuntimeException('Output berkas PO bukan PDF.');
                return ['content' => $content, 'filename' => $this->filename($order)];
            }
            $files[] = $this->write($working, '01_pr.pdf', $this->renderer->render([$pr]));
            $files[] = $this->write($working, '02_po.pdf', $this->renderer->render([$po]));
            foreach ($invoices as $index => $invoice) {
                $storage = Storage::disk((string) $invoice->storage_disk);
                if (! $storage->exists((string) $invoice->storage_path)) throw ValidationException::withMessages(['invoices' => ['Lampiran supplier tidak ditemukan: '.(string) $invoice->original_name]]);
                if ((int) $invoice->file_size > 12 * 1024 * 1024) throw ValidationException::withMessages(['invoices' => ['Lampiran terlalu besar: '.(string) $invoice->original_name]]);
                $bytes = $storage->get((string) $invoice->storage_path);
                if (! is_string($bytes) || strlen($bytes) > 12 * 1024 * 1024) throw new \RuntimeException('Lampiran tidak dapat dibaca.');
                if (! empty($invoice->sha256) && ! hash_equals(strtolower((string) $invoice->sha256), hash('sha256', $bytes))) {
                    throw ValidationException::withMessages(['invoices' => ['Checksum lampiran tidak sesuai: '.(string) $invoice->original_name]]);
                }
                $pdf = $this->attachmentPdf($bytes, (string) $invoice->mime_type);
                $files[] = $this->write($working, sprintf('03_invoice_%03d.pdf', $index + 1), $pdf);
            }
            $files[] = $this->write($working, '04_timeline.pdf', $this->renderer->render([$timeline]));
            $files[] = $this->write($working, '05_berita_acara.pdf', $this->renderer->render([$ba]));
            $merge = $this->mergeCommand($files, $working.'/dossier.pdf');
            $output = $working.'/dossier.pdf';
            $proc = new Process($merge, $working, null, null, 120);
            $proc->run();
            if (! $proc->isSuccessful() || ! is_file($output) || filesize($output) === 0) {
                throw ValidationException::withMessages(['pdf' => ['PDF gabungan gagal dibuat, tidak ada lampiran yang dilewati. '.mb_substr($proc->getErrorOutput(), 0, 200)]]);
            }
            $content = file_get_contents($output);
            if (! is_string($content) || ! str_starts_with($content, '%PDF-')) throw new \RuntimeException('Output gabungan bukan PDF.');
            return ['content' => $content, 'filename' => $this->filename($order)];
        } finally {
            foreach (glob($working.'/*') ?: [] as $path) if (is_file($path)) @unlink($path);
            @rmdir($working);
        }
    }

    private function filename(array $order): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($order['po_number'] ?? 'PO')).'_Berkas.pdf';
    }

    /** Resolve Windows Ghostscript, Unix gs, or Poppler pdfunite without shell concatenation. */
    private function mergeCommand(array $files, string $output): array
    {
        $finder = new ExecutableFinder();
        $configured = trim((string) getenv('ERP_POS_GHOSTSCRIPT_BIN'));
        $binary = $configured !== '' && is_file($configured) ? $configured : null;
        $binary ??= $finder->find('gs') ?: $finder->find('gswin64c.exe') ?: $finder->find('gswin64c')
            ?: $finder->find('gswin32c.exe') ?: $finder->find('gswin32c');
        if ($binary) {
            return array_merge([$binary, '-dSAFER', '-dBATCH', '-dNOPAUSE', '-q', '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4', '-sOutputFile='.$output], $files);
        }
        $pdfunite = $finder->find('pdfunite') ?: $finder->find('pdfunite.exe');
        if ($pdfunite) return array_merge([$pdfunite], $files, [$output]);
        throw ValidationException::withMessages(['pdf' => [
            'Lampiran invoice asli membutuhkan penggabung PDF pada server. Instal Ghostscript (gswin64c.exe di PATH Windows), '
            .'atau atur ERP_POS_GHOSTSCRIPT_BIN ke lokasi executable; server Linux dapat memakai gs atau pdfunite. '
            .'Dokumen tidak diubah atau dilewati.'
        ]]);
    }

    /** Only reconstruct a BA when completed Stock In has a confirmed ledger posting. */
    private function postedReceiptFallback(string $warehouseId, string $orderId, array $order): array
    {
        $stockIn = (array) ($order['stock_in'] ?? []);
        if (empty($stockIn['id']) || empty($stockIn['ledger_posting_id'])
            || ! Schema::hasTable('wh_stock_in_items')) {
            throw ValidationException::withMessages(['receipt' => [
                'PO completed tetapi Berita Acara Stock In tidak ditemukan dan posting stok belum dapat dibuktikan. Periksa arsip transaksi.'
            ]]);
        }
        $rows = DB::table('wh_stock_in_items as si')
            ->join('wh_supplier_purchase_order_items as poi', 'poi.id', '=', 'si.purchase_order_item_id')
            ->join('wh_stock_ins as st', 'st.id', '=', 'si.stock_in_id')
            ->where('st.warehouse_id', $warehouseId)
            ->where('st.purchase_order_id', $orderId)
            ->where('si.stock_in_id', $stockIn['id'])
            ->get(['si.id', 'si.expected_qty_base', 'si.accepted_qty_base',
                'poi.sku_code_snapshot', 'poi.item_name_snapshot', 'poi.base_uom_code_snapshot']);
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['receipt' => [
                'PO completed tetapi tidak ditemukan rincian Stock In yang bisa direkonstruksi.'
            ]]);
        }
        return [
            'generated_at' => (string) ($order['completed_at'] ?? ''),
            'reconstructed' => true,
            'stock_in' => [
                'id' => $stockIn['id'], 'stock_in_number' => $stockIn['stock_in_number'] ?? '-',
                'ledger_posting_id' => $stockIn['ledger_posting_id'],
            ],
            'items' => $rows->map(static function ($row): array {
                $expected = (float) $row->expected_qty_base;
                $received = (float) $row->accepted_qty_base;
                return [
                    'sku_code' => (string) $row->sku_code_snapshot,
                    'item_name' => (string) $row->item_name_snapshot,
                    'uom' => (string) $row->base_uom_code_snapshot,
                    'actual_qty_base' => $expected,
                    'received_qty_base' => $received,
                    'not_received_qty_base' => max(0, $expected - $received),
                ];
            })->all(),
        ];
    }

    private function write(string $dir, string $name, string $bytes): string
    {
        $path = $dir.'/'.$name;
        if (file_put_contents($path, $bytes, LOCK_EX) === false) throw new \RuntimeException('Gagal menulis PDF sementara.');
        return $path;
    }

    private function timelineDocument(array $base, array $order): array
    {
        $rows = [];
        foreach ((array) ($order['timeline'] ?? []) as $i => $ev) {
            $actor = trim((string) ($ev['actor']['name'] ?? '-'));
            $rows[] = [(string) ($i + 1), (string) ($ev['created_at'] ?? '-'),
                strtoupper(str_replace('_', ' ', (string) ($ev['event_type'] ?? '-'))), $actor];
        }
        return array_merge($base, [
            'title' => 'Timeline Purchase Order', 'code' => 'AUDIT', 'number' => (string) ($order['po_number'] ?? '-'),
            'columns' => [['label'=>'No','width'=>8],['label'=>'Tanggal','width'=>30],['label'=>'Event','width'=>42],['label'=>'Oleh','width'=>20]],
            'rows' => $rows, 'totals'=>[['label'=>'Jumlah Event','value'=>(string) count($rows)]], 'notes'=>'Timeline sistem (read only).',
            'signatures'=>[], 'attachments'=>[],
        ]);
    }

    private function minutesDocument(array $base, array $receipt, array $order): array
    {
        $rows = [];
        foreach ((array) ($receipt['items'] ?? []) as $i => $item) {
            $rows[] = [(string)($i+1), trim((string)($item['sku_code']??''). ' · '.(string)($item['item_name']??'')),
                (string)($item['actual_qty_base']??0), (string)($item['received_qty_base']??0), (string)($item['not_received_qty_base']??0), (string)($item['uom']??'-')];
        }
        return array_merge($base, [
            'title'=>'Berita Acara Stock In','code'=>'BA','number'=>(string) ($order['stock_in']['receipt_document']['document_number']??('REKON-'.($receipt['stock_in']['stock_in_number']??'-'))),
            'date'=>(string) ($receipt['generated_at']??''),
            'columns'=>[['label'=>'No','width'=>7],['label'=>'Item','width'=>39],['label'=>'Actual','width'=>14],['label'=>'Received','width'=>14],['label'=>'Selisih','width'=>13],['label'=>'UOM','width'=>13]],
            'rows'=>$rows, 'totals'=>[['label'=>'Total Item','value'=>(string)count($rows)]],
            'notes'=>(!empty($receipt['reconstructed']) ? 'REKONSTRUKSI BACA SAJA: snapshot BA asli tidak tersedia. Qty diambil dari Stock In yang sudah diposting. ' : '').'Stock In: '.(string)($receipt['stock_in']['stock_in_number']??'-').' · Ledger: '.(string)($receipt['stock_in']['ledger_posting_id']??'-'),
            'signatures'=>[], 'attachments'=>[],
        ]);
    }

    private function attachmentPdf(string $bytes, string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if (str_starts_with($bytes, '%PDF-') && in_array($mime, ['application/pdf', 'application/octet-stream'], true)) return $bytes;
        if (! in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
            throw ValidationException::withMessages(['invoices'=>['Format lampiran PDF/JPG/PNG/WEBP tidak didukung.']]);
        }
        $size = @getimagesizefromstring($bytes);
        if (! is_array($size) || (int)$size[0] < 1 || (int)$size[1] < 1 || (int)$size[0] > 10000 || (int)$size[1] > 10000) throw new \RuntimeException('Dimensi gambar invoice tidak valid.');
        if ($mime !== 'image/jpeg') {
            if (! function_exists('imagecreatefromstring')) throw ValidationException::withMessages(['invoices'=>['PHP GD wajib tersedia untuk melampirkan invoice PNG/WEBP.']]);
            $img = @imagecreatefromstring($bytes);
            if (! $img) throw new \RuntimeException('Invoice image tidak dapat dikonversi.');
            ob_start(); imagejpeg($img, null, 90); $bytes = (string) ob_get_clean(); imagedestroy($img);
        }
        $w = (int)$size[0]; $h = (int)$size[1];
        $scale = min(530/$w, 770/$h); $pw = round($w*$scale, 3); $ph = round($h*$scale, 3);
        $content = sprintf("q %.3f 0 0 %.3f %.3f %.3f cm /Im1 Do Q\n", $pw, $ph, (595.28-$pw)/2, (841.89-$ph)/2);
        $objects = [
            1=>'<< /Type /Catalog /Pages 2 0 R >>',
            2=>'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3=>'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>',
            4=>'<< /Type /XObject /Subtype /Image /Width '.$w.' /Height '.$h.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($bytes)." >>\nstream\n".$bytes."\nendstream",
            5=>'<< /Length '.strlen($content)." >>\nstream\n".$content."endstream",
        ];
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $n=>$object) { $offsets[$n]=strlen($pdf);$pdf.=$n." 0 obj\n".$object."\nendobj\n"; }
        $xref=strlen($pdf); $pdf.="xref\n0 6\n0000000000 65535 f \n";
        for($n=1;$n<=5;$n++) $pdf.=sprintf('%010d 00000 n ', $offsets[$n])."\n";
        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }
}
