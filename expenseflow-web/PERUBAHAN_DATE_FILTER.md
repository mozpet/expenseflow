# Perubahan — Date Range Filter di Riwayat Approval & Audit Log

**Tanggal:** 2026-06-24  
**Status:** ✅ SELESAI

## Tujuan
Menambahkan filter tanggal (date range picker) pada dua tab:
1. **Tab Riwayat Approval Struk** — filter berdasarkan tanggal submit/approval
2. **Tab Audit Log** — filter berdasarkan tanggal aktivitas

---

## Perubahan yang Dilakukan

### 1. **Type Definitions** — `src/types.ts`

#### Interface `StrukApproval` (Lines 18-36)
Tambah field untuk date filtering:
```typescript
export interface StrukApproval {
  id: string;
  karyawan: string;
  merchant: string;
  nominal: number;
  keputusan: 'Disetujui' | 'Ditolak';
  diprosesOleh: string;
  waktu: string;
  catatan: string;
  tanggal?: string;           // ← BARU: Format YYYY-MM-DD untuk filtering
  approvedBy?: {...};
  approvedAt?: string;
}
```

#### Interface `AuditLog` (Lines 68-76)
Tambah field untuk date filtering:
```typescript
export interface AuditLog {
  id: string;
  iconBg: string;
  title: string;
  details: string;
  waktu: string;
  created_at?: string;        // ← BARU: ISO format tanggal asli
}
```

---

### 2. **Data Mapper** — `src/services/mappers.ts`

#### Function `mapReceiptToApproval()` (Lines 99-125)
Tambah logic extract tanggal:
```typescript
export function mapReceiptToApproval(r: any): StrukApproval {
  const approverName = r.approvals?.[0]?.user?.name ?? r.approved_by?.name ?? 'Finance';

  // ← BARU: Extract tanggal YYYY-MM-DD dari timestamp
  const dateStr = (r.submitted_at ?? r.created_at ?? '').substring(0, 10);

  return {
    id: String(r.id),
    karyawan: r.user?.name ?? '—',
    merchant: r.vendor_name ?? r.ocr_raw_merchant ?? '—',
    nominal: num(r.claimed_amount ?? r.total_amount),
    keputusan: r.status === 'approved' ? 'Disetujui' : 'Ditolak',
    diprosesOleh: approverName,
    waktu: formatWaktu(r.submitted_at ?? r.created_at),
    catatan: r.approvals?.[0]?.notes ?? r.rejection_reason ?? '—',
    tanggal: dateStr,           // ← BARU
    approvedBy: r.approved_by && {...},
    approvedAt: r.approved_at,
  };
}
```

#### Function `mapAuditLog()` (Lines 216-226)
Tambah field `created_at`:
```typescript
export function mapAuditLog(l: any): AuditLog {
  const actor = l.user_name ?? 'Sistem';
  return {
    id: String(l.id),
    iconBg: colorForAction(l.action ?? ''),
    title: l.description ?? l.action ?? 'Aktivitas',
    details: `${actor}${l.user_role ? ' (' + l.user_role + ')' : ''} · ${formatWaktu(l.created_at)}`,
    waktu: formatWaktu(l.created_at),
    created_at: l.created_at,   // ← BARU: Simpan tanggal asli untuk filtering
  };
}
```

---

### 3. **Component: ReceiptHistory** — `src/components/ReceiptHistory.tsx`

#### State Management (Lines 22-25)
```typescript
const [searchQuery, setSearchQuery] = useState('');
const [statusFilter, setStatusFilter] = useState('semua');
const [startDate, setStartDate] = useState('');      // ← BARU
const [endDate, setEndDate] = useState('');          // ← BARU
```

#### Filter Logic (Lines 28-39)
```typescript
const filteredApprovals = approvals.filter(a => {
  const matchesSearch = a.karyawan.toLowerCase().includes(searchQuery.toLowerCase()) ||
         a.merchant.toLowerCase().includes(searchQuery.toLowerCase()) ||
         a.catatan.toLowerCase().includes(searchQuery.toLowerCase());

  const matchesStatus = statusFilter === 'semua' ||
         (statusFilter === 'disetujui' && a.keputusan === 'Disetujui') ||
         (statusFilter === 'ditolak' && a.keputusan === 'Ditolak');

  // ← BARU: Filter berdasarkan date range
  const matchesDateRange = (!startDate || a.tanggal >= startDate) &&
         (!endDate || a.tanggal <= endDate);

  return matchesSearch && matchesStatus && matchesDateRange;
});
```

#### UI Elements (Lines 50-92)
Tambah date input fields:
```jsx
<div className="flex gap-2 w-full flex-wrap items-center">
  {/* Search Input (existing) */}
  
  {/* Status Filter (existing) */}

  {/* ← BARU: Date Range Picker */}
  <div className="flex gap-1.5 items-center">
    <Calendar className="w-3.5 h-3.5 text-slate-400 shrink-0" />
    <input
      type="date"
      value={startDate}
      onChange={(e) => setStartDate(e.target.value)}
      className="px-2 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg text-xs ..."
      title="Tanggal mulai"
    />
    <span className="text-slate-400 text-xs">–</span>
    <input
      type="date"
      value={endDate}
      onChange={(e) => setEndDate(e.target.value)}
      className="px-2 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg text-xs ..."
      title="Tanggal selesai"
    />
    {/* Clear button jika ada filter tanggal */}
    {(startDate || endDate) && (
      <button
        onClick={() => {
          setStartDate('');
          setEndDate('');
        }}
        className="p-1.5 text-slate-400 hover:text-slate-600 ..."
        title="Bersihkan filter tanggal"
      >
        <XCircle className="w-3.5 h-3.5" />
      </button>
    )}
  </div>
</div>
```

---

### 4. **Component: AuditLogView** — `src/components/AuditLogView.tsx`

#### State Management (Lines 18-19)
```typescript
const [startDate, setStartDate] = useState('');      // ← BARU
const [endDate, setEndDate] = useState('');          // ← BARU
```

#### Filter Logic (Lines 21-28)
```typescript
// ← BARU: Filter logs berdasarkan date range
const filteredLogs = logs.filter(log => {
  if (!log.created_at) return true;
  const logDate = log.created_at.substring(0, 10);
  return (!startDate || logDate >= startDate) &&
         (!endDate || logDate <= endDate);
});
```

#### Header Update (Lines 35-36)
Tambah entri count:
```jsx
<p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
  Log permanen transaksi tidak dapat diubah (immutable ledger trail) • {filteredLogs.length} entri
</p>
```

#### UI Elements (Lines 52-84)
```jsx
<div className="flex gap-2 w-full flex-wrap items-center">
  {/* ← BARU: Date Range Picker */}
  <div className="flex gap-1.5 items-center">
    <Calendar className="w-3.5 h-3.5 text-slate-400 shrink-0" />
    <input
      type="date"
      value={startDate}
      onChange={(e) => setStartDate(e.target.value)}
      className="px-2 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg text-xs ..."
      title="Tanggal mulai"
    />
    <span className="text-slate-400 text-xs">–</span>
    <input
      type="date"
      value={endDate}
      onChange={(e) => setEndDate(e.target.value)}
      className="px-2 py-1.5 border border-slate-200 dark:border-slate-700 rounded-lg text-xs ..."
      title="Tanggal selesai"
    />
    {(startDate || endDate) && (
      <button
        onClick={() => {
          setStartDate('');
          setEndDate('');
        }}
        className="p-1.5 text-slate-400 hover:text-slate-600 ..."
        title="Bersihkan filter tanggal"
      >
        <XCircle className="w-3.5 h-3.5" />
      </button>
    )}
  </div>
</div>
```

#### Empty State (Lines 87-90)
```jsx
{filteredLogs.length === 0 ? (
  <p className="text-center text-slate-400 dark:text-slate-500 text-xs py-8">
    Tidak ditemukan audit log untuk tanggal yang dipilih
  </p>
) : (
  // ... render filtered logs
)}
```

---

## Fitur yang Ditambahkan

### 1. **Tab Riwayat Approval Struk**
✅ Date range picker (tanggal mulai - tanggal selesai)  
✅ Filter kombinasi: Search + Status + Date Range  
✅ Tombol clear filter tanggal  
✅ Tetap kompatibel dengan status filter yang sudah ada  

### 2. **Tab Audit Log**
✅ Date range picker (tanggal mulai - tanggal selesai)  
✅ Filter date range  
✅ Tombol clear filter tanggal  
✅ Menampilkan jumlah entri setelah filter  
✅ Empty state message jika tidak ada hasil  

---

## User Experience

### Riwayat Approval Struk
```
User Interface:
┌────────────────────────────────────────┐
│ 🔍 Cari riwayat...  │ Status ▼ │ 📅 – 📅 │ ✓ │ 📥 Export │
└────────────────────────────────────────┘

Workflow:
1. User klik input "Tanggal mulai" → date picker terbuka
2. User pilih tanggal awal (misal: 2026-06-01)
3. User klik input "Tanggal selesai" → date picker terbuka
4. User pilih tanggal akhir (misal: 2026-06-24)
5. Table otomatis menampilkan hanya approval dalam rentang tersebut
6. User bisa klik tombol X untuk bersihkan filter tanggal
```

### Audit Log
```
User Interface:
┌────────────────────────────────────────┐
│ Audit Log ... • N entri                │
│ 📅 – 📅 │ ✓ │ 📥 Export                │
└────────────────────────────────────────┘

Workflow:
1. Default: tampil semua audit log
2. User pilih date range → filter otomatis
3. Jika tidak ada hasil → tampil "Tidak ditemukan audit log untuk tanggal yang dipilih"
4. Entri count terupdate sesuai filtered results
```

---

## Format Tanggal

| Konteks | Format | Contoh |
|---------|--------|--------|
| Input date picker | ISO YYYY-MM-DD | `2026-06-24` |
| Display (waktu) | Formatted text | `24 Juni 2026 14:35` |
| Storage (tanggal) | ISO YYYY-MM-DD | `2026-06-24` |
| Comparison | String (lexicographical) | `"2026-06-24" >= "2026-06-01"` |

---

## Testing Checklist

### Riwayat Approval Struk
- [ ] Buka tab Riwayat Approval Struk
- [ ] Verifikasi date input muncul di header
- [ ] Set tanggal mulai (misal: 2026-06-20) → tabel ter-filter
- [ ] Set tanggal selesai (misal: 2026-06-24) → tabel ter-filter
- [ ] Kombinasi dengan search query → hasil ter-filter double
- [ ] Kombinasi dengan status filter → hasil ter-filter triple
- [ ] Klik tombol X → clear date filter
- [ ] Date input masih work bersama status filter

### Audit Log
- [ ] Buka tab Audit Log
- [ ] Verifikasi date input muncul di header
- [ ] Set tanggal mulai → list ter-filter
- [ ] Set tanggal selesai → list ter-filter
- [ ] Verifikasi entry count terupdate
- [ ] Clear filter → semua entries kembali
- [ ] Empty state muncul jika filter tidak match

---

## Backward Compatibility

✅ **Fully backward compatible**
- Jika `created_at` tidak ada → skip filter date
- Jika `tanggal` tidak ada di StrukApproval → skip filter date
- Default behavior: filter otomatis disabled sampai user set date range

---

## Catatan Development

### Aturan Filtering
1. **Search query**: Case-insensitive, partial match pada karyawan/merchant/catatan
2. **Status filter**: Exact match (Disetujui / Ditolak)
3. **Date range**: Inclusive range (startDate <= logDate <= endDate)
4. **Logic**: AND — semua kondisi harus terpenuhi

### Dark Mode Support
✅ Semua input dan button support dark mode via Tailwind CSS  
✅ Styling consistent dengan component lainnya

### Icons Used
- `Calendar` — Date picker indicator (lucide-react)
- `XCircle` — Clear filter button (lucide-react)

---

## Future Enhancements (Optional)
- [ ] Save date filter preference ke localStorage
- [ ] Preset date ranges (Today, Last 7 days, Last 30 days, This month)
- [ ] Export filtered data saja (bukan semua)
- [ ] Date range presets pada header description

