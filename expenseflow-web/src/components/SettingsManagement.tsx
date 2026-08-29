import React, { useState, useCallback, useMemo } from 'react';
import {
  Building2,
  Settings,
  Clock,
  MapPin,
  Plus,
  Trash2,
  CalendarDays,
  X,
  AlertTriangle,
  Banknote,
  Coins,
  Percent,
  Calculator,
  CalendarClock,
  ShieldCheck,
  HelpCircle,
  TrendingUp,
  CreditCard,
} from 'lucide-react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { MapContainer, TileLayer, Marker, useMapEvents } from 'react-leaflet';
import markerIconUrl from 'leaflet/dist/images/marker-icon.png';
import markerIcon2xUrl from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadowUrl from 'leaflet/dist/images/marker-shadow.png';
import { attendanceApi } from '../services/endpoints';
import { AppSettings } from '../types';
import { SettingsView } from './SettingsView';

// Fix Leaflet default marker icon (Vite menghapus path asset saat build)
delete (L.Icon.Default.prototype as any)._getIconUrl;
L.Icon.Default.mergeOptions({
  iconUrl: markerIconUrl,
  iconRetinaUrl: markerIcon2xUrl,
  shadowUrl: markerShadowUrl,
});

// ─── Sub-komponen: map click handler (harus anak dari MapContainer) ──
const MapClickHandler: React.FC<{ onMapClick: (lat: number, lng: number) => void }> = ({ onMapClick }) => {
  useMapEvents({
    click(e) {
      onMapClick(
        parseFloat(e.latlng.lat.toFixed(6)),
        parseFloat(e.latlng.lng.toFixed(6)),
      );
    },
  });
  return null;
};

// ─── Sub-komponen: peta pilih lokasi ─────────────────────────
const LocationPicker: React.FC<{
  lat: number | string;
  lng: number | string;
  onChange: (lat: number, lng: number) => void;
}> = ({ lat, lng, onChange }) => {
  const hasCoords =
    lat !== '' && lng !== '' && !isNaN(Number(lat)) && !isNaN(Number(lng));
  const center: [number, number] = hasCoords
    ? [Number(lat), Number(lng)]
    : [-2.5, 118];
  const zoom = hasCoords ? 15 : 5;

  return (
    <div className="space-y-1.5">
      <div
        className="rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 cursor-crosshair"
        style={{ height: 220 }}
      >
        <MapContainer
          center={center}
          zoom={zoom}
          style={{ height: '100%', width: '100%' }}
          scrollWheelZoom
        >
          <TileLayer
            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          />
          <MapClickHandler onMapClick={onChange} />
          {hasCoords && (
            <Marker
              position={[Number(lat), Number(lng)]}
              draggable
              eventHandlers={{
                dragend(e) {
                  const ll = e.target.getLatLng();
                  onChange(
                    parseFloat(ll.lat.toFixed(6)),
                    parseFloat(ll.lng.toFixed(6)),
                  );
                },
              }}
            />
          )}
        </MapContainer>
      </div>
      {hasCoords ? (
        <p className="text-[10px] text-slate-400 font-mono text-center">
          {Number(lat).toFixed(6)}, {Number(lng).toFixed(6)} — klik peta atau drag marker untuk ubah
        </p>
      ) : (
        <p className="text-[10px] text-slate-400 text-center italic">
          Klik pada peta untuk menentukan lokasi kantor
        </p>
      )}
    </div>
  );
};

// ─── Master Data Konfigurasi Payroll Cabang (Roadmap Bagian C) ───
const JKK_TIERS: Record<string, { label: string; rate: string; desc: string; color: string; badgeBg: string; badgeText: string }> = {
  very_low: {
    label: 'Kelompok I — Sangat Rendah',
    rate: '0.24%',
    desc: 'Kantor administrasi, IT, jasa keuangan, konsultansi',
    color: 'emerald',
    badgeBg: 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800',
    badgeText: 'text-emerald-700 dark:text-emerald-400',
  },
  low: {
    label: 'Kelompok II — Rendah (Standar)',
    rate: '0.54%',
    desc: 'Perdagangan besar/eceran, resto, perhotelan, logistik ringan',
    color: 'blue',
    badgeBg: 'bg-blue-50 dark:bg-blue-950/40 border-blue-200 dark:border-blue-800',
    badgeText: 'text-blue-700 dark:text-blue-400',
  },
  medium: {
    label: 'Kelompok III — Sedang',
    rate: '0.89%',
    desc: 'Manufaktur ringan, garmen, perakitan elektronik',
    color: 'amber',
    badgeBg: 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800',
    badgeText: 'text-amber-700 dark:text-amber-400',
  },
  high: {
    label: 'Kelompok IV — Tinggi',
    rate: '1.27%',
    desc: 'Transportasi berat, pergudangan industri, konstruksi menengah',
    color: 'orange',
    badgeBg: 'bg-orange-50 dark:bg-orange-950/40 border-orange-200 dark:border-orange-800',
    badgeText: 'text-orange-700 dark:text-orange-400',
  },
  very_high: {
    label: 'Kelompok V — Sangat Tinggi',
    rate: '1.74%',
    desc: 'Pertambangan, migas, konstruksi berat, kimia berbahaya',
    color: 'rose',
    badgeBg: 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-800',
    badgeText: 'text-rose-700 dark:text-rose-400',
  },
};

const PRORATE_OPTIONS = [
  {
    value: 'working_days',
    title: 'Hari Kerja Aktual',
    subtitle: 'Standar Depnaker',
    badge: 'Rekomendasi',
    desc: 'Pembagi menggunakan jumlah hari kerja operasional bulan berjalan (misal 21 atau 22 hari).',
    formula: '(Gaji Pokok / Hari Kerja Efektif) × Hari Hadir',
  },
  {
    value: 'calendar_days',
    title: 'Hari Kalender',
    subtitle: 'Total Hari Sebulan',
    badge: 'All-in',
    desc: 'Pembagi menggunakan total hari kalender sebulan (28–31 hari).',
    formula: '(Gaji Pokok / Jumlah Hari Kalender) × Hari Aktif',
  },
  {
    value: 'fixed_21',
    title: 'Pembagi Tetap 21 Hari',
    subtitle: 'Konstanta 21 Hari',
    badge: '5 Hari Kerja',
    desc: 'Pembagi diseragamkan tetap 21 hari kerja setiap bulan tanpa terpengaruh tanggal merah.',
    formula: '(Gaji Pokok / 21) × Hari Hadir',
  },
  {
    value: 'fixed_25',
    title: 'Pembagi Tetap 25 Hari',
    subtitle: 'Konstanta 25 Hari',
    badge: '6 Hari Kerja',
    desc: 'Pembagi diseragamkan tetap 25 hari kerja setiap bulan, cocok untuk pola 6 hari kerja/minggu.',
    formula: '(Gaji Pokok / 25) × Hari Hadir',
  },
];

const LATE_DEDUCTION_TYPES = [
  {
    value: 'none',
    label: 'Tidak Ada Potongan (Pencatatan Saja)',
    desc: 'Keterlambatan tetap tercatat di laporan presensi namun tidak memotong nominal gaji pokok.',
  },
  {
    value: 'flat_nominal',
    label: 'Nominal Flat per Kejadian (Rp/Hari)',
    desc: 'Dipotong nominal tetap setiap kali karyawan terlambat melewati batas toleransi masuk.',
  },
  {
    value: 'per_minute',
    label: 'Nominal per Menit Keterlambatan (Rp/Menit)',
    desc: 'Dipotong proporsional per menit keterlambatan yang terjadi melewati toleransi.',
  },
  {
    value: 'tiered',
    label: 'Skema Bertingkat (Tiered)',
    desc: 'Tarif berjenjang (contoh: 1-15 menit toleransi gratis, 16-30 menit tarif A, >30 menit tarif B).',
  },
];

// ─── Sub-komponen: CRUD kantor presensi ──────────────────────
const OfficesTab: React.FC<{
  offices: any[];
  reload: () => Promise<void>;
  onAddAuditLog: (t: string, d: string, b: string) => void;
  onError: (e: unknown, f: string) => void;
}> = ({ offices, reload, onAddAuditLog, onError }) => {
  const empty = {
    office_name: '',
    office_latitude: '',
    office_longitude: '',
    radius_meters: 100,
    work_start_time: '08:00',
    work_end_time: '17:00',
    work_days: [1, 2, 3, 4, 5] as number[],
    late_tolerance_minutes: 15,
    late_checkin_cutoff_minutes: '' as number | '',
    wfh_checkin_window_minutes: 120,
    overtime_enabled: true,
    min_overtime_minutes: 30,
    early_leave_enabled: true,
    early_leave_tolerance_minutes: 30,
    enforce_weekly_hours: false,
    max_weekly_hours: 40,
    shift_notice_days: 0,
    checkout_reminder_minutes: 30,
    auto_checkout_grace_minutes: 60,
    default_leave_quota: 12,
    leave_reset_date: '',
    custom_schedules: {} as Record<number, { start: string; end: string }>,
    // ─── Bidang Payroll Cabang (Roadmap Bagian C) ───
    umr_amount: 0,
    payroll_cutoff_date: 25,
    payroll_payment_date: 1,
    prorate_formula: 'working_days',
    late_deduction_type: 'none',
    late_deduction_amount: 0,
    overtime_rate_type: 'depnaker',
    overtime_flat_rate: 0,
    jkk_tier: 'low',
  };
  const [showForm, setShowForm] = useState(false);
  const [modalTab, setModalTab] = useState<'attendance' | 'payroll'>('attendance');
  const [editId, setEditId] = useState<number | null>(null);
  const [form, setForm] = useState<any>(empty);
  const [saving, setSaving] = useState(false);
  const [validationError, setValidationError] = useState<string | null>(null);
  const [expandedDays, setExpandedDays] = useState<number[]>([]);
  // Dialog konfirmasi reset saldo: tanggal reset yang anniversary-nya sudah lewat
  // tahun ini akan langsung memicu reset saldo TAHUN BERJALAN sekali.
  const [confirmReset, setConfirmReset] = useState<string | null>(null);
  // Nilai leave_reset_date saat form dibuka (untuk mendeteksi perubahan)
  const [originalLeaveReset, setOriginalLeaveReset] = useState<string>('');
  // Gerbang konfirmasi field berbahaya (backend 422 requires_confirmation):
  // HRD wajib mengetik "SIMPAN" sebelum perubahan aturan presensi disimpan.
  const [dangerousFields, setDangerousFields] = useState<string[] | null>(null);
  const [confirmText, setConfirmText] = useState('');

  // Label ramah untuk field berbahaya yang dikirim backend
  const DANGEROUS_LABELS: Record<string, string> = {
    work_start_time: 'Jam Masuk',
    work_end_time: 'Jam Pulang',
    work_days: 'Hari Kerja',
    custom_schedules: 'Jam Kerja Khusus per Hari',
    office_latitude: 'Lokasi Kantor (Latitude)',
    office_longitude: 'Lokasi Kantor (Longitude)',
    radius_meters: 'Radius Presensi',
    late_tolerance_minutes: 'Toleransi Telat',
    late_checkin_cutoff_minutes: 'Batas Waktu Presensi Telat',
    early_leave_tolerance_minutes: 'Toleransi Pulang Awal',
    overtime_enabled: 'Hitung Lembur Otomatis',
    min_overtime_minutes: 'Ambang Minimal Lembur',
    checkout_reminder_minutes: 'Reminder Checkout',
    auto_checkout_grace_minutes: 'Auto-Checkout',
  };

  const openAdd = () => {
    setForm(empty);
    setEditId(null);
    setShowForm(true);
    setModalTab('attendance');
    setValidationError(null);
    setConfirmReset(null);
    setOriginalLeaveReset('');
    setExpandedDays([]);
    setDangerousFields(null);
    setConfirmText('');
  };

  const openEdit = (o: any) => {
    const isEarlyLeaveEnabled = o.early_leave_tolerance_minutes !== null && o.early_leave_tolerance_minutes !== undefined;
    setForm({
      office_name: o.office_name ?? '',
      office_latitude: o.office_latitude ?? '',
      office_longitude: o.office_longitude ?? '',
      radius_meters: o.radius_meters ?? 100,
      work_start_time: (o.work_start_time ?? '08:00').slice(0, 5),
      work_end_time: (o.work_end_time ?? '17:00').slice(0, 5),
      work_days: o.work_days ?? [1, 2, 3, 4, 5],
      late_tolerance_minutes: o.late_tolerance_minutes ?? 15,
      late_checkin_cutoff_minutes: o.late_checkin_cutoff_minutes != null ? o.late_checkin_cutoff_minutes : '',
      wfh_checkin_window_minutes: o.wfh_checkin_window_minutes ?? 120,
      overtime_enabled: o.overtime_enabled ?? true,
      min_overtime_minutes: o.min_overtime_minutes ?? 30,
      early_leave_enabled: isEarlyLeaveEnabled,
      early_leave_tolerance_minutes: isEarlyLeaveEnabled ? o.early_leave_tolerance_minutes : 30,
      enforce_weekly_hours: o.enforce_weekly_hours ?? false,
      max_weekly_hours: o.max_weekly_hours ?? 40,
      shift_notice_days: o.shift_notice_days ?? 0,
      checkout_reminder_minutes: o.checkout_reminder_minutes ?? 30,
      auto_checkout_grace_minutes: o.auto_checkout_grace_minutes ?? 60,
      default_leave_quota: o.default_leave_quota ?? 12,
      leave_reset_date: o.leave_reset_date ? String(o.leave_reset_date).slice(0, 5) : '',
      custom_schedules: o.custom_schedules ?? {},
      // Payroll fields
      umr_amount: o.umr_amount !== undefined && o.umr_amount !== null ? Number(o.umr_amount) : 0,
      payroll_cutoff_date: o.payroll_cutoff_date !== undefined && o.payroll_cutoff_date !== null ? Number(o.payroll_cutoff_date) : 25,
      payroll_payment_date: o.payroll_payment_date !== undefined && o.payroll_payment_date !== null ? Number(o.payroll_payment_date) : 1,
      prorate_formula: o.prorate_formula ?? 'working_days',
      late_deduction_type: o.late_deduction_type ?? 'none',
      late_deduction_amount: o.late_deduction_amount !== undefined && o.late_deduction_amount !== null ? Number(o.late_deduction_amount) : 0,
      overtime_rate_type: o.overtime_rate_type ?? 'depnaker',
      overtime_flat_rate: o.overtime_flat_rate !== undefined && o.overtime_flat_rate !== null ? Number(o.overtime_flat_rate) : 0,
      jkk_tier: o.jkk_tier ?? 'low',
    });
    setOriginalLeaveReset(o.leave_reset_date ? String(o.leave_reset_date).slice(0, 5) : '');
    setEditId(o.id);
    setShowForm(true);
    setModalTab('attendance');
    setValidationError(null);
    setConfirmReset(null);
    setDangerousFields(null);
    setConfirmText('');
    setExpandedDays(Object.keys(o.custom_schedules ?? {}).map(Number));
  };

  const calculatedWeeklyHours = useMemo(() => {
    let totalMinutes = 0;
    for (const day of (form.work_days as number[])) {
      const startStr = form.custom_schedules[day]?.start ?? form.work_start_time;
      const endStr = form.custom_schedules[day]?.end ?? form.work_end_time;
      if (startStr && endStr) {
        const [sH, sM] = startStr.split(':').map(Number);
        const [eH, eM] = endStr.split(':').map(Number);
        let startMins = sH * 60 + sM;
        let endMins = eH * 60 + eM;
        if (endMins <= startMins) endMins += 24 * 60;
        totalMinutes += (endMins - startMins);
      }
    }
    return totalMinutes / 60;
  }, [form.work_days, form.work_start_time, form.work_end_time, form.custom_schedules]);

  // ─── Validasi form (dipanggil submit & sebelum konfirmasi reset) ──
  const validate = (): string | null => {
    if (!form.office_name || form.office_latitude === '' || form.office_longitude === '') {
      return 'Nama kantor, latitude, dan longitude wajib diisi.';
    }
    if (Array.isArray(form.work_days) && form.work_days.length > 6) {
      return 'Hari kerja maksimal 6 hari per minggu. Karyawan wajib mendapat minimal 1 hari libur.';
    }

    // Validasi typo AM/PM
    const checkAmPmTypo = (start: string, end: string, label: string) => {
      const [sH] = start.split(':').map(Number);
      const [eH] = end.split(':').map(Number);
      if (sH >= 6 && sH <= 12 && eH >= 1 && eH <= 6) {
        return `Jam pulang pada ${label} tidak wajar (${end}). Apakah Anda bermaksud ${eH + 12}:00? Gunakan format 24 jam.`;
      }
      return null;
    };

    const defaultTypo = checkAmPmTypo(form.work_start_time, form.work_end_time, 'jam kerja default');
    if (defaultTypo) return defaultTypo;

    for (const day of (form.work_days as number[])) {
      if (form.custom_schedules[day]) {
        const typo = checkAmPmTypo(form.custom_schedules[day].start, form.custom_schedules[day].end, 'jam khusus');
        if (typo) return typo;
      }
    }

    if (form.enforce_weekly_hours) {
      const maxHours = Number(form.max_weekly_hours);
      if (calculatedWeeklyHours > maxHours) {
        return `Total jam kerja per minggu (${calculatedWeeklyHours.toFixed(1)} jam) melebihi batas maksimal yang diatur (${maxHours} jam).`;
      }
    }

    // Auto-checkout: reminder harus sebelum auto-checkout
    if (Number(form.auto_checkout_grace_minutes) <= Number(form.checkout_reminder_minutes)) {
      return 'Menit auto-checkout harus LEBIH BESAR dari menit reminder checkout, agar karyawan sempat menerima pengingat sebelum sistem menutup presensinya.';
    }

    // Toleransi telat tidak boleh lebih besar dari batas waktu presensi telat (cutoff)
    if (
      form.late_checkin_cutoff_minutes !== '' &&
      form.late_checkin_cutoff_minutes !== null &&
      form.late_checkin_cutoff_minutes !== undefined
    ) {
      const tol = Number(form.late_tolerance_minutes);
      const cutoff = Number(form.late_checkin_cutoff_minutes);
      if (tol > cutoff) {
        return `Toleransi telat (${tol} menit) tidak boleh lebih besar dari batas waktu presensi telat (${cutoff} menit). Batas waktu presensi harus minimal sama dengan atau lebih besar dari toleransi telat.`;
      }
    }

    // Saldo cuti: kuota wajib angka ≥ 0
    const leaveQuota = Number(form.default_leave_quota);
    if (isNaN(leaveQuota) || leaveQuota < 0) {
      return 'Saldo cuti default wajib berupa angka minimal 0.';
    }
    return null;
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();

    const err = validate();
    if (err) { setValidationError(err); return; }

    // Konfirmasi tambahan: tanggal reset BARU yang anniversary-nya hari ini / sudah lewat
    // tahun ini akan langsung memicu reset saldo TAHUN BERJALAN sekali (potong pemakaian).
    if (form.leave_reset_date && form.leave_reset_date !== originalLeaveReset) {
      const now = new Date();
      const [mm, dd] = form.leave_reset_date.split('-').map(Number);
      // Bandingkan dari awal hari — tanggal HARI INI juga ikut dikonfirmasi
      // karena scheduler berikutnya langsung memprosesnya.
      const anniversaryThisYear = new Date(now.getFullYear(), mm - 1, dd);
      if (anniversaryThisYear.getTime() <= now.getTime()) {
        setConfirmReset(
          `Tanggal reset saldo cuti (${Number(dd)} ${['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][mm - 1]}) hari ini atau sudah lewat tahun ini. ` +
          'Saat pengaturan disimpan, sistem akan langsung MERESET saldo cuti semua karyawan kantor ini ke saldo default dan pemakaian cuti tahun berjalan menjadi 0. Lanjutkan?'
        );
        return; // tunggu user konfirmasi → doSave()
      }
    }

    await doSave();
  };

  const doSave = async (confirmDangerous: boolean = false) => {
    setConfirmReset(null);
    setSaving(true);
    try {
      const payload: Record<string, unknown> = {
        office_name: form.office_name,
        office_latitude: Number(form.office_latitude),
        office_longitude: Number(form.office_longitude),
        radius_meters: Number(form.radius_meters),
        work_start_time: form.work_start_time,
        work_end_time: form.work_end_time,
        work_days: form.work_days,
        late_tolerance_minutes: Number(form.late_tolerance_minutes),
        late_checkin_cutoff_minutes: form.late_checkin_cutoff_minutes === '' || form.late_checkin_cutoff_minutes === null ? null : Number(form.late_checkin_cutoff_minutes),
        wfh_checkin_window_minutes: form.wfh_checkin_window_minutes === '' ? null : Number(form.wfh_checkin_window_minutes),
        overtime_enabled: !!form.overtime_enabled,
        min_overtime_minutes: Number(form.min_overtime_minutes),
        early_leave_tolerance_minutes: form.early_leave_enabled
          ? (form.early_leave_tolerance_minutes === '' || form.early_leave_tolerance_minutes === null ? 30 : Number(form.early_leave_tolerance_minutes))
          : null,
        enforce_weekly_hours: !!form.enforce_weekly_hours,
        max_weekly_hours: form.enforce_weekly_hours ? Number(form.max_weekly_hours) : null,
        shift_notice_days: Number(form.shift_notice_days ?? 0),
        // Auto-checkout presensi mobile (dihitung dari jam pulang — kantor default ATAU shift)
        checkout_reminder_minutes: Number(form.checkout_reminder_minutes),
        auto_checkout_grace_minutes: Number(form.auto_checkout_grace_minutes),
        // Kebijakan saldo cuti per kantor: kuota default & tanggal reset tahunan
        default_leave_quota: Number(form.default_leave_quota ?? 12),
        leave_reset_date: form.leave_reset_date ? form.leave_reset_date : null,
        // collective_leave_policy: dihapus — hardcode 'block' sejak 2026-08-20
        custom_schedules: form.custom_schedules,
      };
      // Gerbang backend: perubahan field berbahaya wajib menyertakan frasa konfirmasi
      if (editId && confirmDangerous) payload.confirm_dangerous = 'SIMPAN';
      if (editId) {
        await attendanceApi.settings.update(editId, payload);
        onAddAuditLog('Kantor Presensi Diperbarui', `Kantor ${form.office_name} diperbarui`, 'bg-indigo-600');
      } else {
        await attendanceApi.settings.create(payload);
        onAddAuditLog('Kantor Presensi Ditambahkan', `Kantor ${form.office_name} ditambahkan`, 'bg-emerald-600');
      }
      setShowForm(false);
      await reload();
    } catch (e2: any) {
      // Backend menolak (422 requires_confirmation): field berbahaya berubah
      // tanpa frasa "SIMPAN" → tampilkan dialog ketik-konfirmasi.
      if (e2?.data?.requires_confirmation === true) {
        setDangerousFields(Array.isArray(e2.data.dangerous_changed_fields) ? e2.data.dangerous_changed_fields : []);
        setConfirmText('');
      } else {
        onError(e2, 'Gagal menyimpan kantor.');
      }
    } finally {
      setSaving(false);
    }
  };

  const remove = async (o: any) => {
    if (!window.confirm(`Hapus kantor "${o.office_name}"?`)) return;
    try {
      await attendanceApi.settings.destroy(o.id);
      onAddAuditLog('Kantor Presensi Dihapus', `Kantor ${o.office_name} dihapus`, 'bg-rose-600');
      await reload();
    } catch (e) {
      onError(e, 'Gagal menghapus kantor.');
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <p className="text-[11px] text-slate-400">Lokasi kantor & radius presensi (acuan jam kerja untuk status hadir/telat).</p>
        <button onClick={openAdd} className="flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold">
          <Plus className="w-3.5 h-3.5" /> Tambah Kantor
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {offices.length === 0 ? (
          <p className="text-center py-10 text-xs text-slate-400 col-span-full">Belum ada kantor terdaftar.</p>
        ) : (
          offices.map((o) => (
            <div key={o.id} className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-2">
              <div className="flex justify-between items-start">
                <h5 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                  <Building2 className="w-4 h-4 text-indigo-600" /> {o.office_name}
                </h5>
                <div className="flex gap-1.5">
                  <button onClick={() => openEdit(o)} className="px-2 py-1 text-[10px] font-semibold border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800">Edit</button>
                  <button onClick={() => remove(o)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg"><Trash2 className="w-3.5 h-3.5" /></button>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2 text-[11px] text-slate-500">
                <span className="flex items-center gap-1"><MapPin className="w-3 h-3" /> {o.office_latitude}, {o.office_longitude}</span>
                <span>Radius: {o.radius_meters} m</span>
                <span>Jam: {(o.work_start_time ?? '').slice(0, 5)} – {(o.work_end_time ?? '').slice(0, 5)}</span>
                <span>Toleransi telat: {o.late_tolerance_minutes} mnt</span>
                <span className="col-span-2 flex items-center gap-1">
                  <CalendarDays className="w-3 h-3 text-indigo-500" />
                  Hari kerja:{' '}
                  <span className="text-indigo-600 dark:text-indigo-400 font-semibold">
                    {(o.work_days ?? [1, 2, 3, 4, 5]).map((d: number) => ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'][d]).join(', ')}
                  </span>
                </span>
                <span className="col-span-2 flex items-center gap-1">
                  <Clock className="w-3 h-3 text-violet-500" />
                  Window WFH:{' '}
                  {o.wfh_checkin_window_minutes != null
                    ? <span className="text-violet-600 dark:text-violet-400 font-semibold">{o.wfh_checkin_window_minutes} mnt sebelum jam masuk</span>
                    : <span className="italic">Bebas (tidak dibatasi)</span>
                  }
                </span>
                <span className="col-span-2 flex items-center gap-1">
                  <Clock className="w-3 h-3 text-orange-500" />
                  Lembur:{' '}
                  {o.overtime_enabled === false
                    ? <span className="italic">Nonaktif</span>
                    : <span className="text-orange-600 dark:text-orange-400 font-semibold">Aktif (min {o.min_overtime_minutes ?? 30} mnt lewat jam pulang)</span>
                  }
                </span>
                <span className="col-span-2 flex items-center gap-1">
                  <Clock className="w-3 h-3 text-violet-500" />
                  Pulang Awal:{' '}
                  {o.early_leave_tolerance_minutes != null
                    ? <span className="text-violet-600 dark:text-violet-400 font-semibold">Aktif ({o.early_leave_tolerance_minutes} mnt sebelum jam pulang)</span>
                    : <span className="italic">Nonaktif</span>
                  }
                </span>
                <span className="col-span-2 flex items-center gap-1">
                  <Clock className="w-3 h-3 text-indigo-500" />
                  Jam/minggu:{' '}
                  {o.enforce_weekly_hours
                    ? <span className="text-indigo-600 dark:text-indigo-400 font-semibold">Maks {o.max_weekly_hours ?? 40} jam/minggu (aktif)</span>
                    : <span className="italic text-slate-400">Tidak dibatasi</span>
                  }
                </span>
                <span className="col-span-2 flex items-center gap-1">
                  <Clock className="w-3 h-3 text-emerald-500" />
                  Auto-checkout:{' '}
                  <span className="text-emerald-600 dark:text-emerald-400 font-semibold">
                    reminder +{o.checkout_reminder_minutes ?? 30} mnt · tutup otomatis +{o.auto_checkout_grace_minutes ?? 60} mnt setelah jam pulang
                  </span>
                </span>
                <span className="col-span-2 flex items-center gap-1">
                  <CalendarDays className="w-3 h-3 text-teal-500" />
                  Saldo cuti:{' '}
                  <span className="text-teal-600 dark:text-teal-400 font-semibold">
                    {o.default_leave_quota ?? 12} hari/tahun
                    {o.leave_reset_date
                      ? (() => {
                          const [mm, dd] = String(o.leave_reset_date).slice(0, 5).split('-');
                          const bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][Number(mm) - 1] ?? mm;
                          return ` · reset tiap ${Number(dd)} ${bulan}`;
                        })()
                      : ' · tanpa reset otomatis'}
                  </span>
                </span>
              </div>
            </div>
          ))
        )}
      </div>

      {showForm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div onClick={() => setShowForm(false)} className="fixed inset-0 bg-slate-900/60 backdrop-blur-xs" />
          <form onSubmit={submit} className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-3xl lg:max-w-4xl p-6 sm:p-7 shadow-2xl relative z-10 space-y-5 max-h-[90vh] overflow-y-auto">
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3.5 border-b border-slate-100 dark:border-slate-800">
              <div className="flex items-center gap-2">
                <div className="p-2 bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 rounded-xl">
                  <Building2 className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-base font-bold text-slate-800 dark:text-slate-100">
                    {editId ? 'Edit Kantor Cabang' : 'Tambah Kantor Cabang'}
                  </h3>
                  <p className="text-[11px] text-slate-400">
                    {form.office_name ? form.office_name : 'Pengaturan profil lokasi, operasional & penggajian'}
                  </p>
                </div>
              </div>

              <div className="flex items-center gap-2">
                <div className="flex p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl border border-slate-200/80 dark:border-slate-700/80">
                  <button
                    type="button"
                    onClick={() => setModalTab('attendance')}
                    className={`flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-xs font-semibold transition ${
                      modalTab === 'attendance'
                        ? 'bg-white dark:bg-slate-700 text-indigo-600 dark:text-indigo-300 shadow-xs'
                        : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                    }`}
                  >
                    <Clock className="w-3.5 h-3.5" />
                    Presensi & Lokasi
                  </button>
                  <button
                    type="button"
                    onClick={() => setModalTab('payroll')}
                    className={`flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-xs font-semibold transition ${
                      modalTab === 'payroll'
                        ? 'bg-white dark:bg-slate-700 text-emerald-600 dark:text-emerald-300 shadow-xs'
                        : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                    }`}
                  >
                    <Banknote className="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400" />
                    Pengaturan Payroll Cabang
                    <span className="text-[9px] bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 font-bold px-1.5 py-0.2 rounded-full">
                      Roadmap C
                    </span>
                  </button>
                </div>
                <button type="button" onClick={() => setShowForm(false)} className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-full transition-colors text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                  <X className="w-4 h-4" />
                </button>
              </div>
            </div>

            {/* TAB 1: Presensi & Lokasi */}
            {modalTab === 'attendance' && (
              <div className="space-y-4 text-xs">
                {/* Section 1: Informasi Kantor & Lokasi */}
                <div className="p-4 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 space-y-3">
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div className="md:col-span-2 space-y-1">
                      <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">Nama Kantor *</label>
                      <input type="text" value={form.office_name} onChange={(e) => setForm({ ...form, office_name: e.target.value })} required className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-500 focus:outline-none" placeholder="cth: Kantor Pusat / Cabang Jakarta" />
                    </div>
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">Radius Presensi (m)</label>
                      <input type="number" value={form.radius_meters} onChange={(e) => setForm({ ...form, radius_meters: e.target.value })} className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono focus:ring-1 focus:ring-indigo-500 focus:outline-none" />
                    </div>
                  </div>
                  <div className="space-y-1">
                    <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">Titik Koordinat Kantor (GPS) *</label>
                    <LocationPicker
                      lat={form.office_latitude}
                      lng={form.office_longitude}
                      onChange={(lat, lng) => setForm({ ...form, office_latitude: lat, office_longitude: lng })}
                    />
                  </div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">Jam Masuk Default</label>
                      <input type="time" value={form.work_start_time} onChange={(e) => setForm({ ...form, work_start_time: e.target.value })} className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-500 focus:outline-none" />
                    </div>
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">Jam Pulang Default</label>
                      <input type="time" value={form.work_end_time} onChange={(e) => setForm({ ...form, work_end_time: e.target.value })} className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 focus:ring-1 focus:ring-indigo-500 focus:outline-none" />
                    </div>
                  </div>
                </div>

                {/* Section 2: Hari Kerja & Jam Khusus */}
                <div className="space-y-2">
                  <label className="text-[11px] font-bold text-slate-600 dark:text-slate-300 flex items-center justify-between mb-1">
                    <span className="flex items-center gap-2">
                      <CalendarDays className="w-4 h-4 text-indigo-500" />
                      Hari Kerja & Jam per Hari
                      <span className={`px-2 py-0.5 rounded font-mono text-[10px] font-semibold ${
                        form.enforce_weekly_hours && calculatedWeeklyHours > Number(form.max_weekly_hours)
                          ? 'bg-rose-100 text-rose-600 dark:bg-rose-900/40 dark:text-rose-400'
                          : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
                      }`}>
                        Total: {calculatedWeeklyHours.toFixed(1)} jam/mgg
                      </span>
                    </span>
                    {(form.work_days as number[]).length > 6 && (
                      <span className="text-rose-600 bg-rose-50 dark:bg-rose-900/30 dark:text-rose-400 px-2 py-0.5 rounded flex items-center gap-1 leading-none text-[10px] font-semibold">
                        <AlertTriangle className="w-3.5 h-3.5" /> Maks 6 hari kerja
                      </span>
                    )}
                  </label>
                  
                  <div className="space-y-2">
                    {['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'].map((name, idx) => {
                      const active = (form.work_days as number[]).includes(idx);
                      const hasCustom = !!form.custom_schedules[idx];
                      const isExpanded = expandedDays.includes(idx);
                      
                      return (
                        <div key={idx} className={`border rounded-xl overflow-hidden transition ${active ? 'border-indigo-200 dark:border-indigo-800/50 bg-indigo-50/20 dark:bg-indigo-900/10' : 'border-slate-200 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-800/20'}`}>
                          {/* Header Hari */}
                          <div className="p-3 flex items-center justify-between">
                            <div className="flex items-center gap-3">
                              <button
                                type="button"
                                onClick={() => {
                                  const days = active
                                    ? (form.work_days as number[]).filter((d: number) => d !== idx)
                                    : [...(form.work_days as number[]), idx].sort();
                                  
                                  const newCustom = { ...form.custom_schedules };
                                  if (active) {
                                    delete newCustom[idx];
                                    setExpandedDays(prev => prev.filter(d => d !== idx));
                                  }
                                  
                                  setForm({ ...form, work_days: days, custom_schedules: newCustom });
                                }}
                                className={`w-10 h-6 rounded-full transition-colors relative ${active ? 'bg-indigo-600' : 'bg-slate-300 dark:bg-slate-600'}`}
                              >
                                <span className={`absolute top-1 w-4 h-4 rounded-full bg-white transition-transform ${active ? 'left-5' : 'left-1'}`} />
                              </button>
                              <div className="flex flex-col">
                                <span className={`text-sm font-semibold ${active ? 'text-indigo-900 dark:text-indigo-300' : 'text-slate-500'}`}>{name}</span>
                                {active && (
                                  <span className={`text-[9px] px-1.5 py-0.5 rounded w-max mt-0.5 ${hasCustom ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'}`}>
                                    {hasCustom ? 'Jam khusus' : 'Ikut default kantor'}
                                  </span>
                                )}
                              </div>
                            </div>
                            
                            {active && (
                              <button
                                type="button"
                                onClick={() => setExpandedDays(prev => isExpanded ? prev.filter(d => d !== idx) : [...prev, idx])}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition ${isExpanded ? 'bg-indigo-100 border-indigo-200 text-indigo-700 dark:bg-indigo-900/40 dark:border-indigo-800 dark:text-indigo-300' : 'bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50'}`}
                              >
                                {isExpanded ? 'Tutup Atur Jam' : 'Atur Jam Khusus'}
                              </button>
                            )}
                          </div>

                          {/* Accordion Custom Jam */}
                          {active && isExpanded && (
                            <div className="p-3.5 border-t border-indigo-100 dark:border-indigo-800/30 bg-white/70 dark:bg-slate-900/70 space-y-3">
                              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div className="space-y-1">
                                  <label className="text-[10px] font-bold text-slate-500">Jam Masuk</label>
                                  <input
                                    type="time"
                                    value={form.custom_schedules[idx]?.start ?? form.work_start_time}
                                    onChange={(e) => {
                                      const val = e.target.value;
                                      const curr = form.custom_schedules[idx] ?? { start: form.work_start_time, end: form.work_end_time };
                                      setForm({ ...form, custom_schedules: { ...form.custom_schedules, [idx]: { ...curr, start: val } } });
                                    }}
                                    className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg text-sm bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                                  />
                                </div>
                                <div className="space-y-1">
                                  <label className="text-[10px] font-bold text-slate-500">Jam Pulang</label>
                                  <input
                                    type="time"
                                    value={form.custom_schedules[idx]?.end ?? form.work_end_time}
                                    onChange={(e) => {
                                      const val = e.target.value;
                                      const curr = form.custom_schedules[idx] ?? { start: form.work_start_time, end: form.work_end_time };
                                      setForm({ ...form, custom_schedules: { ...form.custom_schedules, [idx]: { ...curr, end: val } } });
                                    }}
                                    className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg text-sm bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100"
                                  />
                                </div>
                              </div>
                              
                              <div className="flex gap-2">
                                <button
                                  type="button"
                                  onClick={() => {
                                    setForm({ ...form, custom_schedules: { ...form.custom_schedules, [idx]: { start: '08:00', end: '13:00' } } });
                                  }}
                                  className="flex-1 px-3 py-1.5 bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400 border border-amber-200 dark:border-amber-800 rounded-lg text-[10px] font-bold hover:bg-amber-100 transition"
                                >
                                  Set Setengah Hari (08:00-13:00)
                                </button>
                                
                                {hasCustom && (
                                  <button
                                    type="button"
                                    onClick={() => {
                                      const newCustom = { ...form.custom_schedules };
                                      delete newCustom[idx];
                                      setForm({ ...form, custom_schedules: newCustom });
                                    }}
                                    className="px-3 py-1.5 bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400 border border-slate-200 dark:border-slate-700 rounded-lg text-[10px] font-bold hover:bg-slate-200 transition"
                                  >
                                    Reset Default
                                  </button>
                                )}
                              </div>
                            </div>
                          )}
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* Section 3: Toleransi & Aturan Presensi */}
                <div className="space-y-3 pt-2">
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-400 block">Toleransi Telat (menit)</label>
                      <input 
                        type="number" 
                        min={0}
                        value={form.late_tolerance_minutes} 
                        onChange={(e) => {
                          const val = parseInt(e.target.value);
                          setForm({ ...form, late_tolerance_minutes: isNaN(val) ? '' : Math.max(0, val) });
                        }} 
                        className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 font-mono" 
                      />
                    </div>
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-400 block">
                        Batas Waktu Presensi Telat (menit)
                      </label>
                      <input
                        type="number"
                        min={0}
                        max={1440}
                        value={form.late_checkin_cutoff_minutes ?? ''}
                        onChange={(e) => {
                          const val = parseInt(e.target.value);
                          setForm({ ...form, late_checkin_cutoff_minutes: isNaN(val) ? '' : Math.max(0, val) });
                        }}
                        placeholder="Kosongkan = tidak ada batas"
                        className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 font-mono"
                      />
                      <p className="text-[9px] text-slate-400">
                        Contoh: 120 menit → presensi ditutup 2 jam setelah jam masuk. Kosongkan = bebas.
                      </p>
                    </div>
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-400 block">
                        Window WFH (menit sebelum jam masuk)
                      </label>
                      <input
                        type="number"
                        min={0}
                        max={720}
                        value={form.wfh_checkin_window_minutes ?? ''}
                        onChange={(e) => setForm({ ...form, wfh_checkin_window_minutes: e.target.value })}
                        placeholder="Kosongkan = bebas"
                        className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 font-mono"
                      />
                      <p className="text-[9px] text-slate-400">
                        Kosongkan untuk tidak dibatasi waktu WFH.
                      </p>
                    </div>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2">
                    {/* Lembur */}
                    <div className="p-3.5 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-slate-50/50 dark:bg-slate-800/20 space-y-2">
                      <label className="flex items-center justify-between gap-3 cursor-pointer">
                        <span className="text-[11px] font-bold text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
                          <Clock className="w-3.5 h-3.5 text-orange-500" /> Hitung lembur otomatis
                        </span>
                        <input
                          type="checkbox"
                          checked={!!form.overtime_enabled}
                          onChange={(e) => setForm({ ...form, overtime_enabled: e.target.checked })}
                          className="w-4 h-4 accent-orange-600"
                        />
                      </label>
                      {form.overtime_enabled && (
                        <div className="space-y-1">
                          <label className="text-[10px] font-bold text-slate-400 block">Ambang minimal lembur (menit)</label>
                          <input
                            type="number"
                            min={0}
                            max={480}
                            value={form.min_overtime_minutes}
                            onChange={(e) => setForm({ ...form, min_overtime_minutes: e.target.value })}
                            className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-xs"
                          />
                          <p className="text-[9px] text-slate-400">
                            Lembur dihitung jika lewat jam pulang ≥ ambang ini.
                          </p>
                        </div>
                      )}
                    </div>

                    {/* Pulang Awal */}
                    <div className="p-3.5 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-slate-50/50 dark:bg-slate-800/20 space-y-2">
                      <label className="flex items-center justify-between gap-3 cursor-pointer">
                        <span className="text-[11px] font-bold text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
                          <Clock className="w-3.5 h-3.5 text-violet-500" /> Deteksi pulang awal
                        </span>
                        <input
                          type="checkbox"
                          checked={!!form.early_leave_enabled}
                          onChange={(e) => setForm({ ...form, early_leave_enabled: e.target.checked })}
                          className="w-4 h-4 accent-violet-600"
                        />
                      </label>
                      {form.early_leave_enabled && (
                        <div className="space-y-1">
                          <label className="text-[10px] font-bold text-slate-400 block">
                            Toleransi pulang awal (menit sebelum jam pulang)
                          </label>
                          <input
                            type="number"
                            min={0}
                            max={480}
                            value={form.early_leave_tolerance_minutes}
                            onChange={(e) => setForm({ ...form, early_leave_tolerance_minutes: e.target.value })}
                            className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-xs"
                          />
                          <p className="text-[9px] text-slate-400">
                            Check-out &gt; ambang ini ditandai Pulang Awal.
                          </p>
                        </div>
                      )}
                    </div>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2">
                    {/* Batas Jam Kerja */}
                    <div className="p-3.5 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-slate-50/50 dark:bg-slate-800/20 space-y-2">
                      <label className="flex items-center justify-between gap-3 cursor-pointer">
                        <span className="text-[11px] font-bold text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
                          <Clock className="w-3.5 h-3.5 text-indigo-500" /> Batas jam/minggu
                          <span className="text-[9px] font-normal text-slate-400 normal-case">(UU 13/2003)</span>
                        </span>
                        <input
                          type="checkbox"
                          checked={!!form.enforce_weekly_hours}
                          onChange={(e) => setForm({ ...form, enforce_weekly_hours: e.target.checked })}
                          className="w-4 h-4 accent-indigo-600"
                        />
                      </label>
                      {form.enforce_weekly_hours ? (
                        <div className="space-y-1">
                          <label className="text-[10px] font-bold text-slate-400 block">
                            Maksimal jam kerja per minggu
                          </label>
                          <input
                            type="number"
                            min={40}
                            max={168}
                            value={form.max_weekly_hours}
                            onChange={(e) => setForm({ ...form, max_weekly_hours: e.target.value })}
                            className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-xs"
                          />
                          <p className="text-[9px] text-slate-400">
                            Standar UU: 40 jam/minggu.
                          </p>
                        </div>
                      ) : (
                        <p className="text-[9px] text-slate-400">
                          Nonaktif — boleh melebihi 40 jam/minggu.
                        </p>
                      )}
                    </div>

                    {/* Notice Shift */}
                    <div className="p-3.5 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-slate-50/50 dark:bg-slate-800/20 space-y-2">
                      <span className="text-[11px] font-bold text-slate-600 dark:text-slate-300 block">
                        Minimum Notice Perubahan Shift (H-N Hari)
                      </span>
                      <div className="space-y-1">
                        <label className="text-[10px] font-bold text-slate-400 block">
                          Notice perubahan jadwal (hari)
                        </label>
                        <input
                          type="number"
                          min={0}
                          max={14}
                          value={form.shift_notice_days ?? 0}
                          onChange={(e) => setForm({ ...form, shift_notice_days: Math.max(0, parseInt(e.target.value) || 0) })}
                          className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-xs"
                        />
                        <p className="text-[9px] text-slate-400">
                          0 = bebas. Peringatan jika ubah shift &lt; N hari sebelum berlaku.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

                {/* Section 4: Auto-Checkout & Saldo Cuti */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2">
                  {/* Auto-Checkout Presensi Mobile */}
                  <div className="p-3.5 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-slate-50/50 dark:bg-slate-800/20 space-y-2.5">
                    <span className="text-[11px] font-bold text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
                      <Clock className="w-3.5 h-3.5 text-emerald-500" /> Auto-Checkout Presensi Mobile
                    </span>
                    <div className="grid grid-cols-2 gap-2">
                      <div className="space-y-1">
                        <label className="text-[10px] font-bold text-slate-400 block">Reminder (menit)</label>
                        <input
                          type="number"
                          min={5}
                          max={120}
                          value={form.checkout_reminder_minutes}
                          onChange={(e) => setForm({ ...form, checkout_reminder_minutes: e.target.value })}
                          className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-xs"
                        />
                      </div>
                      <div className="space-y-1">
                        <label className="text-[10px] font-bold text-slate-400 block">Auto-checkout (menit)</label>
                        <input
                          type="number"
                          min={30}
                          max={240}
                          value={form.auto_checkout_grace_minutes}
                          onChange={(e) => setForm({ ...form, auto_checkout_grace_minutes: e.target.value })}
                          className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-xs"
                        />
                      </div>
                    </div>
                    <p className="text-[9px] text-slate-400">
                      Dihitung dari jam pulang. Auto-checkout wajib &gt; reminder.
                    </p>
                  </div>

                  {/* Saldo Cuti & Reset Tahunan */}
                  <div className="p-3.5 rounded-xl border border-slate-200 dark:border-slate-700/80 bg-slate-50/50 dark:bg-slate-800/20 space-y-2.5">
                    <span className="text-[11px] font-bold text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
                      <CalendarDays className="w-3.5 h-3.5 text-teal-500" /> Saldo Cuti & Reset Tahunan
                    </span>
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-400 block">Saldo cuti default (hari/tahun)</label>
                      <input
                        type="number"
                        min={0}
                        max={365}
                        value={form.default_leave_quota}
                        onChange={(e) => {
                          const val = parseInt(e.target.value);
                          setForm({ ...form, default_leave_quota: isNaN(val) ? '' : Math.max(0, val) });
                        }}
                        className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-xs"
                      />
                    </div>
                    <div className="space-y-1">
                      <label className="text-[10px] font-bold text-slate-400 block">Tanggal reset saldo cuti</label>
                      <div className="grid grid-cols-2 gap-2">
                        <select
                          value={form.leave_reset_date ? form.leave_reset_date.split('-')[0] : ''}
                          onChange={(e) => {
                            const month = e.target.value;
                            if (!month) { setForm({ ...form, leave_reset_date: '' }); return; }
                            let day = form.leave_reset_date ? form.leave_reset_date.split('-')[1] : '01';
                            const maxDays = month === '02' ? 29 : (['04', '06', '09', '11'].includes(month) ? 30 : 31);
                            if (parseInt(day) > maxDays) {
                              day = String(maxDays).padStart(2, '0');
                            }
                            setForm({ ...form, leave_reset_date: `${month}-${day}` });
                          }}
                          className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 text-xs"
                        >
                          <option value="">— Bulan —</option>
                          {['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'].map((m, i) => (
                            <option key={m} value={String(i + 1).padStart(2, '0')}>{m}</option>
                          ))}
                        </select>
                        <select
                          value={form.leave_reset_date ? form.leave_reset_date.split('-')[1] : ''}
                          onChange={(e) => {
                            const day = e.target.value;
                            if (!day) { setForm({ ...form, leave_reset_date: '' }); return; }
                            const month = form.leave_reset_date ? form.leave_reset_date.split('-')[0] : '01';
                            setForm({ ...form, leave_reset_date: `${month}-${day}` });
                          }}
                          disabled={!form.leave_reset_date}
                          className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 text-xs disabled:opacity-50"
                        >
                          <option value="">— Tgl —</option>
                          {(() => {
                            const selectedMonth = form.leave_reset_date ? form.leave_reset_date.split('-')[0] : '';
                            const daysInMonth = selectedMonth === '02' ? 29 : (['04', '06', '09', '11'].includes(selectedMonth) ? 30 : 31);
                            return Array.from({ length: daysInMonth }, (_, i) => i + 1).map((d) => (
                              <option key={d} value={String(d).padStart(2, '0')}>{d}</option>
                            ));
                          })()}
                        </select>
                      </div>
                      {form.leave_reset_date ? (
                        (() => {
                          const [mm, dd] = form.leave_reset_date.split('-').map(Number);
                          const now = new Date();
                          const sudahLewat = new Date(now.getFullYear(), mm - 1, dd).getTime() <= now.getTime();
                          return (
                            <p className={`text-[9px] font-semibold ${sudahLewat ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'}`}>
                              Reset tiap {Number(dd)} {['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'][mm - 1]}.
                              {sudahLewat && ' (Sudah lewat tahun ini).'}
                            </p>
                          );
                        })()
                      ) : (
                        <p className="text-[9px] text-slate-400">
                          Tanpa reset otomatis (diatur manual).
                        </p>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* TAB 2: Pengaturan Payroll Cabang (Roadmap Bagian C) */}
            {modalTab === 'payroll' && (
              <div className="space-y-4 text-xs">
                {/* Banner Info */}
                <div className="p-4 rounded-xl border border-emerald-200/80 dark:border-emerald-800/60 bg-emerald-50/50 dark:bg-emerald-950/20 flex items-start gap-3">
                  <div className="p-2 bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 rounded-lg shrink-0">
                    <Coins className="w-5 h-5" />
                  </div>
                  <div className="space-y-0.5">
                    <h4 className="text-xs font-bold text-emerald-900 dark:text-emerald-200">
                      Konfigurasi Penggajian Cabang (Multi-Branch Payroll)
                    </h4>
                    <p className="text-[11px] text-emerald-700 dark:text-emerald-300/80 leading-relaxed">
                      Aturan UMR regional, siklus cut-off presensi, formula prorate masuk/resign, skema lembur Depnaker, dan potongan keterlambatan untuk seluruh karyawan di <strong>{form.office_name || 'kantor cabang ini'}</strong>.
                    </p>
                  </div>
                </div>

                {/* Panel 1: Dasar Pengupahan & UMR Regional */}
                <div className="p-4 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 space-y-3">
                  <div className="flex items-center justify-between">
                    <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                      <Banknote className="w-4 h-4 text-emerald-600" /> Dasar Pengupahan & UMR Regional
                    </h4>
                    <span className="text-[9px] bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded font-mono">
                      attendance_settings.umr_amount & jkk_tier
                    </span>
                  </div>
                  
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* UMR Amount */}
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">
                        UMR / UMK Regional Cabang (Rp)
                      </label>
                      <div className="relative">
                        <span className="absolute left-3 top-2.5 text-xs font-bold text-slate-400">Rp</span>
                        <input
                          type="number"
                          min={0}
                          step={50000}
                          value={form.umr_amount || ''}
                          onChange={(e) => setForm({ ...form, umr_amount: Math.max(0, parseFloat(e.target.value) || 0) })}
                          placeholder="0"
                          className="w-full pl-9 pr-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-sm font-semibold focus:ring-1 focus:ring-emerald-500 focus:outline-none"
                        />
                      </div>
                      <div className="flex items-center justify-between text-[10px]">
                        <span className="text-slate-400">Preview Nominal:</span>
                        <span className="font-mono font-bold text-emerald-600 dark:text-emerald-400">
                          Rp {Number(form.umr_amount || 0).toLocaleString('id-ID')}
                        </span>
                      </div>
                      <p className="text-[9px] text-slate-400 leading-tight">
                        Sebagai dasar batas bawah upah & perhitungan lembur/BPJS di kota/kabupaten lokasi kantor ini.
                      </p>
                    </div>

                    {/* JKK Tier */}
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">
                        Tingkat Risiko JKK BPJS Ketenagakerjaan
                      </label>
                      <select
                        value={form.jkk_tier ?? 'low'}
                        onChange={(e) => setForm({ ...form, jkk_tier: e.target.value })}
                        className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 text-xs font-semibold focus:ring-1 focus:ring-emerald-500 focus:outline-none"
                      >
                        {Object.entries(JKK_TIERS).map(([key, item]) => (
                          <option key={key} value={key}>
                            {item.label} — {item.rate}
                          </option>
                        ))}
                      </select>
                      {JKK_TIERS[form.jkk_tier ?? 'low'] && (
                        <div className={`p-2 rounded-lg border text-[10px] space-y-0.5 ${JKK_TIERS[form.jkk_tier ?? 'low'].badgeBg}`}>
                          <div className="flex items-center justify-between font-bold">
                            <span className={JKK_TIERS[form.jkk_tier ?? 'low'].badgeText}>
                              Iuran JKK: {JKK_TIERS[form.jkk_tier ?? 'low'].rate}
                            </span>
                            <span className="text-[9px] font-normal text-slate-500 dark:text-slate-400">100% Ditanggung Perusahaan</span>
                          </div>
                          <p className="text-[9px] text-slate-500 dark:text-slate-400 leading-tight">
                            {JKK_TIERS[form.jkk_tier ?? 'low'].desc}
                          </p>
                        </div>
                      )}
                    </div>
                  </div>
                </div>

                {/* Panel 2: Periode & Siklus Payroll (Cut-off & Tanggal Gajian) */}
                <div className="p-4 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 space-y-3">
                  <div className="flex items-center justify-between">
                    <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                      <CalendarClock className="w-4 h-4 text-indigo-600" /> Periode & Siklus Payroll Bulanan
                    </h4>
                    <span className="text-[9px] bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded font-mono">
                      attendance_settings.payroll_cutoff_date & payment_date
                    </span>
                  </div>
                  
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Cut-off Date */}
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">
                        Tanggal Cut-Off Presensi (1 s.d. 31, atau 0)
                      </label>
                      <div className="flex gap-2 items-center">
                        <input
                          type="number"
                          min={0}
                          max={31}
                          value={form.payroll_cutoff_date}
                          onChange={(e) => {
                            const val = parseInt(e.target.value);
                            setForm({ ...form, payroll_cutoff_date: isNaN(val) ? 0 : Math.min(31, Math.max(0, val)) });
                          }}
                          className="w-24 p-2 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-sm font-bold text-center focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                        />
                        <div className="flex-1 text-[11px] text-slate-600 dark:text-slate-300 font-semibold bg-white dark:bg-slate-800 p-2 rounded-xl border border-slate-200 dark:border-slate-700">
                          {Number(form.payroll_cutoff_date) === 0 ? (
                            <span className="text-indigo-600 dark:text-indigo-400">🗓 Akhir Bulan Kalender (1 s.d. Akhir Bulan)</span>
                          ) : (
                            <span className="text-indigo-600 dark:text-indigo-400">
                              🗓 Periode: {Number(form.payroll_cutoff_date) + 1} bulan lalu s.d. {form.payroll_cutoff_date} bulan ini
                            </span>
                          )}
                        </div>
                      </div>
                      <p className="text-[9px] text-slate-400 leading-tight">
                        0 = Akhir bulan penuh. Tanggal 25 = penarikan presensi tgl 26 bulan sebelumnya s.d. tgl 25 bulan berjalan.
                      </p>
                    </div>

                    {/* Payment Date */}
                    <div className="space-y-1.5">
                      <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">
                        Tanggal Pembayaran Gaji / Disbursment (1 s.d. 31)
                      </label>
                      <div className="flex gap-2 items-center">
                        <input
                          type="number"
                          min={1}
                          max={31}
                          value={form.payroll_payment_date}
                          onChange={(e) => {
                            const val = parseInt(e.target.value);
                            setForm({ ...form, payroll_payment_date: isNaN(val) ? 1 : Math.min(31, Math.max(1, val)) });
                          }}
                          className="w-24 p-2 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 font-mono text-sm font-bold text-center focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                        />
                        <div className="flex-1 text-[11px] text-slate-600 dark:text-slate-300 font-semibold bg-white dark:bg-slate-800 p-2 rounded-xl border border-slate-200 dark:border-slate-700">
                          💳 Gajian setiap tanggal <strong className="text-emerald-600 dark:text-emerald-400">{form.payroll_payment_date}</strong>
                        </div>
                      </div>
                      <p className="text-[9px] text-slate-400 leading-tight">
                        Tanggal resmi slip gaji diterbitkan dan dana gaji ditransfer ke rekening karyawan.
                      </p>
                    </div>
                  </div>
                </div>

                {/* Panel 3: Kebijakan Prorate Gaji */}
                <div className="p-4 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 space-y-3">
                  <div className="flex items-center justify-between">
                    <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                      <Calculator className="w-4 h-4 text-violet-600" /> Formula Prorate Gaji (Karyawan Baru / Resign)
                    </h4>
                    <span className="text-[10px] font-mono font-semibold text-violet-600 dark:text-violet-400 bg-violet-50 dark:bg-violet-950/40 px-2 py-0.5 rounded border border-violet-200 dark:border-violet-800">
                      {PRORATE_OPTIONS.find(p => p.value === form.prorate_formula)?.formula}
                    </span>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    {PRORATE_OPTIONS.map((opt) => {
                      const isSelected = form.prorate_formula === opt.value;
                      return (
                        <div
                          key={opt.value}
                          onClick={() => setForm({ ...form, prorate_formula: opt.value })}
                          className={`p-3 rounded-xl border cursor-pointer transition relative space-y-1.5 ${
                            isSelected
                              ? 'border-violet-500 dark:border-violet-400 bg-white dark:bg-slate-800 ring-1 ring-violet-500 shadow-xs'
                              : 'border-slate-200 dark:border-slate-700/80 bg-white/60 dark:bg-slate-800/40 hover:bg-white dark:hover:bg-slate-800'
                          }`}
                        >
                          <div className="flex items-center justify-between">
                            <span className={`text-xs font-bold ${isSelected ? 'text-violet-700 dark:text-violet-300' : 'text-slate-700 dark:text-slate-300'}`}>
                              {opt.title}
                            </span>
                            <span className={`text-[9px] font-bold px-1.5 py-0.2 rounded ${
                              isSelected
                                ? 'bg-violet-100 text-violet-700 dark:bg-violet-900/60 dark:text-violet-300'
                                : 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-400'
                            }`}>
                              {opt.badge}
                            </span>
                          </div>
                          <p className="text-[10px] text-slate-500 dark:text-slate-400 leading-tight">
                            {opt.desc}
                          </p>
                          <div className="text-[9px] font-mono text-slate-400 pt-1 border-t border-slate-100 dark:border-slate-700/50">
                            Formula: {opt.formula}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* Panel 4: Skema Lembur & Sanksi Keterlambatan */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {/* Lembur Rate */}
                  <div className="p-4 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 space-y-3">
                    <div className="flex items-center justify-between">
                      <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                        <Clock className="w-4 h-4 text-orange-500" /> Skema Tarif Lembur
                      </h4>
                      <span className="text-[9px] bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded font-mono">
                        attendance_settings.overtime_rate_type
                      </span>
                    </div>
                    
                    <div className="space-y-2">
                      <label className={`flex items-start gap-2.5 p-3 rounded-xl border cursor-pointer transition ${
                        form.overtime_rate_type === 'depnaker'
                          ? 'border-orange-500 bg-white dark:bg-slate-800 ring-1 ring-orange-500'
                          : 'border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-800/50'
                      }`}>
                        <input
                          type="radio"
                          name="overtime_rate_type"
                          value="depnaker"
                          checked={form.overtime_rate_type === 'depnaker'}
                          onChange={() => setForm({ ...form, overtime_rate_type: 'depnaker' })}
                          className="w-4 h-4 accent-orange-600 mt-0.5"
                        />
                        <div className="space-y-0.5">
                          <span className="text-xs font-bold text-slate-800 dark:text-slate-200 block">
                            Standar PP No. 35/2021 (Depnaker)
                          </span>
                          <p className="text-[10px] text-slate-500 dark:text-slate-400 leading-tight">
                            Rumus 1/173 × Upah Sebulan (Gaji Pokok + Tunjangan Tetap) dengan pengali berjenjang 1.5x / 2x / 3x / 4x.
                          </p>
                        </div>
                      </label>

                      <label className={`flex items-start gap-2.5 p-3 rounded-xl border cursor-pointer transition ${
                        form.overtime_rate_type === 'flat_per_hour'
                          ? 'border-orange-500 bg-white dark:bg-slate-800 ring-1 ring-orange-500'
                          : 'border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-800/50'
                      }`}>
                        <input
                          type="radio"
                          name="overtime_rate_type"
                          value="flat_per_hour"
                          checked={form.overtime_rate_type === 'flat_per_hour'}
                          onChange={() => setForm({ ...form, overtime_rate_type: 'flat_per_hour' })}
                          className="w-4 h-4 accent-orange-600 mt-0.5"
                        />
                        <div className="space-y-2 flex-1">
                          <div className="space-y-0.5">
                            <span className="text-xs font-bold text-slate-800 dark:text-slate-200 block">
                              Tarif Flat Nominal per Jam
                            </span>
                            <p className="text-[10px] text-slate-500 dark:text-slate-400 leading-tight">
                              Nominal tetap per jam tanpa mengacu rumus Depnaker.
                            </p>
                          </div>
                          
                          {form.overtime_rate_type === 'flat_per_hour' && (
                            <div className="space-y-1 pt-1">
                              <label className="text-[9px] font-bold text-slate-500 dark:text-slate-400">Nominal Lembur per Jam (Rp)</label>
                              <div className="relative">
                                <span className="absolute left-2.5 top-2 text-xs font-bold text-slate-400">Rp</span>
                                <input
                                  type="number"
                                  min={0}
                                  step={1000}
                                  value={form.overtime_flat_rate || ''}
                                  onChange={(e) => setForm({ ...form, overtime_flat_rate: Math.max(0, parseFloat(e.target.value) || 0) })}
                                  placeholder="25000"
                                  className="w-full pl-8 pr-3 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-white dark:bg-slate-900 font-mono text-xs font-bold focus:ring-1 focus:ring-orange-500 focus:outline-none"
                                />
                              </div>
                              <div className="text-[9px] font-mono text-orange-600 dark:text-orange-400 font-bold">
                                Rp {Number(form.overtime_flat_rate || 0).toLocaleString('id-ID')} / jam
                              </div>
                            </div>
                          )}
                        </div>
                      </label>
                    </div>
                  </div>

                  {/* Sanksi Telat */}
                  <div className="p-4 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 space-y-3">
                    <div className="flex items-center justify-between">
                      <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                        <AlertTriangle className="w-4 h-4 text-rose-500" /> Sanksi Potongan Keterlambatan
                      </h4>
                      <span className="text-[9px] bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-2 py-0.5 rounded font-mono">
                        attendance_settings.late_deduction_*
                      </span>
                    </div>
                    
                    <div className="space-y-2.5">
                      <div className="space-y-1">
                        <label className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">
                          Skema Potongan Keterlambatan
                        </label>
                        <select
                          value={form.late_deduction_type ?? 'none'}
                          onChange={(e) => setForm({ ...form, late_deduction_type: e.target.value })}
                          className="w-full p-2 border border-slate-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 text-xs font-semibold focus:ring-1 focus:ring-rose-500 focus:outline-none"
                        >
                          {LATE_DEDUCTION_TYPES.map((t) => (
                            <option key={t.value} value={t.value}>
                              {t.label}
                            </option>
                          ))}
                        </select>
                        <p className="text-[9px] text-slate-400">
                          {LATE_DEDUCTION_TYPES.find(t => t.value === form.late_deduction_type)?.desc}
                        </p>
                      </div>

                      {['flat_nominal', 'per_minute'].includes(form.late_deduction_type) && (
                        <div className="space-y-1.5 p-3 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
                          <label className="text-[10px] font-bold text-slate-600 dark:text-slate-300 block">
                            {form.late_deduction_type === 'flat_nominal'
                              ? 'Nominal Potongan Flat per Kejadian (Rp)'
                              : 'Nominal Potongan per Menit Telat (Rp/Menit)'}
                          </label>
                          <div className="relative">
                            <span className="absolute left-2.5 top-2 text-xs font-bold text-slate-400">Rp</span>
                            <input
                              type="number"
                              min={0}
                              step={500}
                              value={form.late_deduction_amount || ''}
                              onChange={(e) => setForm({ ...form, late_deduction_amount: Math.max(0, parseFloat(e.target.value) || 0) })}
                              placeholder="0"
                              className="w-full pl-8 pr-3 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-900 font-mono text-xs font-bold focus:ring-1 focus:ring-rose-500 focus:outline-none"
                            />
                          </div>
                          <div className="flex items-center justify-between text-[9px] text-slate-400">
                            <span>Estimasi potongan:</span>
                            <span className="font-mono font-bold text-rose-600 dark:text-rose-400">
                              Rp {Number(form.late_deduction_amount || 0).toLocaleString('id-ID')}
                              {form.late_deduction_type === 'per_minute' ? ' / menit' : ' / hari telat'}
                            </span>
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            )}

            <div className="flex gap-2.5 pt-3 border-t border-slate-100 dark:border-slate-800">
              <button type="button" onClick={() => setShowForm(false)} className="flex-1 py-2.5 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 rounded-xl text-xs font-semibold hover:bg-slate-50 dark:hover:bg-slate-800">Batal</button>
              <button type="submit" disabled={saving} className="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-bold rounded-xl text-xs">{saving ? 'Menyimpan...' : 'Simpan Pengaturan'}</button>
            </div>
          </form>

          {validationError && (
            <div className="absolute inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in duration-200">
              <div className="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-2xl max-w-sm w-full animate-in zoom-in-95 duration-200 border border-slate-100 dark:border-slate-700">
                <div className="flex items-center gap-3 text-rose-600 dark:text-rose-400 mb-4">
                  <div className="p-2 bg-rose-50 dark:bg-rose-900/30 rounded-full">
                    <AlertTriangle className="w-6 h-6" />
                  </div>
                  <h3 className="font-bold text-base">Tindakan Ditolak</h3>
                </div>
                <p className="text-slate-600 dark:text-slate-300 text-sm mb-6 leading-relaxed">
                  {validationError}
                </p>
                <button
                  type="button"
                  onClick={() => setValidationError(null)}
                  className="w-full py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 font-bold rounded-xl text-xs transition-colors"
                >
                  Kembali Edit Form
                </button>
              </div>
            </div>
          )}

          {/* Konfirmasi reset saldo cuti: anniversary sudah lewat tahun ini →
              reset saldo tahun berjalan langsung terjadi sekali saat disimpan */}
          {/* Konfirmasi reset saldo cuti: anniversary sudah lewat tahun ini →
              reset saldo tahun berjalan langsung terjadi sekali saat disimpan */}
          {confirmReset && (
            <div className="absolute inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in duration-200">
              <div className="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-2xl max-w-sm w-full animate-in zoom-in-95 duration-200 border border-slate-100 dark:border-slate-700">
                <div className="flex items-center gap-3 text-amber-600 dark:text-amber-400 mb-4">
                  <div className="p-2 bg-amber-50 dark:bg-amber-900/30 rounded-full">
                    <AlertTriangle className="w-6 h-6" />
                  </div>
                  <h3 className="font-bold text-base">Konfirmasi Reset Saldo Cuti</h3>
                </div>
                <p className="text-slate-600 dark:text-slate-300 text-sm mb-6 leading-relaxed">
                  {confirmReset}
                </p>
                <div className="flex gap-2.5">
                  <button
                    type="button"
                    onClick={() => setConfirmReset(null)}
                    className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 font-bold rounded-xl text-xs transition-colors"
                  >
                    Batal
                  </button>
                  <button
                    type="button"
                    onClick={doSave}
                    disabled={saving}
                    className="flex-1 py-2.5 bg-amber-500 hover:bg-amber-600 disabled:opacity-60 text-white font-bold rounded-xl text-xs transition-colors"
                  >
                    {saving ? 'Menyimpan...' : 'Ya, Lanjutkan'}
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Gerbang field berbahaya: HRD wajib mengetik "SIMPAN" untuk
              mengonfirmasi perubahan aturan presensi di tengah hari */}
          {dangerousFields && (
            <div className="absolute inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in duration-200">
              <div className="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-2xl max-w-sm w-full animate-in zoom-in-95 duration-200 border border-slate-100 dark:border-slate-700">
                <div className="flex items-center gap-3 text-amber-600 dark:text-amber-400 mb-3">
                  <div className="p-2 bg-amber-50 dark:bg-amber-900/30 rounded-full">
                    <AlertTriangle className="w-6 h-6" />
                  </div>
                  <h3 className="font-bold text-base">Perubahan Aturan Presensi</h3>
                </div>
                <p className="text-slate-600 dark:text-slate-300 text-sm mb-2 leading-relaxed">
                  ⚠️ Anda mengubah aturan presensi berikut di tengah hari:
                </p>
                <ul className="text-xs text-amber-700 dark:text-amber-400 font-semibold list-disc list-inside mb-3 space-y-0.5">
                  {dangerousFields.map((f) => (
                    <li key={f}>{DANGEROUS_LABELS[f] ?? f}</li>
                  ))}
                </ul>
                <p className="text-slate-600 dark:text-slate-300 text-sm mb-4 leading-relaxed">
                  Ini mengubah cara sistem menghitung telat, lembur, dan auto-checkout untuk karyawan yang <strong>belum check-in hari ini</strong> dan <strong>seluruh presensi esok hari</strong>. Karyawan yang sudah check-in tetap memakai aturan saat mereka masuk tadi.
                </p>
                <label className="text-[10px] font-bold text-slate-400 block mb-1">
                  Ketik <span className="font-mono text-rose-600 dark:text-rose-400">SIMPAN</span> untuk melanjutkan
                </label>
                <input
                  type="text"
                  value={confirmText}
                  onChange={(e) => setConfirmText(e.target.value)}
                  placeholder="Ketik SIMPAN di sini"
                  autoFocus
                  className={`w-full p-2.5 border rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 font-mono text-sm mb-4 ${confirmText.length > 0 && confirmText !== 'SIMPAN' ? 'border-rose-300 dark:border-rose-700' : 'border-slate-200 dark:border-slate-700'}`}
                />
                <div className="flex gap-2.5">
                  <button
                    type="button"
                    onClick={() => setDangerousFields(null)}
                    className="flex-1 py-2.5 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 font-bold rounded-xl text-xs transition-colors"
                  >
                    Batal
                  </button>
                  <button
                    type="button"
                    onClick={() => doSave(true)}
                    disabled={saving || confirmText !== 'SIMPAN'}
                    className="flex-1 py-2.5 bg-amber-500 hover:bg-amber-600 disabled:opacity-40 disabled:cursor-not-allowed text-white font-bold rounded-xl text-xs transition-colors"
                  >
                    {saving ? 'Menyimpan...' : 'Ya, Simpan Perubahan'}
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

// ─── Tipe tab ────────────────────────────────────────────────
type TabKey = 'offices' | 'rules';

const TABS: { key: TabKey; label: string; icon: React.ElementType }[] = [
  { key: 'offices', label: 'Kantor Presensi', icon: Building2 },
  { key: 'rules', label: 'Aturan Klaim & Invoice', icon: Settings },
];

// ─── Komponen utama: Pengaturan Aturan ───────────────────────
interface Props {
  onAddAuditLog: (title: string, desc: string, color: string) => void;
  currentSettings: AppSettings;
  onSaveSettings: (s: AppSettings) => void;
}

export const SettingsManagement: React.FC<Props> = ({
  onAddAuditLog,
  currentSettings,
  onSaveSettings,
}) => {
  const [tab, setTab] = useState<TabKey>('offices');
  const [offices, setOffices] = useState<any[]>([]);
  const [error, setError] = useState<string | null>(null);

  const reportApiError = (e: unknown, fallback: string) => {
    const msg = (e as any)?.message ?? fallback;
    setError(msg);
  };

  const loadOffices = useCallback(async () => {
    setError(null);
    try {
      const res: any = await attendanceApi.settings.list();
      setOffices(res?.settings ?? []);
    } catch (e) {
      reportApiError(e, 'Gagal memuat data kantor.');
    }
  }, []);

  // Muat data saat pertama kali render
  React.useEffect(() => {
    loadOffices();
  }, [loadOffices]);

  return (
    <div className="space-y-5">
      {/* Tab Navigation */}
      <div className="flex gap-1.5 overflow-x-auto pb-1">
        {TABS.map(({ key, label, icon: Icon }) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={`flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold whitespace-nowrap transition ${tab === key
                ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-500/20'
                : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800'
              }`}
          >
            <Icon className="w-3.5 h-3.5" />
            {label}
          </button>
        ))}
      </div>

      {/* Error banner */}
      {error && (
        <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-lg px-4 py-3 text-xs">
          <span className="font-semibold">Error:</span> {error}
          <button onClick={() => setError(null)} className="ml-auto text-rose-500 hover:text-rose-700 font-bold">✕</button>
        </div>
      )}

      {/* ─── TAB: Kantor Presensi ─── */}
      {tab === 'offices' && (
        <OfficesTab
          offices={offices}
          reload={loadOffices}
          onAddAuditLog={onAddAuditLog}
          onError={reportApiError}
        />
      )}

      {/* ─── TAB: Aturan Klaim & Invoice ─── */}
      {tab === 'rules' && (
        <SettingsView
          currentSettings={currentSettings}
          onSaveSettings={onSaveSettings}
        />
      )}
    </div>
  );
};
