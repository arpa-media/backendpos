<?php

namespace App\Services\Cogs;

use App\Models\Cogs\UomConversion;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class UomConversionSpreadsheetService
{
    private const HEADERS = ['sku_code', 'from_uom_code', 'to_uom_code', 'conversion_factor', 'notes', 'is_active'];

    public function __construct(private readonly SimpleXlsxService $xlsx, private readonly UomConversionGraphService $graph) {}

    public function template(): Response
    {
        return $this->workbook('template_import_uom_conversion.xlsx', []);
    }

    public function export(): Response
    {
        $rows = UomConversion::query()->with(['sku', 'fromUom', 'toUom'])
            ->orderByRaw('sku_id is null desc')->orderBy('sku_id')->orderBy('from_uom_id')->get()
            ->map(fn (UomConversion $row) => [
                (string) ($row->sku?->sku_code ?? ''),
                (string) ($row->fromUom?->code ?? ''),
                (string) ($row->toUom?->code ?? ''),
                (string) $row->conversion_factor,
                (string) ($row->notes ?? ''),
                $row->is_active ? 'TRUE' : 'FALSE',
            ])->all();
        return $this->workbook('uom_conversion_export_'.now()->format('Ymd_His').'.xlsx', $rows);
    }

    public function import(UploadedFile $file, ?string $userId): array
    {
        $worksheets = $this->xlsx->readWorksheets($file);
        $rows = $this->worksheetRows($worksheets, 'DATA UOM CONVERSION');
        if (count($rows) < 2) return ['created'=>0,'updated'=>0,'skipped'=>0,'failed'=>1,'errors'=>[['row'=>1,'message'=>'Data kosong.']]];

        $headerRow = array_shift($rows);
        if (! is_array($headerRow)) {
            return ['created'=>0,'updated'=>0,'skipped'=>0,'failed'=>1,'errors'=>[['row'=>1,'message'=>'Format header worksheet tidak valid.']]];
        }

        $headers = array_map(fn ($v) => strtolower(trim((string) $v)), $headerRow);
        $map = array_flip($headers);
        foreach (['from_uom_code','to_uom_code','conversion_factor'] as $required) {
            if (! isset($map[$required])) return ['created'=>0,'updated'=>0,'skipped'=>0,'failed'=>1,'errors'=>[['row'=>1,'message'=>"Header {$required} wajib tersedia."]]];
        }
        $uoms = StockUom::query()->whereNull('deleted_at')->get()->keyBy(fn ($u) => strtoupper((string) $u->code));
        $skus = StockSku::query()->whereNull('deleted_at')->get()->keyBy(fn ($s) => strtoupper((string) $s->sku_code));
        $result = ['created'=>0,'updated'=>0,'skipped'=>0,'failed'=>0,'errors'=>[]];
        foreach ($rows as $index => $raw) {
            $line=$index+2; $get=fn ($key) => trim((string) ($raw[$map[$key] ?? -1] ?? ''));
            if (collect($raw)->filter(fn ($v) => trim((string) $v)!=='')->isEmpty()) continue;
            try {
                $skuCode=strtoupper($get('sku_code')); $sku=$skuCode===''?null:$skus->get($skuCode);
                if ($skuCode!=='' && ! $sku) throw new \RuntimeException("SKU {$skuCode} tidak ditemukan.");
                $from=$uoms->get(strtoupper($get('from_uom_code'))); $to=$uoms->get(strtoupper($get('to_uom_code')));
                if (! $from || ! $to) throw new \RuntimeException('UOM asal/base tidak ditemukan.');
                $factor=(float) $get('conversion_factor'); if ($factor<=0) throw new \RuntimeException('conversion_factor harus lebih dari 0.');
                $active=$this->bool($get('is_active'), true);
                if ($active) $this->graph->assertAcyclic((string)$from->id,(string)$to->id,null,$sku?->id);
                $key=['sku_id'=>$sku?->id,'from_uom_id'=>$from->id,'to_uom_id'=>$to->id];
                $existing=UomConversion::query()->where($key)->first();
                $payload=['conversion_factor'=>number_format($factor,8,'.',''),'notes'=>$get('notes')?:null,'is_active'=>$active,'updated_by_user_id'=>$userId];
                if (! $existing) { UomConversion::query()->create([...$key,...$payload,'created_by_user_id'=>$userId]); $result['created']++; }
                elseif (! $existing->isDirty($payload) && (string)$existing->conversion_factor===(string)$payload['conversion_factor'] && (string)($existing->notes??'')===(string)($payload['notes']??'') && (bool)$existing->is_active===$active) { $result['skipped']++; }
                else { $existing->fill($payload)->save(); $result['updated']++; }
            } catch (\Throwable $e) { $result['failed']++; if (count($result['errors'])<100) $result['errors'][]=['row'=>$line,'message'=>$e->getMessage()]; }
        }
        return $result;
    }

    /**
     * SimpleXlsxService::readWorksheets() returns a numeric list containing
     * ['name' => string, 'rows' => array]. Keep this normalizer backward
     * compatible with associative worksheet maps used by older implementations.
     *
     * @param array<int|string,mixed> $worksheets
     * @return array<int,array<int,string>>
     */
    private function worksheetRows(array $worksheets, string $preferredName): array
    {
        foreach ($worksheets as $key => $worksheet) {
            if (is_array($worksheet) && array_key_exists('rows', $worksheet)) {
                $name = trim((string) ($worksheet['name'] ?? $key));
                if (strcasecmp($name, $preferredName) === 0) {
                    return is_array($worksheet['rows']) ? $worksheet['rows'] : [];
                }
            }

            if (is_string($key) && strcasecmp(trim($key), $preferredName) === 0) {
                if (is_array($worksheet) && array_key_exists('rows', $worksheet)) {
                    return is_array($worksheet['rows']) ? $worksheet['rows'] : [];
                }
                return is_array($worksheet) ? $worksheet : [];
            }
        }

        $first = reset($worksheets);
        if (is_array($first) && array_key_exists('rows', $first)) {
            return is_array($first['rows']) ? $first['rows'] : [];
        }

        return is_array($first) ? $first : [];
    }

    private function workbook(string $filename, array $data): Response
    {
        $uoms=StockUom::query()->whereNull('deleted_at')->orderBy('code')->get();
        $skus=StockSku::query()->whereNull('deleted_at')->with('baseUom')->orderBy('name')->get();
        return $this->xlsx->downloadWorkbook($filename, [
            ['name'=>'DATA UOM CONVERSION','rows'=>[self::HEADERS,...$data]],
            ['name'=>'MASTER UOM','rows'=>[['uom_code','uom_name','symbol','is_active'],...$uoms->map(fn($u)=>[$u->code,$u->name,$u->symbol,$u->is_active?'TRUE':'FALSE'])->all()]],
            ['name'=>'MASTER SKU','rows'=>[['sku_code','sku_name','base_uom_code'],...$skus->map(fn($s)=>[$s->sku_code,$s->name,$s->baseUom?->code])->all()]],
            ['name'=>'PETUNJUK','rows'=>[['PETUNJUK'],['1','sku_code kosong = konversi global. sku_code terisi = konversi khusus SKU.'],['2','Kunci upsert adalah sku_code + from_uom_code + to_uom_code.'],['3','Data sama akan SKIPPED; data berubah akan UPDATED.']]],
        ]);
    }

    private function bool(string $value, bool $default): bool
    {
        if ($value==='') return $default;
        return in_array(strtoupper($value), ['1','TRUE','YA','YES','AKTIF'], true);
    }
}
