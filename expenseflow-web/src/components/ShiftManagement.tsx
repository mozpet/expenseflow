import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  CalendarClock, CalendarDays, Plus, Pencil, Trash2, X, RefreshCw,
  Search, Users, Building2, AlertCircle, AlertTriangle, CheckCircle2, Moon,
  Layers, UserCog, Save, History, ArrowRight, Info, Clock, ToggleLeft, ToggleRight,
  Repeat, Check, ChevronRight,
} from 'lucide-react';
import { shiftApi, attendanceApi, ShiftPattern, ShiftPatternItem, ShiftPatternItemInput } from '../services/endpoints';
import { ApiError, invalidateCache } from '../services/api';
import { useDebounce } from '../hooks/useDebounce';
import CustomDatePicker from './CustomDatePicker';
import { ConfirmationDialog, ConfirmationType } from './ConfirmationDialog';


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
  break_minutes?: number; // durasi istirahat menit (default 60)
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
  gender?: string | null;
  birth_date?: string | null;
  age?: number | null;
  is_pregnant?: boolean;
  source: 'shift' | 'office' | 'none';
  shift_name: string | null;
  pattern_id?: number | null;
  pattern_name?: string | null;
  cycle_day?: number | null;
  cycle_days?: number | null;
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
  shift_pattern_id?: number | null;
  anchor_day_order?: number;
  start_date: string;
  end_date: string | null;
  notes: string | null;
  /** Status assignment: 'active' (sedang berlaku) | 'upcoming' (belum mulai) | 'expired' (sudah berakhir) */
  status?: 'active' | 'upcoming' | 'expired';
  shift?: { id: number; name: string } | null;
  shift_pattern?: { id: number; name: string; cycle_days: number } | null;
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
    break_minutes: d === 0 || d === 6 ? 0 : 60,
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
    const gross = isCross ? (1440 - start + end) : (end - start);
    const breakMins = s.break_minutes ?? 60;
    totalMins += Math.max(0, gross - breakMins);
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
          break_minutes: isOff ? 0 : (s?.break_minutes ?? 60),
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
        oldEnd !== newEnd ||
        Number(old.break_minutes ?? 60) !== Number(s.break_minutes ?? 60)
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
            ? { ...s, is_off: false, is_wfh: false, is_field: false, work_start_time: '08:00', work_end_time: '17:00', break_minutes: 60 }
            : { ...s, is_off: true, is_wfh: false, is_field: false, work_start_time: null, work_end_time: null, break_minutes: 0 }
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
        break_minutes: s.is_off ? 0 : Number(s.break_minutes ?? 60),
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
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm px-4 py-6"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto border border-slate-100 dark:border-slate-800 animate-in fade-in slide-in-from-bottom-4 duration-200">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white dark:bg-slate-900 z-10">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-950/70 flex items-center justify-center">
              <CalendarClock className="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
            </div>
            <div>
              <p className="font-bold text-sm text-slate-800 dark:text-slate-100">
                {editing ? 'Ubah Template Shift' : 'Tambah Template Shift'}
              </p>
              <p className="text-xs text-slate-500 dark:text-slate-400">Atur jam kerja per hari (7 hari)</p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 transition cursor-pointer">
            <X className="w-4 h-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          {/* Banner nama cabang jika sedang EDIT */}
          {editing && (
            <div className="bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 rounded-xl p-3 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Building2 className="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                <div>
                  <p className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Cabang Terikat</p>
                  <p className="text-xs font-bold text-slate-800 dark:text-slate-100">
                    {editing.office?.office_name ?? selectedOffice?.office_name ?? 'Semua Cabang'}
                  </p>
                </div>
              </div>
              <span className="text-[10px] font-medium bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded-md">
                Cabang Permanen
              </span>
            </div>
          )}

          {/* Info dasar */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div className={editing ? "sm:col-span-1" : "sm:col-span-1"}>
              <label className="text-xs font-semibold text-slate-600 dark:text-slate-300 block mb-1.5">Nama Shift *</label>
              <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Contoh: Shift Sabtu, Shift Gudang Pagi"
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-400 focus:outline-none focus:border-indigo-400"
              />
            </div>
            {!editing ? (
              <div>
                <label className="text-xs font-semibold text-slate-600 dark:text-slate-300 block mb-1.5">Cabang Kantor *</label>
                <select
                  value={branchId}
                  onChange={(e) => setBranchId(e.target.value)}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                >
                  <option value="">— Pilih cabang —</option>
                  {offices.map((o) => (
                    <option key={o.id} value={o.id}>{o.office_name}</option>
                  ))}
                </select>
              </div>
            ) : null}
            <div>
              <label className="text-xs font-semibold text-slate-600 dark:text-slate-300 block mb-1.5">Warna Shift</label>
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
                        ? 'border-slate-800 dark:border-white scale-125 shadow-md'
                        : isUsed
                          ? 'border-slate-200 dark:border-slate-700 opacity-40 cursor-not-allowed'
                          : 'border-transparent hover:scale-110 hover:border-slate-300 dark:hover:border-slate-600'
                        }`}
                      style={{ backgroundColor: c }}
                    />
                  );
                })}
              </div>
              {/* Preview warna terpilih */}
              <div className="mt-2 flex items-center gap-2">
                <span
                  className="w-4 h-4 rounded-full border border-black/10 dark:border-white/20 shrink-0"
                  style={{ backgroundColor: color }}
                />
                <span className="text-[11px] font-mono text-slate-500 dark:text-slate-400">{color}</span>
                <span className="text-[10px] text-slate-400 dark:text-slate-500">— tampilan di kalender</span>
              </div>
            </div>
            <div className="sm:col-span-2 flex flex-col sm:flex-row gap-4">
              <div className="flex-1">
                <label className="text-xs font-semibold text-slate-600 dark:text-slate-300 block mb-1.5">Deskripsi</label>
                <input
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  placeholder="Opsional — keterangan singkat shift"
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-400 focus:outline-none focus:border-indigo-400"
                />
              </div>
              {!editing && (
                <div className="w-full sm:w-56 shrink-0">
                  <label className="text-xs font-semibold text-slate-600 dark:text-slate-300 block mb-1.5">Status Shift</label>
                  <div className="flex items-center gap-2 mt-2">
                    <button
                      type="button"
                      onClick={() => setIsActive(!isActive)}
                      className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-indigo-600 focus:ring-offset-2 dark:focus:ring-offset-slate-900 ${
                        isActive ? 'bg-indigo-600' : 'bg-slate-200 dark:bg-slate-700'
                      }`}
                    >
                      <span
                        className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                          isActive ? 'translate-x-2' : '-translate-x-2'
                        }`}
                      />
                    </button>
                    <span className="text-[11px] text-slate-600 dark:text-slate-300 font-medium">
                      {isActive ? 'Aktif (Dapat ditugaskan)' : 'Nonaktif (Draf)'}
                    </span>
                  </div>
                </div>
              )}
            </div>
          </div>

          {offices.length === 0 && (
            <div className="flex items-start gap-2 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900 rounded-lg p-3 text-xs text-amber-700 dark:text-amber-300">
              <Info className="w-3.5 h-3.5 mt-0.5 shrink-0" />
              <span>Belum ada kantor/cabang. Tambahkan dulu di menu <strong>Presensi &amp; Cuti → Kantor</strong> sebelum membuat shift.</span>
            </div>
          )}

          {/* Editor 7 hari */}
          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="text-xs font-semibold text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
                <CalendarDays className="w-3.5 h-3.5" /> Jadwal per Hari
              </label>
              {/* Chip indikator total jam/minggu */}
              <div className={`inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-full ${weeklyStatus === 'error' ? 'bg-rose-100 dark:bg-rose-950/50 text-rose-700 dark:text-rose-400' :
                weeklyStatus === 'warning' ? 'bg-amber-100 dark:bg-amber-950/50 text-amber-700 dark:text-amber-400' :
                  weeklyStatus === 'safe' ? 'bg-emerald-100 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-400' :
                    'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'
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
                  className={`flex items-center gap-2 sm:gap-3 p-2 rounded-lg border ${s.is_off ? 'bg-rose-50/50 dark:bg-rose-950/20 border-rose-100 dark:border-rose-900/40' : 'bg-slate-50/60 dark:bg-slate-800/40 border-slate-100 dark:border-slate-750'
                    }`}
                >
                  <span className="w-14 sm:w-16 text-xs font-bold text-slate-700 dark:text-slate-200 shrink-0">
                    {DAY_NAMES[s.day_of_week]}
                  </span>

                  <label className="flex items-center gap-1.5 text-[11px] font-semibold text-slate-500 dark:text-slate-400 cursor-pointer select-none shrink-0">
                    <input
                      type="checkbox"
                      checked={s.is_off}
                      onChange={() => toggleOff(s.day_of_week)}
                      className="w-3.5 h-3.5 rounded accent-rose-500"
                    />
                    Libur
                  </label>

                  <label className={`flex items-center gap-1.5 text-[11px] font-semibold cursor-pointer select-none shrink-0 ${s.is_off ? 'opacity-40 cursor-not-allowed text-slate-400 dark:text-slate-600' : 'text-cyan-700 dark:text-cyan-400'}`}>
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
                    className={`flex items-center gap-1.5 text-[11px] font-semibold cursor-pointer select-none shrink-0 ${s.is_off || !s.is_wfh ? 'opacity-40 cursor-not-allowed text-slate-400 dark:text-slate-600' : 'text-emerald-700 dark:text-emerald-400'}`}
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
                    <span className="text-[11px] text-rose-500 dark:text-rose-400 italic flex-1">Tidak ada jam kerja</span>
                  ) : (
                    <div className="flex items-center gap-2 flex-1">
                      <input
                        type="time"
                        value={s.work_start_time ?? ''}
                        onChange={(e) => setDay(s.day_of_week, { work_start_time: e.target.value })}
                        className="text-xs p-1.5 border border-slate-200 dark:border-slate-700 rounded-md focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                      />
                      <ArrowRight className="w-3 h-3 text-slate-300 dark:text-slate-600 shrink-0" />
                      <input
                        type="time"
                        value={s.work_end_time ?? ''}
                        onChange={(e) => setDay(s.day_of_week, { work_end_time: e.target.value })}
                        className="text-xs p-1.5 border border-slate-200 dark:border-slate-700 rounded-md focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                      />
                      {/* Input Durasi Jam Istirahat (menit) */}
                      <div className="flex items-center gap-1 shrink-0" title="Durasi jam istirahat (menit, standar 60)">
                        <span className="text-[10px] text-slate-400 font-medium">Ist:</span>
                        <input
                          type="number"
                          min={0}
                          max={240}
                          value={s.break_minutes ?? 60}
                          onChange={(e) => setDay(s.day_of_week, { break_minutes: e.target.value === '' ? 0 : Math.max(0, parseInt(e.target.value) || 0) })}
                          className="w-12 text-xs p-1 text-center border border-slate-200 dark:border-slate-700 rounded-md focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                          placeholder="60"
                        />
                        <span className="text-[10px] text-slate-400">m</span>
                      </div>
                      {/* Indikator shift lintas tengah malam (jam pulang <= jam masuk) */}
                      {s.work_start_time && s.work_end_time && s.work_end_time <= s.work_start_time && (
                        <span className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-violet-100 dark:bg-violet-950/60 text-violet-700 dark:text-violet-400 shrink-0" title="Shift berakhir keesokan harinya">
                          <Moon className="w-3 h-3" /> +1 hari
                        </span>
                      )}
                      {/* Badge K3 jeda istirahat ke hari berikutnya */}
                      {k3Gaps[s.day_of_week] && (
                        <span
                          className={`inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full shrink-0 ${k3Gaps[s.day_of_week].status === 'error'
                            ? 'bg-rose-100 dark:bg-rose-950/60 text-rose-700 dark:text-rose-400'
                            : 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400'
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
            <div className="flex items-start gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-lg p-3 text-xs text-rose-700 dark:text-rose-400">
              <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
              <span>{err}</span>
            </div>
          )}

          <div className="flex gap-2 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 py-2.5 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition cursor-pointer"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={busy}
              className="flex-1 py-2.5 text-xs font-bold rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 dark:disabled:bg-indigo-900/50 transition flex items-center justify-center gap-1.5 cursor-pointer"
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
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl w-full max-w-md animate-in fade-in zoom-in-95 duration-200 overflow-hidden">
            {/* Header */}
            <div className="flex items-center gap-3 px-6 py-4 bg-amber-50 dark:bg-amber-950/40 border-b border-amber-100 dark:border-amber-900/40">
              <div className="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/50 flex items-center justify-center shrink-0">
                <AlertTriangle className="w-5 h-5 text-amber-600 dark:text-amber-400" />
              </div>
              <div>
                <p className="text-sm font-bold text-amber-800 dark:text-amber-300">Peringatan K3 — Jeda Istirahat Pendek</p>
                <p className="text-xs text-amber-600 dark:text-amber-400 mt-0.5">Shift berhasil disimpan, namun ada jeda istirahat yang perlu diperhatikan</p>
              </div>
            </div>

            {/* Daftar hari bermasalah */}
            <div className="px-6 py-4 space-y-2.5">
              <p className="text-xs font-semibold text-slate-700 dark:text-slate-200">Hari dengan jeda istirahat di bawah rekomendasi:</p>
              <ul className="space-y-2">
                {k3Warnings.map((w, i) => (
                  <li key={i} className="flex items-start gap-2.5 bg-amber-50 dark:bg-amber-950/30 border border-amber-100 dark:border-amber-900/30 rounded-lg px-3 py-2">
                    <AlertTriangle className="w-3.5 h-3.5 text-amber-500 mt-0.5 shrink-0" />
                    <span className="text-xs text-amber-800 dark:text-amber-300">{w}</span>
                  </li>
                ))}
              </ul>
              <p className="text-[11px] text-slate-500 dark:text-slate-400 pt-1">
                Apakah Anda ingin tetap menggunakan jadwal ini, atau kembali memperbaiki template shift?
              </p>
            </div>

            {/* Tombol aksi */}
            <div className="flex gap-3 px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/40">
              <button
                type="button"
                onClick={() => {
                  // Tidak — tutup konfirmasi, kembali ke form untuk diperbaiki
                  setShowK3Confirm(false);
                }}
                className="flex-1 py-2.5 text-xs font-bold rounded-xl border-2 border-amber-400 text-amber-700 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/50 transition cursor-pointer"
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
                className="flex-1 py-2.5 text-xs font-bold rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white transition cursor-pointer"
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
          <div className="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-2xl w-full max-w-md animate-in fade-in zoom-in-95 duration-200 overflow-hidden">
            <div className="flex items-center gap-3 px-6 py-4 bg-indigo-50 dark:bg-indigo-950/40 border-b border-indigo-100 dark:border-indigo-900/40">
              <div className="w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-900/50 flex items-center justify-center shrink-0">
                <CalendarClock className="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
              </div>
              <div>
                <p className="text-sm font-bold text-indigo-800 dark:text-indigo-300">Perubahan Jam Kerja Shift</p>
                <p className="text-xs text-indigo-600 dark:text-indigo-400 mt-0.5">Jam kerja baru berlaku sesuai pengaturan notice</p>
              </div>
            </div>

            <div className="px-6 py-4 space-y-2.5">
              <p className="text-xs text-slate-700 dark:text-slate-300 leading-relaxed">
                Perubahan <strong>jam kerja</strong> shift <strong>{editing?.name}</strong> tidak mengubah jadwal
                hari ini. Jam kerja baru akan berlaku mulai:
              </p>
              <div className="rounded-lg bg-indigo-50 dark:bg-indigo-950/50 border border-indigo-100 dark:border-indigo-800 px-3 py-2.5 flex items-center gap-2">
                <CalendarDays className="w-4 h-4 text-indigo-600 dark:text-indigo-400 shrink-0" />
                <span className="text-sm font-bold text-indigo-700 dark:text-indigo-300">
                  {new Date(effectiveDate + 'T00:00:00').toLocaleDateString('id-ID', {
                    day: 'numeric', month: 'long', year: 'numeric',
                  })}
                </span>
              </div>
              <p className="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                Karyawan yang terpasang pada shift ini akan menerima pemberitahuan di aplikasi mobile
                bahwa jam kerja shift berubah mulai tanggal tersebut.
              </p>
            </div>

            <div className="flex gap-3 px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/40">
              <button
                type="button"
                onClick={() => setPendingScheduleSave(null)}
                className="flex-1 py-2.5 text-xs font-bold rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition cursor-pointer"
              >
                Batal
              </button>
              <button
                type="button"
                onClick={confirmScheduleSave}
                className="flex-1 py-2.5 text-xs font-bold rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white transition flex items-center justify-center gap-1.5 cursor-pointer"
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

// ─── Helper K3: Deteksi Shift Malam (beririsan dengan 23:00 - 07:00) ───────────
export function isNightShift(shift?: ShiftTemplate | null): boolean {
  if (!shift || !shift.schedules || shift.schedules.length === 0) return false;
  return shift.schedules.some((sc) => {
    if (sc.is_off) return false;
    if (sc.is_cross_day) return true;
    const start = (sc.work_start_time || '').slice(0, 5);
    const end = (sc.work_end_time || '').slice(0, 5);
    if (!start || !end) return false;
    return start < '07:00' || end > '23:00';
  });
}

// ═══════════════════════════════════════════════════════════════
// MODAL: Pola Rotasi Shift (Recurring Rolling Cycles)
// ═══════════════════════════════════════════════════════════════
interface ShiftPatternFormProps {
  shifts: ShiftTemplate[];
  offices: OfficeOpt[];
  editing: ShiftPattern | null;
  onClose: () => void;
  onSaved: () => void;
}

interface PatternDayItemState {
  day_order: number;
  is_off: boolean;
  shift_id: number | null;
  work_start_time: string | null;
  work_end_time: string | null;
  break_minutes: number;
  is_cross_day: boolean;
}

function ShiftPatternFormModal({ shifts, offices, editing, onClose, onSaved }: ShiftPatternFormProps) {
  const [name, setName] = useState(editing?.name ?? '');
  const [description, setDescription] = useState(editing?.description ?? '');
  const [attendanceSettingId, setAttendanceSettingId] = useState<number | ''>(
    editing?.attendance_setting_id ?? '',
  );
  const [cycleDays, setCycleDays] = useState<number>(editing?.cycle_days ?? 6);
  const [isActive, setIsActive] = useState<boolean>(editing?.is_active ?? true);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  // Default shift aktif untuk preset autofill
  const activeShifts = useMemo(() => shifts.filter((s) => s.is_active), [shifts]);
  const defaultShift = activeShifts[0] ?? null;

  const [days, setDays] = useState<PatternDayItemState[]>(() => {
    if (editing?.items?.length) {
      const sorted = [...editing.items].sort((a, b) => a.day_order - b.day_order);
      return sorted.map((it) => ({
        day_order: it.day_order,
        is_off: Boolean(it.is_off),
        shift_id: it.shift_id ?? null,
        work_start_time: it.work_start_time ? it.work_start_time.slice(0, 5) : null,
        work_end_time: it.work_end_time ? it.work_end_time.slice(0, 5) : null,
        break_minutes: it.break_minutes ?? 60,
        is_cross_day: Boolean(it.is_cross_day),
      }));
    }
    // Default: Pola 4-2 (4 Kerja, 2 Libur)
    const defStart = defaultShift?.schedules?.find((sc) => !sc.is_off)?.work_start_time?.slice(0, 5) ?? '07:00';
    const defEnd = defaultShift?.schedules?.find((sc) => !sc.is_off)?.work_end_time?.slice(0, 5) ?? '15:00';

    return Array.from({ length: 6 }, (_, i) => {
      const order = i + 1;
      const isOff = order >= 5;
      return {
        day_order: order,
        is_off: isOff,
        shift_id: isOff ? null : (defaultShift?.id ?? null),
        work_start_time: isOff ? null : defStart,
        work_end_time: isOff ? null : defEnd,
        break_minutes: 60,
        is_cross_day: false,
      };
    });
  });

  const handleCycleDaysChange = (newLen: number) => {
    const val = Math.max(2, Math.min(30, newLen || 2));
    setCycleDays(val);
    setDays((prev) => {
      if (prev.length === val) return prev;
      if (prev.length > val) return prev.slice(0, val);
      const defStart = defaultShift?.schedules?.find((sc) => !sc.is_off)?.work_start_time?.slice(0, 5) ?? '07:00';
      const defEnd = defaultShift?.schedules?.find((sc) => !sc.is_off)?.work_end_time?.slice(0, 5) ?? '15:00';
      const added: PatternDayItemState[] = [];
      for (let i = prev.length + 1; i <= val; i++) {
        added.push({
          day_order: i,
          is_off: false,
          shift_id: defaultShift?.id ?? null,
          work_start_time: defStart,
          work_end_time: defEnd,
          break_minutes: 60,
          is_cross_day: false,
        });
      }
      return [...prev, ...added];
    });
  };

  const applyPreset = (presetName: string, total: number, offDays: number[]) => {
    setName(presetName);
    setCycleDays(total);
    const defStart = defaultShift?.schedules?.find((sc) => !sc.is_off)?.work_start_time?.slice(0, 5) ?? '07:00';
    const defEnd = defaultShift?.schedules?.find((sc) => !sc.is_off)?.work_end_time?.slice(0, 5) ?? '15:00';

    const newRows: PatternDayItemState[] = Array.from({ length: total }, (_, i) => {
      const order = i + 1;
      const isOff = offDays.includes(order);
      return {
        day_order: order,
        is_off: isOff,
        shift_id: isOff ? null : (defaultShift?.id ?? null),
        work_start_time: isOff ? null : defStart,
        work_end_time: isOff ? null : defEnd,
        break_minutes: 60,
        is_cross_day: false,
      };
    });
    setDays(newRows);
  };

  const updateDay = (index: number, patch: Partial<PatternDayItemState>) => {
    setDays((prev) => {
      const copy = [...prev];
      const updated = { ...copy[index], ...patch };
      // Bila memilih shift_id, isi otomatis jam masuk/keluar dari template shift
      if (patch.shift_id !== undefined && patch.shift_id !== null) {
        const sh = shifts.find((s) => s.id === patch.shift_id);
        if (sh && sh.schedules?.length) {
          const firstWork = sh.schedules.find((sc) => !sc.is_off);
          if (firstWork) {
            updated.work_start_time = firstWork.work_start_time?.slice(0, 5) ?? '07:00';
            updated.work_end_time = firstWork.work_end_time?.slice(0, 5) ?? '15:00';
            updated.break_minutes = firstWork.break_minutes ?? 60;
            updated.is_cross_day = Boolean(firstWork.is_cross_day);
          }
        }
      }
      copy[index] = updated;
      return copy;
    });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) {
      setErr('Nama pola rotasi wajib diisi.');
      return;
    }
    if (cycleDays < 2 || cycleDays > 30) {
      setErr('Panjang siklus harus antara 2 hingga 30 hari.');
      return;
    }

    setBusy(true);
    setErr('');
    try {
      const payload = {
        name: name.trim(),
        description: description.trim() || undefined,
        attendance_setting_id: attendanceSettingId ? Number(attendanceSettingId) : null,
        cycle_days: cycleDays,
        is_active: isActive,
        items: days.map((d) => ({
          day_order: d.day_order,
          is_off: d.is_off,
          shift_id: d.is_off ? null : d.shift_id,
          work_start_time: d.is_off ? null : (d.work_start_time || '07:00'),
          work_end_time: d.is_off ? null : (d.work_end_time || '15:00'),
          break_minutes: d.break_minutes ?? 60,
          is_cross_day: d.is_cross_day,
        })),
      };

      if (editing) {
        await shiftApi.patterns.update(editing.id, payload);
      } else {
        await shiftApi.patterns.create(payload);
      }
      onSaved();
      onClose();
    } catch (ex: unknown) {
      setErr(ex instanceof ApiError ? ex.message : 'Gagal menyimpan pola rotasi.');
    } finally {
      setBusy(false);
    }
  };

  const workDaysCount = days.filter((d) => !d.is_off).length;
  const offDaysCount = days.filter((d) => d.is_off).length;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm px-4 py-6"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[92vh] overflow-y-auto border border-slate-100 dark:border-slate-800 animate-in fade-in slide-in-from-bottom-4 duration-200">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white/95 dark:bg-slate-900/95 backdrop-blur-sm z-10">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-200 dark:border-indigo-800 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
              <Repeat className="w-5 h-5" />
            </div>
            <div>
              <p className="font-bold text-sm text-slate-800 dark:text-slate-100">
                {editing ? 'Edit Pola Rotasi Shift' : 'Buat Pola Rotasi Shift Baru'}
              </p>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                Rolling cycle berulang otomatis tanpa perlu reassign tiap minggu
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 transition cursor-pointer">
            <X className="w-4 h-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-5">
          {/* Preset Buttons */}
          <div>
            <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1.5">
              Preset Pola Populer
            </label>
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => applyPreset('Pola 4-2 (4 Kerja, 2 Libur)', 6, [5, 6])}
                className="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-slate-700 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 border border-slate-200 dark:border-slate-700 transition cursor-pointer"
              >
                Pola 4-2 (4 Kerja, 2 Libur)
              </button>
              <button
                type="button"
                onClick={() => applyPreset('Pola 3-1 (3 Kerja, 1 Libur)', 4, [4])}
                className="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-slate-700 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 border border-slate-200 dark:border-slate-700 transition cursor-pointer"
              >
                Pola 3-1 (3 Kerja, 1 Libur)
              </button>
              <button
                type="button"
                onClick={() => applyPreset('Pola 5-2 (5 Kerja, 2 Libur)', 7, [6, 7])}
                className="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-slate-700 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 border border-slate-200 dark:border-slate-700 transition cursor-pointer"
              >
                Pola 5-2 (5 Kerja, 2 Libur)
              </button>
              <button
                type="button"
                onClick={() => applyPreset('Pola 6-1 (6 Kerja, 1 Libur)', 7, [7])}
                className="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-slate-700 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 border border-slate-200 dark:border-slate-700 transition cursor-pointer"
              >
                Pola 6-1 (6 Kerja, 1 Libur)
              </button>
            </div>
          </div>

          {/* Form Fields: Name, Cycle Days, Status */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div className="sm:col-span-2">
              <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">
                Nama Pola Rotasi <span className="text-rose-500">*</span>
              </label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Contoh: Pola 4-2 Pabrik Utama"
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-400 focus:outline-none"
                required
              />
            </div>
            <div>
              <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">
                Panjang Siklus (Hari)
              </label>
              <input
                type="number"
                min={2}
                max={30}
                value={cycleDays}
                onChange={(e) => handleCycleDaysChange(Number(e.target.value))}
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-400 focus:outline-none font-semibold text-center"
                required
              />
            </div>
          </div>

          <div>
            <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">
              Deskripsi Pola <span className="text-slate-400 font-normal">(opsional)</span>
            </label>
            <input
              type="text"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Contoh: 4 hari kerja shift pagi, 2 hari libur berputar otomatis untuk Tim A & B"
              className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-400 focus:outline-none"
            />
          </div>

          {/* Pengkhususan Cabang (Branch-Specific) */}
          <div>
            <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">
              Cabang / Lokasi Kerja
            </label>
            <div className="relative">
              <select
                value={attendanceSettingId}
                onChange={(e) => setAttendanceSettingId(e.target.value ? Number(e.target.value) : '')}
                className="w-full text-xs p-2.5 pl-8 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-400 focus:outline-none cursor-pointer"
              >
                <option value="">— Berlaku untuk Semua Cabang (Company-wide) —</option>
                {offices.map((o) => (
                  <option key={o.id} value={o.id}>
                    Cabang {o.office_name}
                  </option>
                ))}
              </select>
              <Building2 className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-3 pointer-events-none" />
            </div>
            <p className="text-[10px] text-slate-400 dark:text-slate-500 mt-1">
              Pilih cabang tertentu agar pola ini hanya dapat ditugaskan ke karyawan di cabang tersebut dan mencegah salah penugasan lintas cabang.
            </p>
          </div>

          {/* Status aktif toggle */}
          <div className="flex items-center justify-between p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/70 dark:border-slate-700/60">
            <div>
              <p className="text-xs font-semibold text-slate-700 dark:text-slate-200">Status Pola Rotasi</p>
              <p className="text-[11px] text-slate-400 dark:text-slate-500">Pola aktif dapat dipilih saat menugaskan jadwal karyawan</p>
            </div>
            <button
              type="button"
              onClick={() => setIsActive(!isActive)}
              className="flex items-center gap-1.5 cursor-pointer text-slate-600 dark:text-slate-300"
            >
              {isActive ? (
                <span className="inline-flex items-center gap-1 text-xs font-bold text-emerald-600 dark:text-emerald-400">
                  <ToggleRight className="w-6 h-6 text-emerald-500" /> Aktif
                </span>
              ) : (
                <span className="inline-flex items-center gap-1 text-xs font-bold text-slate-400">
                  <ToggleLeft className="w-6 h-6 text-slate-400" /> Nonaktif
                </span>
              )}
            </button>
          </div>

          {/* Sequence Preview Strip */}
          <div className="p-3.5 rounded-xl bg-indigo-50/60 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 space-y-2">
            <div className="flex items-center justify-between">
              <p className="text-xs font-bold text-indigo-950 dark:text-indigo-200 flex items-center gap-1.5">
                <CalendarClock className="w-3.5 h-3.5 text-indigo-500" />
                Urutan Siklus ({cycleDays} Hari)
              </p>
              <span className="text-[11px] font-semibold text-indigo-700 dark:text-indigo-400">
                {workDaysCount} Hari Kerja · {offDaysCount} Hari Libur
              </span>
            </div>
            <div className="flex flex-wrap gap-1.5 pt-1">
              {days.map((d) => (
                <div
                  key={d.day_order}
                  className={`px-2.5 py-1 rounded-lg text-[11px] font-bold border transition ${
                    d.is_off
                      ? 'bg-amber-100/70 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border-amber-300/60 dark:border-amber-800'
                      : 'bg-indigo-600 text-white border-indigo-700 shadow-sm'
                  }`}
                >
                  H{d.day_order}: {d.is_off ? 'Libur' : (d.work_start_time ? `${d.work_start_time}-${d.work_end_time}` : 'Kerja')}
                </div>
              ))}
            </div>
          </div>

          {/* Detail Pengaturan Per Hari */}
          <div className="space-y-2">
            <p className="text-xs font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wider">
              Rincian Hari Siklus (Hari 1 s.d. Hari {cycleDays})
            </p>
            <div className="space-y-2 max-h-72 overflow-y-auto pr-1">
              {days.map((day, idx) => (
                <div
                  key={day.day_order}
                  className={`p-3 rounded-xl border transition ${
                    day.is_off
                      ? 'bg-amber-50/40 dark:bg-amber-950/20 border-amber-200 dark:border-amber-900/40'
                      : 'bg-white dark:bg-slate-850 border-slate-200 dark:border-slate-700/80 shadow-xs'
                  }`}
                >
                  <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                      <span className="w-7 h-7 rounded-lg bg-indigo-100 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 font-bold text-xs flex items-center justify-center shrink-0">
                        H{day.day_order}
                      </span>
                      <div>
                        <p className="text-xs font-bold text-slate-800 dark:text-slate-100">
                          Hari ke-{day.day_order} Siklus
                        </p>
                        <p className="text-[10px] text-slate-400 dark:text-slate-500">
                          {day.is_off ? 'Karyawan dijadwalkan LIBUR pada hari ini' : 'Karyawan dijadwalkan MASUK KERJA'}
                        </p>
                      </div>
                    </div>

                    {/* Switch Libur / Kerja */}
                    <div className="flex items-center gap-1.5 self-start sm:self-center">
                      <button
                        type="button"
                        onClick={() => updateDay(idx, { is_off: false })}
                        className={`px-2.5 py-1 rounded-lg text-xs font-bold transition cursor-pointer ${
                          !day.is_off
                            ? 'bg-indigo-600 text-white shadow-xs'
                            : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-700'
                        }`}
                      >
                        Kerja
                      </button>
                      <button
                        type="button"
                        onClick={() => updateDay(idx, { is_off: true })}
                        className={`px-2.5 py-1 rounded-lg text-xs font-bold transition cursor-pointer ${
                          day.is_off
                            ? 'bg-amber-500 text-white shadow-xs'
                            : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-700'
                        }`}
                      >
                        Libur (OFF)
                      </button>
                    </div>
                  </div>

                  {/* Konfigurasi jam jika hari kerja */}
                  {!day.is_off && (
                    <div className="mt-3 pt-3 border-t border-slate-100 dark:border-slate-800 grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                      <div>
                        <label className="text-[10px] font-semibold text-slate-400 uppercase block mb-1">
                          Template Shift Terkait
                        </label>
                        <select
                          value={day.shift_id ?? ''}
                          onChange={(e) => updateDay(idx, { shift_id: e.target.value ? Number(e.target.value) : null })}
                          className="w-full text-xs p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                        >
                          <option value="">— Jam Custom —</option>
                          {activeShifts.map((s) => (
                            <option key={s.id} value={s.id}>
                              {s.name}
                            </option>
                          ))}
                        </select>
                      </div>

                      <div>
                        <label className="text-[10px] font-semibold text-slate-400 uppercase block mb-1">
                          Jam Masuk
                        </label>
                        <input
                          type="time"
                          value={day.work_start_time ?? '07:00'}
                          onChange={(e) => updateDay(idx, { work_start_time: e.target.value })}
                          className="w-full text-xs p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                        />
                      </div>

                      <div>
                        <label className="text-[10px] font-semibold text-slate-400 uppercase block mb-1">
                          Jam Pulang
                        </label>
                        <input
                          type="time"
                          value={day.work_end_time ?? '15:00'}
                          onChange={(e) => updateDay(idx, { work_end_time: e.target.value })}
                          className="w-full text-xs p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                        />
                      </div>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>

          {err && (
            <div className="flex items-start gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-lg p-2.5 text-[11px] text-rose-700 dark:text-rose-400">
              <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
              <span>{err}</span>
            </div>
          )}

          {/* Footer Buttons */}
          <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition cursor-pointer"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={busy}
              className="flex items-center gap-1.5 px-4 py-2 text-xs font-bold bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-lg transition cursor-pointer shadow-sm"
            >
              {busy ? (
                <>
                  <RefreshCw className="w-3.5 h-3.5 animate-spin" /> Menyimpan...
                </>
              ) : (
                <>
                  <Save className="w-3.5 h-3.5" /> Simpan Pola Rotasi
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// MODAL: Assign shift ke satu karyawan + riwayat assignment
// ═══════════════════════════════════════════════════════════════
interface AssignModalProps {
  user: RosterRow;
  shifts: ShiftTemplate[];
  patterns?: ShiftPattern[];
  onClose: () => void;
  onSaved: () => void;
}

function AssignModal({ user, shifts, patterns = [], onClose, onSaved }: AssignModalProps) {
  const [assignType, setAssignType] = useState<'shift' | 'pattern'>('shift');
  const [shiftId, setShiftId] = useState<string>(''); // '' = default kantor (null)
  const [patternId, setPatternId] = useState<string>('');
  const [anchorDayOrder, setAnchorDayOrder] = useState<number>(1);
  const [startDate, setStartDate] = useState(todayStr());
  const [endDate, setEndDate] = useState(''); // kosong = tanpa batas waktu
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [k3Warnings, setK3Warnings] = useState<string[]>([]);

  const [history, setHistory] = useState<AssignmentRow[]>([]);
  const [loadingHist, setLoadingHist] = useState(true);

  // Dialog konfirmasi hapus/akhiri assignment
  const [confirmDialog, setConfirmDialog] = useState<{
    isOpen: boolean;
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    cancelText?: string;
    type?: ConfirmationType;
    isLoading?: boolean;
    onConfirm: () => void;
  } | null>(null);

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

  // Pola rotasi aktif yang relevan: company-wide atau cabang karyawan yang cocok
  const activePatterns = useMemo(() => {
    return patterns.filter(
      (p) =>
        p.is_active && (
          !p.attendance_setting_id ||
          !user.attendance_setting_id ||
          p.attendance_setting_id === user.attendance_setting_id
        ),
    );
  }, [patterns, user.attendance_setting_id]);
  const selectedPattern = useMemo(
    () => activePatterns.find((p) => String(p.id) === patternId),
    [activePatterns, patternId],
  );

  // Evaluasi kepatuhan K3 jika shift yang dipilih adalah Shift Malam (23:00–07:00)
  const selectedShift = useMemo(() => shifts.find((s) => String(s.id) === shiftId), [shifts, shiftId]);
  const isNight = useMemo(() => (assignType === 'shift' ? isNightShift(selectedShift) : false), [assignType, selectedShift]);

  const k3BlockReason = useMemo(() => {
    if (!isNight) return null;
    if (user.is_pregnant) {
      return `Penugasan shift malam (23:00–07:00) dilarang. Karyawan ${user.name} tercatat dalam kondisi hamil. Sesuai UU No. 13/2003 Pasal 76 ayat (1), pekerja perempuan hamil dilarang dipekerjakan pada shift malam.`;
    }
    if (user.age !== null && user.age !== undefined && user.age < 17) {
      return `Penugasan shift malam (23:00–07:00) dilarang. Karyawan ${user.name} masih berusia ${user.age} tahun (di bawah batas minimum 17 tahun). Sesuai standar K3 UU No. 13/2003, pekerja di bawah umur dilarang ditugaskan pada shift malam.`;
    }
    return null;
  }, [isNight, user]);

  const k3Advisories = useMemo(() => {
    if (!isNight || k3BlockReason) return [];
    const adv: string[] = [];
    if (user.age === 17) {
      adv.push(`K3 Catatan: Karyawan ${user.name} berusia 17 tahun. Berdasarkan standar K3 UU No. 13/2003, disarankan pengawasan ekstra bagi pekerja muda pada jam malam.`);
    } else if (!user.birth_date) {
      adv.push(`Data tanggal lahir ${user.name} belum terisi di profil. Pastikan karyawan berusia minimal 17 tahun untuk shift malam.`);
    }
    if (user.gender === 'Perempuan') {
      adv.push(`Kepatuhan K3: Pekerja perempuan pada shift malam (23:00–07:00) wajib diberikan makanan/minuman bergizi serta fasilitas angkutan antar-jemput aman (UU No. 13/2003 Pasal 76 ayat 3 & 4).`);
    }
    return adv;
  }, [isNight, k3BlockReason, user]);

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
    if (assignType === 'shift' && !shiftId) {
      setErr('Silakan pilih shift terlebih dahulu.');
      return;
    }
    if (assignType === 'pattern' && !patternId) {
      setErr('Silakan pilih pola rotasi terlebih dahulu.');
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
        shift_id: assignType === 'shift' ? (shiftId ? Number(shiftId) : null) : null,
        shift_pattern_id: assignType === 'pattern' ? (patternId ? Number(patternId) : null) : null,
        anchor_day_order: assignType === 'pattern' ? Number(anchorDayOrder || 1) : undefined,
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
      setPatternId('');
      setAnchorDayOrder(1);
      setNotes('');
      setEndDate('');
    } catch (ex: unknown) {
      setErr(ex instanceof ApiError ? ex.message : 'Gagal meng-assign jadwal.');
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = (h: AssignmentRow) => {
    const isAct = h.status === 'active';
    const isUp = h.status === 'upcoming';
    const title = isAct
      ? 'Akhiri Assignment Aktif'
      : isUp
        ? 'Hapus Assignment Mendatang'
        : 'Hapus Riwayat Assignment';

    const msg = isAct
      ? 'Assignment ini SEDANG AKTIF. Mengakhiri assignment akan mengembalikan karyawan ke jadwal kantor default mulai hari ini.'
      : isUp
        ? 'Assignment ini belum dimulai (masa depan). Hapus permanen dari jadwal?'
        : 'Assignment ini sudah berakhir. Hapus catatan ini dari riwayat karyawan?';

    setConfirmDialog({
      isOpen: true,
      title,
      message: (
        <div className="space-y-2">
          <p className="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
            {msg}
          </p>
          {isAct && (
            <div className="p-2.5 rounded-xl bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900/40 text-xs text-blue-700 dark:text-blue-300 space-y-1">
              <p className="font-semibold">ℹ️ Proteksi Sesi Kerja Aktif (Snapshot):</p>
              <p>Jika karyawan sedang aktif bekerja hari ini, presensi hari ini tetap diselesaikan sesuai jadwal saat check-in. Jadwal kantor default akan otomatis berlaku setelah karyawan check-out.</p>
            </div>
          )}
          <div className="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-300 space-y-1">
            <p>· Karyawan: <strong>{user.name}</strong></p>
            <p>· Jadwal: <strong>{h.shift_pattern ? `Pola Rotasi '${h.shift_pattern.name}'` : (h.shift?.name ?? 'Default Kantor')}</strong></p>
            <p>· Periode: Mulai <strong>{fmtDate(h.start_date)}</strong> {h.end_date ? <>s.d. <strong>{fmtDate(h.end_date)}</strong></> : ' (tanpa batas)'}</p>
          </div>
        </div>
      ),
      confirmText: isAct ? 'Ya, Akhiri Assignment' : 'Ya, Hapus',
      cancelText: 'Batal',
      type: isAct ? 'warning' : 'danger',
      onConfirm: async () => {
        try {
          await shiftApi.destroyAssignment(h.id);
          await loadHistory();
          onSaved();
        } catch (ex: unknown) {
          setErr(ex instanceof ApiError ? ex.message : 'Gagal menghapus assignment.');
        }
      },
    });
  };

  const av = avatarFor(user.name);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm px-4 py-6"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto border border-slate-100 dark:border-slate-800 animate-in fade-in slide-in-from-bottom-4 duration-200">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white dark:bg-slate-900 z-10">
          <div className="flex items-center gap-3">
            <div className={`w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold ${av.bg} ${av.text}`}>
              {initialsOf(user.name)}
            </div>
            <div>
              <p className="font-bold text-sm text-slate-800 dark:text-slate-100">{user.name}</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                {user.department || 'Tanpa departemen'}
                {user.branch && <> · {user.branch}</>}
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 transition cursor-pointer">
            <X className="w-4 h-4" />
          </button>
        </div>

        <div className="p-6 space-y-5">
          {/* Form assign */}
          <form onSubmit={handleSubmit} className="space-y-3">
            <p className="text-xs font-bold text-slate-700 dark:text-slate-200 flex items-center gap-1.5">
              <UserCog className="w-3.5 h-3.5 text-indigo-500" /> Assign Jadwal Kerja Baru
            </p>

            {/* Pilihan: Shift Mingguan vs Pola Rotasi */}
            <div className="flex rounded-lg bg-slate-100 dark:bg-slate-800 p-0.5 border border-slate-200 dark:border-slate-700">
              <button
                type="button"
                onClick={() => setAssignType('shift')}
                className={`flex-1 py-1.5 text-xs font-semibold rounded-md transition cursor-pointer flex items-center justify-center gap-1.5 ${
                  assignType === 'shift'
                    ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                }`}
              >
                <Clock className="w-3.5 h-3.5" /> Template Shift Mingguan
              </button>
              <button
                type="button"
                onClick={() => setAssignType('pattern')}
                className={`flex-1 py-1.5 text-xs font-semibold rounded-md transition cursor-pointer flex items-center justify-center gap-1.5 ${
                  assignType === 'pattern'
                    ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                }`}
              >
                <Repeat className="w-3.5 h-3.5" /> Pola Rotasi Siklus
              </button>
            </div>

            {assignType === 'shift' ? (
              <div>
                <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">Pilih Shift</label>
                <select
                  value={shiftId}
                  onChange={(e) => setShiftId(e.target.value)}
                  disabled={loadingHist}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                >
                  <option value="" disabled>— Pilih Shift —</option>
                  {loadingHist ? (
                    <option value="" disabled>Memuat shift...</option>
                  ) : (
                    relevantShifts.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.name}{isNightShift(s) ? ' 🌙 (Shift Malam)' : ''}{s.office ? ` — ${s.office.office_name}` : ' — Semua cabang'}
                      </option>
                    ))
                  )}
                </select>
              </div>
            ) : (
              <div className="space-y-3">
                <div>
                  <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">
                    Pilih Pola Rotasi (Cycle)
                  </label>
                  <select
                    value={patternId}
                    onChange={(e) => {
                      setPatternId(e.target.value);
                      setAnchorDayOrder(1);
                    }}
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                  >
                    <option value="" disabled>— Pilih Pola Rotasi —</option>
                    {activePatterns.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.name} (Siklus {p.cycle_days} Hari{p.office ? ` — ${p.office.office_name}` : ' — Semua cabang'})
                      </option>
                    ))}
                  </select>
                </div>

                {selectedPattern && (
                  <div className="p-3 rounded-xl bg-indigo-50/50 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 space-y-2">
                    <div className="flex items-center justify-between">
                      <label className="text-[11px] font-bold text-indigo-950 dark:text-indigo-200 uppercase">
                        Posisi Awal Siklus pada Tgl Mulai (Fase Tim)
                      </label>
                      <span className="text-[10px] font-semibold text-indigo-600 dark:text-indigo-400">
                        1 s.d. {selectedPattern.cycle_days}
                      </span>
                    </div>
                    <select
                      value={anchorDayOrder}
                      onChange={(e) => setAnchorDayOrder(Number(e.target.value))}
                      className="w-full text-xs p-2 border border-indigo-200 dark:border-indigo-800 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-semibold"
                    >
                      {Array.from({ length: selectedPattern.cycle_days }, (_, i) => {
                        const dayNum = i + 1;
                        const item = selectedPattern.items.find((it) => it.day_order === dayNum);
                        const label = item?.is_off
                          ? 'Libur (OFF)'
                          : (item?.shift?.name ? `${item.shift.name} (${item.work_start_time?.slice(0, 5) ?? '07:00'}-${item.work_end_time?.slice(0, 5) ?? '15:00'})` : 'Kerja');
                        return (
                          <option key={dayNum} value={dayNum}>
                            Hari ke-{dayNum}: {label}
                          </option>
                        );
                      })}
                    </select>
                    <p className="text-[10px] text-slate-500 dark:text-slate-400 leading-relaxed">
                      💡 <strong>Fase Tim:</strong> Untuk membagi regu (Tim A & Tim B), tentukan hari mulai yang berbeda. Misal Tim A mulai Hari 1 (Kerja), Tim B mulai Hari 5 (Libur).
                    </p>
                  </div>
                )}
              </div>
            )}

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">Berlaku Mulai</label>
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
                <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">
                  Berlaku Sampai
                  <span className="ml-1 text-slate-400 dark:text-slate-500 normal-case font-normal">(opsional)</span>
                </label>
                <CustomDatePicker
                  value={endDate}
                  min={startDate || undefined}
                  onChange={setEndDate}
                  placeholder="Pilih tgl selesai"
                  size="sm"
                  align="right"
                />
                <p className="text-[10px] text-slate-400 dark:text-slate-500 mt-1">
                  Kosongkan = berlaku tanpa batas.
                </p>
              </div>
            </div>

            <div>
              <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">Catatan</label>
              <input
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="Opsional"
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-400 focus:outline-none"
              />
            </div>

            {err && (
              <div className="flex items-start gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-lg p-2.5 text-[11px] text-rose-700 dark:text-rose-400">
                <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
                <span>{err}</span>
              </div>
            )}

            {k3Warnings.length > 0 && (
              <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 rounded-lg p-2.5 space-y-1">
                <p className="text-[11px] font-bold text-amber-700 dark:text-amber-400 flex items-center gap-1.5">
                  <AlertTriangle className="w-3.5 h-3.5 shrink-0" /> Peringatan K3
                </p>
                {k3Warnings.map((w, i) => (
                  <p key={i} className="text-[11px] text-amber-700 dark:text-amber-400">· {w}</p>
                ))}
                <p className="text-[10px] text-amber-600 dark:text-amber-400">Assignment berhasil disimpan. Pastikan karyawan mendapat istirahat yang cukup.</p>
              </div>
            )}

            {k3BlockReason && (
              <div className="flex items-start gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-lg p-2.5 text-[11px] text-rose-700 dark:text-rose-400">
                <AlertCircle className="w-4 h-4 mt-0.5 shrink-0" />
                <div className="space-y-0.5">
                  <p className="font-bold">Proteksi K3 Shift Malam</p>
                  <p>{k3BlockReason}</p>
                </div>
              </div>
            )}

            {k3Advisories.length > 0 && (
              <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 rounded-lg p-2.5 space-y-1">
                <p className="text-[11px] font-bold text-amber-700 dark:text-amber-400 flex items-center gap-1.5">
                  <AlertTriangle className="w-3.5 h-3.5 shrink-0" /> Ketentuan K3 Shift Malam
                </p>
                {k3Advisories.map((adv, idx) => (
                  <p key={idx} className="text-[11px] text-amber-700 dark:text-amber-400">· {adv}</p>
                ))}
              </div>
            )}

            <button
              type="submit"
              disabled={busy || !!k3BlockReason}
              className="w-full py-2.5 text-xs font-bold rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-300 dark:disabled:bg-indigo-900/50 transition flex items-center justify-center gap-1.5 cursor-pointer disabled:cursor-not-allowed"
            >
              {busy && <div className="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin" />}
              <CheckCircle2 className="w-3.5 h-3.5" /> Terapkan Assignment
            </button>
          </form>

          {/* Daftar assignment: menunjukkan shift aktif, yang akan datang, dan yang sudah berakhir */}
          <div className="border-t border-slate-100 dark:border-slate-800 pt-4">
            <p className="text-xs font-bold text-slate-700 dark:text-slate-200 flex items-center gap-1.5 mb-2">
              <History className="w-3.5 h-3.5 text-slate-400" /> Assignment Shift Karyawan
              {loadingHist && <div className="w-3 h-3 border-2 border-slate-300 border-t-indigo-500 rounded-full animate-spin" />}
            </p>
            {history.length === 0 && !loadingHist ? (
              <p className="text-[11px] text-slate-400 dark:text-slate-500 italic py-2">
                Belum ada assignment khusus. Karyawan mengikuti jam kantor default.
              </p>
            ) : (
              <div className="space-y-1.5">
                {history.map((h) => (
                  <div key={h.id} className="flex items-start justify-between gap-2 p-2.5 rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-750">
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-1.5">
                        <p className="text-xs font-semibold text-slate-700 dark:text-slate-200 truncate">
                          {h.shift_pattern ? `Pola Rotasi: ${h.shift_pattern.name} (Fase H${h.anchor_day_order ?? 1})` : (h.shift?.name ?? 'Default Kantor')}
                        </p>
                        {/* Badge status assignment */}
                        {h.status === 'active' && (
                          <span className="shrink-0 inline-flex items-center gap-1 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 px-1.5 py-0.5 text-[9px] font-bold">
                            <span className="w-1 h-1 rounded-full bg-emerald-500" /> AKTIF
                          </span>
                        )}
                        {h.status === 'upcoming' && (
                          <span className="shrink-0 inline-flex items-center gap-1 rounded-full bg-sky-100 dark:bg-sky-950/60 text-sky-700 dark:text-sky-400 px-1.5 py-0.5 text-[9px] font-bold">
                            SEGERA
                          </span>
                        )}
                        {h.status === 'expired' && (
                          <span className="shrink-0 inline-flex items-center rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 px-1.5 py-0.5 text-[9px] font-bold">
                            BERAKHIR
                          </span>
                        )}
                      </div>
                      <p className="text-[10px] text-slate-400 dark:text-slate-500">
                        Mulai {fmtDate(h.start_date)}
                        {h.end_date && (
                          <>
                            {' '}·{' '}
                            <span className="inline-flex items-center gap-0.5 font-semibold text-amber-600 dark:text-amber-400">
                              s.d. {fmtDate(h.end_date)}
                            </span>
                          </>
                        )}
                        {h.notes && <> · {h.notes}</>}
                      </p>
                    </div>
                    <button
                      onClick={() => handleDelete(h)}
                      className="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition shrink-0 cursor-pointer"
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

      {/* Confirmation Dialog helper */}
      {confirmDialog && (
        <ConfirmationDialog
          isOpen={confirmDialog.isOpen}
          onClose={() => setConfirmDialog(null)}
          onConfirm={() => {
            confirmDialog.onConfirm();
            setConfirmDialog(null);
          }}
          title={confirmDialog.title}
          message={confirmDialog.message}
          confirmText={confirmDialog.confirmText}
          cancelText={confirmDialog.cancelText}
          type={confirmDialog.type}
          isLoading={confirmDialog.isLoading}
        />
      )}
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
  patterns?: ShiftPattern[];
  /** ID attendance_setting (cabang) dari karyawan yang dipilih — untuk filter dropdown shift */
  selectedBranchIds: Set<number>;
  onClose: () => void;
  onSaved: () => void;
}

function BulkAssignModal({ userIds, userNames, shifts, patterns = [], selectedBranchIds, onClose, onSaved }: BulkModalProps) {
  const [assignType, setAssignType] = useState<'shift' | 'pattern'>('shift');
  const [shiftId, setShiftId] = useState<string>('');
  const [patternId, setPatternId] = useState<string>('');
  const [anchorDayOrder, setAnchorDayOrder] = useState<number>(1);
  const [startDate, setStartDate] = useState(todayStr());
  const [endDate, setEndDate] = useState(''); // kosong = tanpa batas waktu
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [result, setResult] = useState<{ success: number; skipped: { name?: string; reason: string }[] } | null>(null);

  // Dialog konfirmasi bulk assign
  const [confirmDialog, setConfirmDialog] = useState<{
    isOpen: boolean;
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    cancelText?: string;
    type?: ConfirmationType;
    isLoading?: boolean;
    onConfirm: () => void;
  } | null>(null);

  // Pola rotasi aktif yang relevan untuk bulk assign (company-wide atau cocok dengan cabang karyawan terpilih)
  const activePatterns = useMemo(() => {
    return patterns.filter((p) =>
      p.is_active && (
        !p.attendance_setting_id ||
        selectedBranchIds.size === 0 ||
        selectedBranchIds.has(p.attendance_setting_id)
      ),
    );
  }, [patterns, selectedBranchIds]);
  const selectedPattern = useMemo(
    () => activePatterns.find((p) => String(p.id) === patternId),
    [activePatterns, patternId],
  );

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

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (assignType === 'shift' && !shiftId) {
      setErr('Silakan pilih shift terlebih dahulu.');
      return;
    }
    if (assignType === 'pattern' && !patternId) {
      setErr('Silakan pilih pola rotasi terlebih dahulu.');
      return;
    }
    if (endDate && endDate <= startDate) {
      setErr('Tanggal berakhir harus setelah tanggal mulai (minimal 1 hari setelah tanggal mulai).');
      return;
    }
    setErr('');

    const targetShift = availableShifts.find((s) => String(s.id) === String(shiftId));
    const targetPattern = activePatterns.find((p) => String(p.id) === String(patternId));

    setConfirmDialog({
      isOpen: true,
      title: 'Konfirmasi Assign Massal Jadwal',
      message: (
        <div className="space-y-2.5">
          <p className="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
            Apakah Anda yakin ingin menerapkan assignment ini secara massal ke <strong>{userIds.length} karyawan</strong> terpilih?
          </p>
          <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/40 border border-slate-200 dark:border-slate-700 text-xs space-y-1">
            <p className="text-slate-700 dark:text-slate-200">
              · Jadwal:{' '}
              <strong className="text-indigo-600 dark:text-indigo-400">
                {assignType === 'shift'
                  ? (targetShift?.name ?? 'Shift Terpilih')
                  : `${targetPattern?.name ?? 'Pola Rotasi'} (Mulai Fase H${anchorDayOrder})`}
              </strong>
            </p>
            <p className="text-slate-700 dark:text-slate-200">· Periode: Mulai <strong>{fmtDate(startDate)}</strong> {endDate ? <>s.d. <strong>{fmtDate(endDate)}</strong></> : ' (tanpa batas waktu)'}</p>
            {notes.trim() && <p className="text-slate-700 dark:text-slate-200">· Catatan: <em>{notes.trim()}</em></p>}
            <p className="text-slate-500 dark:text-slate-400 text-[11px] pt-1">Total: {userIds.length} karyawan akan diperbarui jadwalnya.</p>
          </div>
        </div>
      ),
      confirmText: `Ya, Terapkan (${userIds.length} Karyawan)`,
      cancelText: 'Batal',
      type: 'info',
      onConfirm: async () => {
        setBusy(true);
        setErr('');
        try {
          const res: any = await shiftApi.bulkAssign({
            user_ids: userIds,
            shift_id: assignType === 'shift' ? (shiftId ? Number(shiftId) : null) : null,
            shift_pattern_id: assignType === 'pattern' ? (patternId ? Number(patternId) : null) : null,
            anchor_day_order: assignType === 'pattern' ? Number(anchorDayOrder || 1) : undefined,
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
      },
    });
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm px-4 py-6"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto border border-slate-100 dark:border-slate-800 animate-in fade-in slide-in-from-bottom-4 duration-200">
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-full bg-violet-100 dark:bg-violet-950/70 flex items-center justify-center">
              <Users className="w-5 h-5 text-violet-600 dark:text-violet-400" />
            </div>
            <div>
              <p className="font-bold text-sm text-slate-800 dark:text-slate-100">Assign Massal</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">{userIds.length} karyawan dipilih</p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 transition cursor-pointer">
            <X className="w-4 h-4" />
          </button>
        </div>

        <div className="p-6 space-y-4">
          {!result ? (
            <form onSubmit={handleSubmit} className="space-y-3">
              {/* Daftar nama terpilih */}
              <div className="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto">
                {userNames.map((n, i) => (
                  <span key={i} className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                    {n}
                  </span>
                ))}
              </div>

              {/* Pilihan: Shift Mingguan vs Pola Rotasi */}
              <div className="flex rounded-lg bg-slate-100 dark:bg-slate-800 p-0.5 border border-slate-200 dark:border-slate-700">
                <button
                  type="button"
                  onClick={() => setAssignType('shift')}
                  className={`flex-1 py-1.5 text-xs font-semibold rounded-md transition cursor-pointer flex items-center justify-center gap-1.5 ${
                    assignType === 'shift'
                      ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                      : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                  }`}
                >
                  <Clock className="w-3.5 h-3.5" /> Template Shift Mingguan
                </button>
                <button
                  type="button"
                  onClick={() => setAssignType('pattern')}
                  className={`flex-1 py-1.5 text-xs font-semibold rounded-md transition cursor-pointer flex items-center justify-center gap-1.5 ${
                    assignType === 'pattern'
                      ? 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-xs'
                      : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                  }`}
                >
                  <Repeat className="w-3.5 h-3.5" /> Pola Rotasi Siklus
                </button>
              </div>

              {assignType === 'shift' ? (
                <div>
                  <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">Shift</label>
                  <select
                    value={shiftId}
                    onChange={(e) => setShiftId(e.target.value)}
                    className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                  >
                    <option value="" disabled>— Pilih Shift —</option>
                    {availableShifts.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.name}{isNightShift(s) ? ' 🌙 (Shift Malam)' : ''}{s.office ? ` — ${s.office.office_name}` : ' — Semua cabang'}
                      </option>
                    ))}
                  </select>
                  {(() => {
                    const sTarget = availableShifts.find((s) => String(s.id) === String(shiftId));
                    if (sTarget && isNightShift(sTarget)) {
                      return (
                        <div className="mt-2 p-2.5 rounded-lg bg-purple-50 dark:bg-purple-950/30 border border-purple-200 dark:border-purple-800/40 text-[11px] text-purple-700 dark:text-purple-300 flex items-start gap-1.5">
                          <span className="shrink-0">🌙</span>
                          <div>
                            <strong className="font-semibold">Proteksi K3 Shift Malam Aktif:</strong>
                            <p className="mt-0.5 text-[10.5px] text-purple-600 dark:text-purple-400">
                              Karyawan berusia di bawah 17 tahun atau tercatat sedang hamil akan otomatis dilindungi dan dilewati (skip) oleh sistem sesuai UU No. 13/2003 Pasal 76.
                            </p>
                          </div>
                        </div>
                      );
                    }
                    return null;
                  })()}
                  <p className="text-[10px] text-slate-400 dark:text-slate-500 mt-1">
                    {selectedBranchIds.size > 0
                      ? 'Menampilkan shift milik cabang karyawan yang dipilih dan shift yang berlaku untuk semua cabang.'
                      : 'Menampilkan semua shift aktif.'}
                  </p>
                </div>
              ) : (
                <div className="space-y-3">
                  <div>
                    <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">
                      Pola Rotasi (Cycle)
                    </label>
                    <select
                      value={patternId}
                      onChange={(e) => {
                        setPatternId(e.target.value);
                        setAnchorDayOrder(1);
                      }}
                      className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                    >
                      <option value="" disabled>— Pilih Pola Rotasi —</option>
                      {activePatterns.map((p) => (
                        <option key={p.id} value={p.id}>
                          {p.name} (Siklus {p.cycle_days} Hari{p.office ? ` — ${p.office.office_name}` : ' — Semua cabang'})
                        </option>
                      ))}
                    </select>
                  </div>

                  {selectedPattern && (
                    <div className="p-3 rounded-xl bg-indigo-50/50 dark:bg-indigo-950/30 border border-indigo-100 dark:border-indigo-900/40 space-y-2">
                      <div className="flex items-center justify-between">
                        <label className="text-[11px] font-bold text-indigo-950 dark:text-indigo-200 uppercase">
                          Mulai Siklus dari Hari ke- (Fase Tim)
                        </label>
                        <span className="text-[10px] font-semibold text-indigo-600 dark:text-indigo-400">
                          1 s.d. {selectedPattern.cycle_days}
                        </span>
                      </div>
                      <select
                        value={anchorDayOrder}
                        onChange={(e) => setAnchorDayOrder(Number(e.target.value))}
                        className="w-full text-xs p-2 border border-indigo-200 dark:border-indigo-800 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-semibold"
                      >
                        {Array.from({ length: selectedPattern.cycle_days }, (_, i) => {
                          const dayNum = i + 1;
                          const item = selectedPattern.items.find((it) => it.day_order === dayNum);
                          const label = item?.is_off
                            ? 'Libur (OFF)'
                            : (item?.shift?.name ? `${item.shift.name}` : 'Kerja');
                          return (
                            <option key={dayNum} value={dayNum}>
                              Hari ke-{dayNum}: {label}
                            </option>
                          );
                        })}
                      </select>
                      <p className="text-[10px] text-slate-500 dark:text-slate-400 leading-relaxed">
                        💡 Seluruh {userIds.length} karyawan terpilih akan mulai berjalan serempak dari fase siklus yang ditentukan.
                      </p>
                    </div>
                  )}
                </div>
              )}

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">Berlaku Mulai</label>
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
                  <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">
                    Berlaku Sampai
                    <span className="ml-1 text-slate-400 dark:text-slate-500 normal-case font-normal">(opsional)</span>
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
                <label className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 uppercase block mb-1">Catatan</label>
                <input
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Opsional"
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-400 focus:outline-none"
                />
              </div>
              <p className="text-[10px] text-slate-400 dark:text-slate-500 -mt-1">
                Kosongkan tanggal berakhir = shift berlaku tanpa batas. Setelah tanggal itu, karyawan otomatis kembali ke jam kantor.
              </p>

              {err && (
                <div className="flex items-start gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-lg p-2.5 text-[11px] text-rose-700 dark:text-rose-400">
                  <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
                  <span>{err}</span>
                </div>
              )}

              <div className="flex gap-2 pt-1">
                <button type="button" onClick={onClose} className="flex-1 py-2.5 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition cursor-pointer">
                  Batal
                </button>
                <button type="submit" disabled={busy} className="flex-1 py-2.5 text-xs font-bold rounded-lg text-white bg-violet-600 hover:bg-violet-700 disabled:bg-violet-300 dark:disabled:bg-violet-900/50 transition flex items-center justify-center gap-1.5 cursor-pointer">
                  {busy && <div className="w-3 h-3 border-2 border-white/40 border-t-white rounded-full animate-spin" />}
                  Assign {userIds.length} Karyawan
                </button>
              </div>
            </form>
          ) : (
            /* Hasil bulk assign */
            <div className="space-y-3">
              <div className="flex items-center gap-2 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900 rounded-lg p-3 text-xs text-emerald-700 dark:text-emerald-400">
                <CheckCircle2 className="w-4 h-4 shrink-0" />
                <span><strong>{result.success}</strong> karyawan berhasil di-assign.</span>
              </div>
              {result.skipped.length > 0 && (
                <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900/40 rounded-lg p-3 text-xs text-amber-700 dark:text-amber-400 space-y-1">
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
              <button onClick={onClose} className="w-full py-2.5 text-xs font-bold rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 transition cursor-pointer">
                Selesai
              </button>
            </div>
          )}
        </div>
      </div>

      {/* Confirmation Dialog helper */}
      {confirmDialog && (
        <ConfirmationDialog
          isOpen={confirmDialog.isOpen}
          onClose={() => setConfirmDialog(null)}
          onConfirm={() => {
            confirmDialog.onConfirm();
            setConfirmDialog(null);
          }}
          title={confirmDialog.title}
          message={confirmDialog.message}
          confirmText={confirmDialog.confirmText}
          cancelText={confirmDialog.cancelText}
          type={confirmDialog.type}
          isLoading={confirmDialog.isLoading}
        />
      )}
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
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm px-4 py-6"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden border border-slate-100 dark:border-slate-800 animate-in fade-in slide-in-from-bottom-4 duration-200">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 shrink-0">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-full flex items-center justify-center" style={{ backgroundColor: (shift.color ?? '#6366f1') + '20' }}>
              <Layers className="w-5 h-5" style={{ color: shift.color ?? '#6366f1' }} />
            </div>
            <div>
              <p className="font-bold text-sm text-slate-800 dark:text-slate-100 flex items-center gap-2">
                {shift.name}
                {!shift.is_active && (
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400">Nonaktif</span>
                )}
              </p>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                {rows.length} karyawan terkait · {activeCount} aktif
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 transition cursor-pointer">
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-6 py-4">
          {error && (
            <div className="flex items-start gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 rounded-lg p-3 text-xs text-rose-700 dark:text-rose-400">
              <AlertCircle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          {loading ? (
            <div className="space-y-2">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="flex items-center gap-2.5 p-2.5 rounded-lg bg-slate-50 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800 animate-pulse">
                  <div className="w-7 h-7 rounded-full bg-slate-200 dark:bg-slate-700 shrink-0" />
                  <div className="flex-1 space-y-1.5">
                    <div className="h-3 w-32 bg-slate-200 dark:bg-slate-700 rounded" />
                    <div className="h-2 w-20 bg-slate-200 dark:bg-slate-700 rounded" />
                  </div>
                </div>
              ))}
            </div>
          ) : rows.length === 0 ? (
            <div className="py-12 text-center text-slate-400 dark:text-slate-500">
              <Users className="w-10 h-10 mx-auto opacity-30" />
              <p className="font-semibold text-sm mt-2">Belum ada karyawan</p>
              <p className="text-xs mt-0.5">Belum ada karyawan yang di-assign ke shift ini.</p>
            </div>
          ) : (
            <div className="space-y-1.5">
              {rows.map((r) => {
                const av = avatarFor(r.name);
                return (
                  <div key={r.assignment_id} className="flex items-center gap-2.5 p-2.5 rounded-lg bg-slate-50 dark:bg-slate-805 bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-750">
                    <div className={`w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold shrink-0 ${av.bg} ${av.text}`}>
                      {initialsOf(r.name)}
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="text-xs font-semibold text-slate-700 dark:text-slate-200 truncate">{r.name}</p>
                      <p className="text-[10px] text-slate-400 dark:text-slate-500 truncate">
                        {r.department || 'Tanpa departemen'}
                        {r.branch && <> · {r.branch}</>}
                      </p>
                    </div>
                    {/* Badge status assignment */}
                    {r.status === 'active' && (
                      <span className="shrink-0 inline-flex items-center gap-1 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 px-1.5 py-0.5 text-[9px] font-bold">
                        <span className="w-1 h-1 rounded-full bg-emerald-500" /> AKTIF
                      </span>
                    )}
                    {r.status === 'upcoming' && (
                      <span className="shrink-0 inline-flex items-center rounded-full bg-sky-100 dark:bg-sky-950/60 text-sky-700 dark:text-sky-400 px-1.5 py-0.5 text-[9px] font-bold">
                        SEGERA
                      </span>
                    )}
                    {r.status === 'expired' && (
                      <span className="shrink-0 inline-flex items-center rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 px-1.5 py-0.5 text-[9px] font-bold">
                        BERAKHIR
                      </span>
                    )}
                    <span className="shrink-0 text-[10px] text-slate-400 dark:text-slate-500">
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
type Tab = 'roster' | 'templates' | 'patterns' | 'kalender';

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

  // Paginasi Roster untuk rendering ringan pada 1.000 karyawan
  const [rosterPage, setRosterPage] = useState<number>(1);
  const [rosterPageSize, setRosterPageSize] = useState<number>(25);

  useEffect(() => {
    setRosterPage(1);
  }, [rosterSearch, rosterBranch, rosterShiftName, rosterDate]);

  // ── Template state ──
  const [loadingShifts, setLoadingShifts] = useState(false);
  const [templateBranch, setTemplateBranch] = useState<string>(''); // '' = semua cabang

  // ── Pola Rotasi (Pattern Cycle) state ──
  const [patterns, setPatterns] = useState<ShiftPattern[]>([]);
  const [loadingPatterns, setLoadingPatterns] = useState(false);
  const [patternBranch, setPatternBranch] = useState<string>(''); // '' = semua cabang
  const [patternForm, setPatternForm] = useState<{ editing: ShiftPattern | null } | null>(null);

  // Filter pola rotasi berdasarkan cabang terpilih (atau company-wide)
  const filteredPatterns = useMemo(() => {
    if (!patternBranch) return patterns;
    return patterns.filter((p) => !p.attendance_setting_id || String(p.attendance_setting_id) === patternBranch);
  }, [patterns, patternBranch]);

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

  // Dialog konfirmasi template shift (hapus / toggle status)
  const [confirmDialog, setConfirmDialog] = useState<{
    isOpen: boolean;
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    cancelText?: string;
    type?: ConfirmationType;
    isLoading?: boolean;
    onConfirm: () => void;
  } | null>(null);

  // ─── Loaders ───────────────────────────────────────────────
  const loadOffices = useCallback(async (forceRefresh = false) => {
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/settings');
      const res: any = await attendanceApi.settings.list(forceRefresh);
      setOffices((res?.settings ?? []) as OfficeOpt[]);
    } catch { /* diam */ }
  }, []);

  const loadShifts = useCallback(async (forceRefresh = false) => {
    setLoadingShifts(true);
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/shifts');
      const res = await shiftApi.list(undefined, forceRefresh);
      setShifts(rows(res) as ShiftTemplate[]);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Gagal memuat template shift.');
    } finally {
      setLoadingShifts(false);
    }
  }, []);

  const loadPatterns = useCallback(async (forceRefresh = false) => {
    setLoadingPatterns(true);
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/shift-patterns');
      const res: any = await shiftApi.patterns.list(undefined, forceRefresh);
      setPatterns((res?.data ?? []) as ShiftPattern[]);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Gagal memuat pola rotasi shift.');
    } finally {
      setLoadingPatterns(false);
    }
  }, []);

  const debouncedRosterSearch = useDebounce(rosterSearch, 500);

  const loadRoster = useCallback(async (forceRefresh = false) => {
    setLoadingRoster(true);
    setError('');
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/shifts/roster');
      const filters: { date?: string; attendance_setting_id?: number; search?: string } = { date: rosterDate };
      if (rosterBranch) filters.attendance_setting_id = Number(rosterBranch);
      if (debouncedRosterSearch.trim()) filters.search = debouncedRosterSearch.trim();
      const res: any = await shiftApi.roster(filters, forceRefresh);
      setRoster((res?.data ?? []) as RosterRow[]);
      setRosterDayName(res?.day_name ?? '');
      setSelected(new Set());
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Gagal memuat roster.');
    } finally {
      setLoadingRoster(false);
    }
  }, [rosterDate, rosterBranch, debouncedRosterSearch]);

  const loadCalendar = useCallback(async (forceRefresh = false) => {
    setLoadingCal(true);
    try {
      if (forceRefresh) invalidateCache('/dashboard/attendance/shifts/calendar');
      const res: any = await shiftApi.calendar(
        calMonth, calYear,
        calBranch ? Number(calBranch) : undefined,
        forceRefresh,
      );
      setCalData((res?.days ?? {}) as Record<string, CalDayEntry[]>);
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Gagal memuat kalender shift.');
    } finally {
      setLoadingCal(false);
    }
  }, [calMonth, calYear, calBranch]);

  useEffect(() => { loadOffices(); loadShifts(); loadPatterns(); }, [loadOffices, loadShifts, loadPatterns]);
  useEffect(() => { if (tab === 'roster') loadRoster(); }, [tab, loadRoster]);
  useEffect(() => { if (tab === 'patterns') loadPatterns(); }, [tab, loadPatterns]);
  useEffect(() => { if (tab === 'kalender') loadCalendar(); }, [tab, loadCalendar]);

  // ─── Aksi Pola Rotasi ───────────────────────────────────────
  const handleDeletePattern = (p: ShiftPattern) => {
    setConfirmDialog({
      isOpen: true,
      title: 'Hapus Pola Rotasi',
      message: (
        <div className="space-y-2">
          <p className="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
            Apakah Anda yakin ingin menghapus pola rotasi <strong>"{p.name}"</strong>?
          </p>
          <p className="text-xs text-rose-600 dark:text-rose-400">
            Tindakan ini tidak dapat dibatalkan. Pola yang sedang aktif digunakan oleh karyawan tidak dapat dihapus.
          </p>
        </div>
      ),
      confirmText: 'Ya, Hapus Pola',
      cancelText: 'Batal',
      type: 'danger',
      onConfirm: async () => {
        setError('');
        try {
          await shiftApi.patterns.destroy(p.id);
          await loadPatterns(true);
          onAddAuditLog?.('Pola rotasi dihapus', p.name, 'bg-rose-500');
        } catch (e: unknown) {
          if (e instanceof ApiError) {
            setError(e.message);
          } else {
            setError('Gagal menghapus pola rotasi.');
          }
        }
      },
    });
  };

  // ─── Aksi template ─────────────────────────────────────────
  const handleDeleteShift = (s: ShiftTemplate) => {
    setConfirmDialog({
      isOpen: true,
      title: 'Hapus Template Shift',
      message: (
        <div className="space-y-2">
          <p className="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
            Apakah Anda yakin ingin menghapus template shift <strong>"{s.name}"</strong>?
          </p>
          <p className="text-xs text-rose-600 dark:text-rose-400">
            Tindakan ini tidak dapat dibatalkan. Template yang masih aktif digunakan oleh karyawan tidak dapat dihapus.
          </p>
        </div>
      ),
      confirmText: 'Ya, Hapus Shift',
      cancelText: 'Batal',
      type: 'danger',
      onConfirm: async () => {
        setError('');
        try {
          await shiftApi.destroy(s.id);
          await loadShifts();
          onAddAuditLog?.('Shift dihapus', s.name, 'bg-rose-500');
        } catch (e: unknown) {
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
      },
    });
  };

  const handleToggleActive = (s: ShiftTemplate) => {
    const aksi = s.is_active ? 'menonaktifkan' : 'mengaktifkan';
    setConfirmDialog({
      isOpen: true,
      title: s.is_active ? 'Nonaktifkan Template Shift' : 'Aktifkan Template Shift',
      message: (
        <div className="space-y-2">
          <p className="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
            Apakah Anda yakin ingin <strong>{aksi}</strong> shift <strong>"{s.name}"</strong>?
          </p>
          {s.is_active ? (
            <p className="text-xs text-amber-600 dark:text-amber-400">
              Shift yang dinonaktifkan tidak akan muncul di pilihan dropdown assignment baru. Karyawan yang sedang memakai shift ini tetap menyelesaikannya sesuai tanggal berlaku.
            </p>
          ) : (
            <p className="text-xs text-slate-500 dark:text-slate-400">
              Shift ini akan kembali tersedia di pilihan dropdown assignment shift karyawan.
            </p>
          )}
        </div>
      ),
      confirmText: s.is_active ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan',
      cancelText: 'Batal',
      type: s.is_active ? 'warning' : 'info',
      onConfirm: async () => {
        setError('');
        try {
          await shiftApi.toggleActive(s.id);
          await loadShifts();
          onAddAuditLog?.(s.is_active ? 'Shift dinonaktifkan' : 'Shift diaktifkan', s.name, 'bg-indigo-500');
        } catch (e: unknown) {
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
      },
    });
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
          { key: 'patterns' as Tab, label: 'Pola Rotasi (Cycle)', icon: <Repeat className="w-3.5 h-3.5" /> },
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
            {t.key === 'patterns' && patterns.length > 0 && (
              <span className={`text-[10px] px-1.5 py-0.5 rounded-full font-mono ${tab === t.key ? 'bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'}`}>
                {patterns.length}
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
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 p-4 shadow-sm">
            {/* Auto-filter info: centang karyawan → daftar menyaring ke cabang yang sama */}
            {selectedBranchIds.size > 0 && (
              <div className="flex items-center gap-2 bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-800 rounded-lg px-3 py-2 mb-3 text-[11px] text-indigo-700 dark:text-indigo-300 animate-in fade-in duration-200">
                <Users className="w-3.5 h-3.5 shrink-0" />
                <span>
                  <strong>Auto-filter cabang aktif:</strong> daftar menampilkan karyawan dengan cabang <strong>{selectedBranchNames}</strong> ({selected.size} dipilih). Hapus centang atau tekan <em>Batal pilih</em> untuk melihat semua.
                </span>
              </div>
            )}
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">
              <div>
                <label className="block text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase mb-1">Cabang</label>
                <select
                  value={rosterBranch}
                  onChange={(e) => setRosterBranch(e.target.value)}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                >
                  <option value="">Semua cabang</option>
                  {offices.map((o) => (
                    <option key={o.id} value={o.id}>{o.office_name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase mb-1">Shift</label>
                <select
                  value={rosterShiftName}
                  onChange={(e) => setRosterShiftName(e.target.value)}
                  className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                >
                  <option value="">Semua shift</option>
                  <option value="DEFAULT">Default Kantor</option>
                  {shifts.map((s) => (
                    <option key={s.id} value={s.name}>{s.name}</option>
                  ))}
                </select>
              </div>
              <div className="sm:col-span-2">
                <label className="block text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase mb-1">Cari Karyawan</label>
                <div className="flex gap-2">
                  <div className="relative flex-1">
                    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                    <input
                      type="text"
                      value={rosterSearch}
                      onChange={(e) => setRosterSearch(e.target.value)}
                      onKeyDown={(e) => e.key === 'Enter' && loadRoster()}
                      placeholder="Nama karyawan..."
                      className="w-full pl-8 text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-400 focus:outline-none"
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
            <div className="flex items-center justify-between gap-3 bg-violet-50 dark:bg-violet-950/40 border border-violet-200 dark:border-violet-800 rounded-xl px-4 py-2.5 animate-in fade-in slide-in-from-top-1 duration-200">
              <p className="text-xs font-semibold text-violet-700 dark:text-violet-300">
                {selected.size} karyawan dipilih
              </p>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setSelected(new Set())}
                  className="text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition cursor-pointer"
                >
                  Batal pilih
                </button>
                <button
                  onClick={() => setShowBulk(true)}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold bg-violet-600 hover:bg-violet-700 text-white rounded-lg transition cursor-pointer"
                >
                  <Users className="w-3.5 h-3.5" /> Assign Massal
                </button>
              </div>
            </div>
          )}

          {/* Tabel roster */}
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm overflow-hidden">
            <div className="px-4 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
              <p className="text-xs font-semibold text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
                <CalendarDays className="w-3.5 h-3.5 text-indigo-500" />
                Jadwal {rosterDayName && <span className="text-indigo-600 dark:text-indigo-400">{rosterDayName}</span>}, {fmtDate(rosterDate)}
                <span className="text-slate-400 dark:text-slate-500">· {filteredRoster.length} karyawan</span>
              </p>
              <button
                onClick={() => loadRoster(true)}
                disabled={loadingRoster}
                className="flex items-center gap-1.5 text-xs font-semibold text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800 bg-white dark:bg-slate-800 px-3 py-1.5 rounded-lg hover:bg-indigo-50 dark:hover:bg-indigo-950/40 transition cursor-pointer"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${loadingRoster ? 'animate-spin' : ''}`} /> Refresh
              </button>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead className="border-b border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/50">
                  <tr>
                    <th className="py-2.5 px-3 w-8">
                      <input
                        type="checkbox"
                        checked={filteredRoster.length > 0 && selected.size === filteredRoster.length}
                        onChange={toggleSelectAll}
                        className="w-3.5 h-3.5 rounded accent-indigo-600 align-middle"
                      />
                    </th>
                    <th className="py-2.5 px-3 font-semibold text-left text-slate-500 dark:text-slate-400">Karyawan</th>
                    <th className="py-2.5 px-3 font-semibold text-left text-slate-500 dark:text-slate-400">Cabang</th>
                    <th className="py-2.5 px-3 font-semibold text-left text-slate-500 dark:text-slate-400">Shift</th>
                    <th className="py-2.5 px-3 font-semibold text-center text-slate-500 dark:text-slate-400">Jam Kerja</th>
                    <th className="py-2.5 px-3 font-semibold text-center text-slate-500 dark:text-slate-400">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {loadingRoster ? (
                    Array.from({ length: 5 }).map((_, i) => (
                      <tr key={`skel-roster-${i}`} className="animate-pulse">
                        <td className="py-3 px-3">
                          <div className="w-3.5 h-3.5 bg-slate-200 dark:bg-slate-700 rounded" />
                        </td>
                        <td className="py-3 px-3">
                          <div className="flex items-center gap-2.5">
                            <div className="w-7 h-7 rounded-full bg-slate-200 dark:bg-slate-700 shrink-0" />
                            <div className="space-y-1.5">
                              <div className="h-3 w-24 bg-slate-200 dark:bg-slate-700 rounded" />
                              <div className="h-2 w-16 bg-slate-200 dark:bg-slate-700 rounded" />
                            </div>
                          </div>
                        </td>
                        <td className="py-3 px-3"><div className="h-3 w-20 bg-slate-200 dark:bg-slate-700 rounded" /></td>
                        <td className="py-3 px-3"><div className="h-3 w-24 bg-slate-200 dark:bg-slate-700 rounded" /></td>
                        <td className="py-3 px-3"><div className="h-3 w-20 bg-slate-200 dark:bg-slate-700 rounded mx-auto" /></td>
                        <td className="py-3 px-3"><div className="h-6 w-16 bg-slate-200 dark:bg-slate-700 rounded-lg mx-auto" /></td>
                      </tr>
                    ))
                  ) : filteredRoster.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="py-16 text-center">
                        <div className="flex flex-col items-center gap-2 text-slate-400 dark:text-slate-500">
                          <Users className="w-10 h-10 opacity-30" />
                          <p className="font-semibold text-sm">Tidak ada karyawan</p>
                          <p className="text-xs">
                            {roster.length > 0 ? "Tidak ada karyawan yang cocok dengan filter." : "Menampilkan semua karyawan aktif. Pastikan sudah ada karyawan di perusahaan ini."}
                          </p>
                        </div>
                      </td>
                    </tr>
                  ) : (() => {
                    const totalRosterPages = Math.max(1, Math.ceil(filteredRoster.length / rosterPageSize));
                    const paginatedRoster = filteredRoster.slice((rosterPage - 1) * rosterPageSize, rosterPage * rosterPageSize);

                    return paginatedRoster.map((r) => {
                      const av = avatarFor(r.name);
                      const isSel = selected.has(r.user_id);
                      return (
                        <tr key={r.user_id} className={`transition-colors ${isSel ? 'bg-indigo-50/40 dark:bg-indigo-950/40' : 'hover:bg-slate-50/60 dark:hover:bg-slate-800/40'}`}>
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
                                <p className="font-semibold text-slate-800 dark:text-slate-100">{r.name}</p>
                                {r.department && <p className="text-[10px] text-slate-400 dark:text-slate-500">{r.department}</p>}
                              </div>
                            </div>
                          </td>
                          <td className="py-3 px-3 text-slate-600 dark:text-slate-300">{r.branch ?? <span className="text-slate-300 dark:text-slate-600">—</span>}</td>
                          <td className="py-3 px-3 text-slate-700 dark:text-slate-200">
                            {r.pattern_name ? (
                              <div className="space-y-0.5">
                                <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800">
                                  <Repeat className="w-2.5 h-2.5" />
                                  {r.pattern_name} · H{r.cycle_day}
                                </span>
                                <p className="text-[10px] text-slate-500 dark:text-slate-400">
                                  {r.is_off ? 'Libur Siklus (OFF)' : (r.shift_name ?? 'Jadwal Kerja')}
                                </p>
                              </div>
                            ) : (
                              r.shift_name ?? (
                                r.upcoming_shift
                                  ? null // ada shift coming soon → jangan tampilkan strip
                                  : r.source === 'office' ? (
                                      <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                        <Building2 className="w-3 h-3" /> Jam Kantor
                                      </span>
                                    ) : (
                                      <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-400">
                                        <AlertCircle className="w-3 h-3" /> Belum Diatur
                                      </span>
                                    )
                              )
                            )}
                            {/* Shift yang sudah di-assign tapi belum aktif (coming soon) */}
                            {r.upcoming_shift && (
                              <div className="mt-1 space-y-0.5">
                                <span
                                  className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-900"
                                  title={`Shift '${r.upcoming_shift.shift_name}' aktif mulai ${fmtDate(r.upcoming_shift.start_date)}`}
                                >
                                  <Clock className="w-2.5 h-2.5 shrink-0" />
                                  Coming Soon
                                </span>
                                <p className="text-[10px] font-semibold text-amber-700 dark:text-amber-300 leading-tight">
                                  {r.upcoming_shift.shift_name}
                                  <span className="text-slate-400 dark:text-slate-500 font-normal"> · Aktif {fmtDate(r.upcoming_shift.start_date)}</span>
                                </p>
                              </div>
                            )}
                          </td>
                          <td className="py-3 px-3 text-center font-mono text-slate-700 dark:text-slate-200">
                            {r.is_off ? (
                              <span className="text-slate-300 dark:text-slate-600">—</span>
                            ) : r.work_start_time ? (
                              <span className="inline-flex items-center gap-1 justify-center">
                                {hhmm(r.work_start_time)}–{hhmm(r.work_end_time)}
                                {r.is_wfh && !r.is_field && (
                                  <span className="inline-flex items-center text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-cyan-100 dark:bg-cyan-950/60 text-cyan-800 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-800" title="Hari WFH terjadwal">
                                    WFH
                                  </span>
                                )}
                                {r.is_wfh && r.is_field && (
                                  <span className="inline-flex items-center text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800" title="Hari Lapangan terjadwal (WFH + Radius GPS)">
                                    Lapangan
                                  </span>
                                )}
                                {r.is_cross_day && (
                                  <span className="inline-flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-violet-100 dark:bg-violet-950/60 text-violet-700 dark:text-violet-300" title="Shift berakhir keesokan harinya">
                                    <Moon className="w-2.5 h-2.5" /> +1
                                  </span>
                                )}
                              </span>
                            ) : (
                              <span className="text-slate-300 dark:text-slate-600">—</span>
                            )}
                          </td>
                          <td className="py-3 px-3 text-center">
                            <button
                              onClick={() => setAssignUser(r)}
                              className="inline-flex items-center gap-1 px-2.5 py-1.5 text-[11px] font-bold text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 rounded-lg transition cursor-pointer"
                            >
                              <UserCog className="w-3 h-3" /> Kelola
                            </button>
                          </td>
                        </tr>
                      );
                    });
                  })()}
                </tbody>
              </table>
            </div>

            {/* Pagination footer */}
            {filteredRoster.length >= 25 && (() => {
              const totalRosterPages = Math.max(1, Math.ceil(filteredRoster.length / rosterPageSize));
              return (
                <div className="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-100 dark:border-slate-800 text-xs">
                  <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                    <span>
                      Menampilkan <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">
                        {Math.min((rosterPage - 1) * rosterPageSize + 1, filteredRoster.length)} - {Math.min(rosterPage * rosterPageSize, filteredRoster.length)}
                      </strong> dari <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">{filteredRoster.length}</strong> karyawan
                    </span>
                    <span className="hidden sm:inline">•</span>
                    <div className="flex items-center gap-1.5">
                      <span className="hidden sm:inline">Per hal:</span>
                      <select
                        value={rosterPageSize}
                        onChange={(e) => {
                          setRosterPageSize(Number(e.target.value));
                          setRosterPage(1);
                        }}
                        className="py-0.5 px-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 focus:outline-none cursor-pointer"
                      >
                        <option value={25}>25</option>
                        <option value={50}>50</option>
                        <option value={100}>100</option>
                      </select>
                    </div>
                  </div>

                  <div className="flex items-center gap-1.5">
                    <button
                      type="button"
                      onClick={() => setRosterPage(1)}
                      disabled={rosterPage === 1}
                      className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                      title="Halaman Pertama"
                    >
                      «
                    </button>
                    <button
                      type="button"
                      onClick={() => setRosterPage(p => Math.max(1, p - 1))}
                      disabled={rosterPage === 1}
                      className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                      title="Halaman Sebelumnya"
                    >
                      ‹
                    </button>
                    <span className="px-2 font-semibold text-slate-700 dark:text-slate-300">
                      Hal <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">{rosterPage}</span> / <span className="font-mono">{totalRosterPages}</span>
                    </span>
                    <button
                      type="button"
                      onClick={() => setRosterPage(p => Math.min(totalRosterPages, p + 1))}
                      disabled={rosterPage === totalRosterPages}
                      className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                      title="Halaman Berikutnya"
                    >
                      ›
                    </button>
                    <button
                      type="button"
                      onClick={() => setRosterPage(totalRosterPages)}
                      disabled={rosterPage === totalRosterPages}
                      className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
                      title="Halaman Terakhir"
                    >
                      »
                    </button>
                  </div>
                </div>
              );
            })()}
          </div>
        </div>
      )}

      {/* ═══════════ TAB: TEMPLATES ═══════════ */}
      {tab === 'templates' && (
        <div className="space-y-4">
          {/* Toolbar: filter cabang + tombol tambah */}
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm p-4">
            <div className="flex flex-col sm:flex-row sm:items-center gap-3">
              {/* Filter cabang */}
              <div className="flex-1 flex items-center gap-2">
                <Building2 className="w-4 h-4 text-slate-400 shrink-0" />
                <select
                  value={templateBranch}
                  onChange={(e) => setTemplateBranch(e.target.value)}
                  className="flex-1 max-w-xs text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                >
                  <option value="">Semua cabang</option>
                  {offices.map((o) => (
                    <option key={o.id} value={o.id}>{o.office_name}</option>
                  ))}
                </select>
                {templateBranch && (
                  <button
                    onClick={() => setTemplateBranch('')}
                    className="flex items-center gap-1 text-xs font-semibold text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition cursor-pointer"
                    title="Hapus filter cabang"
                  >
                    <X className="w-3.5 h-3.5" />
                  </button>
                )}
                <span className="text-[11px] text-slate-400 dark:text-slate-500 shrink-0">
                  {filteredShifts.length}
                  {templateBranch ? ` / ${shifts.length}` : ''} template
                </span>
              </div>
              {/* Tombol tambah + refresh */}
              <div className="flex items-center gap-2">
                <button
                  onClick={() => loadShifts(true)}
                  disabled={loadingShifts}
                  className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800 bg-white dark:bg-slate-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 rounded-lg transition cursor-pointer"
                >
                  <RefreshCw className={`w-3.5 h-3.5 ${loadingShifts ? 'animate-spin' : ''}`} /> Refresh
                </button>
                <button
                  onClick={() => setShiftForm({ editing: null })}
                  disabled={offices.length === 0}
                  className="self-start sm:self-auto flex items-center gap-1.5 px-4 py-2 text-xs font-bold bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 dark:disabled:bg-slate-800 text-white rounded-lg transition cursor-pointer"
                >
                  <Plus className="w-3.5 h-3.5" /> Tambah Shift
                </button>
              </div>
            </div>

            {/* Info: shift company-wide selalu tampil di semua filter */}
            {templateBranch && (
              <p className="text-[10px] text-slate-400 dark:text-slate-500 mt-2 flex items-center gap-1">
                <Info className="w-3 h-3" />
                Menampilkan shift cabang ini + shift berlaku untuk semua cabang.
              </p>
            )}
          </div>

          {loadingShifts ? (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={`skel-shift-${i}`} className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm p-4 animate-pulse">
                  <div className="flex items-start justify-between gap-2 mb-3">
                    <div className="space-y-2.5 flex-1">
                      <div className="flex items-center gap-2">
                        <div className="w-3.5 h-3.5 rounded-full bg-slate-200 dark:bg-slate-700" />
                        <div className="h-4 w-32 bg-slate-200 dark:bg-slate-700 rounded" />
                        <div className="h-3.5 w-10 bg-slate-200 dark:bg-slate-700 rounded-full" />
                      </div>
                      <div className="h-3 w-24 bg-slate-200 dark:bg-slate-700 rounded" />
                      <div className="h-3 w-40 bg-slate-200 dark:bg-slate-700 rounded" />
                    </div>
                    <div className="flex gap-1 shrink-0">
                      <div className="w-6 h-6 bg-slate-200 dark:bg-slate-700 rounded-lg" />
                      <div className="w-6 h-6 bg-slate-200 dark:bg-slate-700 rounded-lg" />
                    </div>
                  </div>
                  <div className="grid grid-cols-7 gap-1">
                    {Array.from({ length: 7 }).map((_, j) => (
                      <div key={j} className="h-[42px] bg-slate-100 dark:bg-slate-800 rounded-lg border border-slate-200/50 dark:border-slate-700/50" />
                    ))}
                  </div>
                </div>
              ))}
            </div>
          ) : filteredShifts.length === 0 ? (
            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm py-16 text-center">
              <div className="flex flex-col items-center gap-2 text-slate-400 dark:text-slate-500">
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
                        className="ml-1 text-indigo-500 hover:underline cursor-pointer"
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
                <div key={s.id} className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm p-4 space-y-3">
                  {/* Header Cabang Kantor di Paling Atas Card */}
                  <div className="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                    <span className="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-700 dark:text-indigo-300 bg-indigo-50/80 dark:bg-indigo-950/60 px-2.5 py-1 rounded-md">
                      <Building2 className="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400" />
                      {s.office?.office_name ?? 'Semua Cabang'}
                    </span>
                    {s.is_active ? (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400">Aktif</span>
                    ) : (
                      <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400">Nonaktif</span>
                    )}
                  </div>

                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        {/* Bulatan warna shift untuk kalender */}
                        <span
                          className="w-3.5 h-3.5 rounded-full shrink-0 border border-black/10 dark:border-white/20"
                          style={{ backgroundColor: s.color ?? '#6366f1' }}
                        />
                        <p className="font-bold text-sm text-slate-800 dark:text-slate-100">{s.name}</p>
                        {isNightShift(s) && (
                          <span className="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800/40">
                            🌙 Shift Malam
                          </span>
                        )}
                      </div>
                      {s.description && <p className="text-[11px] text-slate-400 dark:text-slate-500 mt-1">{s.description}</p>}
                    </div>
                    <div className="flex items-center gap-1 shrink-0">
                      <button
                        onClick={() => handleToggleActive(s)}
                        className="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none cursor-pointer"
                        style={{ backgroundColor: s.is_active ? '#10b981' : '#64748b' }}
                        title={s.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                      >
                        <span
                          className={`inline-block h-3.5 w-3.5 rounded-full bg-white shadow-sm transition-transform ${s.is_active ? 'translate-x-[18px]' : 'translate-x-[3px]'}`}
                        />
                      </button>
                      <button
                        onClick={() => setShiftUsersView(s)}
                        className="inline-flex items-center gap-1 px-2 py-1.5 text-[10px] font-bold text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 rounded-lg transition cursor-pointer"
                        title="Lihat karyawan yang terkait shift ini"
                      >
                        <Users className="w-3 h-3" /> Lihat Karyawan
                      </button>
                      <button
                        onClick={() => setShiftForm({ editing: s })}
                        className="p-1.5 rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer"
                        title="Ubah"
                      >
                        <Pencil className="w-3.5 h-3.5" />
                      </button>
                      <button
                        onClick={() => handleDeleteShift(s)}
                        className="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition cursor-pointer"
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
                          className={`rounded-lg p-1.5 text-center border ${off ? 'bg-rose-50/60 dark:bg-rose-950/30 border-rose-100 dark:border-rose-900/40' : sch?.is_field ? 'bg-emerald-50/70 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800' : sch?.is_wfh ? 'bg-cyan-50/70 dark:bg-cyan-950/40 border-cyan-200 dark:border-cyan-800' : 'bg-indigo-50/60 dark:bg-indigo-950/40 border-indigo-100 dark:border-indigo-800'
                            }`}
                          title={off ? 'Libur' : sch?.is_field ? `Lapangan: ${hhmm(sch?.work_start_time)}–${hhmm(sch?.work_end_time)}` : sch?.is_wfh ? `WFH: ${hhmm(sch?.work_start_time)}–${hhmm(sch?.work_end_time)}` : `${hhmm(sch?.work_start_time)}–${hhmm(sch?.work_end_time)}`}
                        >
                          <p className="text-[9px] font-bold text-slate-500 dark:text-slate-400">{DAY_SHORT[d]}</p>
                          {off ? (
                            <p className="text-[9px] text-rose-400 dark:text-rose-400 font-semibold mt-0.5">Off</p>
                          ) : (
                            <>
                              <p className={`text-[9px] font-mono font-semibold mt-0.5 leading-tight ${sch?.is_field ? 'text-emerald-800 dark:text-emerald-300' : sch?.is_wfh ? 'text-cyan-800 dark:text-cyan-300' : 'text-indigo-700 dark:text-indigo-300'}`}>
                                {hhmm(sch?.work_start_time)}
                              </p>
                              <p className="text-[9px] font-mono text-slate-400 dark:text-slate-500 leading-tight flex items-center justify-center gap-0.5">
                                {hhmm(sch?.work_end_time)}
                                {sch && sch.is_field ? (
                                  <span className="text-[8px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-100 dark:bg-emerald-950 px-1 rounded">FLD</span>
                                ) : sch && sch.is_wfh ? (
                                  <span className="text-[8px] font-bold text-cyan-600 dark:text-cyan-400 bg-cyan-100 dark:bg-cyan-950 px-1 rounded">WFH</span>
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

      {/* ═══════════ TAB: POLA ROTASI SIKLUS (ROLLING CYCLES) ═══════════ */}
      {tab === 'patterns' && (
        <div className="space-y-4">
          {/* Toolbar Pola Rotasi */}
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
              <h2 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <Repeat className="w-4 h-4 text-indigo-500" /> Pola Rotasi Shift Berulang (Rolling Cycle)
              </h2>
              <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                Jadwal siklis berulang (misal: 4 hari kerja 2 hari libur) yang terus berputar deterministik otomatis tanpa perlu penugasan ulang mingguan.
              </p>
            </div>
            <div className="flex items-center gap-2 shrink-0 flex-wrap">
              {/* Filter Cabang Pola Rotasi */}
              <div className="flex items-center gap-1.5">
                <Building2 className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                <select
                  value={patternBranch}
                  onChange={(e) => setPatternBranch(e.target.value)}
                  className="text-xs px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-1 focus:ring-indigo-500 cursor-pointer"
                >
                  <option value="">Semua Cabang</option>
                  {offices.map((o) => (
                    <option key={o.id} value={String(o.id)}>
                      {o.office_name}
                    </option>
                  ))}
                </select>
              </div>

              <button
                onClick={() => loadPatterns(true)}
                disabled={loadingPatterns}
                className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/80 rounded-xl transition cursor-pointer"
              >
                <RefreshCw className={`w-3.5 h-3.5 ${loadingPatterns ? 'animate-spin' : ''}`} /> Refresh
              </button>
              <button
                onClick={() => setPatternForm({ editing: null })}
                className="flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl transition cursor-pointer shadow-sm shadow-indigo-200 dark:shadow-none"
              >
                <Plus className="w-3.5 h-3.5" /> Tambah Pola Rotasi
              </button>
            </div>
          </div>

          {/* Info: pola company-wide selalu tampil di semua filter */}
          {patternBranch && (
            <p className="text-[10px] text-slate-400 dark:text-slate-500 mt-0 flex items-center gap-1">
              <Info className="w-3 h-3" />
              Menampilkan pola rotasi cabang ini + pola yang berlaku untuk semua cabang.
            </p>
          )}

          {/* Daftar Pola Rotasi */}
          {loadingPatterns ? (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={`skel-pat-${i}`} className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 p-5 space-y-3 animate-pulse">
                  <div className="flex justify-between items-center">
                    <div className="h-4 w-36 bg-slate-200 dark:bg-slate-700 rounded" />
                    <div className="h-5 w-20 bg-slate-200 dark:bg-slate-700 rounded-full" />
                  </div>
                  <div className="h-3 w-48 bg-slate-200 dark:bg-slate-700 rounded" />
                  <div className="flex gap-1.5 pt-2">
                    {Array.from({ length: 6 }).map((_, j) => (
                      <div key={j} className="h-7 w-12 bg-slate-200 dark:bg-slate-700 rounded-lg" />
                    ))}
                  </div>
                </div>
              ))}
            </div>
          ) : filteredPatterns.length === 0 ? (
            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm p-12 text-center">
              <div className="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-100 dark:border-indigo-900 flex items-center justify-center text-indigo-500 mx-auto mb-3">
                <Repeat className="w-6 h-6" />
              </div>
              <p className="font-bold text-slate-800 dark:text-slate-100 text-sm">
                {patterns.length === 0 ? 'Belum Ada Pola Rotasi Shift' : 'Tidak Ada Pola Rotasi untuk Cabang Ini'}
              </p>
              <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-sm mx-auto">
                {patterns.length === 0 ? (
                  'Buat pola rotasi siklik berulang (seperti Pola 4-2, 3-1, atau 2-2-2) agar jadwal regu kerja terus berputar otomatis tanpa intervensi HR tiap minggu.'
                ) : (
                  <>
                    Cabang yang dipilih belum memiliki pola rotasi.
                    <button
                      onClick={() => setPatternBranch('')}
                      className="ml-1 text-indigo-500 hover:underline cursor-pointer font-semibold"
                    >
                      Tampilkan semua
                    </button>
                  </>
                )}
              </p>
              {patterns.length === 0 && (
                <button
                  onClick={() => setPatternForm({ editing: null })}
                  className="mt-4 inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl transition cursor-pointer shadow-sm shadow-indigo-200 dark:shadow-none"
                >
                  <Plus className="w-3.5 h-3.5" /> Buat Pola Rotasi Pertama
                </button>
              )}
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {filteredPatterns.map((p) => {
                const sortedItems = [...(p.items || [])].sort((a, b) => a.day_order - b.day_order);
                const workDays = sortedItems.filter((it) => !it.is_off).length;
                const offDays = sortedItems.filter((it) => it.is_off).length;

                return (
                  <div
                    key={p.id}
                    className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 shadow-xs hover:shadow-md transition p-5 flex flex-col justify-between gap-4"
                  >
                    <div className="space-y-3">
                      {/* Header Cabang Kantor di Paling Atas Card */}
                      <div className="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-800">
                        <span className="inline-flex items-center gap-1 text-[11px] font-bold text-indigo-700 dark:text-indigo-300 bg-indigo-50/80 dark:bg-indigo-950/60 px-2.5 py-1 rounded-md">
                          <Building2 className="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400" />
                          {p.office?.office_name ? `Cabang ${p.office.office_name}` : 'Semua Cabang'}
                        </span>
                        {p.is_active ? (
                          <span className="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                            <span className="w-1 h-1 rounded-full bg-emerald-500" /> Aktif
                          </span>
                        ) : (
                          <span className="inline-flex items-center text-[9px] font-bold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400">
                            Nonaktif
                          </span>
                        )}
                      </div>

                      {/* Top Header */}
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <div className="flex items-center gap-2">
                            <h3 className="font-bold text-sm text-slate-800 dark:text-slate-100">{p.name}</h3>
                          </div>
                          {p.description && (
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-1 leading-relaxed">
                              {p.description}
                            </p>
                          )}
                        </div>

                        <span className="shrink-0 inline-flex items-center gap-1 text-xs font-bold px-2.5 py-1 rounded-full bg-indigo-50 dark:bg-indigo-950/70 text-indigo-600 dark:text-indigo-400 border border-indigo-200/70 dark:border-indigo-800">
                          Siklus {p.cycle_days} Hari
                        </span>
                      </div>

                      {/* Summary hari kerja vs libur */}
                      <div className="flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                        <span className="font-semibold text-indigo-600 dark:text-indigo-400">{workDays} Hari Kerja</span>
                        <span>·</span>
                        <span className="font-semibold text-amber-600 dark:text-amber-400">{offDays} Hari Libur</span>
                      </div>

                      {/* Visual Timeline Strip Per Hari */}
                      <div className="flex flex-wrap gap-1.5 pt-1">
                        {sortedItems.map((it) => (
                          <div
                            key={it.day_order}
                            className={`p-2 rounded-xl text-center border min-w-[70px] flex-1 sm:flex-none transition ${
                              it.is_off
                                ? 'bg-amber-50/60 dark:bg-amber-950/30 border-amber-200/70 dark:border-amber-900/40 text-amber-800 dark:text-amber-300'
                                : 'bg-indigo-50/60 dark:bg-indigo-950/40 border-indigo-200/70 dark:border-indigo-800 text-indigo-700 dark:text-indigo-300'
                            }`}
                          >
                            <p className="text-[10px] font-bold">H{it.day_order}</p>
                            <p className="text-[11px] font-semibold truncate max-w-[90px]">
                              {it.is_off ? 'Libur' : (it.shift?.name ? it.shift.name : 'Kerja')}
                            </p>
                            {!it.is_off && it.work_start_time && (
                              <p className="text-[9px] opacity-80 mt-0.5">
                                {it.work_start_time.slice(0, 5)}–{it.work_end_time?.slice(0, 5)}
                              </p>
                            )}
                          </div>
                        ))}
                      </div>
                    </div>

                    {/* Action buttons */}
                    <div className="flex items-center justify-end gap-1.5 pt-3 border-t border-slate-100 dark:border-slate-800">
                      <button
                        onClick={() => setPatternForm({ editing: p })}
                        className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer"
                      >
                        <Pencil className="w-3.5 h-3.5 text-slate-400" /> Edit
                      </button>
                      <button
                        onClick={() => handleDeletePattern(p)}
                        className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition cursor-pointer"
                      >
                        <Trash2 className="w-3.5 h-3.5" /> Hapus
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}

      {/* ═══════════ TAB: KALENDER ═══════════ */}
      {tab === 'kalender' && (
        <div className="space-y-4">
          {/* Toolbar: navigasi bulan + filter cabang */}
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm p-4 flex flex-col sm:flex-row sm:items-center gap-3">
            {/* Navigasi bulan */}
            <div className="flex items-center gap-2 shrink-0">
              <button
                onClick={() => {
                  const d = new Date(calYear, calMonth - 2, 1);
                  setCalMonth(d.getMonth() + 1);
                  setCalYear(d.getFullYear());
                }}
                className="p-2 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition text-slate-600 dark:text-slate-300 cursor-pointer"
              >
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" /></svg>
              </button>
              <span className="text-sm font-bold text-slate-800 dark:text-slate-100 w-36 text-center">
                {new Date(calYear, calMonth - 1, 1).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' })}
              </span>
              <button
                onClick={() => {
                  const d = new Date(calYear, calMonth, 1);
                  setCalMonth(d.getMonth() + 1);
                  setCalYear(d.getFullYear());
                }}
                className="p-2 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition text-slate-600 dark:text-slate-300 cursor-pointer"
              >
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>
              </button>
              <button
                onClick={() => { setCalMonth(new Date().getMonth() + 1); setCalYear(new Date().getFullYear()); }}
                className="ml-1 px-3 py-1.5 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 transition cursor-pointer"
              >
                Bulan Ini
              </button>
            </div>
            {/* Filter cabang */}
            <div className="flex-1 flex items-center gap-2">
              <select
                value={calBranch}
                onChange={(e) => setCalBranch(e.target.value)}
                className="flex-1 max-w-xs text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-lg focus:ring-1 focus:ring-indigo-400 focus:outline-none bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
              >
                <option value="">Semua cabang</option>
                {offices.map((o) => <option key={o.id} value={o.id}>{o.office_name}</option>)}
              </select>
              <button
                onClick={() => loadCalendar(true)}
                className="flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border border-indigo-200 dark:border-indigo-800 bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 rounded-lg transition cursor-pointer"
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
                  <span key={s.id} className="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border border-black/10 dark:border-white/10"
                    style={{ backgroundColor: (s.color ?? '#6366f1') + '20', color: s.color ?? '#6366f1', borderColor: (s.color ?? '#6366f1') + '40' }}>
                    <span className="w-2 h-2 rounded-full" style={{ backgroundColor: s.color ?? '#6366f1' }} />
                    {s.name}
                  </span>
                ))}
                {/* Keterangan simbol libur & lintas hari */}
                <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-1 rounded-full border border-slate-300 dark:border-slate-700 bg-slate-100/60 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                  <span className="w-2 h-2 rounded-full bg-slate-500" />
                  Libur / Off
                </span>
                <span className="inline-flex items-center gap-1 text-[11px] font-semibold px-2.5 py-1 rounded-full bg-violet-50 dark:bg-violet-950/60 text-violet-600 dark:text-violet-300 border border-violet-200 dark:border-violet-800">
                  <Moon className="w-3 h-3" /> = Lintas hari
                </span>
              </div>
            );
          })()}

          {/* Grid kalender */}
          <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 shadow-sm overflow-hidden">
            {/* Header hari */}
            <div className="grid grid-cols-7 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40">
              {['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'].map((d, i) => (
                <div key={d} className={`py-3 text-center text-[11px] font-bold tracking-wide ${i === 0 || i === 6 ? 'text-rose-500 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400'
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
                    className={`min-h-[110px] border-b border-r border-slate-100 dark:border-slate-800 p-1.5 flex flex-col gap-1 ${!isCurrentMonth ? 'bg-slate-50/40 dark:bg-slate-950/60' : isWeekend ? 'bg-rose-50/20 dark:bg-rose-950/10' : 'bg-white dark:bg-slate-900'
                      } ${isToday ? 'ring-2 ring-inset ring-indigo-400 dark:ring-indigo-500' : ''}`}
                  >
                    {/* Nomor tanggal */}
                    <div className="flex items-center justify-between px-0.5">
                      <span className={`text-xs font-bold leading-none ${!isCurrentMonth ? 'text-slate-300 dark:text-slate-700'
                        : isToday ? 'w-5 h-5 rounded-full bg-indigo-500 text-white flex items-center justify-center text-[10px]'
                          : isWeekend ? 'text-rose-500 dark:text-rose-400'
                            : 'text-slate-700 dark:text-slate-300'
                        }`}>
                        {isCurrentMonth ? dayNum : ''}
                      </span>
                    </div>

                    {/* Shift badge per tanggal */}
                    <div className="flex flex-col gap-0.5 flex-1">
                      {loadingCal && isCurrentMonth ? (
                        Array.from({ length: 2 }).map((_, idx) => (
                          <div key={`cal-skel-${idx}`} className="w-full h-4 bg-slate-200 dark:bg-slate-700 rounded animate-pulse" />
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
                              className="w-full text-left rounded px-1.5 py-1 text-[10px] font-bold leading-tight transition hover:brightness-90 active:scale-95 cursor-pointer"
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
                          className="text-[10px] text-slate-400 dark:text-slate-500 font-semibold hover:text-slate-600 dark:hover:text-slate-300 text-left px-1.5 cursor-pointer"
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
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm px-4"
          onClick={(e) => { if (e.target === e.currentTarget) setCalDetail(null); }}
        >
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl shadow-2xl w-full max-w-md max-h-[80vh] overflow-y-auto animate-in fade-in slide-in-from-bottom-4 duration-200">
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 sticky top-0 bg-white dark:bg-slate-900 z-10">
              <div>
                <p className="font-bold text-sm text-slate-800 dark:text-slate-100">
                  {new Date(calDetail.date + 'T00:00:00').toLocaleDateString('id-ID', {
                    weekday: 'long', day: '2-digit', month: 'long', year: 'numeric',
                  })}
                </p>
                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{calDetail.entries.length} shift aktif</p>
              </div>
              <button onClick={() => setCalDetail(null)} className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 transition cursor-pointer">
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
                          className="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-violet-100 dark:bg-violet-950/60 text-violet-700 dark:text-violet-300 shrink-0"
                          title="Shift ini melewati tengah malam (berakhir keesokan hari)"
                        >
                          <Moon className="w-3 h-3" /> Lintas hari
                        </span>
                      )}
                      <span className="ml-auto text-[11px] font-semibold text-slate-500 dark:text-slate-400">{entry.user_count} karyawan</span>
                    </div>
                    {/* Jam kerja shift */}
                    <div className="flex items-center gap-1.5 px-3 py-1.5 bg-slate-50/60 dark:bg-slate-800/60 border-b border-slate-100 dark:border-slate-800">
                      <Clock className="w-3 h-3 text-slate-400" />
                      {isOff ? (
                        <span className="text-[11px] font-medium text-slate-500 dark:text-slate-400 italic">Hari Libur (Tidak ada jam kerja)</span>
                      ) : (
                        <>
                          <span className="text-[11px] font-mono text-slate-600 dark:text-slate-300">{jamKerja}</span>
                          {crossDay && (
                            <span className="text-[10px] text-violet-500 dark:text-violet-400 font-semibold">— selesai esok hari</span>
                          )}
                        </>
                      )}
                    </div>
                    {/* Daftar karyawan */}
                    <div className="divide-y divide-slate-50 dark:divide-slate-800">
                      {entry.users.map((u) => {
                        const av = avatarFor(u.name);
                        return (
                          <div key={u.user_id} className="flex items-center gap-2.5 px-3 py-2 bg-white dark:bg-slate-900">
                            <div className={`w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-bold shrink-0 ${av.bg} ${av.text}`}>
                              {initialsOf(u.name)}
                            </div>
                            <div className="min-w-0">
                              <p className="text-xs font-semibold text-slate-700 dark:text-slate-200 truncate">{u.name}</p>
                              {u.department && <p className="text-[10px] text-slate-400 dark:text-slate-500 truncate">{u.department}</p>}
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
          patterns={patterns}
          onClose={() => setAssignUser(null)}
          onSaved={() => { loadRoster(); }}
        />
      )}

      {showBulk && (
        <BulkAssignModal
          userIds={Array.from(selected)}
          userNames={selectedNames}
          shifts={shifts}
          patterns={patterns}
          selectedBranchIds={selectedBranchIds}
          onClose={() => { setShowBulk(false); }}
          onSaved={() => { loadRoster(); }}
        />
      )}

      {patternForm && (
        <ShiftPatternFormModal
          shifts={shifts}
          offices={offices}
          editing={patternForm.editing}
          onClose={() => setPatternForm(null)}
          onSaved={() => {
            loadPatterns(true);
            onAddAuditLog?.(patternForm.editing ? 'Pola rotasi diperbarui' : 'Pola rotasi dibuat', '', 'bg-indigo-500');
          }}
        />
      )}

      {shiftUsersView && (
        <ShiftUsersModal
          shift={shiftUsersView}
          onClose={() => setShiftUsersView(null)}
        />
      )}

      {/* Confirmation Dialog helper */}
      {confirmDialog && (
        <ConfirmationDialog
          isOpen={confirmDialog.isOpen}
          onClose={() => setConfirmDialog(null)}
          onConfirm={() => {
            confirmDialog.onConfirm();
            setConfirmDialog(null);
          }}
          title={confirmDialog.title}
          message={confirmDialog.message}
          confirmText={confirmDialog.confirmText}
          cancelText={confirmDialog.cancelText}
          type={confirmDialog.type}
          isLoading={confirmDialog.isLoading}
        />
      )}
    </div>
  );
}
