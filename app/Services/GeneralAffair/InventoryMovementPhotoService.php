<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\InventoryMovement;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InventoryMovementPhotoService
{
    public function store(UploadedFile $file, string $movementNumber, string $itemCode, string $itemName, CarbonInterface $movementAt): array
    {
        $disk = 'public';
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) $extension = 'jpg';

        $movement = Str::slug($movementNumber, '-') ?: 'movement';
        $code = Str::slug($itemCode, '-') ?: 'item';
        $name = Str::slug($itemName, '-') ?: 'barang';
        $filename = $movement.'_'.$movementAt->format('Ymd_His').'_'.$code.'_'.$name.'.'.$extension;
        $folder = 'general-affair/profile-inventory/logs/'.$movementAt->format('Y/m');
        $path = $file->storeAs($folder, $filename, $disk);

        return [
            'photo_disk' => $disk,
            'photo_path' => $path,
            'photo_original_name' => $file->getClientOriginalName(),
            'photo_mime_type' => $file->getMimeType(),
            'photo_size_bytes' => (int) $file->getSize(),
        ];
    }

    public function delete(array $metadata): void
    {
        $path = trim((string) ($metadata['photo_path'] ?? ''));
        if ($path === '') return;
        try { Storage::disk((string) ($metadata['photo_disk'] ?? 'public'))->delete($path); } catch (\Throwable) {}
    }

    public function url(InventoryMovement $movement): ?string
    {
        $path = trim((string) ($movement->photo_path ?? ''));
        if ($path === '') return null;
        try { return Storage::disk((string) ($movement->photo_disk ?: 'public'))->url($path); }
        catch (\Throwable) { return null; }
    }
}
