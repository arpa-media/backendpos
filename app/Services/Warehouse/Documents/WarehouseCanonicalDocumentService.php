<?php

namespace App\Services\Warehouse\Documents;

use App\Services\Warehouse\FinanceV4\WarehouseTreasuryV4Service;
use App\Services\Warehouse\PurchasingV3\WarehousePurchasingV3Service;
use App\Services\Warehouse\WarehouseReceivingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WarehouseCanonicalDocumentService
{
    private const COMPANY = [
        'name' => 'PT. Aneka Produk Berkah',
        'address' => 'Jl. Candi Penataran No. 43C, Malang, Jawa Timur',
        'phone' => '+62822 3461 7007',
        'email' => 'warehouse.jayagroup@gmail.com',
        'brand_footer' => 'TOKO KOPI JAYA',
        'logo' => '/logoWarehouse.png',
    ];

    public function __construct(
        private readonly WarehousePurchasingV3Service $purchasing,
        private readonly WarehouseReceivingService $receiving,
        private readonly WarehouseTreasuryV4Service $treasury,
    ) {}

    public function purchaseRequest(string $warehouseId, string $id): array
    {
        $d = $this->purchasing->showRequest($id, $warehouseId);
        $warehouse = $this->warehouse($warehouseId);
        $supplier = $this->supplier($d['supplier']['id'] ?? null);
        $rows = [];
        foreach ((array) ($d['items'] ?? []) as $index => $item) {
            $qty = (float) ($item['requested_qty_uom'] ?? 0);
            $rows[] = [
                (string) ($index + 1),
                trim(($item['sku_code'] ?? '').' · '.($item['item_name'] ?? ''), ' ·'),
                $this->qty($qty),
                (string) ($item['request_uom']['code'] ?? $item['base_uom']['code'] ?? 'UNIT'),
            ];
        }
        return $this->base('purchase-request', 'Purchase Requisition', 'PR', (string) ($d['pr_number'] ?? '-'), (string) ($d['request_date'] ?? ''), [
            'status' => strtoupper((string) ($d['status'] ?? '-')),
            'left_party' => $this->party('Vendor', $supplier),
            'right_party' => $this->party('Ship to', $warehouse),
            'meta' => array_values(array_filter([
                ['label' => 'Needed Date', 'value' => $this->date($d['needed_date'] ?? null)],
                ['label' => 'Requested By', 'value' => $this->person($d['requester'] ?? null)],
                ['label' => 'Approved By', 'value' => $this->person($d['approver'] ?? null)],
            ], fn ($r) => ($r['value'] ?? '-') !== '-')),
            'columns' => [
                ['label' => 'Item #', 'align' => 'left', 'width' => 12],
                ['label' => 'Deskripsi', 'align' => 'left', 'width' => 58],
                ['label' => 'QTY', 'align' => 'right', 'width' => 15],
                ['label' => 'UOM', 'align' => 'left', 'width' => 15],
            ],
            'rows' => $rows,
            'totals' => [['label' => 'Total Item', 'value' => (string) count($rows)]],
            'notes' => (string) ($d['notes'] ?? ''),
            'signatures' => [
                ['label' => 'Dibuat oleh', 'name' => $this->person($d['requester'] ?? null), 'role' => 'Staff Area'],
                ['label' => 'Disetujui oleh', 'name' => $this->person($d['approver'] ?? null), 'role' => 'Staff Finance'],
            ],
        ]);
    }

    public function purchaseOrder(string $warehouseId, string $id): array
    {
        $d = $this->purchasing->showOrder($id, $warehouseId);
        $warehouse = $this->warehouse($warehouseId);
        $supplier = $this->supplier($d['supplier']['id'] ?? null);
        $rows = [];
        $subtotal = 0.0;
        foreach ((array) ($d['items'] ?? []) as $index => $item) {
            // A Purchase Order is the approved commercial commitment. Actual receiving values
            // belong to Stock In / GR and must never rewrite the PO document after completion.
            $qty = (float) ($item['ordered_qty_uom'] ?? 0);
            $price = (float) ($item['approved_purchase_price'] ?? 0);
            $line = (float) ($item['approved_line_total'] ?? 0);
            if ($line <= 0 && $qty > 0 && $price > 0) $line = round($qty * $price, 2);
            $subtotal += $line;
            $rows[] = [
                (string) ($index + 1),
                trim(($item['sku_code'] ?? '').' · '.($item['item_name'] ?? ''), ' ·'),
                $this->qty($qty),
                (string) ($item['purchase_uom_code'] ?? $item['base_uom_code'] ?? 'UNIT'),
                $this->money($price),
                $this->money($line),
            ];
        }
        $total = (float) ($d['approved_total'] ?? $subtotal);
        if ($total <= 0) $total = $subtotal;
        return $this->base('purchase-order', 'Purchase Order', 'PO', (string) ($d['po_number'] ?? '-'), (string) ($d['created_at'] ?? $d['request_date'] ?? ''), [
            'status' => strtoupper((string) ($d['status'] ?? '-')),
            'reference' => (string) ($d['pr_number'] ?? ''),
            'left_party' => $this->party('Vendor', $supplier),
            'right_party' => $this->party('Ship to', $warehouse),
            'meta' => array_values(array_filter([
                ['label' => 'PR', 'value' => (string) ($d['pr_number'] ?? '-')],
                ['label' => 'Needed Date', 'value' => $this->date($d['needed_date'] ?? null)],
                ['label' => 'Currency', 'value' => 'IDR'],
                ['label' => 'Invoice Supplier', 'value' => count((array) ($d['invoices'] ?? [])).' file'],
            ], fn ($r) => ($r['value'] ?? '-') !== '-')),
            'columns' => [
                ['label' => 'Item #', 'align' => 'left', 'width' => 9],
                ['label' => 'Deskripsi', 'align' => 'left', 'width' => 40],
                ['label' => 'QTY', 'align' => 'right', 'width' => 10],
                ['label' => 'UOM', 'align' => 'left', 'width' => 10],
                ['label' => 'Harga Unit', 'align' => 'right', 'width' => 15],
                ['label' => 'Total', 'align' => 'right', 'width' => 16],
            ],
            'rows' => $rows,
            'totals' => [
                ['label' => 'Subtotal', 'value' => $this->money($subtotal)],
                ['label' => 'Total', 'value' => $this->money($total)],
            ],
            'notes' => (string) ($d['notes'] ?? ''),
            'signatures' => [
                ['label' => 'Dibuat oleh', 'name' => $this->person($d['requester'] ?? null), 'role' => 'Staff Finance'],
                ['label' => 'Disetujui oleh', 'name' => $this->person($d['approver'] ?? null), 'role' => 'SPV Finance'],
            ],
            'attachments' => collect((array) ($d['invoices'] ?? []))->map(fn ($f) => [
                'id' => (string) ($f['id'] ?? ''), 'name' => (string) ($f['original_name'] ?? ''),
                'size' => (int) ($f['file_size'] ?? 0), 'sha256' => (string) ($f['sha256'] ?? ''),
            ])->values()->all(),
        ]);
    }

    public function goodsReceipt(string $warehouseId, string $receivingId): array
    {
        $d = $this->receiving->showForWarehouse($receivingId, $warehouseId);
        if (empty($d['goods_receipt'])) throw ValidationException::withMessages(['goods_receipt' => ['Goods Receipt belum tersedia.']]);
        $rows = [];
        foreach ((array) ($d['items'] ?? []) as $index => $item) {
            $rows[] = [
                (string) ($index + 1),
                trim(($item['sku_code'] ?? '').' · '.($item['item_name'] ?? ''), ' ·'),
                $this->qty($item['received_qty_base'] ?? 0),
                (string) ($item['base_uom_code'] ?? 'UNIT'),
            ];
        }
        return $this->base('goods-receipt', 'Good Receipt', 'GR', (string) ($d['goods_receipt']['gr_number'] ?? '-'), (string) ($d['goods_receipt']['receipt_date'] ?? $d['actual_delivery_at'] ?? ''), [
            'status' => strtoupper((string) ($d['goods_receipt']['status'] ?? $d['status'] ?? '-')),
            'reference' => (string) ($d['delivery_number'] ?? ''),
            'left_party' => $this->party('Vendor / Sender', $d['warehouse'] ?? []),
            'right_party' => $this->party('Ship to', $d['outlet'] ?? []),
            'meta' => [
                ['label' => 'Delivery Order', 'value' => (string) ($d['delivery_number'] ?? '-')],
                ['label' => 'Stock Request', 'value' => (string) ($d['request_number'] ?? '-')],
                ['label' => 'Actual Delivery', 'value' => $this->dateTime($d['actual_delivery_at'] ?? null)],
                ['label' => 'Received By', 'value' => $this->person($d['received_by'] ?? null)],
            ],
            'columns' => [
                ['label' => 'Item #', 'align' => 'left', 'width' => 12],
                ['label' => 'Deskripsi', 'align' => 'left', 'width' => 58],
                ['label' => 'QTY', 'align' => 'right', 'width' => 15],
                ['label' => 'UOM', 'align' => 'left', 'width' => 15],
            ],
            'rows' => $rows,
            'totals' => [
                ['label' => 'Total Item', 'value' => (string) count($rows)],
                ['label' => 'Total Nilai', 'value' => $this->money($d['goods_receipt']['total_amount'] ?? 0)],
            ],
            'notes' => (string) ($d['notes'] ?? ''),
            'signatures' => [
                ['label' => 'Disetujui oleh', 'name' => $this->person($d['sender'] ?? null), 'role' => 'Staff Area'],
                ['label' => 'Disetujui oleh', 'name' => $this->person($d['received_by'] ?? null), 'role' => 'Staff Finance'],
            ],
        ]);
    }

    public function treasury(string $warehouseId, string $type, string $id): array
    {
        if (! in_array($type, ['cash_in','cash_out','bank_in','bank_out'], true)) abort(404);
        $d = $this->treasury->detail($id, [$warehouseId]);
        if (($d['transaction_type'] ?? null) !== $type) abort(404);
        $map = [
            'cash_in' => ['Bukti Kas Masuk', 'BKM', 'Diterima Dari', 'Diserahkan Oleh'],
            'cash_out' => ['Bukti Kas Keluar', 'BKK', 'Dibayarkan Kepada', 'Diterima Oleh'],
            'bank_in' => ['Bukti Bank Masuk', 'BBM', 'Diterima Dari', 'Diserahkan Oleh'],
            'bank_out' => ['Bukti Bank Keluar', 'BBK', 'Dibayarkan Kepada', 'Diterima Oleh'],
        ][$type];
        $postingLines = (array) ($d['general_posting']['lines'] ?? []);
        $rows = [];
        if ($postingLines !== []) {
            foreach ($postingLines as $index => $line) {
                $amount = max((float) ($line['debit'] ?? 0), (float) ($line['credit'] ?? 0));
                $rows[] = [(string) ($index + 1), trim(($line['account_code'] ?? '').' · '.($line['account_name'] ?? ''), ' ·'), (string) ($line['description'] ?? $d['description'] ?? '-'), $this->money($amount)];
            }
        } else {
            $account = $type === 'cash_in' || $type === 'bank_in' ? ($d['to_account'] ?? []) : ($d['from_account'] ?? []);
            $rows[] = ['1', trim(($account['finance_coa']['code'] ?? $account['code'] ?? '').' · '.($account['name'] ?? 'Treasury'), ' ·'), (string) ($d['description'] ?? '-'), $this->money($d['amount'] ?? 0)];
        }
        $party = [
            'name' => (string) ($d['counterparty_name'] ?? '-'),
            'company' => (string) ($d['counterparty_name'] ?? '-'),
            'address' => '', 'phone' => '', 'email' => '',
        ];
        return $this->base($type, $map[0], $map[1], (string) ($d['treasury_number'] ?? '-'), (string) ($d['transaction_date'] ?? ''), [
            'status' => strtoupper((string) ($d['status'] ?? '-')),
            'left_party' => $this->party($map[2], $party),
            'right_party' => null,
            'meta' => [
                ['label' => 'Reference', 'value' => (string) ($d['reference_number'] ?? '-')],
                ['label' => 'Warehouse', 'value' => trim(($d['warehouse']['code'] ?? '').' · '.($d['warehouse']['name'] ?? ''), ' ·') ?: '-'],
            ],
            'columns' => [
                ['label' => 'No', 'align' => 'left', 'width' => 8],
                ['label' => 'COA', 'align' => 'left', 'width' => 28],
                ['label' => 'Uraian', 'align' => 'left', 'width' => 44],
                ['label' => 'Jumlah', 'align' => 'right', 'width' => 20],
            ],
            'rows' => $rows,
            'totals' => [['label' => 'Total', 'value' => $this->money($d['amount'] ?? 0)]],
            'terbilang' => $this->terbilang((float) ($d['amount'] ?? 0)).' rupiah',
            'notes' => (string) ($d['notes'] ?? ''),
            'signatures' => [
                ['label' => 'Staff Finance', 'name' => (string) ($d['created_by_name'] ?? '-'), 'role' => 'Staff Finance'],
                ['label' => 'SPV Finance', 'name' => (string) ($d['submitted_by_name'] ?? '-'), 'role' => 'SPV Finance'],
                ['label' => 'Head Finance', 'name' => (string) ($d['approved_by_name'] ?? '-'), 'role' => 'Head Finance'],
                ['label' => $map[3], 'name' => (string) ($d['counterparty_name'] ?? '-'), 'role' => $map[3]],
            ],
        ]);
    }

    private function base(string $type, string $title, string $code, string $number, string $date, array $extra): array
    {
        return array_merge([
            'schema_version' => 1,
            'template_source' => 'PT Aneka Produk Berkah.xlsx',
            'type' => $type, 'title' => $title, 'code' => $code,
            'number' => $number, 'date' => $this->date($date),
            'company' => self::COMPANY,
            'status' => '-', 'reference' => null, 'left_party' => null, 'right_party' => null,
            'meta' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'terbilang' => null, 'notes' => '', 'signatures' => [], 'attachments' => [],
            'generated_at' => now('Asia/Jakarta')->toIso8601String(),
        ], $extra);
    }

    private function warehouse(string $warehouseId): array
    {
        $w = DB::table('outlets')->where('id', $warehouseId)->first();
        return $w ? ['name' => (string) $w->name, 'company' => self::COMPANY['name'], 'address' => (string) ($w->address ?? self::COMPANY['address']), 'phone' => (string) ($w->phone ?? self::COMPANY['phone']), 'email' => self::COMPANY['email']] : ['name' => 'Warehouse', 'company' => self::COMPANY['name'], 'address' => self::COMPANY['address'], 'phone' => self::COMPANY['phone'], 'email' => self::COMPANY['email']];
    }

    private function supplier(?string $id): array
    {
        if (! $id) return [];
        $s = DB::table('pur_supplier_sources')->where('id', $id)->first();
        return $s ? ['name' => (string) ($s->contact_name ?: $s->name), 'company' => (string) $s->name, 'address' => (string) ($s->address ?? ''), 'phone' => (string) ($s->phone ?? ''), 'email' => (string) ($s->email ?? '')] : [];
    }

    private function party(string $label, array $p): array
    {
        return ['label' => $label, 'name' => (string) ($p['name'] ?? '-'), 'company' => (string) ($p['company'] ?? $p['name'] ?? '-'), 'address' => (string) ($p['address'] ?? ''), 'phone' => (string) ($p['phone'] ?? ''), 'email' => (string) ($p['email'] ?? '')];
    }

    private function person(mixed $p): string
    {
        if (! is_array($p)) return '-';
        $name = trim((string) ($p['name'] ?? ''));
        $nisj = trim((string) ($p['nisj'] ?? ''));
        return $name !== '' ? $name.($nisj !== '' ? ' · '.$nisj : '') : '-';
    }

    private function qty(mixed $value): string
    {
        $v = (float) $value;
        $formatted = number_format($v, 4, '.', ',');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function money(mixed $value): string { return 'Rp '.number_format((float) $value, 0, ',', '.'); }

    private function date(mixed $value): string
    {
        if (! $value) return '-';
        try { return \Carbon\Carbon::parse($value)->timezone('Asia/Jakarta')->format('d/m/Y'); } catch (\Throwable) { return substr((string) $value, 0, 10); }
    }

    private function dateTime(mixed $value): string
    {
        if (! $value) return '-';
        try { return \Carbon\Carbon::parse($value)->timezone('Asia/Jakarta')->format('d/m/Y H:i'); } catch (\Throwable) { return (string) $value; }
    }

    private function terbilang(float $amount): string
    {
        $n = (int) round(abs($amount));
        if ($n === 0) return 'nol';
        return trim($this->spell($n));
    }

    private function spell(int $n): string
    {
        $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($n < 12) return $words[$n];
        if ($n < 20) return $this->spell($n - 10).' belas';
        if ($n < 100) return $this->spell(intdiv($n, 10)).' puluh '.($n % 10 ? $this->spell($n % 10) : '');
        if ($n < 200) return 'seratus '.($n > 100 ? $this->spell($n - 100) : '');
        if ($n < 1000) return $this->spell(intdiv($n, 100)).' ratus '.($n % 100 ? $this->spell($n % 100) : '');
        if ($n < 2000) return 'seribu '.($n > 1000 ? $this->spell($n - 1000) : '');
        if ($n < 1000000) return $this->spell(intdiv($n, 1000)).' ribu '.($n % 1000 ? $this->spell($n % 1000) : '');
        if ($n < 1000000000) return $this->spell(intdiv($n, 1000000)).' juta '.($n % 1000000 ? $this->spell($n % 1000000) : '');
        if ($n < 1000000000000) return $this->spell(intdiv($n, 1000000000)).' miliar '.($n % 1000000000 ? $this->spell($n % 1000000000) : '');
        return $this->spell(intdiv($n, 1000000000000)).' triliun '.($n % 1000000000000 ? $this->spell($n % 1000000000000) : '');
    }
}
