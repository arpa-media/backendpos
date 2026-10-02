<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\InventoryAuditLine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InventoryAuditPhotoService
{
    public function store(UploadedFile $file, string $auditNumber, string $itemCode): array
    {
        $disk = 'public';
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $safeCode = Str::slug($itemCode, '-') ?: 'item';
        $safeAudit = Str::slug($auditNumber, '-') ?: 'audit';
        $name = $safeCode.'_'.now()->format('Ymd_His').'_'.Str::lower(Str::random(6)).'.'.$ext;
        $path = $file->storeAs('general-affair/profile-inventory/audits/'.$safeAudit, $name, $disk);
        return [
            'photo_disk' => $disk, 'photo_path' => $path, 'photo_original_name' => $file->getClientOriginalName(),
            'photo_mime_type' => $file->getMimeType(), 'photo_size_bytes' => $file->getSize(),
        ];
    }

    public function delete(?string $disk, ?string $path): void
    {
        if (! $disk || ! $path) return;
        try { if (Storage::disk($disk)->exists($path)) Storage::disk($disk)->delete($path); }
        catch (\Throwable) {}
    }

    public function url(InventoryAuditLine $line): ?string
    {
        if (! $line->photo_path) return null;
        try { return Storage::disk($line->photo_disk ?: 'public')->url($line->photo_path); }
        catch (\Throwable) { return null; }
    }
}
