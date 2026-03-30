<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class AppUpdateController extends Controller
{
    public function android(Request $request)
    {
        $variant = strtolower(trim((string) $request->input('variant', 'bluetooth')));
        $allowedVariants = array_keys(config('pos_app_update.android_variants', []));

        if (!in_array($variant, $allowedVariants, true)) {
            return ApiResponse::error('Variant update Android tidak dikenal.', 'APP_UPDATE_VARIANT_INVALID', 404);
        }

        $config = config("pos_app_update.android_variants.{$variant}", []);
        $baseDir = public_path(trim((string) ($config['public_dir'] ?? "app-updates/android/{$variant}"), '/'));
        $manifestFile = trim((string) ($config['manifest'] ?? 'manifest.json'));
        $manifestPath = $baseDir . DIRECTORY_SEPARATOR . $manifestFile;

        if (!File::exists($manifestPath)) {
            return ApiResponse::ok([
                'available' => false,
                'variant' => $variant,
                'message' => 'Manifest update belum tersedia.',
            ], 'OK');
        }

        $raw = File::get($manifestPath);
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            return ApiResponse::error('Manifest update Android tidak valid.', 'APP_UPDATE_MANIFEST_INVALID', 500);
        }

        $apkFile = trim((string) Arr::get($manifest, 'apk_file', ''));
        if ($apkFile === '') {
            return ApiResponse::error('apk_file wajib diisi pada manifest update Android.', 'APP_UPDATE_APK_MISSING', 500);
        }

        $apkPath = $baseDir . DIRECTORY_SEPARATOR . $apkFile;
        if (!File::exists($apkPath)) {
            return ApiResponse::error('File APK terbaru tidak ditemukan.', 'APP_UPDATE_APK_NOT_FOUND', 404);
        }

        $publicDir = trim((string) ($config['public_dir'] ?? "app-updates/android/{$variant}"), '/');
        $apkUrl = url($publicDir . '/' . rawurlencode($apkFile));

        return ApiResponse::ok([
            'available' => true,
            'variant' => $variant,
            'version_name' => (string) Arr::get($manifest, 'version_name', ''),
            'version_code' => (int) Arr::get($manifest, 'version_code', 0),
            'mandatory' => (bool) Arr::get($manifest, 'mandatory', false),
            'released_at' => Arr::get($manifest, 'released_at'),
            'notes' => array_values(array_filter((array) Arr::get($manifest, 'notes', []), fn ($item) => trim((string) $item) !== '')),
            'apk_file' => $apkFile,
            'apk_size_bytes' => (int) File::size($apkPath),
            'apk_url' => $apkUrl,
        ], 'OK');
    }
}
