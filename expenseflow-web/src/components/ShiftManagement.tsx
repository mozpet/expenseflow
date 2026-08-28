import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  CalendarClock, CalendarDays, Plus, Pencil, Trash2, X, RefreshCw,
  Search, Users, Building2, AlertCircle, AlertTriangle, CheckCircle2, Moon,
  Layers, UserCog, Save, History, ArrowRight, Info, Clock, ToggleLeft, ToggleRight,
} from 'lucide-react';
import { shiftApi, attendanceApi } from '../services/endpoints';
import { ApiError } from '../services/api';
import { useDebounce } from '../hooks/useDebounce';
import CustomDatePicker from './CustomDatePicker';


// ═══════════════════════════════════════════════════════════════
// Types
// ═══════════════════════════════════════════════════════════════
interface OfficeOpt {
  id: number;
  office_name: string;
  work_start_time?: string | null;
  work_end_time?: string | null;
  enforce_weekly_hours?: boolean;
  max_weekly_hours?: number | null;
  shift_notice_days?: number | null;
}

interface ScheduleRow {
  day_of_week: number;
  is_off: boolean;
  is_wfh?: boolean;
  is_field?: boolean;
  work_start_time: string | null; // "HH:MM"
  work_end_time: string | null;
  is_cross_day?: boolean; // shift lintas tengah malam (jam pulang <= jam masuk)
}

interface ShiftTemplate {
  id: number;
  name: string;
  description: string | null;
  is_active: boolean;
  color: string | null;
  attendance_setting_id: number | null;
  office?: { id: number; office_name: string } | null;
  schedules: ScheduleRow[];
}

interface RosterRow {
  user_id: number;
  attendance_setting_id: number | null;
  name: string;
  department: string | null;
  branch: string | null;
  source: 'shift' | 'office' | 'none';
  shift_name: string | null;
  work_start_time: string | null;
  work_end_time: string | null;
  is_off: boolean;
  is_wfh?: boolean;
  is_field?: boolean;
  is_cross_day?: boolean;
  /** Shift yang sudah di-assign tapi belum aktif (start_date di masa depan) */
  upcoming_shift?: {
    shift_id: number;
    shift_name: string;
    color: string | null;
    start_date: string;
  } | null;
}

interface CalDayEntry {
  shift_id: number;
  shift_name: string;
  color: string;
  user_count: number;
  /** true jika shift ini lintas tengah malam pada hari kalender yang bersangkutan */
  is_cross_day?: boolean;
  users: { user_id: number; name: string; department: string | null }[];
}

interface AssignmentRow {
  id: number;
  shift_id: number | null;
  start_date: string;
  end_date: string | null;
  notes: string | null;
  /** Status assignment: 'active' (sedang berlaku) | 'upcoming' (belum mulai) | 'expired' (sudah berakhir) */
  status?: 'active' | 'upcoming' | 'expired';
  shift?: { id: number; name: string } | null;
}

interface ShiftUserRow {
  user_id: number;
  name: string;
  department: string | null;
  branch: string | null;
  assignment_id: number;
  status: 'active' | 'upcoming' | 'expired';
  start_date: string;
  end_date: string | null;
}

// ═══════════════════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════════════════
const DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const DAY_SHORT = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

// "08:00:00" / "08:00" → "08:00"; null → ""
const hhmm = (t?: string | null) => (t ? t.slice(0, 5) : '');

const todayStr = () => new Date().toISOString().slice(0, 10);

const addDays = (dateStr: string, days: number = 1): string => {
  if (!dateStr) return '';
  const [y, m, d] = dateStr.split('-').map(Number);
  if (!y || !m || !d) return '';
  const date = new Date(y, m - 1, d + days);
  const yyyy = date.getFullYear();
  const mm = String(date.getMonth() + 1).padStart(2, '0');
  const dd = String(date.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
};

const fmtDate = (iso?: string | null) => {
  if (!iso) return '-';
  return new Date(iso).toLocaleDateString('id-ID', {
    day: '2-digit', month: 'short', year: 'numeric',
  });
};

const initialsOf = (name: string) =>
  name.split(/\s+/).slice(0, 2).map((s) => s[0]).join('').toUpperCase();

const avatarColors = [
  { bg: 'bg-indigo-100', text: 'text-indigo-700' },
  { bg: 'bg-emerald-100', text: 'text-emerald-700' },
  { bg: 'bg-amber-100', text: 'text-amber-700' },
  { bg: 'bg-rose-100', text: 'text-rose-700' },
  { bg: 'bg-violet-100', text: 'text-violet-700' },
  { bg: 'bg-teal-100', text: 'text-teal-700' },
];
const avatarFor = (name: string) =>
  avatarColors[name.split('').reduce((a, c) => a + c.charCodeAt(0), 0) % avatarColors.length];

// Default 7 hari kerja Sen–Jum, libur Sabtu–Minggu.
const defaultSchedules = (start = '08:00', end = '17:00'): ScheduleRow[] =>
  Array.from({ length: 7 }, (_, d) => ({
    day_of_week: d,
    is_off: d === 0 || d === 6,
    is_wfh: false,
    is_field: false,
    work_start_time: d === 0 || d === 6 ? null : start,
    work_end_time: d === 0 || d === 6 ? null : end,
  }));

const rows = (res: any): any[] => {
  if (Array.isArray(res)) return res;
  if (Array.isArray(res?.data)) return res.data;
  return [];
};

// ─── Konstanta K3 (harus sinkron dengan ShiftRestService.php) ─────────────
const K3_MIN_REST_HOURS = 8;
const K3_REC_REST_HOURS = 11;

/** Parse "HH:MM" atau "HH:MM:SS" → total menit sejak tengah malam */
function timeToMins(t: string): number {
  const [h, m] = t.split(':').map(Number);
  return (h || 0) * 60 + (m || 0);
}

/**
 * Hitung jeda istirahat antar hari berurutan dalam template.
 * Return: map day_of_week → { status, hours, nextDayName }
 * Key = hari yang JAM PULANGnya terlalu dekat ke hari berikutnya.
 */
function computeTemplateGaps(
  schedules: ScheduleRow[],
): Record<number, { status: 'error' | 'warning'; hours: number; nextDayName: string }> {
  const byDay: Record<number, ScheduleRow> = {};
  schedules.forEach((s) => { byDay[s.day_of_week] = s; });

  const result: Record<number, { status: 'error' | 'warning'; hours: number; nextDayName: string }> = {};

  for (let d = 0; d < 7; d++) {
    const today = byDay[d];
    const tomorrow = byDay[(d + 1) % 7];

    if (!today || today.is_off || !today.work_end_time || !today.work_start_time) continue;
    if (!tomorrow || tomorrow.is_off || !tomorrow.work_start_time) continue;

    const endMins = timeToMins(today.work_end_time);
    const isCrossDay = today.work_end_time <= today.work_start_time;
    // Jam pulang cross-day jatuh di +1440 menit (hari berikutnya)
    const adjEndMins = isCrossDay ? endMins + 1440 : endMins;
    // Jam mulai shift berikutnya selalu di hari +1 (daysBetween=1).
    // Untuk cross-day: endDt sudah di hari+1, startDt juga di hari+1 → perbandingan benar.
    const nextStartMins = 1440 + timeToMins(tomorrow.work_start_time);

    const gapMins = nextStartMins - adjEndMins;
    const gapHours = gapMins / 60;

    if (gapHours < K3_MIN_REST_HOURS) {
      result[d] = { status: 'error', hours: Math.max(0, Math.round(gapHours * 10) / 10), nextDayName: DAY_NAMES[(d + 1) % 7] };
    } else if (gapHours < K3_REC_REST_HOURS) {
      result[d] = { status: 'warning', hours: Math.round(gapHours * 10) / 10, nextDayName: DAY_NAMES[(d + 1) % 7] };
    }
  }

  return result;
}

/** Hitung total jam kerja per minggu dari array schedules */
function computeWeeklyHours(schedules: ScheduleRow[]): number {
  let totalMins = 0;
  for (const s of schedules) {
    if (s.is_off || !s.work_start_time || !s.work_end_time) continue;
    const start = timeToMins(s.work_start_time);
    const end = timeToMins(s.work_end_time);
    const isCross = end <= start;
    totalMins += isCross ? (1440 - start + end) : (end - start);
  }
  return Math.round((totalMins / 60) * 10) / 10;
}

/**
 * Cek apakah shift template merupakan shift lintas hari pada hari tertentu (day_of_week 0-6).
 * Lintas hari = work_end_time <= work_start_time.
 */
function isCrossDayOnDate(
  shiftId: number,
  dayOfWeek: number,
  shiftsData: ShiftTemplate[],
): boolean {
  const tmpl = shiftsData.find((s) => s.id === shiftId);
  if (!tmpl) return false;
  const sch = tmpl.schedules?.find((s) => s.day_of_week === dayOfWeek);
  if (!sch || sch.is_off || !sch.work_start_time || !sch.work_end_time) return false;
  return sch.work_end_time <= sch.work_start_time;
}

/**
 * Cek apakah shift template merupakan hari libur (is_off) pada hari tertentu (day_of_week 0-6).
 */
function isOffOnDate(
  shiftId: number,
  dayOfWeek: number,
  shiftsData: ShiftTemplate[],
): boolean {
  const tmpl = shiftsData.find((s) => s.id === shiftId);
  if (!tmpl) return false;
  const sch = tmpl.schedules?.find((s) => s.day_of_week === dayOfWeek);
  return sch?.is_off ?? false;
}

// ═══════════════════════════════════════════════════════════════
// MODAL: Form Template Shift (create / edit)
// ═══════════════════════════════════════════════════════════════
interface ShiftFormProps {
  offices: OfficeOpt[];
  shifts: ShiftTemplate[];
  editing: ShiftTemplate | null;
  onClose: () => void;
  onSaved: () => void;
}

function ShiftFormModal({ offices, shifts, editing, onClose, onSaved }: ShiftFormProps) {
  const [name, setName] = useState(editing?.name ?? '');
  const [description, setDescription] = useState(editing?.description ?? '');
  const [isActive, setIsActive] = useState(editing?.is_active ?? true);
  const [color, setColor] = useState(editing?.color ?? '#6366f1');
  const [branchId, setBranchId] = useState<string>(
    editing?.attendance_setting_id ? String(editing.attendance_setting_id) : '',
  );
  const [schedules, setSchedules] = useState<ScheduleRow[]>(() => {
    if (editing?.schedules?.length) {
      // urutkan by day_of_week & normalisasi jam ke HH:MM
      const map = new Map(editing.schedules.map((s) => [s.day_of_week, s]));
      return Array.from({ length: 7 }, (_, d) => {
        const s = map.get(d);
        const isOff = s?.is_off ?? (d === 0 || d === 6);
        const isWfh = isOff ? false : Boolean(s?.is_wfh);
        const isField = (isOff || !isWfh) ? false : Boolean(s?.is_field);
        return {
          day_of_week: d,
          is_off: isOff,
          is_wfh: isWfh,
          is_field: isField,
          work_start_time: isOff ? null : hhmm(s?.work_start_time) || '08:00',
          work_end_time: isOff ? null : hhmm(s?.work_end_time) || '17:00',
        };
      });
    }
    return defaultSchedules();
  });
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [k3Warnings, setK3Warnings] = useState<string[]>([]);
  const [showK3Confirm, setShowK3Confirm] = useState(false);

  // Konfirmasi perubahan jam kerja shift yang berlaku mulai tanggal efektif
  const [pendingScheduleSave, setPendingScheduleSave] = useState<(() => void) | null>(null);

  // Ambil setting kantor yang dipilih (untuk validasi & hitung tanggal efektif)
  const selectedOffice = useMemo(
    () => offices.find((o) => String(o.id) === String(editing?.attendance_setting_id ?? branchId)) ?? offices[0] ?? null,
    [offices, branchId, editing],
  );

  // Deteksi apakah jam kerja (schedules) berubah vs jadwal shift saat ini
  const scheduleChanged = useMemo(() => {
    if (!editing) return false; // create → tidak perlu konfirmasi (langsung berlaku)
    const current = editing.schedules ?? [];
    const sameCount = current.length === schedules.length;
    if (!sameCount) return true;
    return schedules.some((s) => {
      const old = current.find((c) => c.day_of_week === s.day_of_week);
      if (!old) return true;

      const oldOff = Boolean(old.is_off);
      const newOff = Boolean(s.is_off);

      const oldWfh = oldOff ? false : Boolean(old.is_wfh);
      const newWfh = newOff ? false : Boolean(s.is_wfh);

      const oldField = (oldOff || !oldWfh) ? false : Boolean(old.is_field);
      const newField = (newOff || !newWfh) ? false : Boolean(s.is_field);

      const oldStart = oldOff ? null : hhmm(old.work_start_time);
      const newStart = newOff ? null : hhmm(s.work_start_time);

      const oldEnd = oldOff ? null : hhmm(old.work_end_time);
      const newEnd = newOff ? null : hhmm(s.work_end_time);

      return (
        oldOff !== newOff ||
        oldWfh !== newWfh ||
        oldField !== newField ||
        oldStart !== newStart ||
        oldEnd !== newEnd
      );
    });
  }, [editing, schedules]);

  // Tanggal efektif jam kerja baru = hari ini + max(1, shift_notice_days kantor)
  const effectiveDate = useMemo(() => {
    if (!editing || !scheduleChanged) return null;
    const noticeDays = selectedOffice?.shift_notice_days && Number(selectedOffice.shift_notice_days) > 0
      ? Number(selectedOffice.shift_notice_days)
      : 1;
    const d = new Date();
    d.setDate(d.getDate() + noticeDays);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }, [editing, scheduleChanged, selectedOffice]);

  // Hitung jeda K3 antar hari secara real-time saat user ubah jam
  const k3Gaps = useMemo(() => computeTemplateGaps(schedules), [schedules]);

  // Hitung total jam kerja per minggu secara real-time
  const weeklyHours = useMemo(() => computeWeeklyHours(schedules), [schedules]);

  // Status indikator jam/minggu
  const weeklyStatus = useMemo(() => {
    if (!selectedOffice?.enforce_weekly_hours) return 'info';
    const max = selectedOffice.max_weekly_hours ?? 40;
    if (weeklyHours > max) return 'error';
    if (weeklyHours > max * 0.9) return 'warning';
    return 'safe';
  }, [weeklyHours, selectedOffice]);

  const setDay = (d: number, patch: Partial<ScheduleRow>) =>
    setSchedules((prev) => prev.map((s) => (s.day_of_week === d ? { ...s, ...patch } : s)));

  const toggleOff = (d: number) =>
    setSchedules((prev) =>
      prev.map((s) =>
        s.day_of_week === d
          ? s.is_off
            ? { ...s, is_off: false, is_wfh: false, is_field: false, work_start_time: '08:00', work_end_time: '17:00' }
            : { ...s, is_off: true, is_wfh: false, is_field: false, work_start_time: null, work_end_time: null }
          : s,
      ),
    );

  const toggleWfh = (d: number) =>
    setSchedules((prev) =>
      prev.map((s) => {
        if (s.day_of_week !== d || s.is_off) return s;
        const nextWfh = !s.is_wfh;
        return {
          ...s,
          is_wfh: nextWfh,
          is_field: nextWfh ? Boolean(s.is_field) : false,
        };
      }),
    );

  const toggleField = (d: number) =>
    setSchedules((prev) =>
      prev.map((s) => {
        if (s.day_of_week !== d || s.is_off || !s.is_wfh) return s;
        return {
          ...s,
          is_field: !s.is_field,
        };
      }),
    );

  const validate = (): string | null => {
    if (!name.trim()) return 'Nama shift wajib diisi.';
    if (!editing && !branchId) return 'Cabang kantor wajib dipilih.';
    // P0 #2 — min 1 hari libur per minggu (UU No. 13/2003 Pasal 79)
    const workingDays = schedules.filter((s) => !s.is_off).length;
    if (workingDays > 6) return 'Karyawan wajib mendapat minimal 1 hari libur per minggu (UU No. 13/2003 Pasal 79).';
    for (const s of schedules) {
      if (s.is_off) continue;
      if (!s.work_start_time || !s.work_end_time)
        return `${DAY_NAMES[s.day_of_week]}: jam masuk & pulang wajib diisi (atau tandai libur).`;
    }
    return null;
  };

  // Validasi duplikat warna lokal (sama logika backend, per-kantor)
  const validateColorLocal = (): string | null => {
    if (!color) return null;
    const targetBranchLocal =
      branchId !== '' ? Number(branchId) : editing?.attendance_setting_id ?? null;
    const editingIsCompanyWideLocal =
      editing != null && editing.attendance_setting_id == null;
    const clash = shifts.find((s) => {
      if (s.id === editing?.id) return false;
      if (s.color == null || s.color.toLowerCase() !== color.toLowerCase()) return false;
      if (s.attendance_setting_id == null) return true;
      if (editingIsCompanyWideLocal) return true;
      if (targetBranchLocal == null) return false;
      return s.attendance_setting_id === targetBranchLocal;
    });
    return clash
      ? `Warna ${color} sudah dipakai oleh shift '${clash.name}' (kantor ini). Pilih warna yang berbeda dalam kantor ini.`
      : null;
  };

  // Kirim ke backend (dipanggil langsung atau setelah konfirmasi)
  const doSave = async () => {
    setBusy(true);
    setErr('');
    const payload: any = {
      name: name.trim(),
      description: description.trim() || undefined,
      color: color || null,
      is_active: isActive,
      schedules: schedules.map((s) => ({
        day_of_week: s.day_of_week,
        is_off: s.is_off,
        is_wfh: s.is_off ? false : Boolean(s.is_wfh),
        is_field: (s.is_off || !s.is_wfh) ? false : Boolean(s.is_field),
        work_start_time: s.is_off ? null : s.work_start_time,
        work_end_time: s.is_off ? null : s.work_end_time,
      })),
    };
    if (!editing) {
      payload.attendance_setting_id = Number(branchId);
    }
    try {
      const res: any = editing ? await shiftApi.update(editing.id, payload) : await shiftApi.create(payload);
      if (res?.warnings?.length) {
        setK3Warnings(res.warnings);
        setShowK3Confirm(true);
        setBusy(false);
        return;
      }
      onSaved();
      onClose();
    } catch (ex: unknown) {
      setErr(ex instanceof ApiError ? ex.message : 'Gagal menyimpan shift.');
      setBusy(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const v = validate();
    if (v) { setErr(v); return; }

    const colorErr = validateColorLocal();
    if (colorErr) { setErr(colorErr); return; }

    // Saat EDIT dan jam kerja (schedules) berubah → konfirmasi tanggal efektif.
    // Nama/warna/deskripsi saja → langsung simpan (tidak mengubah jadwal karyawan).
    if (editing && scheduleChanged && effectiveDate) {
      setPendingScheduleSave(() => doSave);
      return; // tampilkan modal konfirmasi
    }

    await doSave();
  };

  const confirmScheduleSave = () => {
    const fn = pendingScheduleSave;
    setPendingScheduleSave(null);
    if (fn) fn();
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm px-4 py-6"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto animate-in fade-in slide-in-from-bottom-4 duration-200">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 sticky top-0 bg-white z-10">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
              <CalendarClock className="w-5 h-5 text-indigo-600" />
            </div>
            <div>
              <p className="font-bold text-sm text-slate-800">
                {editing ? 'Ubah Template Shift' : 'Tambah Template Shift'}
              </p>
              <p className="text-xs text-slate-500">Atur jam kerja per hari (7 hari)</p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-slate-100 rounded-lg text-slate-400 transition">
            <X className="w-4 h-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          {/* Banner nama cabang jika sedang EDIT */}
          {editing && (
            <div className="bg-slate-50 border border-slate-200 rounded-xl p-3 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Building2 className="w-4 h-4 text-indigo-600" />
                <div>
                  <p className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Cabang Terikat</p>
                  <p className="text-xs font-bold text-slate-800">
                    {editing.office?.office_name ?? selectedOffice?.office_name ?? 'Semua Cabang'}
                  </p>
                </div>
              </div>
              <span className="text-[10px] font-medium bg-slate-200 text-slate-600 px-2 py-0.5 rounded-md">
                Cabang Permanen
              </span>
            </div>
          )}

          {/* Info dasar */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div className={editing ? "sm:col-span-1" : "sm:col-span-1"}>
              <label className="text-xs font-semibold text-slate-600 block mb-1.5">Nama Shift *</label>
              <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Contoh: Shift Sabtu, Shift Gudang Pagi"
                className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none focus:border-indigo-400"
              />
            </div>
            {!editing ? (
              <div>
                <label className="text-xs font-semibold text-slate-600 block mb-1.5">Cabang Kantor *</label>
                <select
                  value={branchId}
                  onChange={(e) => setBranchId(e.target.value)}
                  className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white"
                >
                  <option value="">— Pilih cabang —</option>
                  {offices.map((o) => (
                    <option key={o.id} value={o.id}>{o.office_name}</option>
                  ))}
                </select>
              </div>
            ) : null}
            <div>
              <label className="text-xs font-semibold text-slate-600 block mb-1.5">Warna Shift</label>
              {/* 20 preset warna kontras untuk kalender — pilih satu.
                  Warna yang sudah dipakai shift lain dinonaktifkan (unused) */}
              <div className="flex flex-wrap gap-2">
                {[
                  '#e53e3e', // merah
                  '#dd6b20', // oranye tua
                  '#d69e2e', // kuning keemasan
                  '#38a169', // hijau
                  '#2b6cb0', // biru tua
                  '#6b46c1', // ungu
                  '#d53f8c', // pink magenta
                  '#319795', // teal
                  '#00b5d8', // cyan
                  '#84cc16', // hijau lime
                  '#c05621', // burnt orange
                  '#285e61', // teal gelap
                  '#44337a', // ungu gelap
                  '#702459', // pink tua
                  '#234e52', // hijau tua
                  '#2a4365', // biru navy
                  '#7b341e', // coklat merah
                  '#1a365d', // biru midnight
                  '#f6ad55', // oranye muda
                  '#48bb78', // hijau muda
                ].map((c) => {
                  // Warna unik PER KANTOR. Lingkup bentrok mengikuti kantor yang
                  // DIPILIH di form (bukan status editing):
                  // - Shift lain company-wide (attendance_setting_id null) selalu
                  //   bentrok, karena berlaku di semua kantor.
                  // - Jika user sudah pilih cabang: warna bentrok bila dipakai
                  //   shift lain di cabang yang sama.
                  // - Jika user belum pilih cabang (khusus saat BUAT): jangan
                  //   disable apa pun — biarkan backend yang memvalidasi.
                  // - Saat EDIT: kantor target = kantor yang dipilih di form
                  //   (default = kantor shift yang sedang diedit).
                  // Perbandingan warna case-insensitive (#E53E3E == #e53e3e).
                  // Saat EDIT shift company-wide (tidak ada pilihan cabang, branchId
                  // kosong) → target dianggap company-wide → bentrok dengan semua.
                  const isEditingCompanyWide =
                    editing != null && editing.attendance_setting_id == null;
                  const targetBranch =
                    branchId !== '' ? Number(branchId) : editing?.attendance_setting_id ?? null;
                  const usedBy = shifts.find((s) => {
                    if (s.color == null || s.color.toLowerCase() !== c.toLowerCase()) return false;
                    if (s.id === editing?.id) return false;
                    // Shift lain company-wide selalu bentrok (di kantor mana pun)
                    if (s.attendance_setting_id == null) return true;
                    // Shift target company-wide → semua shift cabang bentrok
                    if (isEditingCompanyWide) return true;
                    // Belum bisa tahu kantor target → biarkan backend memvalidasi
                    if (targetBranch == null) return false;
                    // Bentrok hanya jika di cabang yang sama
                    return s.attendance_setting_id === targetBranch;
                  });
                  const isUsed = !!usedBy;
                  const isSelected = color === c;
                  return (
                    <button
                      key={c}
                      type="button"
                      onClick={() => { if (!isUsed) setColor(c); }}
                      disabled={isUsed}
                      title={usedBy ? `Sudah dipakai oleh shift "${usedBy.name}" (kantor ini)` : c}
                      className={`w-7 h-7 rounded-full border-[3px] transition-all ${isSelected
                        ? 'border-slate-800 scale-125 shadow-md'
                        : isUsed
                          ? 'border-slate-200 opacity-40 cursor-not-allowed'
                          : 'border-transparent hover:scale-110 hover:border-slate-300'
                        }`}
                      style={{ backgroundColor: c }}
                    />
                  );
                })}
              </div>
              {/* Preview warna terpilih */}
              <div className="mt-2 flex items-center gap-2">
                <span
                  className="w-4 h-4 rounded-full border border-black/10 shrink-0"
                  style={{ backgroundColor: color }}
                />
                <span className="text-[11px] font-mono text-slate-500">{color}</span>
                <span className="text-[10px] text-slate-400">— tampilan di kalender</span>
              </div>
            </div>
            <div className="sm:col-span-2 flex flex-col sm:flex-row gap-4">
              <div className="flex-1">
                <label className="text-xs font-semibold text-slate-600 block mb-1.5">Deskripsi</label>
                <input
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="Opsional — keterangan singkat shift"
                  className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none focus:border-indigo-400"
                />
              </div>
              {!editing && (
                <div className="w-full sm:w-56 shrink-0">
                  <label className="text-xs font-semibold text-slate-600 block mb-1.5">Status Shift</label>
                  <div className="flex items-center gap-2 mt-2">
                    <button
                      type="button"
                      onClick={() => setIsActive(!isActive)}
                      className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 ${
                        isActive ? 'bg-indigo-600' : 'bg-slate-200'
                      }`}
                    >
                      <span
                        className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                          isActive ? 'translate-x-2' : '-translate-x-2'
                        }`}
                      />
                    </button>
                    <span className="text-[11px] text-slate-600 font-medium">
                      {isActive ? 'Aktif (Dapat ditugaskan)' : 'Nonaktif (Draf)'}
                    </span>
                  </div>
                </div>
              )}
            </div>
          </div>

          {offices.length === 0 && (
            <div className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700">
              <Info className="w-3.5 h-3.5 mt-0.5 shrink-0" />
              <span>Belum ada kantor/cabang. Tambahkan dulu di menu <strong>Presensi &amp; Cuti → Kantor</strong> sebelum membuat shift.</span>
            </div>
          )}

          {/* Editor 7 hari */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="text-xs font-semibold text-slate-600 flex items-center gap-1.5">
                <CalendarDays className="w-3.5 h-3.5" /> Jadwal per Hari
              </label>
              {/* Chip indikator total jam/minggu */}
              <div className={`inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-full ${weeklyStatus === 'error' ? 'bg-rose-100 text-rose-700' :
                weeklyStatus === 'warning' ? 'bg-amber-100 text-amber-700' :
                  weeklyStatus === 'safe' ? 'bg-emerald-100 text-emerald-700' :
                    'bg-slate-100 text-slate-500'
                }`}>
                <Clock className="w-3 h-3" />
                {weeklyHours}j/minggu
                {selectedOffice?.enforce_weekly_hours && (
                  <span className="opacity-70">/ maks {selectedOffice.max_weekly_hours ?? 40}j</span>
                )}
              </div>
            </div>
            <div className="space-y-1.5">
              {schedules.map((s) => (
                <div
                  key={s.day_of_week}
                  className={`flex items-center gap-2 sm:gap-3 p-2 rounded-lg border ${s.is_off ? 'bg-rose-50/50 border-rose-100' : 'bg-slate-50/60 border-slate-100'
                    }`}
                >
                  <span className="w-14 sm:w-16 text-xs font-bold text-slate-700 shrink-0">
                    {DAY_NAMES[s.day_of_week]}
                  </span>

                  <label className="flex items-center gap-1.5 text-[11px] font-semibold text-slate-500 cursor-pointer select-none shrink-0">
                    <input
                      type="checkbox"
                      checked={s.is_off}
                      onChange={() => toggleOff(s.day_of_week)}
                      className="w-3.5 h-3.5 rounded accent-rose-500"
                    />
                    Libur
                  </label>

                  <label className={`flex items-center gap-1.5 text-[11px] font-semibold cursor-pointer select-none shrink-0 ${s.is_off ? 'opacity-40 cursor-not-allowed text-slate-400' : 'text-cyan-700'}`}>
                    <input
                      type="checkbox"
                      checked={!s.is_off && Boolean(s.is_wfh)}
                      disabled={s.is_off}
                      onChange={() => toggleWfh(s.day_of_week)}
                      className="w-3.5 h-3.5 rounded accent-cyan-600"
                    />
                    WFH
                  </label>

                  <label
                    className={`flex items-center gap-1.5 text-[11px] font-semibold cursor-pointer select-none shrink-0 ${s.is_off || !s.is_wfh ? 'opacity-40 cursor-not-allowed text-slate-400' : 'text-emerald-700'}`}
                    title={!s.is_wfh ? 'Centang WFH terlebih dahulu untuk memilih Lapangan' : undefined}
                  >
                    <input
                      type="checkbox"
                      checked={!s.is_off && Boolean(s.is_wfh) && Boolean(s.is_field)}
                      disabled={s.is_off || !s.is_wfh}
                      onChange={() => toggleField(s.day_of_week)}
                      className="w-3.5 h-3.5 rounded accent-emerald-600"
                    />
                    Lapangan
                  </label>

                  {s.is_off ? (
                    <span className="text-[11px] text-rose-500 italic flex-1">Tidak ada jam kerja</span>
                  ) : (
                    <div className="flex items-center gap-2 flex-1">
                      <input
                        type="time"
                        value={s.work_start_time ?? ''}
                        onChange={(e) => setDay(s.day_of_week, { work_start_time: e.target.value })}
                        className="text-xs p-1.5 border border-slate-200 rounded-md focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white"
                      />
                      <ArrowRight className="w-3 h-3 text-slate-300 shrink-0" />
                      <input
                        type="time"
                        value={s.work_end_time ?? ''}
                        onChange={(e) => setDay(s.day_of_week, { work_end_time: e.target.value })}
                        className="text-xs p-1.5 border border-slate-200 rounded-md focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white"
                      />
                      {/* Indikator shift lintas tengah malam (jam pulang <= jam masuk) */}
                      {s.work_start_time && s.work_end_time && s.work_end_time <= s.work_start_time && (
                        <span className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-violet-100 text-violet-700 shrink-0" title="Shift berakhir keesokan harinya">
                          <Moon className="w-3 h-3" /> +1 hari
                        </span>
                      )}
                      {/* Badge K3 jeda istirahat ke hari berikutnya */}
                      {k3Gaps[s.day_of_week] && (
                        <span
                          className={`inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full shrink-0 ${k3Gaps[s.day_of_week].status === 'error'
                            ? 'bg-rose-100 text-rose-700'
                            : 'bg-amber-100 text-amber-700'
                            }`}
                          title={`Jeda ke ${k3Gaps[s.day_of_week].nextDayName}: ${k3Gaps[s.day_of_week].hours}j`}
                        >
                          <AlertCircle className="w-3 h-3" />
                          {k3Gaps[s.day_of_week].hours}j → {k3Gaps[s.day_of_week].nextDayName}
                        </span>
                      )}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>

          {err && (
            <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 rounded-lg p-3 text-xs text-rose-700">
              <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
              <span>{err}</span>
            </div>
          )}

          <div className="flex gap-2 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 py-2.5 text-xs font-semibold border border-slate-200 rounded-lg hover:bg-slate-50 text-slate-600 transition"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={busy}
              className="flex-1 py-2.5 text-xs font-bold rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 transition flex items-center justify-center gap-1.5"
            >
              {busy && <div className="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin" />}
              <Save className="w-3.5 h-3.5" />
              {editing ? 'Simpan Perubahan' : 'Buat Shift'}
            </button>
          </div>
        </form>
      </div>

      {/* ── Modal konfirmasi K3 — muncul di tengah layar setelah save berhasil dengan warning ── */}
      {showK3Confirm && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 backdrop-blur-sm px-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md animate-in fade-in zoom-in-95 duration-200 overflow-hidden">
            {/* Header */}
            <div className="flex items-center gap-3 px-6 py-4 bg-amber-50 border-b border-amber-100">
              <div className="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
                <AlertTriangle className="w-5 h-5 text-amber-600" />
              </div>
              <div>
                <p className="text-sm font-bold text-amber-800">Peringatan K3 — Jeda Istirahat Pendek</p>
                <p className="text-xs text-amber-600 mt-0.5">Shift berhasil disimpan, namun ada jeda istirahat yang perlu diperhatikan</p>
              </div>
            </div>

            {/* Daftar hari bermasalah */}
            <div className="px-6 py-4 space-y-2.5">
              <p className="text-xs font-semibold text-slate-700">Hari dengan jeda istirahat di bawah rekomendasi:</p>
              <ul className="space-y-2">
                {k3Warnings.map((w, i) => (
                  <li key={i} className="flex items-start gap-2.5 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
                    <AlertTriangle className="w-3.5 h-3.5 text-amber-500 mt-0.5 shrink-0" />
                    <span className="text-xs text-amber-800">{w}</span>
                  </li>
                ))}
              </ul>
              <p className="text-[11px] text-slate-500 pt-1">
                Apakah Anda ingin tetap menggunakan jadwal ini, atau kembali memperbaiki template shift?
              </p>
            </div>

            {/* Tombol aksi */}
            <div className="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50/60">
              <button
                type="button"
                onClick={() => {
                  // Tidak — tutup konfirmasi, kembali ke form untuk diperbaiki
                  setShowK3Confirm(false);
                }}
                className="flex-1 py-2.5 text-xs font-bold rounded-xl border-2 border-amber-400 text-amber-700 hover:bg-amber-50 transition"
              >
                Tidak, Perbaiki Jadwal
              </button>
              <button
                type="button"
                onClick={() => {
                  // Ya — terima warning, tutup semua modal
                  setShowK3Confirm(false);
                  onSaved();
                  onClose();
                }}
                className="flex-1 py-2.5 text-xs font-bold rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white transition"
              >
                Ya, Gunakan Jadwal Ini
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── Modal konfirmasi perubahan jam kerja — berlaku mulai tanggal efektif ── */}
      {pendingScheduleSave && effectiveDate && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/50 backdrop-blur-sm px-4">
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md animate-in fade-in zoom-in-95 duration-200 overflow-hidden">
            <div className="flex items-center gap-3 px-6 py-4 bg-indigo-50 border-b border-indigo-100">
              <div className="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center shrink-0">
                <CalendarClock className="w-5 h-5 text-indigo-600" />
              </div>
              <div>
                <p className="text-sm font-bold text-indigo-800">Perubahan Jam Kerja Shift</p>
                <p className="text-xs text-indigo-600 mt-0.5">Jam kerja baru berlaku sesuai pengaturan notice</p>
              </div>
            </div>

            <div className="px-6 py-4 space-y-2.5">
              <p className="text-xs text-slate-700 leading-relaxed">
                Perubahan <strong>jam kerja</strong> shift <strong>{editing?.name}</strong> tidak mengubah jadwal
                hari ini. Jam kerja baru akan berlaku mulai:
              </p>
              <div className="rounded-lg bg-indigo-50 border border-indigo-100 px-3 py-2.5 flex items-center gap-2">
                <CalendarDays className="w-4 h-4 text-indigo-600 shrink-0" />
                <span className="text-sm font-bold text-indigo-700">
                  {new Date(effectiveDate + 'T00:00:00').toLocaleDateString('id-ID', {
                    day: 'numeric', month: 'long', year: 'numeric',
                  })}
                </span>
              </div>
              <p className="text-[11px] text-slate-500 leading-relaxed">
                Karyawan yang terpasang pada shift ini akan menerima pemberitahuan di aplikasi mobile
                bahwa jam kerja shift berubah mulai tanggal tersebut.
              </p>
            </div>

            <div className="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50/60">
              <button
                type="button"
                onClick={() => setPendingScheduleSave(null)}
                className="flex-1 py-2.5 text-xs font-bold rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-50 transition"
              >
                Batal
              </button>
              <button
                type="button"
                onClick={confirmScheduleSave}
                className="flex-1 py-2.5 text-xs font-bold rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white transition flex items-center justify-center gap-1.5"
              >
                <CheckCircle2 className="w-3.5 h-3.5" /> Ya, Simpan Perubahan
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// MODAL: Assign shift ke satu karyawan + riwayat assignment
// ═══════════════════════════════════════════════════════════════
interface AssignModalProps {
  user: RosterRow;
  shifts: ShiftTemplate[];
  onClose: () => void;
  onSaved: () => void;
}

function AssignModal({ user, shifts, onClose, onSaved }: AssignModalProps) {
  const [shiftId, setShiftId] = useState<string>(''); // '' = default kantor (null)
  const [startDate, setStartDate] = useState(todayStr());
  const [endDate, setEndDate] = useState(''); // kosong = tanpa batas waktu
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [k3Warnings, setK3Warnings] = useState<string[]>([]);

  const [history, setHistory] = useState<AssignmentRow[]>([]);
  const [loadingHist, setLoadingHist] = useState(true);

  // Shift yang relevan: company-wide atau cabang yang sama dengan karyawan (bandingkan by ID),
  // TANPA shift yang sedang AKTIF atau SEGERA dipakai karyawan (cegah assign shift yang sama).
  const relevantShifts = useMemo(() => {
    const activeShiftIds = new Set(
      history
        .filter((h) => (h.status === 'active' || h.status === 'upcoming') && h.shift?.id != null)
        .map((h) => h.shift!.id),
    );
    return shifts.filter(
      (s) =>
        s.is_active && (
          !s.attendance_setting_id ||        // company-wide → boleh untuk semua karyawan
          !user.attendance_setting_id ||     // user belum ada cabang → tampilkan semua shift
          s.attendance_setting_id === user.attendance_setting_id // ID cabang cocok
        ) &&
        !activeShiftIds.has(s.id),           // JANGAN tampilkan shift yang aktif/segera
    );
  }, [shifts, user.attendance_setting_id, history]);

  const loadHistory = useCallback(async () => {
    setLoadingHist(true);
    try {
      const res = await shiftApi.history(user.user_id);
      setHistory(rows(res) as AssignmentRow[]);
    } catch { /* diam */ } finally {
      setLoadingHist(false);
    }
  }, [user.user_id]);

  useEffect(() => { loadHistory(); }, [loadHistory]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!shiftId) {
      setErr('Silakan pilih shift terlebih dahulu.');
      return;
    }
    if (endDate && endDate <= startDate) {
      setErr('Tanggal berakhir harus setelah tanggal mulai (minimal 1 hari setelah tanggal mulai).');
      return;
    }
    setBusy(true);
    setErr('');
    setK3Warnings([]);
    try {
      const res: any = await shiftApi.assign({
        user_id: user.user_id,
        shift_id: shiftId ? Number(shiftId) : null,
        start_date: startDate,
        end_date: endDate || undefined,
        notes: notes.trim() || undefined,
      });
      if (res?.warnings?.length) {
        setK3Warnings(res.warnings);
      }
      await loadHistory();
      onSaved();
      setShiftId('');
      setNotes('');
      setEndDate('');
    } catch (ex: unknown) {
      setErr(ex instanceof ApiError ? ex.message : 'Gagal meng-assign shift.');
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async (h: AssignmentRow) => {
    // Pesan konfirmasi disesuaikan dengan status assignment
    const msg =
      h.status === 'active'
        ? 'Assignment ini SEDANG AKTIF. Mengakhiri akan mengembalikan karyawan ke jadwal kantor default mulai hari ini. Catatan: jika karyawan sedang dalam jam kerja shift, aksi ini DITOLAK sistem (kembalikan hanya saat di luar jam kerja). Histori shift tetap tersimpan. Lanjutkan?'
        : h.status === 'upcoming'
          ? 'Assignment ini belum dimulai (masa depan). Hapus permanen?'
          : 'Assignment ini sudah berakhir. Hapus dari riwayat?';
    if (!confirm(msg)) return;
    try {
      await shiftApi.destroyAssignment(h.id);
      await loadHistory();
      onSaved();
    } catch (ex: unknown) {
      setErr(ex instanceof ApiError ? ex.message : 'Gagal menghapus assignment.');
    }
  };

  const av = avatarFor(user.name);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm px-4 py-6"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto animate-in fade-in slide-in-from-bottom-4 duration-200">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 sticky top-0 bg-white z-10">
          <div className="flex items-center gap-3">
            <div className={`w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold ${av.bg} ${av.text}`}>
              {initialsOf(user.name)}
            </div>
            <div>
              <p className="font-bold text-sm text-slate-800">{user.name}</p>
              <p className="text-xs text-slate-500">
                {user.department || 'Tanpa departemen'}
                {user.branch && <> · {user.branch}</>}
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-slate-100 rounded-lg text-slate-400 transition">
            <X className="w-4 h-4" />
          </button>
        </div>

        <div className="p-6 space-y-5">
          {/* Form assign */}
          <form onSubmit={handleSubmit} className="space-y-3">
            <p className="text-xs font-bold text-slate-700 flex items-center gap-1.5">
              <UserCog className="w-3.5 h-3.5 text-indigo-500" /> Assign Shift Baru
            </p>
            <div>
              <label className="text-[11px] font-semibold text-slate-500 uppercase block mb-1">Shift</label>
              <select
                value={shiftId}
                onChange={(e) => setShiftId(e.target.value)}
                disabled={loadingHist}
                className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white"
              >
                <option value="" disabled>— Pilih Shift —</option>
                {loadingHist ? (
                  <option value="" disabled>Memuat shift...</option>
                ) : (
                  relevantShifts.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}{s.office ? ` — ${s.office.office_name}` : ' — Semua cabang'}
                    </option>
                  ))
                )}
              </select>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="text-[11px] font-semibold text-slate-500 uppercase block mb-1">Berlaku Mulai</label>
                <CustomDatePicker
                  value={startDate}
                  onChange={(val) => {
                    setStartDate(val);
                    if (endDate && endDate < val) {
                      setEndDate('');
                    }
                  }}
                  placeholder="Pilih tgl mulai"
                  size="sm"
                />
              </div>
              <div>
                <label className="text-[11px] font-semibold text-slate-500 uppercase block mb-1">
                  Berlaku Sampai
                  <span className="ml-1 text-slate-400 normal-case font-normal">(opsional)</span>
                </label>
                <CustomDatePicker
                  value={endDate}
                  min={startDate || undefined}
                  onChange={setEndDate}
                  placeholder="Pilih tgl selesai"
                  size="sm"
                  align="right"
                />
                <p className="text-[10px] text-slate-400 mt-1">
                  Kosongkan = berlaku tanpa batas.
                </p>
              </div>
            </div>

            <div>
              <label className="text-[11px] font-semibold text-slate-500 uppercase block mb-1">Catatan</label>
              <input
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="Opsional"
                className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none"
              />
            </div>

            {err && (
              <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 rounded-lg p-2.5 text-[11px] text-rose-700">
                <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
                <span>{err}</span>
              </div>
            )}

            {k3Warnings.length > 0 && (
              <div className="bg-amber-50 border border-amber-200 rounded-lg p-2.5 space-y-1">
                <p className="text-[11px] font-bold text-amber-700 flex items-center gap-1.5">
                  <AlertTriangle className="w-3.5 h-3.5 shrink-0" /> Peringatan K3
                </p>
                {k3Warnings.map((w, i) => (
                  <p key={i} className="text-[11px] text-amber-700">· {w}</p>
                ))}
                <p className="text-[10px] text-amber-600">Assignment berhasil disimpan. Pastikan karyawan mendapat istirahat yang cukup.</p>
              </div>
            )}

            <button
              type="submit"
              disabled={busy}
              className="w-full py-2.5 text-xs font-bold rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 transition flex items-center justify-center gap-1.5"
            >
              {busy && <div className="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin" />}
              <CheckCircle2 className="w-3.5 h-3.5" /> Terapkan Assignment
            </button>
          </form>

          {/* Daftar assignment: menunjukkan shift aktif, yang akan datang, dan yang sudah berakhir */}
          <div className="border-t border-slate-100 pt-4">
            <p className="text-xs font-bold text-slate-700 flex items-center gap-1.5 mb-2">
              <History className="w-3.5 h-3.5 text-slate-400" /> Assignment Shift Karyawan
              {loadingHist && <div className="w-3 h-3 border-2 border-slate-300 border-t-indigo-500 rounded-full animate-spin" />}
            </p>
            {history.length === 0 && !loadingHist ? (
              <p className="text-[11px] text-slate-400 italic py-2">
                Belum ada assignment khusus. Karyawan mengikuti jam kantor default.
              </p>
            ) : (
              <div className="space-y-1.5">
                {history.map((h) => (
                  <div key={h.id} className="flex items-start justify-between gap-2 p-2.5 rounded-lg bg-slate-50 border border-slate-100">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-1.5">
                        <p className="text-xs font-semibold text-slate-700 truncate">
                          {h.shift?.name ?? 'Default Kantor'}
                        </p>
                        {/* Badge status assignment */}
                        {h.status === 'active' && (
                          <span className="shrink-0 inline-flex items-center gap-1 rounded-full bg-emerald-100 text-emerald-700 px-1.5 py-0.5 text-[9px] font-bold">
                            <span className="w-1 h-1 rounded-full bg-emerald-500" /> AKTIF
                          </span>
                        )}
                        {h.status === 'upcoming' && (
                          <span className="shrink-0 inline-flex items-center gap-1 rounded-full bg-sky-100 text-sky-700 px-1.5 py-0.5 text-[9px] font-bold">
                            SEGERA
                          </span>
                        )}
                        {h.status === 'expired' && (
                          <span className="shrink-0 inline-flex items-center rounded-full bg-slate-100 text-slate-400 px-1.5 py-0.5 text-[9px] font-bold">
                            BERAKHIR
                          </span>
                        )}
                      </div>
                      <p className="text-[10px] text-slate-400">
                        Mulai {fmtDate(h.start_date)}
                        {h.end_date && (
                          <>
                            {' '}·{' '}
                            <span className="inline-flex items-center gap-0.5 font-semibold text-amber-600">
                              s.d. {fmtDate(h.end_date)}
                            </span>
                          </>
                        )}
                        {h.notes && <> · {h.notes}</>}
                      </p>
                    </div>
                    <button
                      onClick={() => handleDelete(h)}
                      className="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50 transition shrink-0"
                      title={h.status === 'active' ? 'Akhiri assignment (kembali ke jadwal kantor)' : 'Hapus assignment'}
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// MODAL: Bulk assign ke banyak karyawan
// ═══════════════════════════════════════════════════════════════
interface BulkModalProps {
  userIds: number[];
  userNames: string[];
  shifts: ShiftTemplate[];
  /** ID attendance_setting (cabang) dari karyawan yang dipilih — untuk filter dropdown shift */
  selectedBranchIds: Set<number>;
  onClose: () => void;
  onSaved: () => void;
}

function BulkAssignModal({ userIds, userNames, shifts, selectedBranchIds, onClose, onSaved }: BulkModalProps) {
  const [shiftId, setShiftId] = useState<string>('');
  const [startDate, setStartDate] = useState(todayStr());
  const [endDate, setEndDate] = useState(''); // kosong = tanpa batas waktu
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [result, setResult] = useState<{ success: number; skipped: { name?: string; reason: string }[] } | null>(null);

  // Filter shift: hanya tampilkan shift yang cocok dengan cabang karyawan yang dipilih.
  // Shift company-wide (attendance_setting_id null) selalu ditampilkan karena berlaku untuk semua cabang.
  // Jika selectedBranchIds kosong (karyawan belum ada cabang), tampilkan semua shift aktif.
  const availableShifts = useMemo(() => {
    return shifts.filter((s) =>
      s.is_active && (
        !s.attendance_setting_id ||                            // company-wide → tampilkan untuk semua
        selectedBranchIds.size === 0 ||                        // belum ada cabang terpilih → tampilkan semua
        selectedBranchIds.has(s.attendance_setting_id)         // cabang shift cocok dengan cabang karyawan
      ),
    );
  }, [shifts, selectedBranchIds]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!shiftId) {
      setErr('Silakan pilih shift terlebih dahulu.');
      return;
    }
    if (endDate && endDate <= startDate) {
      setErr('Tanggal berakhir harus setelah tanggal mulai (minimal 1 hari setelah tanggal mulai).');
      return;
    }
    setBusy(true);
    setErr('');
    try {
      const res: any = await shiftApi.bulkAssign({
        user_ids: userIds,
        shift_id: shiftId ? Number(shiftId) : null,
        start_date: startDate,
        end_date: endDate || undefined,
        notes: notes.trim() || undefined,
      });
      setResult({
        success: res?.total_success ?? res?.assigned?.length ?? 0,
        skipped: res?.skipped ?? [],
      });
      onSaved();
    } catch (ex: unknown) {
      setErr(ex instanceof ApiError ? ex.message : 'Gagal melakukan bulk assign.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm px-4 py-6"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto animate-in fade-in slide-in-from-bottom-4 duration-200">
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-full bg-violet-100 flex items-center justify-center">
              <Users className="w-5 h-5 text-violet-600" />
            </div>
            <div>
              <p className="font-bold text-sm text-slate-800">Assign Massal</p>
              <p className="text-xs text-slate-500">{userIds.length} karyawan dipilih</p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-slate-100 rounded-lg text-slate-400 transition">
            <X className="w-4 h-4" />
          </button>
        </div>

        <div className="p-6 space-y-4">
          {!result ? (
            <form onSubmit={handleSubmit} className="space-y-3">
              {/* Daftar nama terpilih */}
              <div className="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto">
                {userNames.map((n, i) => (
                  <span key={i} className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">
                    {n}
                  </span>
                ))}
              </div>

              <div>
                <label className="text-[11px] font-semibold text-slate-500 uppercase block mb-1">Shift</label>
                <select
                  value={shiftId}
                  onChange={(e) => setShiftId(e.target.value)}
                  className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white"
                >
                  <option value="" disabled>— Pilih Shift —</option>
                  {availableShifts.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}{s.office ? ` — ${s.office.office_name}` : ' — Semua cabang'}
                    </option>
                  ))}
                </select>
                <p className="text-[10px] text-slate-400 mt-1">
                  {selectedBranchIds.size > 0
                    ? 'Menampilkan shift milik cabang karyawan yang dipilih dan shift yang berlaku untuk semua cabang.'
                    : 'Menampilkan semua shift aktif.'}
                </p>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-[11px] font-semibold text-slate-500 uppercase block mb-1">Berlaku Mulai</label>
                  <CustomDatePicker
                    value={startDate}
                    onChange={(val) => {
                      setStartDate(val);
                      if (endDate && endDate < val) {
                        setEndDate('');
                      }
                    }}
                    placeholder="Pilih tgl mulai"
                    size="sm"
                  />
                </div>
                <div>
                  <label className="text-[11px] font-semibold text-slate-500 uppercase block mb-1">
                    Berlaku Sampai
                    <span className="ml-1 text-slate-400 normal-case font-normal">(opsional)</span>
                  </label>
                  <CustomDatePicker
                    value={endDate}
                    min={startDate || undefined}
                    onChange={setEndDate}
                    placeholder="Pilih tgl selesai"
                    size="sm"
                    align="right"
                  />
                </div>
              </div>

              <div>
                <label className="text-[11px] font-semibold text-slate-500 uppercase block mb-1">Catatan</label>
                <input
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Opsional"
                  className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none"
                />
              </div>
              <p className="text-[10px] text-slate-400 -mt-1">
                Kosongkan tanggal berakhir = shift berlaku tanpa batas. Setelah tanggal itu, karyawan otomatis kembali ke jam kantor.
              </p>

              {err && (
                <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 rounded-lg p-2.5 text-[11px] text-rose-700">
                  <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
                  <span>{err}</span>
                </div>
              )}

              <div className="flex gap-2 pt-1">
                <button type="button" onClick={onClose} className="flex-1 py-2.5 text-xs font-semibold border border-slate-200 rounded-lg hover:bg-slate-50 text-slate-600 transition">
                  Batal
                </button>
                <button type="submit" disabled={busy} className="flex-1 py-2.5 text-xs font-bold rounded-lg text-white bg-violet-600 hover:bg-violet-700 disabled:bg-violet-300 transition flex items-center justify-center gap-1.5">
                  {busy && <div className="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin" />}
                  Assign {userIds.length} Karyawan
                </button>
              </div>
            </form>
          ) : (
            /* Hasil bulk assign */
            <div className="space-y-3">
              <div className="flex items-center gap-2 bg-emerald-50 border border-emerald-200 rounded-lg p-3 text-xs text-emerald-700">
                <CheckCircle2 className="w-4 h-4 shrink-0" />
                <span><strong>{result.success}</strong> karyawan berhasil di-assign.</span>
              </div>
              {result.skipped.length > 0 && (
                <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-700 space-y-1">
                  <p className="font-bold flex items-center gap-1.5">
                    <AlertCircle className="w-3.5 h-3.5" /> {result.skipped.length} dilewati:
                  </p>
                  <ul className="space-y-0.5 pl-1">
                    {result.skipped.map((s, i) => (
                      <li key={i} className="text-[11px]">• {s.name ?? `User #${i}`}: {s.reason}</li>
                    ))}
                  </ul>
                </div>
              )}
              <button onClick={onClose} className="w-full py-2.5 text-xs font-bold rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 transition">
                Selesai
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// MODAL: Lihat karyawan yang terkait sebuah template shift
// ═══════════════════════════════════════════════════════════════
interface ShiftUsersModalProps {
  shift: ShiftTemplate;
  onClose: () => void;
}

function ShiftUsersModal({ shift, onClose }: ShiftUsersModalProps) {
  const [rows, setRows] = useState<ShiftUserRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError('');
      try {
        const res: any = await shiftApi.users(shift.id);
        if (!cancelled) setRows((res?.data ?? []) as ShiftUserRow[]);
      } catch (ex: unknown) {
        if (!cancelled) setError(ex instanceof ApiError ? ex.message : 'Gagal memuat karyawan shift.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, [shift.id]);

  const activeCount = rows.filter((r) => r.status === 'active').length;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm px-4 py-6"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden animate-in fade-in slide-in-from-bottom-4 duration-200">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-full flex items-center justify-center" style={{ backgroundColor: (shift.color ?? '#6366f1') + '20' }}>
              <Layers className="w-5 h-5" style={{ color: shift.color ?? '#6366f1' }} />
            </div>
            <div>
              <p className="font-bold text-sm text-slate-800 flex items-center gap-2">
                {shift.name}
                {!shift.is_active && (
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">Nonaktif</span>
                )}
              </p>
              <p className="text-xs text-slate-500">
                {rows.length} karyawan terkait · {activeCount} aktif
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-slate-100 rounded-lg text-slate-400 transition">
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-6 py-4">
          {error && (
            <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 rounded-lg p-3 text-xs text-rose-700">
              <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          {loading ? (
            <div className="space-y-2">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="flex items-center gap-2.5 p-2.5 rounded-lg bg-slate-50 border border-slate-100 animate-pulse">
                  <div className="w-7 h-7 rounded-full bg-slate-200 shrink-0" />
                  <div className="flex-1 space-y-1.5">
                    <div className="h-3 w-32 bg-slate-200 rounded" />
                    <div className="h-2 w-20 bg-slate-200 rounded" />
                  </div>
                </div>
              ))}
            </div>
          ) : rows.length === 0 ? (
            <div className="py-12 text-center text-slate-400">
              <Users className="w-10 h-10 mx-auto opacity-30" />
              <p className="font-semibold text-sm mt-2">Belum ada karyawan</p>
              <p className="text-xs mt-0.5">Belum ada karyawan yang di-assign ke shift ini.</p>
            </div>
          ) : (
            <div className="space-y-1.5">
              {rows.map((r) => {
                const av = avatarFor(r.name);
                return (
                  <div key={r.assignment_id} className="flex items-center gap-2.5 p-2.5 rounded-lg bg-slate-50 border border-slate-100">
                    <div className={`w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0 ${av.bg} ${av.text}`}>
                      {initialsOf(r.name)}
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="text-xs font-semibold text-slate-700 truncate">{r.name}</p>
                      <p className="text-[10px] text-slate-400 truncate">
                        {r.department || 'Tanpa departemen'}
                        {r.branch && <> · {r.branch}</>}
                      </p>
                    </div>
                    {/* Badge status assignment */}
                    {r.status === 'active' && (
                      <span className="shrink-0 inline-flex items-center gap-1 rounded-full bg-emerald-100 text-emerald-700 px-1.5 py-0.5 text-[9px] font-bold">
                        <span className="w-1 h-1 rounded-full bg-emerald-500" /> AKTIF
                      </span>
                    )}
                    {r.status === 'upcoming' && (
                      <span className="shrink-0 inline-flex items-center rounded-full bg-sky-100 text-sky-700 px-1.5 py-0.5 text-[9px] font-bold">
                        SEGERA
                      </span>
                    )}
                    {r.status === 'expired' && (
                      <span className="shrink-0 inline-flex items-center rounded-full bg-slate-100 text-slate-400 px-1.5 py-0.5 text-[9px] font-bold">
                        BERAKHIR
                      </span>
                    )}
                    <span className="shrink-0 text-[10px] text-slate-400">
                      {fmtDate(r.start_date)}
                      {r.end_date && <> → {fmtDate(r.end_date)}</>}
                    </span>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// MAIN COMPONENT
// ═══════════════════════════════════════════════════════════════
type Tab = 'roster' | 'templates' | 'kalender';

interface Props {
  onAddAuditLog?: (title: string, details: string, bg: string) => void;
}

export function ShiftManagement({ onAddAuditLog }: Props) {
  const [tab, setTab] = useState<Tab>('roster');
  const [offices, setOffices] = useState<OfficeOpt[]>([]);
  const [shifts, setShifts] = useState<ShiftTemplate[]>([]);
  const [error, setError] = useState('');

  // ── Roster state ──
  const [roster, setRoster] = useState<RosterRow[]>([]);
  const [rosterDate, setRosterDate] = useState(todayStr());
  const [rosterBranch, setRosterBranch] = useState<string>('');
  const [rosterSearch, setRosterSearch] = useState('');
  const [rosterDayName, setRosterDayName] = useState('');
  const [rosterShiftName, setRosterShiftName] = useState('');
  const [loadingRoster, setLoadingRoster] = useState(false);
  const [selected, setSelected] = useState<Set<number>>(new Set());

  // ── Template state ──
  const [loadingShifts, setLoadingShifts] = useState(false);
  const [templateBranch, setTemplateBranch] = useState<string>(''); // '' = semua cabang

  // ── Kalender state ──
  const [calMonth, setCalMonth] = useState(() => new Date().getMonth() + 1); // 1-12
  const [calYear, setCalYear] = useState(() => new Date().getFullYear());
  const [calBranch, setCalBranch] = useState<string>('');
  const [calData, setCalData] = useState<Record<string, CalDayEntry[]>>({});
  const [loadingCal, setLoadingCal] = useState(false);
  const [calDetail, setCalDetail] = useState<{ date: string; entries: CalDayEntry[] } | null>(null);

  // ── Modals ──
  const [assignUser, setAssignUser] = useState<RosterRow | null>(null);
  const [showBulk, setShowBulk] = useState(false);
  const [shiftForm, setShiftForm] = useState<{ editing: ShiftTemplate | null } | null>(null);
  const [shiftUsersView, setShiftUsersView] = useState<ShiftTemplate | null>(null);

  // ─── Loaders ───────────────────────────────────────────────
  const loadOffices = useCallback(async () => {
    try {
      const res: any = await attendanceApi.settings.list();
      setOffices((res?.settings ?? []) as OfficeOpt[]);
    } catch { /* diam */ }
  }, []);

  const loadShifts = useCallback(async () => {
    setLoadingShifts(true);
    try {
      const res = await shiftApi.list();
      setShifts(rows(res) as ShiftTemplate[]);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Gagal memuat template shift.');
    } finally {
      setLoadingShifts(false);
    }
  }, []);

  const debouncedRosterSearch = useDebounce(rosterSearch, 500);

  const loadRoster = useCallback(async () => {
    setLoadingRoster(true);
    setError('');
    try {
      const filters: { date?: string; attendance_setting_id?: number; search?: string } = { date: rosterDate };
      if (rosterBranch) filters.attendance_setting_id = Number(rosterBranch);
      if (debouncedRosterSearch.trim()) filters.search = debouncedRosterSearch.trim();
      const res: any = await shiftApi.roster(filters);
      setRoster((res?.data ?? []) as RosterRow[]);
      setRosterDayName(res?.day_name ?? '');
      setSelected(new Set());
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Gagal memuat roster.');
    } finally {
      setLoadingRoster(false);
    }
  }, [rosterDate, rosterBranch, debouncedRosterSearch]);

  const loadCalendar = useCallback(async () => {
    setLoadingCal(true);
    try {
      const res: any = await shiftApi.calendar(
        calMonth, calYear,
        calBranch ? Number(calBranch) : undefined,
      );
      setCalData((res?.days ?? {}) as Record<string, CalDayEntry[]>);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Gagal memuat kalender shift.');
    } finally {
      setLoadingCal(false);
    }
  }, [calMonth, calYear, calBranch]);

  useEffect(() => { loadOffices(); loadShifts(); }, [loadOffices, loadShifts]);
  useEffect(() => { if (tab === 'roster') loadRoster(); }, [tab, loadRoster]);
  useEffect(() => { if (tab === 'kalender') loadCalendar(); }, [tab, loadCalendar]);

  // ─── Aksi template ─────────────────────────────────────────
  const handleDeleteShift = async (s: ShiftTemplate) => {
    if (!confirm(`Hapus template shift "${s.name}"? Aksi ini tidak bisa dibatalkan.`)) return;
    setError('');
    try {
      await shiftApi.destroy(s.id);
      await loadShifts();
      onAddAuditLog?.('Shift dihapus', s.name, 'bg-rose-500');
    } catch (e: unknown) {
      // 409 = masih ada karyawan yang memakai shift → tampilkan petunjuk lengkap
      if (e instanceof ApiError) {
        const affected = e.data?.affected_names as string | undefined;
        setError(
          affected
            ? `${e.message}\nKaryawan terdampak: ${affected}`
            : e.message,
        );
      } else {
        setError('Gagal menghapus shift.');
      }
    }
  };

  const handleToggleActive = async (s: ShiftTemplate) => {
    const aksi = s.is_active ? 'menonaktifkan' : 'mengaktifkan';
    if (!confirm(`Yakin ingin ${aksi} shift "${s.name}"?`)) return;
    setError('');
    try {
      await shiftApi.toggleActive(s.id);
      await loadShifts();
      onAddAuditLog?.(s.is_active ? 'Shift dinonaktifkan' : 'Shift diaktifkan', s.name, 'bg-indigo-500');
    } catch (e: unknown) {
      // 409 = masih ada karyawan yang memakai shift → tampilkan petunjuk lengkap
      if (e instanceof ApiError) {
        const affected = e.data?.affected_names as string | undefined;
        setError(
          affected
            ? `${e.message}\nKaryawan terdampak: ${affected}`
            : e.message,
        );
      } else {
        setError(`Gagal ${aksi} shift.`);
      }
    }
  };

  // ─── Seleksi roster ────────────────────────────────────────
  const toggleSelect = (id: number) =>
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });

  // ID cabang dari karyawan yang sedang dipilih (untuk auto-filter roster).
  const selectedBranchIds = useMemo(() => {
    const ids = new Set<number>();
    roster.forEach((r) => {
      if (selected.has(r.user_id) && r.attendance_setting_id != null) {
        ids.add(r.attendance_setting_id);
      }
    });
    return ids;
  }, [roster, selected]);

  const filteredRoster = useMemo(() => {
    return roster.filter((r) => {
      // Auto-filter: jika ada karyawan yang dicentang, hanya tampilkan karyawan
      // dengan kantor cabang yang sama (memudahkan bulk assign per tim cabang).
      if (selectedBranchIds.size > 0) {
        if (r.attendance_setting_id == null) return false;
        if (!selectedBranchIds.has(r.attendance_setting_id)) return false;
      }
      if (!rosterShiftName) return true;
      if (rosterShiftName === 'DEFAULT') return r.source === 'office';
      return r.shift_name === rosterShiftName;
    });
  }, [roster, rosterShiftName, selectedBranchIds]);

  const toggleSelectAll = () =>
    setSelected((prev) =>
      prev.size === filteredRoster.length && filteredRoster.length > 0
        ? new Set()
        : new Set(filteredRoster.map((r) => r.user_id)),
    );

  const selectedNames = roster.filter((r) => selected.has(r.user_id)).map((r) => r.name);

  // Nama cabang dari karyawan yang sedang dipilih — untuk keterangan auto-filter.
  const selectedBranchNames = useMemo(() => {
    if (selectedBranchIds.size === 0) return '';
    return offices
      .filter((o) => selectedBranchIds.has(o.id))
      .map((o) => o.office_name)
      .join(', ');
  }, [selectedBranchIds, offices]);

  // ── Filter template berdasarkan cabang (client-side, tanpa request baru) ──
  // Shift company-wide (attendance_setting_id = null) selalu tampil di semua pilihan cabang.
  const filteredShifts = useMemo(() => {
    if (!templateBranch) return shifts; // semua cabang → tampilkan semua
    const branchId = Number(templateBranch);
    return shifts.filter(
      (s) => s.attendance_setting_id === null || s.attendance_setting_id === branchId,
    );
  }, [shifts, templateBranch]);

  // ═════════════════════════════════════════════════════════
  return (
    <div className="p-4 md:p-6 space-y-5 animate-in fade-in slide-in-from-bottom-2 duration-300">
      {/* Tabs */}
      <div className="flex flex-wrap items-center gap-1 border-b border-slate-200 dark:border-slate-800">
        {[
          { key: 'roster' as Tab, label: 'Roster Harian', icon: <CalendarDays className="w-3.5 h-3.5" /> },
          { key: 'templates' as Tab, label: 'Template Shift', icon: <Layers className="w-3.5 h-3.5" /> },
          { key: 'kalender' as Tab, label: 'Kalender', icon: <CalendarClock className="w-3.5 h-3.5" /> },
        ].map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={`flex items-center gap-1.5 px-3.5 sm:px-4 py-2.5 text-xs font-bold border-b-2 -mb-px transition cursor-pointer ${tab === t.key
              ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
              : 'border-transparent text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300'
              }`}
          >
            {t.icon}
            {t.label}
            {t.key === 'templates' && shifts.length > 0 && (
              <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-mono ${tab === t.key ? 'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'}`}>
                {shifts.length}
              </span>
            )}
          </button>
        ))}
      </div>

      {error && (
        <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl px-4 py-3 text-xs">
          <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
          <span className="flex-1 whitespace-pre-line">{error}</span>
        </div>
      )}

      {/* ═══════════ TAB: ROSTER ═══════════ */}
      {tab === 'roster' && (
        <div className="space-y-4">
          {/* Filter bar */}
          <div className="bg-white rounded-2xl border border-slate-100 p-4 shadow-sm">
            {/* Auto-filter info: centang karyawan → daftar menyaring ke cabang yang sama */}
            {selectedBranchIds.size > 0 && (
              <div className="flex items-center gap-2 bg-indigo-50 border border-indigo-200 rounded-lg px-3 py-2 mb-3 text-[11px] text-indigo-700 animate-in fade-in duration-200">
                <Users className="w-3.5 h-3.5 shrink-0" />
                <span>
                  <strong>Auto-filter cabang aktif:</strong> daftar menampilkan karyawan dengan cabang <strong>{selectedBranchNames}</strong> ({selected.size} dipilih). Hapus centang atau tekan <em>Batal pilih</em> untuk melihat semua.
                </span>
              </div>
            )}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">
              <div>
                <label className="block text-[10px] font-semibold text-slate-500 uppercase mb-1">Cabang</label>
                <select
                  value={rosterBranch}
                  onChange={(e) => setRosterBranch(e.target.value)}
                  className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white"
                >
                  <option value="">Semua cabang</option>
                  {offices.map((o) => (
                    <option key={o.id} value={o.id}>{o.office_name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-[10px] font-semibold text-slate-500 uppercase mb-1">Shift</label>
                <select
                  value={rosterShiftName}
                  onChange={(e) => setRosterShiftName(e.target.value)}
                  className="w-full text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white"
                >
                  <option value="">Semua shift</option>
                  <option value="DEFAULT">Default Kantor</option>
                  {shifts.map((s) => (
                    <option key={s.id} value={s.name}>{s.name}</option>
                  ))}
                </select>
              </div>
              <div className="sm:col-span-2">
                <label className="block text-[10px] font-semibold text-slate-500 uppercase mb-1">Cari Karyawan</label>
                <div className="flex gap-2">
                  <div className="relative flex-1">
                    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                    <input
                      type="text"
                      value={rosterSearch}
                      onChange={(e) => setRosterSearch(e.target.value)}
                      onKeyDown={(e) => e.key === 'Enter' && loadRoster()}
                      placeholder="Nama karyawan..."
                      className="w-full pl-8 text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none"
                    />
                  </div>
                  <button
                    onClick={loadRoster}
                    className="px-4 py-2 text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition shrink-0 cursor-pointer"
                  >
                    Terapkan
                  </button>
                </div>
              </div>
            </div>

          </div>

          {/* Bulk action bar */}
          {selected.size > 0 && (
            <div className="flex items-center justify-between gap-3 bg-violet-50 border border-violet-200 rounded-xl px-4 py-2.5 animate-in fade-in slide-in-from-top-1 duration-200">
              <p className="text-xs font-semibold text-violet-700">
                {selected.size} karyawan dipilih
              </p>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setSelected(new Set())}
                  className="text-xs font-semibold text-slate-500 hover:text-slate-700 transition"
                >
                  Batal pilih
                </button>
                <button
                  onClick={() => setShowBulk(true)}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition"
                >
                  <Users className="w-3.5 h-3.5" /> Assign Massal
                </button>
              </div>
            </div>
          )}

          {/* Tabel roster */}
          <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
            <div className="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
              <p className="text-xs font-semibold text-slate-600 flex items-center gap-1.5">
                <CalendarDays className="w-3.5 h-3.5 text-indigo-500" />
                Jadwal {rosterDayName && <span className="text-indigo-600">{rosterDayName}</span>}, {fmtDate(rosterDate)}
                <span className="text-slate-400">· {filteredRoster.length} karyawan</span>
              </p>
              <button
                onClick={loadRoster}
                disabled={loadingRoster}
                className="flex items-center gap-1.5 text-xs font-semibold text-indigo-600 border border-indigo-200 bg-white px-3 py-1.5 rounded-lg hover:bg-indigo-50 transition"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${loadingRoster ? 'animate-spin' : ''}`} /> Refresh
              </button>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead className="border-b border-slate-100 bg-slate-50/60">
                  <tr>
                    <th className="py-2.5 px-3 w-8">
                      <input
                        type="checkbox"
                        checked={filteredRoster.length > 0 && selected.size === filteredRoster.length}
                        onChange={toggleSelectAll}
                        className="w-3.5 h-3.5 rounded accent-indigo-600 align-middle"
                      />
                    </th>
                    <th className="py-2.5 px-3 font-semibold text-left text-slate-500">Karyawan</th>
                    <th className="py-2.5 px-3 font-semibold text-left text-slate-500">Cabang</th>
                    <th className="py-2.5 px-3 font-semibold text-left text-slate-500">Shift</th>
                    <th className="py-2.5 px-3 font-semibold text-center text-slate-500">Jam Kerja</th>
                    <th className="py-2.5 px-3 font-semibold text-center text-slate-500">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {loadingRoster ? (
                    Array.from({ length: 5 }).map((_, i) => (
                      <tr key={`skel-roster-${i}`} className="animate-pulse">
                        <td className="py-3 px-3">
                          <div className="w-3.5 h-3.5 bg-slate-200 rounded" />
                        </td>
                        <td className="py-3 px-3">
                          <div className="flex items-center gap-2.5">
                            <div className="w-7 h-7 rounded-full bg-slate-200 shrink-0" />
                            <div className="space-y-1.5">
                              <div className="h-3 w-24 bg-slate-200 rounded" />
                              <div className="h-2 w-16 bg-slate-200 rounded" />
                            </div>
                          </div>
                        </td>
                        <td className="py-3 px-3"><div className="h-3 w-20 bg-slate-200 rounded" /></td>
                        <td className="py-3 px-3"><div className="h-3 w-24 bg-slate-200 rounded" /></td>
                        <td className="py-3 px-3"><div className="h-3 w-20 bg-slate-200 rounded mx-auto" /></td>
                        <td className="py-3 px-3"><div className="h-6 w-16 bg-slate-200 rounded-lg mx-auto" /></td>
                      </tr>
                    ))
                  ) : filteredRoster.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="py-16 text-center">
                        <div className="flex flex-col items-center gap-2 text-slate-400">
                          <Users className="w-10 h-10 opacity-30" />
                          <p className="font-semibold text-sm">Tidak ada karyawan</p>
                          <p className="text-xs">
                            {roster.length > 0 ? "Tidak ada karyawan yang cocok dengan filter." : "Menampilkan semua karyawan aktif. Pastikan sudah ada karyawan di perusahaan ini."}
                          </p>
                        </div>
                      </td>
                    </tr>
                  ) : filteredRoster.map((r) => {
                    const av = avatarFor(r.name);
                    const isSel = selected.has(r.user_id);
                    return (
                      <tr key={r.user_id} className={`transition-colors ${isSel ? 'bg-indigo-50/40' : 'hover:bg-slate-50/60'}`}>
                        <td className="py-3 px-3">
                          <input
                            type="checkbox"
                            checked={isSel}
                            onChange={() => toggleSelect(r.user_id)}
                            className="w-3.5 h-3.5 rounded accent-indigo-600 align-middle"
                          />
                        </td>
                        <td className="py-3 px-3">
                          <div className="flex items-center gap-2.5">
                            <div className={`w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0 ${av.bg} ${av.text}`}>
                              {initialsOf(r.name)}
                            </div>
                            <div>
                              <p className="font-semibold text-slate-800">{r.name}</p>
                              {r.department && <p className="text-[10px] text-slate-400">{r.department}</p>}
                            </div>
                          </div>
                        </td>
                        <td className="py-3 px-3 text-slate-600">{r.branch ?? <span className="text-slate-300">—</span>}</td>
                        <td className="py-3 px-3 text-slate-700">
                          {r.shift_name ?? (
                            r.upcoming_shift
                              ? null // ada shift coming soon → jangan tampilkan strip
                              : r.source === 'office' ? (
                                  <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">
                                    <Building2 className="w-3 h-3" /> Jam Kantor
                                  </span>
                                ) : (
                                  <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">
                                    <AlertCircle className="w-3 h-3" /> Belum Diatur
                                  </span>
                                )
                          )}
                          {/* Shift yang sudah di-assign tapi belum aktif (coming soon) */}
                          {r.upcoming_shift && (
                            <div className="mt-1 space-y-0.5">
                              <span
                                className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200"
                                title={`Shift '${r.upcoming_shift.shift_name}' aktif mulai ${fmtDate(r.upcoming_shift.start_date)}`}
                              >
                                <Clock className="w-2.5 h-2.5 shrink-0" />
                                Coming Soon
                              </span>
                              <p className="text-[10px] font-semibold text-amber-700 leading-tight">
                                {r.upcoming_shift.shift_name}
                                <span className="text-slate-400 font-normal"> · Aktif {fmtDate(r.upcoming_shift.start_date)}</span>
                              </p>
                            </div>
                          )}
                        </td>
                        <td className="py-3 px-3 text-center font-mono text-slate-700">
                          {r.is_off ? (
                            <span className="text-slate-300">—</span>
                          ) : r.work_start_time ? (
                            <span className="inline-flex items-center gap-1 justify-center">
                              {hhmm(r.work_start_time)}–{hhmm(r.work_end_time)}
                              {r.is_wfh && !r.is_field && (
                                <span className="inline-flex items-center text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-cyan-100 text-cyan-800 border border-cyan-200" title="Hari WFH terjadwal">
                                  WFH
                                </span>
                              )}
                              {r.is_wfh && r.is_field && (
                                <span className="inline-flex items-center text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200" title="Hari Lapangan terjadwal (WFH + Radius GPS)">
                                  Lapangan
                                </span>
                              )}
                              {r.is_cross_day && (
                                <span className="inline-flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-violet-100 text-violet-700" title="Shift berakhir keesokan harinya">
                                  <Moon className="w-2.5 h-2.5" /> +1
                                </span>
                              )}
                            </span>
                          ) : (
                            <span className="text-slate-300">—</span>
                          )}
                        </td>
                        <td className="py-3 px-3 text-center">
                          <button
                            onClick={() => setAssignUser(r)}
                            className="inline-flex items-center gap-1 px-2.5 py-1.5 text-[11px] font-bold text-indigo-600 border border-indigo-200 hover:bg-indigo-50 rounded-lg transition"
                          >
                            <UserCog className="w-3 h-3" /> Kelola
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* ═══════════ TAB: TEMPLATES ═══════════ */}
      {tab === 'templates' && (
        <div className="space-y-4">
          {/* Toolbar: filter cabang + tombol tambah */}
          <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4">
            <div className="flex flex-col sm:flex-row sm:items-center gap-3">
              {/* Filter cabang */}
              <div className="flex-1 flex items-center gap-2">
                <Building2 className="w-4 h-4 text-slate-400 shrink-0" />
                <select
                  value={templateBranch}
                  onChange={(e) => setTemplateBranch(e.target.value)}
                  className="flex-1 max-w-xs text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white"
                >
                  <option value="">Semua cabang</option>
                  {offices.map((o) => (
                    <option key={o.id} value={o.id}>{o.office_name}</option>
                  ))}
                </select>
                {templateBranch && (
                  <button
                    onClick={() => setTemplateBranch('')}
                    className="flex items-center gap-1 text-xs font-semibold text-slate-400 hover:text-slate-600 transition"
                    title="Hapus filter cabang"
                  >
                    <X className="w-3.5 h-3.5" />
                  </button>
                )}
                <span className="text-[11px] text-slate-400 shrink-0">
                  {filteredShifts.length}
                  {templateBranch ? ` / ${shifts.length}` : ''} template
                </span>
              </div>
              {/* Tombol tambah + refresh */}
              <div className="flex items-center gap-2">
                <button
                  onClick={loadShifts}
                  disabled={loadingShifts}
                  className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-indigo-600 border border-indigo-200 bg-white hover:bg-indigo-50 rounded-lg transition"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${loadingShifts ? 'animate-spin' : ''}`} /> Refresh
                </button>
                <button
                  onClick={() => setShiftForm({ editing: null })}
                  disabled={offices.length === 0}
                  className="self-start sm:self-auto flex items-center gap-1.5 px-4 py-2 text-xs font-bold bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 text-white rounded-lg transition"
                >
                  <Plus className="w-3.5 h-3.5" /> Tambah Shift
                </button>
              </div>
            </div>

            {/* Info: shift company-wide selalu tampil di semua filter */}
            {templateBranch && (
              <p className="text-[10px] text-slate-400 mt-2 flex items-center gap-1">
                <Info className="w-3 h-3" />
                Menampilkan shift cabang ini + shift berlaku untuk semua cabang.
              </p>
            )}
          </div>

          {loadingShifts ? (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={`skel-shift-${i}`} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4 animate-pulse">
                  <div className="flex items-start justify-between gap-2 mb-3">
                    <div className="space-y-2.5 flex-1">
                      <div className="flex items-center gap-2">
                        <div className="w-3.5 h-3.5 rounded-full bg-slate-200" />
                        <div className="h-4 w-32 bg-slate-200 rounded" />
                        <div className="h-3.5 w-10 bg-slate-200 rounded-full" />
                      </div>
                      <div className="h-3 w-24 bg-slate-200 rounded" />
                      <div className="h-3 w-40 bg-slate-200 rounded" />
                    </div>
                    <div className="flex gap-1 shrink-0">
                      <div className="w-6 h-6 bg-slate-200 rounded-lg" />
                      <div className="w-6 h-6 bg-slate-200 rounded-lg" />
                    </div>
                  </div>
                  <div className="grid grid-cols-7 gap-1">
                    {Array.from({ length: 7 }).map((_, j) => (
                      <div key={j} className="h-[42px] bg-slate-100 rounded-lg border border-slate-200/50" />
                    ))}
                  </div>
                </div>
              ))}
            </div>
          ) : filteredShifts.length === 0 ? (
            <div className="bg-white rounded-2xl border border-slate-100 shadow-sm py-16 text-center">
              <div className="flex flex-col items-center gap-2 text-slate-400">
                <Layers className="w-10 h-10 opacity-30" />
                {shifts.length === 0 ? (
                  <>
                    <p className="font-semibold text-sm">Belum ada template shift</p>
                    <p className="text-xs">Buat template untuk menjadwalkan karyawan di luar jam kantor default.</p>
                  </>
                ) : (
                  <>
                    <p className="font-semibold text-sm">Tidak ada shift untuk cabang ini</p>
                    <p className="text-xs">
                      Cabang yang dipilih belum memiliki template shift.
                      <button
                        onClick={() => setTemplateBranch('')}
                        className="ml-1 text-indigo-500 hover:underline"
                      >
                        Tampilkan semua
                      </button>
                    </p>
                  </>
                )}
              </div>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {filteredShifts.map((s) => (
                <div key={s.id} className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4 space-y-3">
                  {/* Header Cabang Kantor di Paling Atas Card */}
                  <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                    <span className="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-700 bg-indigo-50/80 px-2.5 py-1 rounded-md">
                      <Building2 className="w-3.5 h-3.5 text-indigo-600" />
                      {s.office?.office_name ?? 'Semua Cabang'}
                    </span>
                    {s.is_active ? (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">Aktif</span>
                    ) : (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">Nonaktif</span>
                    )}
                  </div>

                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        {/* Bulatan warna shift untuk kalender */}
                        <span
                          className="w-3.5 h-3.5 rounded-full shrink-0 border border-black/10"
                          style={{ backgroundColor: s.color ?? '#6366f1' }}
                        />
                        <p className="font-bold text-sm text-slate-800">{s.name}</p>
                      </div>
                      {s.description && <p className="text-[11px] text-slate-400 mt-1">{s.description}</p>}
                    </div>
                    <div className="flex items-center gap-1 shrink-0">
                      <button
                        onClick={() => handleToggleActive(s)}
                        className="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none"
                        style={{ backgroundColor: s.is_active ? '#10b981' : '#cbd5e1' }}
                        title={s.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                      >
                        <span
                          className={`inline-block h-3.5 w-3.5 rounded-full bg-white shadow-sm transition-transform ${s.is_active ? 'translate-x-[18px]' : 'translate-x-[3px]'}`}
                        />
                      </button>
                      <button
                        onClick={() => setShiftUsersView(s)}
                        className="inline-flex items-center gap-1 px-2 py-1.5 text-[10px] font-bold text-indigo-600 border border-indigo-200 hover:bg-indigo-50 rounded-lg transition"
                        title="Lihat karyawan yang terkait shift ini"
                      >
                        <Users className="w-3 h-3" /> Lihat Karyawan
                      </button>
                      <button
                        onClick={() => setShiftForm({ editing: s })}
                        className="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 transition"
                        title="Ubah"
                      >
                        <Pencil className="w-3.5 h-3.5" />
                      </button>
                      <button
                        onClick={() => handleDeleteShift(s)}
                        className="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50 transition"
                        title="Hapus"
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>

                  {/* Mini jadwal 7 hari */}
                  <div className="grid grid-cols-7 gap-1">
                    {Array.from({ length: 7 }, (_, d) => {
                      const sch = s.schedules?.find((x) => x.day_of_week === d);
                      const off = sch?.is_off ?? true;
                      return (
                        <div
                          key={d}
                          className={`rounded-lg p-1.5 text-center border ${off ? 'bg-rose-50/60 border-rose-100' : sch?.is_field ? 'bg-emerald-50/70 border-emerald-200' : sch?.is_wfh ? 'bg-cyan-50/70 border-cyan-200' : 'bg-indigo-50/60 border-indigo-100'
                            }`}
                          title={off ? 'Libur' : sch?.is_field ? `Lapangan: ${hhmm(sch?.work_start_time)}–${hhmm(sch?.work_end_time)}` : sch?.is_wfh ? `WFH: ${hhmm(sch?.work_start_time)}–${hhmm(sch?.work_end_time)}` : `${hhmm(sch?.work_start_time)}–${hhmm(sch?.work_end_time)}`}
                        >
                          <p className="text-[9px] font-bold text-slate-500">{DAY_SHORT[d]}</p>
                          {off ? (
                            <p className="text-[9px] text-rose-400 font-semibold mt-0.5">Off</p>
                          ) : (
                            <>
                              <p className={`text-[9px] font-mono font-semibold mt-0.5 leading-tight ${sch?.is_field ? 'text-emerald-800' : sch?.is_wfh ? 'text-cyan-800' : 'text-indigo-700'}`}>
                                {hhmm(sch?.work_start_time)}
                              </p>
                              <p className="text-[9px] font-mono text-slate-400 leading-tight flex items-center justify-center gap-0.5">
                                {hhmm(sch?.work_end_time)}
                                {sch && sch.is_field ? (
                                  <span className="text-[8px] font-bold text-emerald-600 bg-emerald-100 px-1 rounded">FLD</span>
                                ) : sch && sch.is_wfh ? (
                                  <span className="text-[8px] font-bold text-cyan-600 bg-cyan-100 px-1 rounded">WFH</span>
                                ) : null}
                                {sch && !sch.is_off && sch.work_start_time && sch.work_end_time && sch.work_end_time <= sch.work_start_time && (
                                  <Moon className="w-2 h-2 text-violet-500" />
                                )}
                              </p>
                            </>
                          )}
                        </div>
                      );
                    })}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* ═══════════ TAB: KALENDER ═══════════ */}
      {tab === 'kalender' && (
        <div className="space-y-4">
          {/* Toolbar: navigasi bulan + filter cabang */}
          <div className="bg-white rounded-2xl border border-slate-100 shadow-sm p-4 flex flex-col sm:flex-row sm:items-center gap-3">
            {/* Navigasi bulan */}
            <div className="flex items-center gap-2 shrink-0">
              <button
                onClick={() => {
                  const d = new Date(calYear, calMonth - 2, 1);
                  setCalMonth(d.getMonth() + 1);
                  setCalYear(d.getFullYear());
                }}
                className="p-2 rounded-lg border border-slate-200 hover:bg-slate-50 transition text-slate-600"
              >
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" /></svg>
              </button>
              <span className="text-sm font-bold text-slate-800 w-36 text-center">
                {new Date(calYear, calMonth - 1, 1).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })}
              </span>
              <button
                onClick={() => {
                  const d = new Date(calYear, calMonth, 1);
                  setCalMonth(d.getMonth() + 1);
                  setCalYear(d.getFullYear());
                }}
                className="p-2 rounded-lg border border-slate-200 hover:bg-slate-50 transition text-slate-600"
              >
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>
              </button>
              <button
                onClick={() => { setCalMonth(new Date().getMonth() + 1); setCalYear(new Date().getFullYear()); }}
                className="ml-1 px-3 py-1.5 text-xs font-semibold border border-slate-200 rounded-lg hover:bg-slate-50 text-slate-600 transition"
              >
                Bulan Ini
              </button>
            </div>
            {/* Filter cabang */}
            <div className="flex-1 flex items-center gap-2">
              <select
                value={calBranch}
                onChange={(e) => setCalBranch(e.target.value)}
                className="flex-1 max-w-xs text-xs p-2.5 border border-slate-200 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white"
              >
                <option value="">Semua cabang</option>
                {offices.map((o) => <option key={o.id} value={o.id}>{o.office_name}</option>)}
              </select>
              <button
                onClick={loadCalendar}
                className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border border-indigo-200 bg-white text-indigo-600 hover:bg-indigo-50 rounded-lg transition"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${loadingCal ? 'animate-spin' : ''}`} />
                Muat
              </button>
            </div>
          </div>

          {/* Legend shift + keterangan lintas hari */}
          {(() => {
            const calShifts = shifts.filter(s => s.is_active && (!calBranch || s.attendance_setting_id === null || s.attendance_setting_id === Number(calBranch)));
            if (calShifts.length === 0) return null;
            return (
              <div className="flex flex-wrap items-center gap-2">
                {calShifts.map(s => (
                  <span key={s.id} className="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border border-black/10"
                    style={{ backgroundColor: (s.color ?? '#6366f1') + '20', color: s.color ?? '#6366f1', borderColor: (s.color ?? '#6366f1') + '40' }}>
                    <span className="w-2 h-2 rounded-full" style={{ backgroundColor: s.color ?? '#6366f1' }} />
                    {s.name}
                  </span>
                ))}
                {/* Keterangan simbol libur & lintas hari */}
                <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border"
                  style={{ backgroundColor: '#71809618', color: '#718096', borderColor: '#71809640' }}>
                  <span className="w-2 h-2 rounded-full" style={{ backgroundColor: '#718096' }} />
                  Libur / Off
                </span>
                <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2.5 py-1 rounded-full bg-violet-50 text-violet-600 border border-violet-200">
                  <Moon className="w-3 h-3" /> = Lintas hari
                </span>
              </div>
            );
          })()}

          {/* Grid kalender */}
          <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
            {/* Header hari */}
            <div className="grid grid-cols-7 border-b border-slate-100">
              {['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'].map((d, i) => (
                <div key={d} className={`py-3 text-center text-[11px] font-bold tracking-wide ${i === 0 || i === 6 ? 'text-rose-400' : 'text-slate-500'
                  }`}>
                  {d}
                </div>
              ))}
            </div>

            {/* Sel-sel hari */}
            {(() => {
              const today = todayStr();
              const firstDay = new Date(calYear, calMonth - 1, 1).getDay(); // 0=Min
              const daysInMonth = new Date(calYear, calMonth, 0).getDate();
              const totalCells = Math.ceil((firstDay + daysInMonth) / 7) * 7;
              const cells: React.ReactNode[] = [];

              for (let i = 0; i < totalCells; i++) {
                const dayNum = i - firstDay + 1;
                const isCurrentMonth = dayNum >= 1 && dayNum <= daysInMonth;
                const dateStr = isCurrentMonth
                  ? `${calYear}-${String(calMonth).padStart(2, '0')}-${String(dayNum).padStart(2, '0')}`
                  : null;
                const isToday = dateStr === today;
                const isWeekend = i % 7 === 0 || i % 7 === 6;
                const dayEntries: CalDayEntry[] = (dateStr && calData[dateStr]) ? calData[dateStr] : [];

                cells.push(
                  <div
                    key={i}
                    className={`min-h-[110px] border-b border-r border-slate-100 p-1.5 flex flex-col gap-1 ${!isCurrentMonth ? 'bg-slate-50/40' : isWeekend ? 'bg-rose-50/20' : 'bg-white'
                      } ${isToday ? 'ring-2 ring-inset ring-indigo-400' : ''}`}
                  >
                    {/* Nomor tanggal */}
                    <div className="flex items-center justify-between px-0.5">
                      <span className={`text-xs font-bold leading-none ${!isCurrentMonth ? 'text-slate-300'
                        : isToday ? 'w-5 h-5 rounded-full bg-indigo-500 text-white flex items-center justify-center text-[10px]'
                          : isWeekend ? 'text-rose-400'
                            : 'text-slate-700'
                        }`}>
                        {isCurrentMonth ? dayNum : ''}
                      </span>
                    </div>

                    {/* Shift badge per tanggal */}
                    <div className="flex flex-col gap-0.5 flex-1">
                      {loadingCal && isCurrentMonth ? (
                        Array.from({ length: 2 }).map((_, idx) => (
                          <div key={`cal-skel-${idx}`} className="w-full h-4 bg-slate-200 rounded animate-pulse" />
                        ))
                      ) : (
                        dayEntries.slice(0, 3).map((entry) => {
                          // Cek libur & lintas hari pada tanggal tersebut
                          const dayOfWeek = dateStr ? new Date(dateStr + 'T00:00:00').getDay() : -1;
                          const isOff = dayOfWeek >= 0 && isOffOnDate(entry.shift_id, dayOfWeek, shifts);
                          const crossDay = entry.is_cross_day ?? (dayOfWeek >= 0 && isCrossDayOnDate(entry.shift_id, dayOfWeek, shifts));
                          const entryColor = isOff ? '#718096' : (entry.color || '#6366f1');

                          return (
                            <button
                              key={entry.shift_id}
                              onClick={() => dateStr && setCalDetail({ date: dateStr, entries: dayEntries })}
                              className="w-full text-left rounded px-1.5 py-1 text-[10px] font-bold leading-tight transition hover:brightness-90 active:scale-95"
                              style={{
                                backgroundColor: entryColor + '25',
                                color: entryColor,
                                borderLeft: `3px solid ${entryColor}`,
                              }}
                              title={`${entry.shift_name}${isOff ? ' (Libur)' : crossDay ? ' (lintas hari)' : ''} — ${entry.user_count} karyawan`}
                            >
                              <span className="flex items-center gap-1 min-w-0">
                                <span className="truncate flex-1">{entry.shift_name}</span>
                                <span className="font-normal opacity-70 shrink-0">({entry.user_count})</span>
                                {isOff ? (
                                  <span className="text-[9px] font-semibold opacity-80 shrink-0">(Off)</span>
                                ) : crossDay && (
                                  <Moon
                                    className="w-2.5 h-2.5 shrink-0"
                                    style={{ color: entryColor }}
                                    title="Shift lintas hari (berakhir keesokan harinya)"
                                  />
                                )}
                              </span>
                            </button>
                          );
                        })
                      )}
                      {dayEntries.length > 3 && (
                        <button
                          onClick={() => dateStr && setCalDetail({ date: dateStr, entries: dayEntries })}
                          className="text-[10px] text-slate-400 font-semibold hover:text-slate-600 text-left px-1.5"
                        >
                          +{dayEntries.length - 3} lainnya
                        </button>
                      )}
                    </div>
                  </div>
                );
              }

              const rows7: React.ReactNode[][] = [];
              for (let r = 0; r < totalCells / 7; r++) {
                rows7.push(cells.slice(r * 7, r * 7 + 7));
              }

              return rows7.map((row, ri) => (
                <div key={ri} className="grid grid-cols-7">
                  {row}
                </div>
              ));
            })()}
          </div>
        </div>
      )}

      {/* ── Modal detail hari ── */}
      {calDetail && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm px-4"
          onClick={(e) => { if (e.target === e.currentTarget) setCalDetail(null); }}
        >
          <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[80vh] overflow-y-auto animate-in fade-in slide-in-from-bottom-4 duration-200">
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 sticky top-0 bg-white z-10">
              <div>
                <p className="font-bold text-sm text-slate-800">
                  {new Date(calDetail.date + 'T00:00:00').toLocaleDateString('id-ID', {
                    weekday: 'long', day: '2-digit', month: 'long', year: 'numeric',
                  })}
                </p>
                <p className="text-xs text-slate-500 mt-0.5">{calDetail.entries.length} shift aktif</p>
              </div>
              <button onClick={() => setCalDetail(null)} className="p-1.5 hover:bg-slate-100 rounded-lg text-slate-400 transition">
                <X className="w-4 h-4" />
              </button>
            </div>

            <div className="p-4 space-y-3">
              {calDetail.entries.map((entry) => {
                const detailDayOfWeek = new Date(calDetail.date + 'T00:00:00').getDay();
                const isOff = isOffOnDate(entry.shift_id, detailDayOfWeek, shifts);
                const crossDay = entry.is_cross_day ?? isCrossDayOnDate(entry.shift_id, detailDayOfWeek, shifts);
                const entryColor = isOff ? '#718096' : (entry.color || '#6366f1');
                const tmpl = shifts.find((s) => s.id === entry.shift_id);
                const sch = tmpl?.schedules?.find((s) => s.day_of_week === detailDayOfWeek);
                const jamKerja = sch && !sch.is_off && sch.work_start_time && sch.work_end_time
                  ? `${hhmm(sch.work_start_time)} – ${hhmm(sch.work_end_time)}${crossDay ? ' (+1 hari)' : ''}`
                  : null;

                return (
                  <div key={entry.shift_id} className="rounded-xl border overflow-hidden" style={{ borderColor: entryColor + '40' }}>
                    {/* Judul shift + indikator libur/lintas hari */}
                    <div className="flex items-center gap-2 px-3 py-2.5" style={{ backgroundColor: entryColor + '15' }}>
                      <span className="w-3 h-3 rounded-full shrink-0" style={{ backgroundColor: entryColor }} />
                      <span className="font-bold text-xs" style={{ color: entryColor }}>{entry.shift_name}</span>
                      {isOff ? (
                        <span
                          className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full border shrink-0"
                          style={{ backgroundColor: '#71809618', color: '#718096', borderColor: '#71809640' }}
                        >
                          Libur / Off (#718096)
                        </span>
                      ) : crossDay && (
                        <span
                          className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-violet-100 text-violet-700 shrink-0"
                          title="Shift ini melewati tengah malam (berakhir keesokan hari)"
                        >
                          <Moon className="w-3 h-3" /> Lintas hari
                        </span>
                      )}
                      <span className="ml-auto text-[11px] font-semibold text-slate-500">{entry.user_count} karyawan</span>
                    </div>
                    {/* Jam kerja shift */}
                    <div className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-50/60 border-b border-slate-100">
                      <Clock className="w-3 h-3 text-slate-400" />
                      {isOff ? (
                        <span className="text-[11px] font-medium text-slate-500 italic">Hari Libur (Tidak ada jam kerja)</span>
                      ) : (
                        <>
                          <span className="text-[11px] font-mono text-slate-600">{jamKerja}</span>
                          {crossDay && (
                            <span className="text-[10px] text-violet-500 font-semibold">— selesai esok hari</span>
                          )}
                        </>
                      )}
                    </div>
                    {/* Daftar karyawan */}
                    <div className="divide-y divide-slate-50">
                      {entry.users.map((u) => {
                        const av = avatarFor(u.name);
                        return (
                          <div key={u.user_id} className="flex items-center gap-2.5 px-3 py-2">
                            <div className={`w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-bold shrink-0 ${av.bg} ${av.text}`}>
                              {initialsOf(u.name)}
                            </div>
                            <div className="min-w-0">
                              <p className="text-xs font-semibold text-slate-700 truncate">{u.name}</p>
                              {u.department && <p className="text-[10px] text-slate-400 truncate">{u.department}</p>}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      )}

      {/* ═══════════ MODALS ═══════════ */}
      {shiftForm && (
        <ShiftFormModal
          offices={offices}
          shifts={shifts}
          editing={shiftForm.editing}
          onClose={() => setShiftForm(null)}
          onSaved={() => { loadShifts(); onAddAuditLog?.(shiftForm.editing ? 'Shift diperbarui' : 'Shift dibuat', '', 'bg-indigo-500'); }}
        />
      )}

      {assignUser && (
        <AssignModal
          user={assignUser}
          shifts={shifts}
          onClose={() => setAssignUser(null)}
          onSaved={() => { loadRoster(); }}
        />
      )}

      {showBulk && (
        <BulkAssignModal
          userIds={Array.from(selected)}
          userNames={selectedNames}
          shifts={shifts}
          selectedBranchIds={selectedBranchIds}
          onClose={() => { setShowBulk(false); }}
          onSaved={() => { loadRoster(); }}
        />
      )}

      {shiftUsersView && (
        <ShiftUsersModal
          shift={shiftUsersView}
          onClose={() => setShiftUsersView(null)}
        />
      )}
    </div>
  );
}
