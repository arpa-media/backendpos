<?php

namespace App\Services\Warehouse\StockV3;

use App\Models\Warehouse\WarehousePricePolicyV3;
use App\Models\Warehouse\WarehouseSku;
use App\Services\Support\SimpleXlsxService;
use App\Services\Warehouse\Billing\WarehouseBillingUomService;
use App\Services\Warehouse\Billing\WarehouseOutgoingInvoiceRepriceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WarehouseStockPriceSpreadsheetService
{
    private const HEADERS = [
        'target_type','target_code','target_name','sku_code','sku_name',
        'base_uom','price_uom','price_conversion_factor','price',
        'effective_from','effective_to','is_active',
    ];

    public function __construct(
        private readonly SimpleXlsxService $xlsx,
        private readonly WarehouseBillingUomService $billing,
        private readonly WarehouseOutgoingInvoiceRepriceService $reprice,
    ) {}

    public function template(string $warehouseId, string $targetType, string $targetId): Response
    {
        [$targetCode, $targetName] = $this->targetIdentity($warehouseId, $targetType, $targetId);
        $rows = [self::HEADERS];
        foreach ($this->skus() as $sku) {
            $existing = WarehousePricePolicyV3::query()
                ->where('warehouse_id', $warehouseId)->where('target_type', $targetType)
                ->where('target_id', $targetId)->where('sku_id', $sku->id)->first();
            $uom = $this->workbookUom($sku, $existing);
            $rows[] = [
                $targetType, $targetCode, $targetName, $sku->sku_code, $sku->name,
                (string) ($sku->baseUom?->code ?? ''),
                $uom['code'], $uom['factor'],
                $existing ? (float) $existing->price : '',
                $existing?->effective_from?->format('Y-m-d') ?? now('Asia/Jakarta')->format('Y-m-d'),
                $existing?->effective_to?->format('Y-m-d') ?? '',
                $existing ? ($existing->is_active ? 'TRUE' : 'FALSE') : 'TRUE',
            ];
        }

        return $this->xlsx->downloadWorkbook(
            'template_harga_'.$targetType.'_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $targetCode).'_'.now()->format('Ymd_His').'.xlsx',
            [
                ['name' => 'HARGA', 'rows' => $rows],
                ['name' => 'PETUNJUK', 'rows' => [
                    ['PETUNJUK IMPORT HARGA WAREHOUSE'],
                    ['1', 'Harga selalu mempunyai UOM harga. Stock tetap disimpan pada Base UOM.'],
                    ['2', 'Contoh: Base UOM GR, price_uom PAX, factor 1000, price 50000 berarti 1 PAX = 1000 GR dan harga Rp50.000/PAX.'],
                    ['3', 'price_conversion_factor hanya informasi; sistem selalu mengambil faktor terbaru dari Master SKU/UOM.'],
                    ['4', 'price_uom harus merupakan Base UOM, Purchase UOM, atau UOM aktif SKU yang mempunyai konversi ke Base UOM.'],
                    ['5', 'Baris baru INSERT; nilai berubah UPDATE; nilai sama SKIP. Draft Outgoing/Purchasing Invoice terkait otomatis direprice.'],
                    ['6', 'Invoice yang sudah issued/paid tidak diubah otomatis untuk menjaga audit trail.'],
                    ['7', 'effective_to boleh kosong. is_active menerima TRUE/FALSE atau 1/0.'],
                ]],
            ]
        );
    }

    public function export(string $warehouseId, string $targetType, ?string $targetId = null): Response
    {
        $query = WarehousePricePolicyV3::query()->where('warehouse_id', $warehouseId)->where('target_type', $targetType);
        if ($targetId) $query->where('target_id', $targetId);
        $policies = $query->get()->keyBy(fn ($row) => $row->target_id.'|'.$row->sku_id);
        $targets = $targetId ? [$this->targetRecord($warehouseId, $targetType, $targetId)] : $this->targets($warehouseId, $targetType);
        $rows = [self::HEADERS];
        foreach ($targets as $target) {
            foreach ($this->skus() as $sku) {
                $policy = $policies->get($target->id.'|'.$sku->id);
                $uom = $this->workbookUom($sku, $policy);
                $rows[] = [
                    $targetType, (string) $target->code, (string) $target->name,
                    $sku->sku_code, $sku->name, (string) ($sku->baseUom?->code ?? ''),
                    $uom['code'], $uom['factor'],
                    $policy ? (float) $policy->price : '',
                    $policy?->effective_from?->format('Y-m-d') ?? '',
                    $policy?->effective_to?->format('Y-m-d') ?? '',
                    $policy ? ($policy->is_active ? 'TRUE' : 'FALSE') : 'TRUE',
                ];
            }
        }
        return $this->xlsx->download('harga_warehouse_'.$targetType.'_'.now()->format('Ymd_His').'.xlsx', 'HARGA', $rows);
    }

    public function import(UploadedFile $file, string $warehouseId, string $userId): array
    {
        $worksheets = $this->xlsx->readWorksheets($file);
        $sheet = collect($worksheets)->first(fn ($s) => strtoupper(trim($s['name'])) === 'HARGA') ?? ($worksheets[0] ?? null);
        if (! $sheet || count($sheet['rows']) < 2) throw ValidationException::withMessages(['file' => ['Worksheet HARGA kosong atau tidak ditemukan.']]);

        $header = array_map(fn ($v) => strtolower(trim((string) $v)), $sheet['rows'][0]);
        $map = array_flip($header);
        foreach (['target_type','target_code','sku_code','price'] as $required) {
            if (! array_key_exists($required, $map)) throw ValidationException::withMessages(['file' => ["Header {$required} wajib tersedia."]]);
        }

        $skus = $this->skus();
        $skuMap = $skus->keyBy(fn ($sku) => strtoupper($sku->sku_code));
        $targetMaps = [
            'outlet' => collect($this->targets($warehouseId, 'outlet'))->keyBy(fn ($x) => strtoupper($x->code)),
            'customer' => collect($this->targets($warehouseId, 'customer'))->keyBy(fn ($x) => strtoupper($x->code)),
        ];
        $existingPolicies = WarehousePricePolicyV3::query()->where('warehouse_id', $warehouseId)->get()->keyBy(fn ($x) => $x->target_type.'|'.$x->target_id.'|'.$x->sku_id);
        $prepared = [];
        $errors = [];

        foreach (array_slice($sheet['rows'], 1) as $offset => $row) {
            $line = $offset + 2;
            if (collect($row)->every(fn ($v) => trim((string) $v) === '')) continue;
            $get = fn ($key) => trim((string) ($row[$map[$key] ?? -1] ?? ''));
            $targetType = strtolower($get('target_type'));
            $targetCode = strtoupper($get('target_code'));
            $skuCode = strtoupper($get('sku_code'));
            $rawPrice = str_replace(',', '', $get('price'));
            if (! in_array($targetType, ['outlet','customer'], true)) { $errors[] = "Baris {$line}: target_type wajib outlet/customer."; continue; }
            $target = $targetMaps[$targetType]->get($targetCode);
            $sku = $skuMap->get($skuCode);
            if (! $target) { $errors[] = "Baris {$line}: target {$targetCode} tidak ditemukan/aktif."; continue; }
            if (! $sku) { $errors[] = "Baris {$line}: SKU {$skuCode} tidak ditemukan/aktif."; continue; }
            if ($rawPrice === '') continue;
            if (! is_numeric($rawPrice) || (float) $rawPrice < 0) { $errors[] = "Baris {$line}: price tidak valid."; continue; }

            $existing = $existingPolicies->get($targetType.'|'.$target->id.'|'.$sku->id);
            $priceUomCode = array_key_exists('price_uom', $map) ? strtoupper($get('price_uom')) : '';
            if ($priceUomCode === '') {
                $priceUomCode = strtoupper((string) ($existing?->price_uom_code_snapshot ?: $this->workbookUom($sku, $existing)['code']));
            }
            try {
                $priceUom = $this->billing->resolveByCodeForSku((string) $sku->id, $priceUomCode);
            } catch (ValidationException $e) {
                $errors[] = "Baris {$line}: ".collect($e->errors())->flatten()->first();
                continue;
            }

            try {
                $effectiveFrom = $this->dateValue($get('effective_from') ?: now('Asia/Jakarta')->format('Y-m-d'));
                $effectiveTo = $get('effective_to') !== '' ? $this->dateValue($get('effective_to')) : null;
            } catch (\Throwable) {
                $errors[] = "Baris {$line}: tanggal efektif tidak valid. Gunakan YYYY-MM-DD.";
                continue;
            }
            if ($effectiveTo && $effectiveTo < $effectiveFrom) { $errors[] = "Baris {$line}: effective_to tidak boleh sebelum effective_from."; continue; }

            $isActiveRaw = strtoupper($get('is_active') ?: 'TRUE');
            if (! in_array($isActiveRaw, ['TRUE','FALSE','1','0','YA','TIDAK','YES','NO','AKTIF','NONAKTIF'], true)) {
                $errors[] = "Baris {$line}: is_active tidak valid."; continue;
            }
            $isActive = in_array($isActiveRaw, ['TRUE','1','YA','YES','AKTIF'], true);
            $prepared[] = [
                'target_type' => $targetType, 'target_id' => (string) $target->id,
                'sku_id' => (string) $sku->id,
                'price_uom_id' => $priceUom['price_uom_id'],
                'price_uom_code_snapshot' => $priceUom['price_uom_code'],
                'price_conversion_factor_snapshot' => $priceUom['conversion_factor'],
                'price_basis' => 'PER_PRICE_UOM', 'price_uom_review_required' => false,
                'price' => round((float) $rawPrice, 6),
                'effective_from' => $effectiveFrom, 'effective_to' => $effectiveTo, 'is_active' => $isActive,
            ];
        }
        if ($errors) throw ValidationException::withMessages(['file' => $errors]);

        $result = DB::transaction(function () use ($prepared, $warehouseId, $userId): array {
            $inserted = $updated = $skipped = 0;
            $changed = [];
            foreach ($prepared as $data) {
                $row = WarehousePricePolicyV3::query()->where([
                    'warehouse_id' => $warehouseId, 'target_type' => $data['target_type'],
                    'target_id' => $data['target_id'], 'sku_id' => $data['sku_id'],
                ])->lockForUpdate()->first();
                if (! $row) {
                    WarehousePricePolicyV3::query()->create([...$data, 'warehouse_id' => $warehouseId, 'created_by_user_id' => $userId, 'updated_by_user_id' => $userId]);
                    $inserted++; $changed[] = $data; continue;
                }
                $hasChanged = abs((float) $row->price - (float) $data['price']) > 0.000001
                    || (string) $row->price_uom_id !== (string) $data['price_uom_id']
                    || abs((float) $row->price_conversion_factor_snapshot - (float) $data['price_conversion_factor_snapshot']) > 0.00000001
                    || (string) ($row->effective_from?->format('Y-m-d') ?? '') !== (string) $data['effective_from']
                    || (string) ($row->effective_to?->format('Y-m-d') ?? '') !== (string) ($data['effective_to'] ?? '')
                    || (bool) $row->is_active !== (bool) $data['is_active']
                    || (bool) $row->price_uom_review_required;
                if (! $hasChanged) { $skipped++; continue; }
                $row->fill([...$data, 'updated_by_user_id' => $userId])->save();
                $updated++; $changed[] = $data;
            }
            return compact('inserted', 'updated', 'skipped', 'changed');
        });

        $repriced = 0;
        foreach ($result['changed'] as $changed) {
            $repriced += $this->reprice->repriceForPolicy($warehouseId, $changed['target_type'], $changed['target_id'], $changed['sku_id'], $userId);
        }
        unset($result['changed']);
        return [...$result, 'processed' => count($prepared), 'repriced_draft_invoices' => $repriced];
    }

    private function skus()
    {
        return WarehouseSku::query()
            ->with(['baseUom:id,code,name,symbol', 'purchaseUom:id,code,name,symbol', 'skuUoms.uom:id,code,name,symbol'])
            ->where('is_active', true)
            ->orderBy('sku_code')
            ->get(['id','sku_code','name','base_uom_id','purchase_uom_id','purchase_conversion_factor']);
    }

    public function targets(string $warehouseId, string $targetType): array
    {
        if ($targetType === 'outlet') {
            return DB::table('wh_chain_supplies as cs')->join('outlets as o', 'o.id', '=', 'cs.outlet_id')
                ->where('cs.warehouse_id', $warehouseId)->where('cs.is_active', true)
                ->select('o.id','o.code','o.name')->orderBy('o.code')->get()->all();
        }
        return DB::table('wh_customers')->whereNull('deleted_at')->where('is_active', true)
            ->select('id','code','name')->orderBy('code')->get()->all();
    }

    private function workbookUom(WarehouseSku $sku, ?WarehousePricePolicyV3 $policy): array
    {
        if ($policy?->price_uom_id) {
            return [
                'code' => (string) ($policy->price_uom_code_snapshot ?: $sku->baseUom?->code ?: ''),
                'factor' => (float) ($policy->price_conversion_factor_snapshot ?: 1),
            ];
        }
        if ($sku->purchase_uom_id && (float) $sku->purchase_conversion_factor > 0) {
            return ['code' => (string) ($sku->purchaseUom?->code ?: $sku->baseUom?->code ?: ''), 'factor' => (float) $sku->purchase_conversion_factor];
        }
        return ['code' => (string) ($sku->baseUom?->code ?: ''), 'factor' => 1.0];
    }

    private function targetRecord(string $warehouseId, string $targetType, string $targetId): object
    {
        $target = collect($this->targets($warehouseId, $targetType))->first(fn ($x) => (string) $x->id === $targetId);
        if (! $target) throw ValidationException::withMessages(['target_id' => ['Target harga tidak ditemukan atau tidak aktif.']]);
        return $target;
    }

    private function targetIdentity(string $warehouseId, string $targetType, string $targetId): array
    {
        $target = $this->targetRecord($warehouseId, $targetType, $targetId);
        return [(string) $target->code, (string) $target->name];
    }

    private function dateValue(string $value): string
    {
        $date = Carbon::createFromFormat('Y-m-d', trim($value));
        if (! $date || $date->format('Y-m-d') !== trim($value)) throw new \InvalidArgumentException('Invalid date');
        return $date->format('Y-m-d');
    }
}
