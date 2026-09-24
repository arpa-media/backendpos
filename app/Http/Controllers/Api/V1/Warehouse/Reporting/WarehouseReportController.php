<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Reporting;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\WarehouseReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseReportController extends Controller
{
    public function options(Request $request, WarehouseReportingService $service): JsonResponse
    {
        try {
            return response()->json(['data' => $service->options($request)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function show(Request $request, string $reportKey, WarehouseReportingService $service): JsonResponse
    {
        try {
            return response()->json(['data' => $service->report($request, $reportKey)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function export(Request $request, string $reportKey, WarehouseReportingService $service): StreamedResponse|JsonResponse
    {
        try {
            $payload = $service->report($request, $reportKey);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $rows = (array) ($payload['rows'] ?? []);
        $columns = (array) ($payload['columns'] ?? []);
        $service->recordRun($request, $reportKey, 'csv', count($rows));
        $date = now()->format('Ymd_His');
        $filename = 'warehouse_'.Str::slug($reportKey, '_').'_'.$date.'.csv';

        return response()->streamDownload(function () use ($rows, $columns): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            $keys = collect($columns)->pluck('key')->filter()->values()->all();
            if ($keys === [] && $rows !== []) {
                $keys = array_keys($rows[0]);
            }
            $labels = collect($columns)->mapWithKeys(fn (array $column): array => [
                (string) ($column['key'] ?? '') => (string) ($column['label'] ?? $column['key'] ?? ''),
            ])->all();
            fputcsv($handle, array_map(fn (string $key): string => $labels[$key] ?? Str::headline($key), $keys));
            foreach ($rows as $row) {
                fputcsv($handle, array_map(function (string $key) use ($row) {
                    $value = $row[$key] ?? null;
                    if (is_array($value) || is_object($value)) {
                        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    if (is_bool($value)) {
                        return $value ? 'true' : 'false';
                    }
                    return $value;
                }, $keys));
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function markPrinted(Request $request, WarehouseReportingService $service): JsonResponse
    {
        $validated = $request->validate([
            'report_key' => ['required', 'string', 'max:80'],
            'row_count' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (! array_key_exists($validated['report_key'], WarehouseReportingService::REPORTS)) {
            return response()->json(['message' => 'Report Warehouse tidak dikenal.'], 422);
        }

        $service->recordRun(
            $request,
            $validated['report_key'],
            'print',
            (int) ($validated['row_count'] ?? 0),
            (array) ($validated['metadata'] ?? [])
        );

        return response()->json(['message' => 'Print report tercatat.']);
    }
}
