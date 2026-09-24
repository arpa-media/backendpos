<?php

namespace App\Services\Warehouse\Documents\I05;

use App\Services\Warehouse\SalesV3\WarehouseSalesDemandV3Service;
use Illuminate\Support\Facades\DB;

final class WarehouseProductionRequestCanonicalI05Service
{
    private const COMPANY = [
        'name' => 'PT. Aneka Produk Berkah',
        'address' => 'Jl. Candi Penataran No. 43C, Malang, Jawa Timur',
        'phone' => '+62822 3461 7007',
        'email' => 'warehouse.jayagroup@gmail.com',
        'brand_footer' => 'TOKO KOPI JAYA',
        'logo' => '/logoWarehouse.png',
    ];

    public function __construct(private readonly WarehouseSalesDemandV3Service $sales) {}

    public function document(string $warehouseId, string $id): array
    {
        $d = $this->sales->productionRequestDetail($warehouseId, $id);
        $warehouse = $this->warehouse($warehouseId);
        $rows = [];
        $requestedBase = 0.0;
        $approvedBase = 0.0;
        foreach ((array) ($d['items'] ?? []) as $index => $item) {
            $requestedBase += (float) ($item['requested_qty_base'] ?? 0);
            $approvedBase += (float) ($item['approved_qty_base'] ?? 0);
            $rows[] = [
                (string) ($index + 1),
                trim(($item['sku_code'] ?? '').' · '.($item['item_name'] ?? ''), ' ·'),
                $this->qty($item['requested_qty_uom'] ?? 0),
                (string) ($item['uom_code'] ?? 'UNIT'),
                $this->qty($item['approved_qty_uom'] ?? 0),
            ];
        }

        return [
            'schema_version' => 1,
            'template_source' => 'PT Aneka Produk Berkah.xlsx',
            'type' => 'production-request',
            'title' => 'Production Request',
            'code' => 'PROD-REQ',
            'number' => (string) ($d['request_number'] ?? '-'),
            'date' => $this->date($d['requested_at'] ?? $d['production_date'] ?? null),
            'company' => self::COMPANY,
            'status' => strtoupper((string) ($d['status'] ?? '-')),
            'reference' => (string) ($d['production_number'] ?? ''),
            'left_party' => [
                'label' => 'Production Order',
                'name' => (string) ($d['production_number'] ?? '-'),
                'company' => self::COMPANY['name'],
                'address' => 'Production Date: '.$this->date($d['production_date'] ?? null),
                'phone' => '', 'email' => '',
            ],
            'right_party' => [
                'label' => 'Warehouse',
                'name' => (string) ($warehouse['name'] ?? 'Warehouse'),
                'company' => self::COMPANY['name'],
                'address' => (string) ($warehouse['address'] ?? self::COMPANY['address']),
                'phone' => (string) ($warehouse['phone'] ?? self::COMPANY['phone']),
                'email' => self::COMPANY['email'],
            ],
            'meta' => array_values(array_filter([
                ['label' => 'Requested By', 'value' => $this->person($d['requested_by'] ?? null)],
                ['label' => 'Approved By', 'value' => $this->person($d['approved_by'] ?? null)],
                ['label' => 'Material Source', 'value' => 'Production Stock + Warehouse'],
                ['label' => 'Ledger', 'value' => (string) ($d['material_ledger_posting_id'] ?? '-')],
            ], fn ($r) => ($r['value'] ?? '-') !== '-')),
            'columns' => [
                ['label' => 'Item #', 'align' => 'left', 'width' => 9],
                ['label' => 'Deskripsi', 'align' => 'left', 'width' => 43],
                ['label' => 'Requested', 'align' => 'right', 'width' => 16],
                ['label' => 'UOM', 'align' => 'left', 'width' => 12],
                ['label' => 'Approved', 'align' => 'right', 'width' => 20],
            ],
            'rows' => $rows,
            'totals' => [
                ['label' => 'Total Item', 'value' => (string) count($rows)],
                ['label' => 'Requested Base', 'value' => $this->qty($requestedBase)],
                ['label' => 'Approved Base', 'value' => $this->qty($approvedBase)],
            ],
            'terbilang' => null,
            'notes' => trim((string) (($d['approval_notes'] ?? '') ?: ($d['production_notes'] ?? ''))),
            'signatures' => [
                ['label' => 'Diajukan oleh', 'name' => $this->person($d['requested_by'] ?? null), 'role' => 'Production'],
                ['label' => 'Disetujui oleh', 'name' => $this->person($d['approved_by'] ?? null), 'role' => 'Warehouse'],
            ],
            'attachments' => [],
            'generated_at' => now('Asia/Jakarta')->toIso8601String(),
        ];
    }

    private function warehouse(string $warehouseId): array
    {
        $w = DB::table('outlets')->where('id', $warehouseId)->first();
        return $w ? ['name'=>(string)$w->name,'address'=>(string)($w->address ?? self::COMPANY['address']),'phone'=>(string)($w->phone ?? self::COMPANY['phone'])] : ['name'=>'Warehouse','address'=>self::COMPANY['address'],'phone'=>self::COMPANY['phone']];
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
        $formatted = number_format((float) $value, 4, '.', ',');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function date(mixed $value): string
    {
        if (! $value) return '-';
        try { return \Carbon\Carbon::parse($value)->timezone('Asia/Jakarta')->format('d/m/Y'); }
        catch (\Throwable) { return substr((string) $value, 0, 10); }
    }
}
