<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Services\GeneralAffair\DriveInventoryMasterService;
use App\Services\GeneralAffair\DriveInventoryStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GeneralAffairDriveInventoryController extends Controller
{
    public function __construct(
        private readonly DriveInventoryStorageService $drive,
        private readonly DriveInventoryMasterService $masters,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['nullable','string','max:700'],
            'search' => ['nullable','string','max:180'],
            'page' => ['nullable','integer','min:1'],
            'per_page' => ['nullable','integer','min:20','max:200'],
        ]);
        try {
            return response()->json(['data' => $this->drive->browse($data['path'] ?? '', $data)]);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function meta(): JsonResponse
    {
        return response()->json(['data' => $this->drive->meta()]);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['nullable','string','max:700'],
            'name' => ['required','string','max:180'],
        ]);
        try {
            $result = $this->drive->createFolder($data['path'] ?? '', (string) $data['name'], $this->actor($request));
            return response()->json(['data' => $result, 'message' => 'Folder berhasil dibuat.'], 201);
        } catch (\InvalidArgumentException $e) { abort(422, $e->getMessage()); }
    }

    public function renameFolder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required','string','max:700'],
            'name' => ['required','string','max:180'],
        ]);
        try {
            $result = $this->drive->renameFolder((string) $data['path'], (string) $data['name'], $this->actor($request));
            return response()->json(['data' => $result, 'message' => 'Folder berhasil di-rename.']);
        } catch (\InvalidArgumentException $e) { abort(422, $e->getMessage()); }
    }

    public function move(Request $request): JsonResponse
    {
        $data = $request->validate([
            'paths' => ['required','array','min:1','max:200'],
            'paths.*' => ['required','string','max:700'],
            'destination_path' => ['nullable','string','max:700'],
        ]);
        try {
            $result = $this->drive->move($data['paths'], $data['destination_path'] ?? '', $this->actor($request));
            return response()->json(['data' => $result, 'message' => $result['moved'].' item berhasil dipindahkan.']);
        } catch (\InvalidArgumentException $e) { abort(422, $e->getMessage()); }
    }

    public function delete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'paths' => ['required','array','min:1','max:200'],
            'paths.*' => ['required','string','max:700'],
            'confirmation' => ['required', Rule::in(['HAPUS'])],
        ]);
        try {
            $result = $this->drive->delete($data['paths'], $this->actor($request));
            return response()->json(['data' => $result, 'message' => $result['deleted'].' item berhasil dihapus.']);
        } catch (\InvalidArgumentException $e) { abort(422, $e->getMessage()); }
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'folder_path' => ['nullable','string','max:700'],
            'file' => ['required','file','max:20480'],
        ]);
        try {
            $result = $this->drive->upload((string) ($data['folder_path'] ?? ''), $request->file('file'), $this->actor($request));
            return response()->json(['data' => $result, 'message' => 'File berhasil diupload.'], 201);
        } catch (\InvalidArgumentException $e) { abort(422, $e->getMessage()); }
    }

    public function download(Request $request): BinaryFileResponse
    {
        $data = $request->validate(['path' => ['required','string','max:700']]);
        try {
            $file = $this->drive->fileForDownload((string) $data['path']);
            return response()->download($file['absolute'], $file['name']);
        } catch (\InvalidArgumentException $e) { abort(422, $e->getMessage()); }
    }

    public function mapOutlet(Request $request, string $outletId): JsonResponse
    {
        $data = $request->validate(['folder_path' => ['required','string','max:700']]);
        try {
            $result = $this->drive->mapOutlet($outletId, (string) $data['folder_path'], $this->actor($request));
            return response()->json(['data' => $result, 'message' => 'Destinasi outlet berhasil disimpan dan Master Inventory disinkronkan.']);
        } catch (\InvalidArgumentException $e) { abort(422, $e->getMessage()); }
    }

    public function unmapOutlet(Request $request, string $outletId): JsonResponse
    {
        $result = $this->drive->unmapOutlet($outletId, $this->actor($request));
        return response()->json(['data' => $result, 'message' => 'Mapping outlet berhasil dilepas.']);
    }

    public function syncMasters(Request $request): JsonResponse
    {
        $data = $request->validate(['outlet_id' => ['nullable','string','max:64']]);
        if (filled($data['outlet_id'] ?? null)) {
            $result = $this->masters->regenerateForOutlet((string) $data['outlet_id']);
            if (! $result) abort(422, 'Outlet belum memiliki mapping folder Drive Inventory.');
            return response()->json(['data' => ['generated' => 1, 'failed' => 0, 'results' => [$result], 'errors' => []], 'message' => 'Master Inventory outlet berhasil diperbarui.']);
        }

        $result = $this->masters->syncAll();
        $message = $result['failed'] > 0
            ? 'Sinkronisasi Master Inventory selesai dengan catatan.'
            : 'Seluruh Master Inventory berhasil disinkronkan.';
        return response()->json(['data' => $result, 'message' => $message]);
    }

    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();
        return $id ? (string) $id : null;
    }
}
