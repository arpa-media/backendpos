<?php

namespace App\Services\GeneralAffair;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetInventoryPhotoService
{
    public function store(Model $model, UploadedFile $file, string $kind, string $code, string $itemName, ?string $outletCode): void
    {
        $disk = 'public';
        $outlet = Str::slug((string) ($outletCode ?: 'unknown-outlet')) ?: 'unknown-outlet';
        $safeCode = Str::slug($code, '-') ?: 'item';
        $safeName = Str::slug($itemName, '-') ?: 'barang';
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (! in_array($extension, ['jpg','jpeg','png','webp'], true)) $extension = 'jpg';
        $filename = $safeCode.'_'.now()->format('Ymd').'_'.$safeName.'.'.$extension;
        $folder = 'general-affair/profile-inventory/'.($kind === 'asset' ? 'assets' : 'inventory').'/'.$outlet;

        $oldDisk = (string) ($model->photo_disk ?: '');
        $oldPath = (string) ($model->photo_path ?: '');
        $path = $file->storeAs($folder, $filename, $disk);

        $model->forceFill([
            'photo_disk' => $disk,
            'photo_path' => $path,
            'photo_original_name' => $file->getClientOriginalName(),
            'photo_mime_type' => $file->getMimeType(),
            'photo_size_bytes' => (int) $file->getSize(),
        ])->save();

        if ($oldPath !== '' && ($oldDisk !== $disk || $oldPath !== $path)) {
            try { Storage::disk($oldDisk !== '' ? $oldDisk : $disk)->delete($oldPath); } catch (\Throwable) {}
        }
    }

    public function url(Model $model): ?string
    {
        $path = trim((string) ($model->photo_path ?? ''));
        if ($path === '') return null;
        try { return Storage::disk((string) ($model->photo_disk ?: 'public'))->url($path); }
        catch (\Throwable) { return null; }
    }
}
