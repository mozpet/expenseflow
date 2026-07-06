================================================================================
                    RECEIPT HISTORY DATA SOURCE REPORT
                        ExpenseFlow React Frontend
================================================================================

TASK: Find where ReceiptHistory data comes from with focus on diprosesOleh field

================================================================================
EXECUTIVE SUMMARY
================================================================================

PRIMARY SOURCE:  Backend API: GET /dashboard/receipts/all
                 Field source: approvals[0].user.name
                 Fallback: 'Finance' (hardcoded string)

MOCK DATA:       web akuntan/src/data.ts (Lines 62-113)
                 Variable: initialStrukApprovals (5 items, all 'Sari Rahma')

DATA FLOW:       Backend API → App.tsx loadReceiptHistory()
                 → mapReceiptToApproval() → App State
                 → ReceiptHistory Component → Table Display

COMPONENT:       web akuntan/src/components/ReceiptHistory.tsx
                 Displays in: Table row, column "Diproses Oleh"

================================================================================
1. MOCK DATA LOCATION
================================================================================

FILE:    web akuntan/src/data.ts
LINES:   62-113
VAR:     initialStrukApprovals: StrukApproval[]
COUNT:   5 items (all with diprosesOleh: 'Sari Rahma')

ITEMS:
  1. RA-01: Diana Putri, Grab Food, Rp 320.000, Disetujui
  2. RA-02: Hendra K, SPBU Pertamina, Rp 300.000, Ditolak
  3. RA-03: Mega Sari, Hotel Aston, Rp 1.250.000, Disetujui
  4. RA-04: Joko S, Resto XYZ, Rp 2.500.000, Ditolak
  5. RA-05: Fitri H, Indomaret, Rp 87.000, Disetujui

STATUS: Defined but NOT used in App.tsx (never imported)

================================================================================
2. DATA TYPE STRUCTURE
================================================================================

FILE:   web akuntan/src/types.ts
LINES:  18-27

interface StrukApproval {
  id: string;
  karyawan: string;
  merchant: string;
  nominal: number;
  keputusan: 'Disetujui' | 'Ditolak';
  diprosesOleh: string;      ← APPROVER NAME FIELD
  waktu: string;
  catatan: string;
}

================================================================================
3. API SERVICE
================================================================================

FILE:   web akuntan/src/services/endpoints.ts
LINES:  27-40

receiptApi.all() → GET /dashboard/receipts/all
BASE URL: http://localhost:8000/api/v1
FULL URL: http://localhost:8000/api/v1/dashboard/receipts/all

Returns: { data: [...] } containing approved/rejected receipts
         Each with: approvals[0].user.name field

================================================================================
4. MAPPER FUNCTION
================================================================================

FILE:     web akuntan/src/services/mappers.ts
LINES:    99-110
FUNCTION: mapReceiptToApproval(r)

KEY LOGIC:
  diprosesOleh: r.approvals?.[0]?.user?.name ?? 'Finance'

EXPLANATION:
  1. Try to get first approver name: r.approvals[0].user.name
  2. If not found: fallback to 'Finance' (hardcoded string)
  3. Does NOT use mock data

================================================================================
5. DATA FLOW
================================================================================

App.tsx → loadReceiptHistory()
  ├─> await receiptApi.all()
  ├─> Filter: status === 'approved' || 'rejected'
  ├─> map(mapReceiptToApproval)
  └─> setReceiptHistory(results)

mapReceiptToApproval()
  ├─> Input: backend receipt object
  └─> Extract: diprosesOleh from r.approvals[0].user.name

App State: receiptHistory
  └─> Type: StrukApproval[]

ReceiptHistory Component
  ├─> Receives: approvals prop
  └─> Displays: item.diprosesOleh in table [Line 149]

================================================================================
6. COMPONENT DISPLAY
================================================================================

FILE:   ReceiptHistory.tsx
LINES:  146-151

Table cell displays diprosesOleh:
  <User icon/> {item.diprosesOleh}

Example output:
  👤 Sari Rahma

================================================================================
7. EXPECTED BACKEND RESPONSE
================================================================================

{
  "data": [
    {
      "id": 1,
      "status": "approved",
      "user": { "name": "Diana Putri" },
      "vendor_name": "Grab Food",
      "claimed_amount": 320000,
      "approvals": [
        {
          "user": { "name": "Sari Rahma" },    ← EXTRACTED HERE
          "notes": "Persetujuan selesai"
        }
      ],
      "submitted_at": "2026-05-25T14:20:00"
    }
  ]
}

================================================================================
8. FILE REFERENCE
================================================================================

Component:        ReceiptHistory.tsx (display line 149)
Type:             types.ts (definition lines 18-27)
Mock Data:        data.ts (lines 62-113)
Mapper:           services/mappers.ts (lines 99-110)
API:              services/endpoints.ts (lines 27-40)
State:            App.tsx (line 75)
Load:             App.tsx (lines 98-104)
Render:           App.tsx (line 275)

================================================================================
9. STATUS
================================================================================

WORKING:
  ✓ Field displays correctly
  ✓ Mapper extracts from API
  ✓ Type-safe definition
  ✓ Auto-refresh after approvals

ISSUES:
  ⚠ Fallback is generic 'Finance'
  ⚠ Mock data unused (import but don't use)
  ⚠ Only first approver taken
  ⚠ No validation of user existence

================================================================================
