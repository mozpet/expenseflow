<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
// AttendanceSetting berada di namespace yang sama (App\Models)

class Shift extends Model
{
    protected $fillable = [
        'company_id',
        'attendance_setting_id',
        'name',
        'description',
        'is_active',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // Relasi ke perusahaan pemilik shift
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // Relasi ke cabang (attendance_settings) pemilik shift. Null = company-wide.
    public function office(): BelongsTo
    {
        return $this->belongsTo(AttendanceSetting::class, 'attendance_setting_id');
    }

    // SEMUA jadwal harian (termasuk beberapa versi per hari bila ada)
    public function allSchedules(): HasMany
    {
        return $this->hasMany(ShiftSchedule::class)
            ->orderBy('effective_date')
            ->orderBy('day_of_week');
    }

    // Versi jadwal PALING BARU per hari (template "saat ini").
    // Karena setiap versi baru di-insert dengan effective_date yang lebih besar,
    // MAX(id) == versi dengan effective_date terbesar.
    // Dipakai frontend untuk menampilkan jam kerja shift di list/kalender/form.
    //
    // Dioptimasi: pilih baris dengan id TERBESAR per (shift_id, day_of_week)
    // lewat SATU subquery GROUP BY + JOIN (bukan correlated subquery di WHERE IN
    // yang lama yang dievaluasi per baris). Hasil akhir IDENTIK — hanya butuh
    // satu pass, sehingga GET /shifts dan kalender shift tidak lambat walau
    // banyak shift / banyak versi jadwal (fitur effective_date).
    public function schedules(): HasMany
    {
        $latestIds = ShiftSchedule::query()
            ->selectRaw('MAX(id) as latest_id')
            ->from('shift_schedules as sub')
            ->groupBy('sub.shift_id', 'sub.day_of_week');

        return $this->hasMany(ShiftSchedule::class)
            ->joinSub($latestIds, 'latest_sched', fn ($join) => $join->on('shift_schedules.id', '=', 'latest_sched.latest_id'))
            ->orderBy('day_of_week');
    }

    // Versi jadwal yang berlaku pada tanggal tertentu (effective_date <= tanggal).
    // Untuk tiap hari (0-6), ambil versi terbaru yang sudah efektif pada tanggal tsb.
    public function schedulesForDate(string $date): array
    {
        $rows = ShiftSchedule::query()
            ->where('shift_id', $this->id)
            ->where('effective_date', '<=', $date)
            ->orderBy('effective_date')
            ->get();

        // Ambil versi terakhir (effective_date terbesar) per day_of_week
        $latest = [];
        foreach ($rows as $row) {
            $latest[$row->day_of_week] = $row;
        }

        return array_values($latest);
    }

    // Semua assignment karyawan yang menggunakan shift ini
    public function userShifts(): HasMany
    {
        return $this->hasMany(UserShift::class);
    }

    /**
     * Cek apakah template shift memiliki setidaknya satu hari kerja malam (23:00 - 07:00).
     */
    public function hasNightSchedule(?string $date = null): bool
    {
        $scheds = $date ? $this->schedulesForDate($date) : $this->schedules;
        foreach ($scheds as $sch) {
            if (! $sch->is_off && ShiftSchedule::isNightSchedule($sch->work_start_time, $sch->work_end_time, (bool) $sch->is_cross_day)) {
                return true;
            }
        }

        return false;
    }
}
