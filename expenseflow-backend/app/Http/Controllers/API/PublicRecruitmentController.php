<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * PublicRecruitmentController — endpoint publik tanpa autentikasi.
 * Diakses oleh portal karir publik (expenseflow-public).
 */
class PublicRecruitmentController extends Controller
{
    /**
     * Daftar lowongan yang sedang open.
     * Query: ?company_id=1&search=developer&employment_type=full_time
     */
    public function jobList(Request $request): JsonResponse
    {
        $query = JobPosting::where('status', 'open')
            ->select([
                'id', 'company_id', 'title', 'department', 'location',
                'employment_type', 'description', 'requirements',
                'salary_min', 'salary_max', 'show_salary', 'max_applicants',
                'deadline', 'published_at',
            ])
            ->withCount('applications')
            ->with('company:id,name')
            ->latest('published_at');

        // Filter per perusahaan (wajib untuk public portal)
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        // Pencarian judul / departemen
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                  ->orWhere('department', 'like', $search)
                  ->orWhere('location', 'like', $search);
            });
        }

        // Filter tipe pekerjaan
        if ($request->filled('employment_type')) {
            $query->where('employment_type', $request->employment_type);
        }

        // Filter hanya yang belum melewati deadline
        $query->where(function ($q) {
            $q->whereNull('deadline')->orWhere('deadline', '>=', now()->toDateString());
        });

        $perPage  = min((int) $request->get('per_page', 12), 50);
        $postings = $query->paginate($perPage);

        // Sembunyikan gaji jika show_salary = false & periksa kuota penuh
        $items = collect($postings->items())->map(function ($p) {
            $data = $p->toArray();
            if (! $p->show_salary) {
                $data['salary_min'] = null;
                $data['salary_max'] = null;
            }
            // Indikator apakah kuota telah terpenuhi
            $data['is_quota_full'] = $p->max_applicants ? ($p->applications_count >= $p->max_applicants) : false;
            return $data;
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $postings->currentPage(),
                'last_page'    => $postings->lastPage(),
                'per_page'     => $postings->perPage(),
                'total'        => $postings->total(),
            ],
        ]);
    }

    /**
     * Detail satu lowongan (hanya yang open).
     */
    public function jobDetail(int $id): JsonResponse
    {
        $posting = JobPosting::where('status', 'open')
            ->where(function ($q) {
                // Belum melewati deadline
                $q->whereNull('deadline')->orWhere('deadline', '>=', now()->toDateString());
            })
            ->withCount('applications')
            ->with('company:id,name')
            ->select([
                'id', 'company_id', 'title', 'department', 'location',
                'employment_type', 'description', 'requirements',
                'salary_min', 'salary_max', 'show_salary', 'max_applicants',
                'deadline', 'published_at',
            ])
            ->findOrFail($id);

        // Jika kuota sudah tercapai saat dibuka, auto close
        if ($posting->max_applicants && $posting->applications_count >= $posting->max_applicants) {
            $posting->update(['status' => 'closed']);
            return response()->json([
                'message' => "Mohon maaf, lowongan ini telah ditutup karena kuota maksimal pelamar ({$posting->max_applicants} orang) telah terpenuhi.",
            ], 404);
        }

        $data = $posting->toArray();
        if (! $posting->show_salary) {
            $data['salary_min'] = null;
            $data['salary_max'] = null;
        }
        $data['employment_type_label'] = $posting->employment_type_label;
        $data['is_quota_full']         = $posting->max_applicants ? ($posting->applications_count >= $posting->max_applicants) : false;

        return response()->json(['data' => $data]);
    }

    /**
     * Kirim lamaran ke lowongan tertentu.
     * Mendukung upload file CV (PDF, max 5MB).
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        $posting = JobPosting::where('status', 'open')
            ->where(function ($q) {
                $q->whereNull('deadline')->orWhere('deadline', '>=', now()->toDateString());
            })
            ->findOrFail($id);

        // Periksa apakah kuota maksimal pelamar sudah tercapai sebelum menerima lamaran
        if ($posting->max_applicants && $posting->applications()->count() >= $posting->max_applicants) {
            $posting->update(['status' => 'closed']);
            return response()->json([
                'message' => "Mohon maaf, kuota maksimal pelamar ({$posting->max_applicants} orang) untuk posisi ini telah terpenuhi dan lowongan telah ditutup.",
            ], 422);
        }

        $validated = $request->validate([
            'full_name'        => 'required|string|max:255',
            'gender'           => 'required|string|max:20',
            'birth_place'      => 'required|string|max:100',
            'birth_date'       => 'required|date',
            'nationality'      => 'required|string|max:50',
            'email'            => 'required|email|max:255',
            'phone'            => 'required|string|max:30',
            'postal_code'      => 'required|string|max:10',
            'province'         => 'required|string|max:100',
            'city'             => 'required|string|max:100',
            'district'         => 'required|string|max:100',
            'subdistrict'      => 'nullable|string|max:100',
            'address'          => 'required|string|max:500',
            'education'        => 'required|string|max:100',
            'institution_name' => 'required|string|max:255',
            'experience_years' => 'required|integer|min:0|max:50',
            'notice_period'    => 'required|string|max:100',
            'expected_salary'  => 'nullable|numeric|min:0',
            'portfolio_url'    => 'nullable|string|max:500',
            'cover_letter'     => 'required|string|max:5000',
            'resume'           => 'required|file|mimes:pdf|max:5120', // max 5MB PDF wajib
        ], [
            'full_name.required'        => 'Nama lengkap wajib diisi.',
            'gender.required'           => 'Jenis kelamin wajib dipilih.',
            'birth_place.required'      => 'Tempat lahir wajib diisi.',
            'birth_date.required'       => 'Tanggal lahir wajib diisi.',
            'nationality.required'      => 'Kewarganegaraan wajib diisi.',
            'email.required'            => 'Alamat email wajib diisi.',
            'email.email'               => 'Format email tidak valid.',
            'phone.required'            => 'Nomor telepon/WhatsApp wajib diisi.',
            'postal_code.required'      => 'Kode pos wajib diisi.',
            'province.required'         => 'Provinsi wajib diisi.',
            'city.required'             => 'Kota / Kabupaten wajib diisi.',
            'district.required'         => 'Kecamatan wajib diisi.',
            'address.required'          => 'Alamat lengkap / nama jalan / RT RW wajib diisi.',
            'education.required'        => 'Pendidikan terakhir wajib dipilih.',
            'institution_name.required' => 'Nama sekolah / universitas terakhir wajib diisi.',
            'experience_years.required' => 'Pengalaman kerja wajib diisi (isi 0 jika fresh graduate).',
            'experience_years.integer'  => 'Pengalaman kerja harus berupa angka.',
            'notice_period.required'    => 'Ketersediaan mulai bekerja (Notice Period) wajib dipilih.',
            'cover_letter.required'     => 'Catatan/surat lamaran singkat wajib diisi.',
            'resume.required'           => 'Berkas CV (format PDF) wajib diunggah.',
            'resume.mimes'              => 'Berkas CV harus berformat PDF.',
            'resume.max'                => 'Ukuran berkas CV maksimal 5MB.',
        ]);

        // Cegah lamaran ganda dari email yang sama ke lowongan yang sama
        $alreadyApplied = JobApplication::where('job_posting_id', $posting->id)
            ->where('email', $validated['email'])
            ->exists();

        if ($alreadyApplied) {
            return response()->json([
                'message' => 'Email ini sudah pernah melamar untuk posisi ini.',
            ], 422);
        }

        // Simpan file CV jika ada
        $resumePath = null;
        if ($request->hasFile('resume')) {
            $resumePath = $request->file('resume')->store(
                'resumes/' . $posting->company_id,
                'local'
            );
        }

        $application = JobApplication::create([
            'job_posting_id'   => $posting->id,
            'company_id'       => $posting->company_id,
            'full_name'        => $validated['full_name'],
            'gender'           => $validated['gender'] ?? null,
            'birth_place'      => $validated['birth_place'] ?? null,
            'birth_date'       => $validated['birth_date'] ?? null,
            'nationality'      => $validated['nationality'] ?? 'WNI (Indonesia)',
            'email'            => $validated['email'],
            'phone'            => $validated['phone'] ?? null,
            'postal_code'      => $validated['postal_code'] ?? null,
            'province'         => $validated['province'] ?? null,
            'city'             => $validated['city'] ?? null,
            'district'         => $validated['district'] ?? null,
            'subdistrict'      => $validated['subdistrict'] ?? null,
            'address'          => $validated['address'] ?? null,
            'education'        => $validated['education'] ?? null,
            'institution_name' => $validated['institution_name'] ?? null,
            'experience_years' => $validated['experience_years'] ?? null,
            'notice_period'    => $validated['notice_period'] ?? null,
            'expected_salary'  => $validated['expected_salary'] ?? null,
            'portfolio_url'    => $validated['portfolio_url'] ?? null,
            'cover_letter'     => $validated['cover_letter'] ?? null,
            'resume_path'      => $resumePath,
            'status'           => 'new',
        ]);



        // Periksa apakah kuota telah tercapai setelah lamaran baru masuk
        $currentApplicationsCount = $posting->applications()->count();
        if ($posting->max_applicants && $currentApplicationsCount >= $posting->max_applicants) {
            // Otomatis tutup lowongan jika kuota telah terpenuhi!
            $posting->update(['status' => 'closed']);
        }

        return response()->json([
            'message' => 'Lamaran Anda berhasil dikirim. Kami akan menghubungi Anda jika lolos seleksi berkas.',
            'data'    => [
                'id'        => $application->id,
                'full_name' => $application->full_name,
                'email'     => $application->email,
                'position'  => $posting->title,
                'has_resume' => $application->hasResume(),
            ],
        ], 201);
    }

    /**
     * Cari wilayah administratif berdasarkan kode pos Indonesia.
     * Mengembalikan daftar kelurahan, kecamatan, kota/kabupaten, dan provinsi.
     */
    public function searchPostalCode(string $code): JsonResponse
    {
        $clean = preg_replace('/\D/', '', $code);
        if (strlen($clean) < 3) {
            return response()->json([
                'found' => false,
                'message' => 'Kode pos minimal 3 digit.',
                'data' => [],
            ], 400);
        }

        $filePath = storage_path('app/postal_codes.json');
        if (!file_exists($filePath)) {
            return response()->json([
                'found' => false,
                'message' => 'Database kode pos belum dimuat.',
                'data' => [],
            ], 404);
        }

        $content = file_get_contents($filePath);
        $postalDict = json_decode($content, true);

        if (isset($postalDict[$clean])) {
            $items = $postalDict[$clean];
            $results = array_map(function ($item) {
                return [
                    'province'    => $item['p'] ?? '',
                    'city'        => $item['c'] ?? '',
                    'district'    => $item['d'] ?? '',
                    'subdistrict' => $item['s'] ?? '',
                ];
            }, $items);

            return response()->json([
                'found' => true,
                'postal_code' => $clean,
                'data' => $results,
            ]);
        }

        return response()->json([
            'found' => false,
            'postal_code' => $clean,
            'message' => 'Kode pos tidak ditemukan di database.',
            'data' => [],
        ], 404);
    }
}


