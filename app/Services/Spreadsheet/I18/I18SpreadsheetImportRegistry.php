<?php

namespace App\Services\Spreadsheet\I18;

use InvalidArgumentException;

final class I18SpreadsheetImportRegistry
{
    /** @var array<string,I18SpreadsheetImportAdapter> */
    private array $map;

    public function __construct(
        GaBillDueDateI18Adapter $gaBill,
        GaAssetI18Adapter $gaAsset,
        GaInventoryI18Adapter $gaInventory,
        CogsUomConversionI18Adapter $cogsUom,
        WarehouseStockPriceI18Adapter $warehousePrice,
        WarehouseSkuUomI18Adapter $warehouseSkuUom,
        WarehouseParStockI18Adapter $warehouseParStock,
    ) {
        $this->map = [];
        foreach ([$gaBill,$gaAsset,$gaInventory,$cogsUom,$warehousePrice,$warehouseSkuUom,$warehouseParStock] as $adapter) {
            $this->map[$adapter->moduleKey()] = $adapter;
        }
    }

    public function get(string $moduleKey): I18SpreadsheetImportAdapter
    {
        if (! isset($this->map[$moduleKey])) {
            throw new InvalidArgumentException("Spreadsheet module '{$moduleKey}' belum terdaftar di I18.");
        }
        return $this->map[$moduleKey];
    }
}
