# ReceiptHistory Data Source Analysis

## Summary
The ReceiptHistory component displays approval history for processed receipts (struk). The `diprosesOleh` field is currently populated from backend approval records and has mock data fallback.

---

## 1. File Paths: Mock/Dummy Data Definition

### Primary Mock Data File
- **File:** `web akuntan/src/data.ts`
- **Lines:** 62-113
- **Variable:** `initialStrukApprovals: StrukApproval[]`

**Example mock data:**
```typescript
export const initialStrukApprovals: StrukApproval[] = [
  {
    id: 'RA-01',
    karyawan: 'Diana Putri',
    merchant: 'Grab Food',
    nominal: 320000,
    keputusan: 'Disetujui',
    diprosesOleh: 'Sari Rahma',        // ← This field
    waktu: '25 Mei, 14:20',
    catatan: '—'
  },
  {
    id: 'RA-02',
    karyawan: 'Hendra K',
    merchant: 'SPBU Pertamina',
    nominal: 300000,
    keputusan: 'Ditolak',
    diprosesOleh: 'Sari Rahma',
    waktu: '24 Mei, 10:05',
    catatan: 'Struk tidak terbaca'
  }
];
```

**Current Status:** All 5 mock items have `diprosesOleh` hardcoded to `'Sari Rahma'`.

---

## 2. Data Structure: StrukApproval Interface

**File:** `web akuntan/src/types.ts` (Lines 18-27)

```typescript
export interface StrukApproval {
  id: string;
  karyawan: string;
  merchant: string;
  nominal: number;
  keputusan: 'Disetujui' | 'Ditolak';
  diprosesOleh: string;    // ← Field we're tracking
  waktu: string;
  catatan: string;
}
```

---

## 3. API Service Layer

### Receipt API Endpoints
**File:** `web akuntan/src/services/endpoints.ts` (Lines 27-40)

```typescript
export const receiptApi = {
  inbox: () => apiGet('/dashboard/receipts'),
  all: (status?: 'submitted' | 'approved' | 'rejected') =>
    apiGet('/dashboard/receipts/all', { status }),
  show: (id: number | string) => apiGet(`/dashboard/receipts/${id}`),
  approve: (id: number | string, notes: string) =>
    apiPost(`/dashboard/receipts/${id}/approve`, { notes }),
  reject: (id: number | string, notes: string) =>
    apiPost(`/dashboard/receipts/${id}/reject`, { notes }),
};
```

**Base URL:** `http://localhost:8000/api/v1` (from `services/api.ts`, line 4-6)

**API Endpoint for History:** `GET /dashboard/receipts/all`
- Returns approved AND rejected receipts with their approval tracking data

---

## 4. Mapper Function: Backend Response → Frontend Data

**File:** `web akuntan/src/services/mappers.ts` (Lines 98-110)

### The Critical Mapping Function
```typescript
export function mapReceiptToApproval(r: any): StrukApproval {
  return {
    id: String(r.id),
    karyawan: r.user?.name ?? '—',
    merchant: r.vendor_name ?? r.ocr_raw_merchant ?? '—',
    nominal: num(r.claimed_amount ?? r.total_amount),
    keputusan: r.status === 'approved' ? 'Disetujui' : 'Ditolak',
    diprosesOleh: r.approvals?.[0]?.user?.name ?? 'Finance',  // ← KEY FIELD
    waktu: formatWaktu(r.submitted_at ?? r.created_at),
    catatan: r.approvals?.[0]?.notes ?? r.rejection_reason ?? '—',
  };
}
```

**How `diprosesOleh` is populated (Line 106):**
1. First: Tries to extract from `r.approvals?.[0]?.user?.name` (nested object)
2. If not found: Falls back to hardcoded string `'Finance'`
3. Does NOT use the mock data from `data.ts`

---

## 5. Data Flow: Source to Display

### App.tsx - Main Data Loading
**Lines 98-104:**
```typescript
const loadReceiptHistory = useCallback(async () => {
  const res = await receiptApi.all();
  const list = rows(res.receipts ?? res);
  setReceiptHistory(
    list.filter((r: any) => r.status === 'approved' || r.status === 'rejected')
      .map(mapReceiptToApproval),
  );
}, []);
```

### App.tsx - Component Rendering
**Line 275:**
```typescript
case 'riwayat-struk':
  return <ReceiptHistory approvals={receiptHistory} />;
```

### Auto-refresh After Approval
**Lines 161-169:**
```typescript
const handleApproveReceipt = async (id: string, note: string) => {
  await receiptApi.approve(id, note);
  await Promise.all([
    loadReceipts(), 
    loadReceiptHistory(),      // ← Reloads history after action
    loadAuditLogs(), 
    loadNotifications()
  ]);
};
```

---

## 6. Expected Backend Response Structure

For the mapper to correctly extract `diprosesOleh`:

```json
{
  "data": [
    {
      "id": 1,
      "status": "approved",
      "user": {
        "id": 5,
        "name": "Diana Putri",
        "department": "Marketing"
      },
      "vendor_name": "Grab Food",
      "claimed_amount": 320000,
      "approvals": [
        {
          "id": 101,
          "user": {
            "id": 3,
            "name": "Sari Rahma"
          },
          "notes": "Persetujuan selesai",
          "approved_at": "2026-05-25T14:30:00Z"
        }
      ],
      "submitted_at": "2026-05-25T14:20:00Z"
    }
  ]
}
```

**Critical Fields for diprosesOleh:**
- `approvals` - Array of approval records
- `approvals[0].user.name` - Name of first approver (USED)
- Falls back to `'Finance'` if missing

---

## 7. Data Sources Summary

| Source | Location | Status | Used By |
|--------|----------|--------|---------|
| **Real API** | Backend `/dashboard/receipts/all` | Production | App.tsx → loadReceiptHistory() |
| **Mock Data** | `web akuntan/src/data.ts` | Development | Defined but NOT used |
| **Mapper** | `services/mappers.ts` | Active | Transforms API response |
| **Component** | `ReceiptHistory.tsx` | Display | Renders final UI |

---

## 8. Quick Reference Table

| Aspect | Value |
|--------|-------|
| **Component** | `ReceiptHistory.tsx` |
| **Field Name** | `diprosesOleh` |
| **Field Type** | `string` |
| **Display Location** | Table cell, Line 149 |
| **Data Type** | `StrukApproval` interface |
| **Type File** | `web akuntan/src/types.ts` |
| **Mock Data File** | `web akuntan/src/data.ts` |
| **Mock Variable** | `initialStrukApprovals` |
| **Mock Values** | All = `'Sari Rahma'` (5 items) |
| **API Endpoint** | `GET /dashboard/receipts/all` |
| **API Base URL** | `http://localhost:8000/api/v1` |
| **Mapper Function** | `mapReceiptToApproval()` |
| **Mapper Location** | `services/mappers.ts` (Lines 99-110) |
| **Source Field** | `r.approvals?.[0]?.user?.name` |
| **Fallback Value** | `'Finance'` |
| **State Holder** | `App.tsx` receiptHistory state |
| **Prop Passed** | `approvals: StrukApproval[]` |

---

## 9. Key Findings

### What Works
✅ Field definition is complete and type-safe
✅ Mock data is well-structured and consistent
✅ Mapper has sensible fallback logic
✅ Component displays the data correctly
✅ Auto-refresh after approval actions

### Potential Issues
⚠️ **Hardcoded fallback:** If approval data missing, shows generic `'Finance'` instead of actual user
⚠️ **Single approver assumption:** Only takes `approvals[0]`, ignores multi-level approvals
⚠️ **Mock data unused:** `initialStrukApprovals` defined but never imported in App.tsx
⚠️ **No validation:** Doesn't verify approver user still exists (deleted users not handled)

### Current Value in Mock Data
All 5 mock items show:
- `diprosesOleh: 'Sari Rahma'`
