import React, { useState, useCallback } from 'react';
import {
  Building2,
  Settings,
  Clock,
  MapPin,
  Plus,
  Trash2,
  X,
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
    late_tolerance_minutes: 15,
    wfh_checkin_window_minutes: 120,
    overtime_enabled: true,
    min_overtime_minutes: 30,
    early_leave_tolerance_minutes: 30,
  };
  const [showForm, setShowForm] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [form, setForm] = useState<any>(empty);
  const [saving, setSaving] = useState(false);

  const openAdd = () => { setForm(empty); setEditId(null); setShowForm(true); };
  const openEdit = (o: any) => {
    setForm({
      office_name: o.office_name ?? '',
      office_latitude: o.office_latitude ?? '',
      office_longitude: o.office_longitude ?? '',
      radius_meters: o.radius_meters ?? 100,
      work_start_time: (o.work_start_time ?? '08:00').slice(0, 5),
      work_end_time: (o.work_end_time ?? '17:00').slice(0, 5),
      late_tolerance_minutes: o.late_tolerance_minutes ?? 15,
      wfh_checkin_window_minutes: o.wfh_checkin_window_minutes ?? 120,
      overtime_enabled: o.overtime_enabled ?? true,
      min_overtime_minutes: o.min_overtime_minutes ?? 30,
      early_leave_tolerance_minutes: o.early_leave_tolerance_minutes ?? 30,
    });
    setEditId(o.id);
    setShowForm(true);
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.office_name || form.office_latitude === '' || form.office_longitude === '') {
      alert('Nama kantor, latitude, dan longitude wajib diisi.');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        office_name: form.office_name,
        office_latitude: Number(form.office_latitude),
        office_longitude: Number(form.office_longitude),
        radius_meters: Number(form.radius_meters),
        work_start_time: form.work_start_time,
        work_end_time: form.work_end_time,
        late_tolerance_minutes: Number(form.late_tolerance_minutes),
        wfh_checkin_window_minutes: form.wfh_checkin_window_minutes === '' ? null : Number(form.wfh_checkin_window_minutes),
        overtime_enabled: !!form.overtime_enabled,
        min_overtime_minutes: Number(form.min_overtime_minutes),
        early_leave_tolerance_minutes: form.early_leave_tolerance_minutes === '' ? null : Number(form.early_leave_tolerance_minutes),
      };
      if (editId) {
        await attendanceApi.settings.update(editId, payload);
        onAddAuditLog('Kantor Presensi Diperbarui', `Kantor ${form.office_name} diperbarui`, 'bg-indigo-600');
      } else {
        await attendanceApi.settings.create(payload);
        onAddAuditLog('Kantor Presensi Ditambahkan', `Kantor ${form.office_name} ditambahkan`, 'bg-emerald-600');
      }
      setShowForm(false);
      await reload();
    } catch (e2) {
      onError(e2, 'Gagal menyimpan kantor.');
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
              </div>
            </div>
          ))
        )}
      </div>

      {showForm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div onClick={() => setShowForm(false)} className="fixed inset-0 bg-slate-900/60 backdrop-blur-xs" />
          <form onSubmit={submit} className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-lg p-6 shadow-2xl relative z-10 space-y-4 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center pb-3 border-b border-slate-100 dark:border-slate-800">
              <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
                <Building2 className="w-4 h-4 text-indigo-600" /> {editId ? 'Edit Kantor' : 'Tambah Kantor'}
              </h3>
              <button type="button" onClick={() => setShowForm(false)} className="p-1 hover:bg-slate-50 dark:hover:bg-slate-800 rounded-full"><X className="w-4 h-4" /></button>
            </div>

            <div className="space-y-3 text-xs">
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 block">Nama Kantor *</label>
                <input type="text" value={form.office_name} onChange={(e) => setForm({ ...form, office_name: e.target.value })} required className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100" />
              </div>
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 block">Lokasi Kantor *</label>
                <LocationPicker
                  lat={form.office_latitude}
                  lng={form.office_longitude}
                  onChange={(lat, lng) => setForm({ ...form, office_latitude: lat, office_longitude: lng })}
                />
              </div>
              <div className="grid grid-cols-3 gap-3">
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 block">Radius (m)</label>
                  <input type="number" value={form.radius_meters} onChange={(e) => setForm({ ...form, radius_meters: e.target.value })} className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 font-mono" />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 block">Masuk</label>
                  <input type="time" value={form.work_start_time} onChange={(e) => setForm({ ...form, work_start_time: e.target.value })} className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100" />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-bold text-slate-400 block">Pulang</label>
                  <input type="time" value={form.work_end_time} onChange={(e) => setForm({ ...form, work_end_time: e.target.value })} className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100" />
                </div>
              </div>
              <div className="space-y-1">
                <label className="text-[10px] font-bold text-slate-400 block">Toleransi Telat (menit)</label>
                <input type="number" value={form.late_tolerance_minutes} onChange={(e) => setForm({ ...form, late_tolerance_minutes: e.target.value })} className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 font-mono" />
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
                  Contoh: 120 = karyawan WFH hanya bisa presensi mulai 2 jam sebelum jam masuk. Kosongkan untuk tidak dibatasi.
                </p>
              </div>

              <div className="pt-3 border-t border-slate-100 dark:border-slate-800 space-y-3">
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
                      className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 font-mono"
                    />
                    <p className="text-[9px] text-slate-400">
                      Lembur dihitung jika melewati jam pulang ≥ ambang ini. Kerja di hari libur/weekend dihitung lembur penuh.
                    </p>
                  </div>
                )}

                <div className="pt-3 border-t border-slate-100 dark:border-slate-800 space-y-3">
                  <label className="flex items-center justify-between gap-3 cursor-pointer">
                    <span className="text-[11px] font-bold text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
                      <Clock className="w-3.5 h-3.5 text-violet-500" /> Deteksi pulang awal
                    </span>
                    <input
                      type="checkbox"
                      checked={form.early_leave_tolerance_minutes !== '' && form.early_leave_tolerance_minutes !== null}
                      onChange={(e) => setForm({ ...form, early_leave_tolerance_minutes: e.target.checked ? 30 : '' })}
                      className="w-4 h-4 accent-violet-600"
                    />
                  </label>
                  {form.early_leave_tolerance_minutes !== '' && form.early_leave_tolerance_minutes !== null && (
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
                        className="w-full p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50 dark:bg-slate-800/20 text-slate-800 dark:text-slate-100 font-mono"
                      />
                      <p className="text-[9px] text-slate-400">
                        Contoh: 30 = karyawan yang check-out lebih dari 30 menit sebelum jam pulang ditandai <strong className="text-violet-600">Pulang Awal</strong>. Tidak berlaku di hari libur/weekend.
                      </p>
                    </div>
                  )}
                </div>
              </div>
            </div>

            <div className="flex gap-2.5 pt-3 border-t border-slate-100 dark:border-slate-800">
              <button type="button" onClick={() => setShowForm(false)} className="flex-1 py-2.5 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 rounded-xl text-xs font-semibold hover:bg-slate-50 dark:hover:bg-slate-800">Batal</button>
              <button type="submit" disabled={saving} className="flex-1 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-bold rounded-xl text-xs">{saving ? 'Menyimpan...' : 'Simpan'}</button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
};

// ─── Tipe tab ────────────────────────────────────────────────
type TabKey = 'offices' | 'rules';

const TABS: { key: TabKey; label: string; icon: React.ElementType }[] = [
  { key: 'offices', label: 'Kantor Presensi', icon: Building2 },
  { key: 'rules',   label: 'Aturan Klaim & Invoice', icon: Settings },
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
            className={`flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold whitespace-nowrap transition ${
              tab === key
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
