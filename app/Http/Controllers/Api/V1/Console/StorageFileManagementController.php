<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Http\Controllers\Controller;
use App\Services\Console\StorageFileManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StorageFileManagementController extends Controller
{
    public function __construct(private readonly StorageFileManagementService $files) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'disk' => ['nullable', 'in:local,public'],
            'path' => ['nullable', 'string', 'max:1500'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:200'],
        ]);
        return response()->json(['data' => $this->files->browse(
            (string) ($validated['disk'] ?? 'local'),
            $validated['path'] ?? '',
            $validated,
        )]);
    }

    public function createFolder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'disk' => ['required', 'in:local,public'],
            'path' => ['nullable', 'string', 'max:1500'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $data = $this->files->createFolder(
            (string) $validated['disk'],
            $validated['path'] ?? '',
            (string) $validated['name'],
            $request->user()?->getAuthIdentifier() ? (string) $request->user()->getAuthIdentifier() : null,
        );
        return response()->json(['data' => $data, 'message' => 'Folder berhasil dibuat.'], 201);
    }

    public function move(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'disk' => ['required', 'in:local,public'],
            'paths' => ['required', 'array', 'min:1', 'max:200'],
            'paths.*' => ['required', 'string', 'max:1500'],
            'destination_path' => ['nullable', 'string', 'max:1500'],
        ]);
        $data = $this->files->move(
            (string) $validated['disk'],
            $validated['paths'],
            $validated['destination_path'] ?? '',
            $request->user()?->getAuthIdentifier() ? (string) $request->user()->getAuthIdentifier() : null,
        );
        return response()->json(['data' => $data, 'message' => $data['moved'].' item berhasil dipindahkan.']);
    }

    public function delete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'disk' => ['required', 'in:local,public'],
            'paths' => ['required', 'array', 'min:1', 'max:200'],
            'paths.*' => ['required', 'string', 'max:1500'],
            'confirmation' => ['required', 'in:HAPUS'],
        ]);
        $data = $this->files->delete(
            (string) $validated['disk'],
            $validated['paths'],
            $request->user()?->getAuthIdentifier() ? (string) $request->user()->getAuthIdentifier() : null,
        );
        return response()->json(['data' => $data, 'message' => $data['deleted'].' item berhasil dihapus permanen.']);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'disk' => ['required', 'in:local,public'],
            'path' => ['required', 'string', 'max:1500'],
        ]);
        $file = $this->files->fileForDownload((string) $validated['disk'], (string) $validated['path']);
        return response()->download($file['absolute'], $file['name']);
    }
}
