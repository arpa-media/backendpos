# Iterasi 05 — Master Import Data UOM All Item Warehouse

Tanggal: 2026-08-19

## Scope
Membuat master Excel import UOM untuk Portal Warehouse → Stock → All Item.

## Source
- `data_master.xlsx` sheet `SKU`
- Backend contract: `WarehouseSkuUomBulkService`
- Prerequisite: Iterasi 02 Data SKU + Iterasi 03 UOM Conversion

## Output
- Source SKU: 884
- Generated UOM mapping rows: 1768
- Mapping per SKU: 2
- Purchase UOM: PAX
- Base UOM: GR / ML / PCS
- Validation errors: 0
- Duplicate business key: 0

## Import design
Setiap SKU memiliki dua row:
1. `PAX` — `adopt=TRUE`, `is_purchase_default=TRUE`, `is_request_enabled=TRUE`, `is_active=TRUE`.
2. Base UOM — `adopt=TRUE`, `is_purchase_default=FALSE`, `is_request_enabled=TRUE`, `is_active=TRUE`.

**PAX diletakkan lebih dulu** agar importer aman bila Base UOM sebelumnya merupakan purchase default.

`conversion_factor_hpp` dan `conversion_path` hanya informasi. Backend menghitung ulang conversion factor menggunakan graph HPP/COGS ketika import.

## Import order
1. Import Iterasi 02 Data SKU.
2. Import Iterasi 03 UOM Conversion.
3. Portal Warehouse → Stock → All Item → Import UOM → upload `Master_Import_Warehouse_All_Item_UOM_Iter05_20260819.xlsx`.

## Access Matrix
Tidak ada menu/action baru.
Permission existing:
- `warehouse.inventory.item.view`
- `warehouse.inventory.item.update`

## Standalone
Patch hanya menambahkan folder `backend/database/data-imports/master-data-draft/iter05_warehouse_all_item_uom_20260819/`.
Tidak menimpa patch Iterasi 01–04.
