<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserDocumentController extends Controller
{
    /**
     * Helper log aktivitas.
     */
    protected function logActivity(int $userId, int $companyId, string $action, string $description, ?string $subjectType = null, ?int $subjectId = null, array $properties = []): void
    {
        try {
            ActivityLog::create([
                'company_id'   => $companyId,
                'user_id'      => $userId,
                'action'       => $action,
                'description'  => $description,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'properties'   => !empty($properties) ? json_encode($properties) : null,
            ]);
        } catch (\Throwable $e) {
            // Abaikan kegagalan log agar tidak mengganggu alur utama
        }
    }

    /**
     * Dapatkan judul bawaan berdasarkan tipe dokumen.
     */
    protected function getDefaultTitle(string $type): string
    {
        return match ($type) {
            'ktp'             => 'KTP Asli',
            'kartu_keluarga'  => 'Kartu Keluarga (KK)',
            'npwp'            => 'Kartu NPWP',
            'buku_tabungan'   => 'Buku Tabungan / Rekening',
            'kontrak_kerja'   => 'Surat Kontrak Kerja (SPK)',
            'ijazah'          => 'Ijazah Terakhir',
            'sertifikat'      => 'Sertifikat Keahlian / K3',
            default           => 'Dokumen Karyawan',
        };
    }

    /**
     * Daftar dokumen digital milik karyawan tertentu.
     * GET /api/v1/admin/users/{userId}/documents
     */
    public function index(Request $request, int $userId): JsonResponse
    {
        $authUser = Auth::user();
        $companyId = $authUser->company_id;

        $targetUser = User::where('company_id', $companyId)->findOrFail($userId);

        $documents = UserDocument::where('company_id', $companyId)
            ->where('user_id', $targetUser->id)
            ->with('uploader:id,name,role')
            ->latest()
            ->get();

        return response()->json([
            'success'   => true,
            'user'      => [
                'id'            => $targetUser->id,
                'name'          => $targetUser->name,
                'employee_code' => $targetUser->employee_code,
            ],
            'documents' => $documents,
        ]);
    }

    /**
     * Unggah berkas dokumen digital karyawan.
     * POST /api/v1/admin/users/{userId}/documents
     */
    public function store(Request $request, int $userId): JsonResponse
    {
        $authUser = Auth::user();
        $companyId = $authUser->company_id;

        $targetUser = User::where('company_id', $companyId)->findOrFail($userId);

        $documentType = $request->input('document_type');
        
        $mimes = 'pdf,jpg,jpeg,png,webp';
        $maxSize = 10240; // 10MB
        if (in_array($documentType, ['ktp', 'npwp', 'buku_tabungan'])) {
            $mimes = 'jpg,jpeg,png,webp';
            $maxSize = 5120; // 5MB
        } elseif (in_array($documentType, ['kontrak_kerja', 'ijazah', 'sertifikat'])) {
            $mimes = 'pdf';
        }

        $validated = $request->validate([
            'file'          => 'required|file|mimes:' . $mimes . '|max:' . $maxSize,
            'document_type' => 'required|string|in:ktp,kartu_keluarga,npwp,buku_tabungan,kontrak_kerja,ijazah,sertifikat,lainnya',
            'title'         => 'nullable|string|max:150',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $uploadedFile = $request->file('file');
        $directory    = "user_documents/{$companyId}/{$targetUser->id}";
        $filePath     = $uploadedFile->store($directory, 'local');

        $title = !empty($validated['title'])
            ? trim($validated['title'])
            : $this->getDefaultTitle($validated['document_type']);

        $document = UserDocument::create([
            'company_id'    => $companyId,
            'user_id'       => $targetUser->id,
            'document_type' => $validated['document_type'],
            'title'         => $title,
            'file_path'     => $filePath,
            'file_name'     => $uploadedFile->getClientOriginalName() ?: basename($filePath),
            'file_size'     => $uploadedFile->getSize() ?: 0,
            'mime_type'     => $uploadedFile->getMimeType() ?: 'application/octet-stream',
            'uploaded_by'   => $authUser->id,
            'notes'         => $validated['notes'] ?? null,
        ]);

        $this->logActivity(
            $authUser->id,
            $companyId,
            'user_document_uploaded',
            "Mengunggah berkas \"{$title}\" ({$validated['document_type']}) untuk karyawan {$targetUser->name}",
            'UserDocument',
            $document->id
        );

        return response()->json([
            'success'  => true,
            'message'  => "Berkas \"{$title}\" berhasil diunggah dan diarsipkan.",
            'document' => $document->load('uploader:id,name,role'),
        ], 201);
    }

    /**
     * Preview / stream berkas dokumen (inline di browser).
     * GET /api/v1/admin/users/{userId}/documents/{documentId}/stream
     */
    public function stream(Request $request, int $userId, int $documentId)
    {
        $authUser  = Auth::user();
        $companyId = $authUser->company_id;

        $document = UserDocument::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->findOrFail($documentId);

        if (!Storage::disk('local')->exists($document->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Berkas fisik tidak ditemukan di penyimpanan server.',
            ], 404);
        }

        $fullPath  = Storage::disk('local')->path($document->file_path);
        $cleanName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $document->file_name);

        return response()->file($fullPath, [
            'Content-Type'        => $document->mime_type,
            'Content-Disposition' => 'inline; filename="' . $cleanName . '"',
        ]);
    }

    /**
     * Unduh berkas dokumen (attachment).
     * GET /api/v1/admin/users/{userId}/documents/{documentId}/download
     */
    public function download(Request $request, int $userId, int $documentId): BinaryFileResponse|JsonResponse
    {
        $authUser  = Auth::user();
        $companyId = $authUser->company_id;

        $document = UserDocument::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->findOrFail($documentId);

        if (!Storage::disk('local')->exists($document->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Berkas fisik tidak ditemukan di penyimpanan server.',
            ], 404);
        }

        $fullPath = Storage::disk('local')->path($document->file_path);
        return response()->download($fullPath, $document->file_name);
    }

    /**
     * Hapus berkas dokumen digital.
     * DELETE /api/v1/admin/users/{userId}/documents/{documentId}
     */
    public function destroy(Request $request, int $userId, int $documentId): JsonResponse
    {
        $authUser  = Auth::user();
        $companyId = $authUser->company_id;

        $document = UserDocument::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->findOrFail($documentId);

        $targetUser = User::find($userId);
        $title      = $document->title ?: $document->file_name;

        // Hapus berkas fisik jika ada
        if (Storage::disk('local')->exists($document->file_path)) {
            Storage::disk('local')->delete($document->file_path);
        }

        $document->delete();

        $this->logActivity(
            $authUser->id,
            $companyId,
            'user_document_deleted',
            "Menghapus berkas \"{$title}\" dari arsip karyawan " . ($targetUser?->name ?? "ID #{$userId}"),
            'UserDocument',
            $documentId
        );

        return response()->json([
            'success' => true,
            'message' => "Berkas \"{$title}\" berhasil dihapus dari arsip.",
        ]);
    }
}
