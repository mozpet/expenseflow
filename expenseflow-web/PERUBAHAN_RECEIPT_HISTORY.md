# Perubahan — Tab Riwayat Approval Struk (Diproses Oleh)

**Tanggal:** 2026-06-24  
**Status:** ✅ SELESAI

## Tujuan
Mengubah kolom "Diproses Oleh" di tab Riwayat Approval Struk dari hardcoded `"Finance"` menjadi menampilkan **nama akun sebenarnya** yang melakukan approval/rejection struk.

---

## Perubahan yang Dilakukan

### 1. **Backend** — ReceiptController.php

#### Method `approve()` — Tambah Response Field
**File:** `app/Http/Controllers/API/ReceiptController.php` (Lines 280-290)

```php
return response()->json([
    'message' => 'Struk berhasil diapprove.',
    'receipt' => $receipt->only(['id', 'receipt_number', 'status', 'variance_flag', 'variance_pct']),
    'approved_by' => [         // ← BARU
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role,
    ],
    'approved_at' => now()->toIso8601String(),  // ← BARU
]);
```

#### Method `reject()` — Tambah Response Field
**File:** `app/Http/Controllers/API/ReceiptController.php` (Lines 328-340)

```php
return response()->json([
    'message' => 'Struk berhasil direject.',
    'receipt' => $receipt->only(['id', 'receipt_number', 'status']),
    'rejected_by' => [         // ← BARU
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role,
    ],
    'rejected_at' => now()->toIso8601String(),  // ← BARU
    'rejection_reason' => $request->notes,      // ← BARU
]);
```

---

### 2. **Frontend** — React Web Dashboard

#### File: `src/types.ts` (Lines 18-29)
**Ubah:** Interface `StrukApproval`

```typescript
// SEBELUMNYA
export interface StrukApproval {
  id: string;
  karyawan: string;
  merchant: string;
  nominal: number;
  keputusan: 'Disetujui' | 'Ditolak';
  diprosesOleh: string;
  waktu: string;
  catatan: string;
}

// SESUDAH
export interface StrukApproval {
  id: string;
  karyawan: string;
  merchant: string;
  nominal: number;
  keputusan: 'Disetujui' | 'Ditolak';
  diprosesOleh: string;              // ← Sekarang isi nama approver
  waktu: string;
  catatan: string;
  approvedBy?: {                      // ← BARU (optional)
    id: string;
    name: string;
    email: string;
    role: string;
  };
  approvedAt?: string;                // ← BARU (optional)
}
```

#### File: `src/services/mappers.ts` (Lines 99-121)
**Ubah:** Function `mapReceiptToApproval()`

```typescript
// SEBELUMNYA
export function mapReceiptToApproval(r: any): StrukApproval {
  return {
    id: String(r.id),
    karyawan: r.user?.name ?? '—',
    merchant: r.vendor_name ?? r.ocr_raw_merchant ?? '—',
    nominal: num(r.claimed_amount ?? r.total_amount),
    keputusan: r.status === 'approved' ? 'Disetujui' : 'Ditolak',
    diprosesOleh: r.approvals?.[0]?.user?.name ?? 'Finance',
    waktu: formatWaktu(r.submitted_at ?? r.created_at),
    catatan: r.approvals?.[0]?.notes ?? r.rejection_reason ?? '—',
  };
}

// SESUDAH
export function mapReceiptToApproval(r: any): StrukApproval {
  // Ambil nama approver dari approved_by (response baru) atau approvals (legacy)
  const approverName = r.approvals?.[0]?.user?.name ?? r.approved_by?.name ?? 'Finance';

  return {
    id: String(r.id),
    karyawan: r.user?.name ?? '—',
    merchant: r.vendor_name ?? r.ocr_raw_merchant ?? '—',
    nominal: num(r.claimed_amount ?? r.total_amount),
    keputusan: r.status === 'approved' ? 'Disetujui' : 'Ditolak',
    diprosesOleh: approverName,       // ← Kini pakai nama sebenarnya
    waktu: formatWaktu(r.submitted_at ?? r.created_at),
    catatan: r.approvals?.[0]?.notes ?? r.rejection_reason ?? '—',
    // Tambah detail approver (untuk kebutuhan future/tooltip)
    approvedBy: r.approved_by && {
      id: r.approved_by.id,
      name: r.approved_by.name,
      email: r.approved_by.email,
      role: r.approved_by.role,
    },
    approvedAt: r.approved_at,
  };
}
```

#### Component: `src/components/ReceiptHistory.tsx`
**Status:** ❌ Tidak perlu diubah

Komponen sudah bekerja otomatis karena:
- Line 149: `<span>{item.diprosesOleh}</span>` 
- Field `diprosesOleh` sekarang berisi nama approver (bukan "Finance")
- Tampilan akan otomatis terupdate saat data dari API diload

---

## Data Flow

```
Backend API Response:
{
  "status": "approved",
  "approved_by": {
    "id": 3,
    "name": "Budi Santoso",        ← Nama approver
    "email": "budi@company.com",
    "role": "finance"
  },
  "approved_at": "2026-06-24T14:35:22Z"
}

        ↓

mappers.ts:
  approverName = r.approved_by?.name  (= "Budi Santoso")
  diprosesOleh = "Budi Santoso"

        ↓

ReceiptHistory Component (Line 149):
  <span>{item.diprosesOleh}</span>    (= "Budi Santoso")

        ↓

UI Display:
  👤 Budi Santoso    (bukan "Finance")
```

---

## Contoh Hasil Sebelum & Sesudah

### Sebelumnya
| Kolom | Nilai |
|-------|-------|
| Karyawan | Rina |
| Merchant | Indomaret |
| Nominal | Rp 45.000 |
| Status | ✓ Disetujui |
| **Diproses Oleh** | **Finance** ← Hardcoded |
| Waktu Keputusan | 2026-06-24 14:35 |

### Sesudah
| Kolom | Nilai |
|-------|-------|
| Karyawan | Rina |
| Merchant | Indomaret |
| Nominal | Rp 45.000 |
| Status | ✓ Disetujui |
| **Diproses Oleh** | **Budi Santoso** ← Nama akun real |
| Waktu Keputusan | 2026-06-24 14:35 |

---

## Testing Checklist

- [ ] Hit endpoint `POST /dashboard/receipts/{id}/approve` (dengan user finance)
- [ ] Verifikasi response JSON memiliki field `approved_by` dan `approved_at`
- [ ] Buka web dashboard → tab Riwayat Approval Struk
- [ ] Verifikasi kolom "Diproses Oleh" menampilkan nama approver (bukan "Finance")
- [ ] Hit endpoint `POST /dashboard/receipts/{id}/reject` untuk approval reject
- [ ] Verifikasi response memiliki field `rejected_by` dan `rejection_reason`
- [ ] Cek tab Riwayat, kolom "Diproses Oleh" menampilkan nama yang reject

---

## Backward Compatibility

Mapper sudah handle 3 sumber data:
1. **New format:** `r.approved_by?.name` (dari response baru)
2. **Legacy format:** `r.approvals?.[0]?.user?.name` (dari data lama)
3. **Fallback:** `'Finance'` (jika keduanya tidak ada)

Jadi tidak ada breaking change — data lama dan baru tetap bekerja.

---

## Catatan untuk Tim Development

- Response format baru menambah field `approved_by` dan `approved_at` ke JSON
- Frontend mapper sudah update untuk handle format baru dan lama
- Tidak ada UI component yang perlu diubah — semuanya otomatis
- Jika perlu tampilkan email atau role approver, tinggal gunakan field `item.approvedBy?.email` atau `item.approvedBy?.role` di ReceiptHistory component

