<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * RecruitmentController — kelola lowongan kerja & seleksi pelamar (HRD/Admin/Super Admin).
 */
class RecruitmentController extends Controller
{
    // ── Helper: catat aktivitas ke activity_logs ──────────────────────────
    private function logActivity(int $userId, ?int $companyId, string $action, string $description, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('activity_logs')->insert([
            'company_id'  => $companyId,
            'user_id'     => $userId,
            'action'      => $action,
            'description' => $description,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    // ── Lowongan ─────────────────────────────────────────────────────────────

    /**
     * Daftar semua lowongan perusahaan.
     * Query: ?status=open&search=developer&per_page=15
     */
    public function index(Request $request): JsonResponse
    {
        $user      = Auth::user();
        $companyId = $user->company_id;

        $query = JobPosting::where('company_id', $companyId)
            ->withCount('applications')
            ->with('creator:id,name')
            ->latest();

        // Filter status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Pencarian judul / departemen / lokasi
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                  ->orWhere('department', 'like', $search)
                  ->orWhere('location', 'like', $search);
            });
        }

        $perPage  = min(max((int) $request->get('per_page', 12), 1), 100);
        $postings = $query->paginate($perPage);

        return response()->json([
            'data'  => $postings->items(),
            'meta'  => [
                'current_page' => $postings->currentPage(),
                'last_page'    => $postings->lastPage(),
                'per_page'     => $postings->perPage(),
                'total'        => $postings->total(),
            ],
            'summary' => [
                'total'  => JobPosting::where('company_id', $companyId)->count(),
                'open'   => JobPosting::where('company_id', $companyId)->where('status', 'open')->count(),
                'draft'  => JobPosting::where('company_id', $companyId)->where('status', 'draft')->count(),
                'closed' => JobPosting::where('company_id', $companyId)->where('status', 'closed')->count(),
            ],
        ]);
    }

    /**
     * Buat lowongan baru.
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'title'           => 'required|string|max:255',
            'department'      => 'nullable|string|max:100',
            'location'        => 'nullable|string|max:100',
            'employment_type' => 'required|in:full_time,part_time,contract,internship',
            'description'     => 'required|string',
            'requirements'    => 'nullable|string',
            'salary_min'      => 'nullable|numeric|min:0',
            'salary_max'      => 'nullable|numeric|min:0',
            'show_salary'     => 'boolean',
            'max_applicants'  => 'nullable|integer|min:1',
            'contact_email'   => 'nullable|email|max:255',
            'status'          => 'in:draft,open,closed',
            'deadline'        => 'nullable|date',
        ]);

        // Validasi gaji max >= min jika keduanya diisi
        if (!empty($validated['salary_min']) && !empty($validated['salary_max']) && $validated['salary_max'] < $validated['salary_min']) {
            return response()->json([
                'message' => 'Gaji maksimum harus lebih besar atau sama dengan gaji minimum.',
                'errors'  => ['salary_max' => ['Gaji maksimum harus lebih besar atau sama dengan gaji minimum.']],
            ], 422);
        }

        // Sanitasi field nullable kosong
        $validated['department']     = !empty($validated['department']) ? trim($validated['department']) : null;
        $validated['location']       = !empty($validated['location']) ? trim($validated['location']) : null;
        $validated['requirements']   = !empty($validated['requirements']) ? trim($validated['requirements']) : null;
        $validated['deadline']       = !empty($validated['deadline']) ? $validated['deadline'] : null;
        $validated['max_applicants'] = !empty($validated['max_applicants']) ? (int) $validated['max_applicants'] : null;

        $validated['salary_min']   = !empty($validated['salary_min']) ? (int) $validated['salary_min'] : null;
        $validated['salary_max']   = !empty($validated['salary_max']) ? (int) $validated['salary_max'] : null;
        $validated['show_salary']  = (bool) ($validated['show_salary'] ?? false);
        $validated['status']       = $validated['status'] ?? 'draft';

        $validated['company_id']  = $user->company_id;
        $validated['created_by']  = $user->id;

        if ($validated['status'] === 'open') {
            $validated['published_at'] = now();
        }

        $posting = JobPosting::create($validated);

        // Catat aktivitas
        $this->logActivity(
            $user->id,
            $user->company_id,
            'job_posting_created',
            "Lowongan \"{$posting->title}\" dibuat",
            'JobPosting',
            $posting->id
        );

        return response()->json([
            'message' => 'Lowongan berhasil dibuat.',
            'data'    => $posting->load('creator:id,name'),
        ], 201);
    }

    /**
     * Detail lowongan beserta statistik pelamar.
     */
    public function show(int $id): JsonResponse
    {
        $user    = Auth::user();
        $posting = JobPosting::where('company_id', $user->company_id)
            ->withCount([
                'applications',
                'applications as new_count'         => fn($q) => $q->where('status', 'new'),
                'applications as reviewed_count'    => fn($q) => $q->where('status', 'reviewed'),
                'applications as shortlisted_count' => fn($q) => $q->where('status', 'shortlisted'),
                'applications as rejected_count'    => fn($q) => $q->where('status', 'rejected'),
                'applications as hired_count'       => fn($q) => $q->where('status', 'hired'),
            ])
            ->with('creator:id,name')
            ->findOrFail($id);

        return response()->json(['data' => $posting]);
    }

    /**
     * Update lowongan.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user    = Auth::user();
        $posting = JobPosting::where('company_id', $user->company_id)->findOrFail($id);

        if ($posting->status === 'closed' && $request->input('status') === 'closed') {
            return response()->json(['message' => 'Lowongan yang sudah ditutup tidak bisa diedit. Ubah statusnya terlebih dahulu.'], 422);
        }

        $validated = $request->validate([
            'title'           => 'sometimes|required|string|max:255',
            'department'      => 'nullable|string|max:100',
            'location'        => 'nullable|string|max:100',
            'employment_type' => 'sometimes|required|in:full_time,part_time,contract,internship',
            'description'     => 'sometimes|required|string',
            'requirements'    => 'nullable|string',
            'salary_min'      => 'nullable|numeric|min:0',
            'salary_max'      => 'nullable|numeric|min:0',
            'show_salary'     => 'boolean',
            'max_applicants'  => 'nullable|integer|min:1',
            'contact_email'   => 'nullable|email|max:255',
            'status'          => 'nullable|in:draft,open,closed',
            'deadline'        => 'nullable|date',
        ]);

        $min = array_key_exists('salary_min', $validated) ? $validated['salary_min'] : $posting->salary_min;
        $max = array_key_exists('salary_max', $validated) ? $validated['salary_max'] : $posting->salary_max;

        if (!empty($min) && !empty($max) && (float) $max < (float) $min) {
            return response()->json([
                'message' => 'Gaji maksimum harus lebih besar atau sama dengan gaji minimum.',
                'errors'  => ['salary_max' => ['Gaji maksimum harus lebih besar atau sama dengan gaji minimum.']],
            ], 422);
        }

        // Sanitasi field
        if (array_key_exists('department', $validated)) {
            $validated['department'] = !empty($validated['department']) ? trim($validated['department']) : null;
        }
        if (array_key_exists('location', $validated)) {
            $validated['location'] = !empty($validated['location']) ? trim($validated['location']) : null;
        }
        if (array_key_exists('requirements', $validated)) {
            $validated['requirements'] = !empty($validated['requirements']) ? trim($validated['requirements']) : null;
        }
        if (array_key_exists('deadline', $validated)) {
            $validated['deadline'] = !empty($validated['deadline']) ? $validated['deadline'] : null;
        }
        if (array_key_exists('max_applicants', $validated)) {
            $validated['max_applicants'] = !empty($validated['max_applicants']) ? (int) $validated['max_applicants'] : null;
        }
        if (array_key_exists('salary_min', $validated)) {
            $validated['salary_min'] = !empty($validated['salary_min']) ? (int) $validated['salary_min'] : null;
        }
        if (array_key_exists('salary_max', $validated)) {
            $validated['salary_max'] = !empty($validated['salary_max']) ? (int) $validated['salary_max'] : null;
        }


        // Jika status berubah ke open dan belum punya published_at
        if (isset($validated['status']) && $validated['status'] === 'open' && !$posting->published_at) {
            $validated['published_at'] = now();
        }

        $posting->update($validated);

        $this->logActivity(
            $user->id,
            $user->company_id,
            'job_posting_updated',
            "Lowongan \"{$posting->title}\" diperbarui",
            'JobPosting',
            $posting->id
        );

        return response()->json([
            'message' => 'Lowongan berhasil diperbarui.',
            'data'    => $posting->fresh()->load('creator:id,name'),
        ]);
    }

    /**
     * Hapus lowongan (hanya status draft & belum ada pelamar).
     */
    public function destroy(int $id): JsonResponse
    {
        $user    = Auth::user();
        $posting = JobPosting::where('company_id', $user->company_id)->findOrFail($id);

        if ($posting->status !== 'draft') {
            return response()->json(['message' => 'Hanya lowongan berstatus draft yang bisa dihapus.'], 422);
        }

        if ($posting->applications()->exists()) {
            return response()->json(['message' => 'Lowongan yang sudah memiliki pelamar tidak bisa dihapus.'], 422);
        }

        $title = $posting->title;
        $posting->delete();

        $this->logActivity(
            $user->id,
            $user->company_id,
            'job_posting_deleted',
            "Lowongan \"{$title}\" dihapus",
            'JobPosting',
            $id
        );

        return response()->json(['message' => 'Lowongan berhasil dihapus.']);
    }

    /**
     * Publish lowongan (draft/closed → open).
     */
    public function publish(int $id): JsonResponse
    {
        $user    = Auth::user();
        $posting = JobPosting::where('company_id', $user->company_id)->findOrFail($id);

        $posting->update([
            'status'       => 'open',
            'published_at' => $posting->published_at ?? now(),
        ]);

        $this->logActivity(
            $user->id,
            $user->company_id,
            'job_posting_published',
            "Lowongan \"{$posting->title}\" dipublikasikan",
            'JobPosting',
            $posting->id
        );

        return response()->json([
            'message' => 'Lowongan berhasil dipublikasikan ke publik.',
            'data'    => $posting->fresh()->load('creator:id,name'),
        ]);
    }

    /**
     * Tutup lowongan (open → closed).
     */
    public function close(int $id): JsonResponse
    {
        $user    = Auth::user();
        $posting = JobPosting::where('company_id', $user->company_id)->findOrFail($id);

        $posting->update(['status' => 'closed']);

        $this->logActivity(
            $user->id,
            $user->company_id,
            'job_posting_closed',
            "Lowongan \"{$posting->title}\" ditutup",
            'JobPosting',
            $posting->id
        );

        return response()->json([
            'message' => 'Lowongan berhasil ditutup.',
            'data'    => $posting->fresh()->load('creator:id,name'),
        ]);
    }

    // ── Pelamar ──────────────────────────────────────────────────────────────

    /**
     * Daftar pelamar untuk lowongan tertentu.
     * Query: ?status=new&search=budi&per_page=20
     */
    public function applications(Request $request, int $id): JsonResponse
    {
        $user    = Auth::user();
        $posting = JobPosting::where('company_id', $user->company_id)->findOrFail($id);

        $query = $posting->applications()
            ->with('reviewer:id,name')
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', $search)
                  ->orWhere('email', 'like', $search)
                  ->orWhere('phone', 'like', $search);
            });
        }

        $perPage      = min(max((int) $request->get('per_page', 20), 1), 100);
        $applications = $query->paginate($perPage);

        $items = collect($applications->items())->map(fn($a) => array_merge(
            $a->toArray(),
            [
                'has_resume'   => $a->hasResume(),
                'status_label' => $a->status_label,
                'resume_path'  => null, // Sembunyikan path mentah
            ]
        ));

        return response()->json([
            'posting' => [
                'id'         => $posting->id,
                'title'      => $posting->title,
                'department' => $posting->department,
                'status'     => $posting->status,
            ],
            'data'    => $items,
            'meta'    => [
                'current_page' => $applications->currentPage(),
                'last_page'    => $applications->lastPage(),
                'per_page'     => $applications->perPage(),
                'total'        => $applications->total(),
            ],
        ]);
    }

    /**
     * Semua pelamar lintas lowongan perusahaan.
     */
    public function allApplications(Request $request): JsonResponse
    {
        $user      = Auth::user();
        $companyId = $user->company_id;

        $query = JobApplication::where('company_id', $companyId)
            ->with(['jobPosting:id,title,department,status', 'reviewer:id,name'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('job_posting_id')) {
            $query->where('job_posting_id', $request->integer('job_posting_id'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', $search)
                  ->orWhere('email', 'like', $search)
                  ->orWhere('phone', 'like', $search);
            });
        }

        $perPage      = min(max((int) $request->get('per_page', 20), 1), 100);
        $applications = $query->paginate($perPage);

        $items = collect($applications->items())->map(fn($a) => array_merge(
            $a->toArray(),
            [
                'has_resume'   => $a->hasResume(),
                'status_label' => $a->status_label,
                'resume_path'  => null,
            ]
        ));

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page'    => $applications->lastPage(),
                'per_page'     => $applications->perPage(),
                'total'        => $applications->total(),
            ],
            'summary' => [
                'total'       => JobApplication::where('company_id', $companyId)->count(),
                'new'         => JobApplication::where('company_id', $companyId)->where('status', 'new')->count(),
                'reviewed'    => JobApplication::where('company_id', $companyId)->where('status', 'reviewed')->count(),
                'shortlisted' => JobApplication::where('company_id', $companyId)->where('status', 'shortlisted')->count(),
                'rejected'    => JobApplication::where('company_id', $companyId)->where('status', 'rejected')->count(),
                'hired'       => JobApplication::where('company_id', $companyId)->where('status', 'hired')->count(),
            ],
        ]);
    }

    /**
     * Detail satu pelamar.
     */
    public function applicationDetail(int $id): JsonResponse
    {
        $user        = Auth::user();
        $application = JobApplication::where('company_id', $user->company_id)
            ->with(['jobPosting:id,title,department,location,status', 'reviewer:id,name'])
            ->findOrFail($id);

        $data                 = $application->toArray();
        $data['has_resume']   = $application->hasResume();
        $data['status_label'] = $application->status_label;
        $data['resume_path']  = null;

        return response()->json(['data' => $data]);
    }

    /**
     * Update status seleksi pelamar.
     */
    public function updateApplicationStatus(Request $request, int $id): JsonResponse
    {
        $user        = Auth::user();
        $application = JobApplication::where('company_id', $user->company_id)->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|in:new,reviewed,shortlisted,rejected,hired',
            'notes'  => 'nullable|string|max:1000',
        ]);

        $updateData = [
            'status'      => $validated['status'],
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ];

        if (array_key_exists('notes', $validated)) {
            $updateData['notes'] = $validated['notes'];
        }

        $application->update($updateData);

        $this->logActivity(
            $user->id,
            $user->company_id,
            'job_application_status_updated',
            "Status pelamar \"{$application->full_name}\" diubah menjadi {$application->status_label}",
            'JobApplication',
            $application->id
        );

        $data                 = $application->fresh()->load('reviewer:id,name')->toArray();
        $data['has_resume']   = $application->hasResume();
        $data['status_label'] = $application->status_label;
        $data['resume_path']  = null;

        return response()->json([
            'message' => 'Status pelamar berhasil diperbarui.',
            'data'    => $data,
        ]);
    }

    /**
     * Download / stream file CV pelamar.
     * Otomatis mengubah status pelamar dari 'new' menjadi 'reviewed' saat HRD membuka berkas.
     */
    public function downloadResume(Request $request, int $id)
    {
        $user        = Auth::user();
        $application = JobApplication::where('company_id', $user->company_id)->findOrFail($id);

        if (! $application->hasResume()) {
            return response()->json(['message' => 'Pelamar ini tidak memiliki file CV.'], 404);
        }

        $path = $application->resume_path;
        if (! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'File CV tidak ditemukan di server storage.'], 404);
        }

        // Otomatis ubah status pelamar dari 'new' menjadi 'reviewed' saat HRD membuka berkas CV
        if ($application->status === 'new') {
            $application->update([
                'status'      => 'reviewed',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            $this->logActivity(
                $user->id,
                $user->company_id,
                'job_application_status_updated',
                "Status pelamar \"{$application->full_name}\" otomatis diubah menjadi Ditinjau (HRD membuka berkas CV)",
                'JobApplication',
                $application->id
            );
        }

        $fullPath  = Storage::disk('local')->path($path);
        $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $application->full_name);
        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $cleanName . '.pdf"',
        ]);

    }


    /**
     * Hapus data pelamar (misalnya yang berstatus ditolak / rejected).
     */
    public function destroyApplication(int $id): JsonResponse
    {
        $user        = Auth::user();
        $application = JobApplication::where('company_id', $user->company_id)->findOrFail($id);

        $name = $application->full_name;

        // Hapus file CV jika ada di storage
        if ($application->resume_path && Storage::disk('local')->exists($application->resume_path)) {
            Storage::disk('local')->delete($application->resume_path);
        }

        $application->delete();

        $this->logActivity(
            $user->id,
            $user->company_id,
            'job_application_deleted',
            "Data pelamar \"{$name}\" berhasil dihapus",
            'JobApplication',
            $id
        );

        return response()->json([
            'message' => "Data pelamar \"{$name}\" berhasil dihapus.",
        ]);
    }
}


