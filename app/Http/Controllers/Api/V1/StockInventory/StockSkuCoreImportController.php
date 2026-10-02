<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Spreadsheet\SpreadsheetChunkImportService;
use App\Services\StockInventory\StockSkuCoreImportAdapter;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class StockSkuCoreImportController extends Controller
{
    public function __construct(
        private readonly SpreadsheetChunkImportService $chunks,
        private readonly StockSkuCoreImportAdapter $adapter,
    ) {}

    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'batch_id' => ['required', 'ulid'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'chunk_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $result = $this->chunks->process(
                $request->user(), (string) $data['batch_id'], (int) ($data['offset'] ?? 0),
                $this->adapter->aliases(), $this->adapter->requiredHeaders(),
                fn (array $row, array $context): array => $this->adapter->handle($row, $context),
                (int) ($data['chunk_size'] ?? 30), StockSkuCoreImportAdapter::MODULE_KEY,
            );
            return ApiResponse::ok($result, 'Chunk import SKU selesai.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'STOCK_SKU_CORE_IMPORT_INVALID', 422);
        }
    }
}
