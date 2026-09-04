// Smart In-Memory Caching Module for ExpenseFlow Web
// Berdasarkan dokumen panduan: doc/11-FRONTEND-CACHING-STRATEGY-AUDIT.md & doc/rules.md

export interface CacheEntry<T = any> {
  data: T;
  timestamp: number;
  ttl: number; // Durasi berlaku dalam milidetik
  swr: boolean; // Apakah menggunakan pola Stale-While-Revalidate
  tags: string[];
}

export interface EndpointCacheRule {
  pattern: RegExp;
  ttl: number;
  swr: boolean;
  tags: string[];
}

// ─── Default Caching Strategy Rules (17 Halaman / Fitur) ─────────────
// Sesuai Matriks Evaluasi Caching pada doc/11-FRONTEND-CACHING-STRATEGY-AUDIT.md
export const ENDPOINT_CACHE_RULES: EndpointCacheRule[] = [
  // KELOMPOK 1: 🟢 SANGAT AMAN DI-CACHE (Data Master & Referensi)
  // 1. Pengaturan Aturan & Kantor (TTL 5 Menit, SWR: false)
  {
    pattern: /^\/dashboard\/attendance\/settings(\/\d+)?$/,
    ttl: 5 * 60 * 1000,
    swr: false,
    tags: ['attendance-settings', 'settings'],
  },
  {
    pattern: /^\/dashboard\/settings$/,
    ttl: 5 * 60 * 1000,
    swr: false,
    tags: ['finance-settings', 'settings'],
  },
  // 2. Shift & Jadwal Kerja (TTL 5 Menit, SWR: false)
  // Catatan: /roster didefinisikan terpisah di Kelompok 2 karena antrean harian
  {
    pattern: /^\/dashboard\/attendance\/shifts\/calendar/,
    ttl: 5 * 60 * 1000,
    swr: false,
    tags: ['shifts', 'calendar'],
  },
  {
    pattern: /^\/dashboard\/attendance\/shifts(\/\d+(\/users)?)?$/,
    ttl: 5 * 60 * 1000,
    swr: false,
    tags: ['shifts'],
  },
  {
    pattern: /^\/dashboard\/attendance\/users\/\d+\/shift-history/,
    ttl: 5 * 60 * 1000,
    swr: false,
    tags: ['shifts'],
  },
  // 3. Master Vendor (TTL 5 Menit, SWR: false)
  {
    pattern: /^\/dashboard\/vendors(\/\d+)?$/,
    ttl: 5 * 60 * 1000,
    swr: false,
    tags: ['vendors'],
  },
  // 4. Manajemen Karyawan (TTL 3 Menit, SWR: false)
  {
    pattern: /^\/admin\/users(\/\d+)?$/,
    ttl: 3 * 60 * 1000,
    swr: false,
    tags: ['users', 'karyawan'],
  },
  {
    pattern: /^\/dashboard\/attendance\/users\/all$/,
    ttl: 5 * 60 * 1000,
    swr: false,
    tags: ['users', 'attendance-users-all'],
  },
  // 5. Rekrutmen & Lowongan (TTL 3 Menit, SWR: false)
  {
    pattern: /^\/recruitment\/postings(\/\d+)?$/,
    ttl: 3 * 60 * 1000,
    swr: false,
    tags: ['recruitment', 'postings'],
  },
  {
    pattern: /^\/recruitment\/applications(\/\d+)?$/,
    ttl: 3 * 60 * 1000,
    swr: false,
    tags: ['recruitment', 'applications'],
  },
  // 6. Laporan & Rekap Bulanan (TTL 5 Menit, SWR: false)
  {
    pattern: /^\/dashboard\/attendance\/summary/,
    ttl: 5 * 60 * 1000,
    swr: false,
    tags: ['reports', 'summary'],
  },
  // 7. Riwayat Struk (Arsip / Historis: TTL 3 Menit, SWR: false)
  {
    pattern: /^\/dashboard\/receipts\/all$/,
    ttl: 3 * 60 * 1000,
    swr: false,
    tags: ['receipts', 'receipt-history'],
  },
  // 9. Audit Log Sistem (TTL 2 Menit, SWR: false)
  {
    pattern: /^\/dashboard\/activity-logs$/,
    ttl: 2 * 60 * 1000,
    swr: false,
    tags: ['auditlog'],
  },
  // Profil User Login (TTL 5 Menit, SWR: false)
  {
    pattern: /^\/me$/,
    ttl: 5 * 60 * 1000,
    swr: false,
    tags: ['auth'],
  },

  // KELOMPOK 2: 🟡 AMAN BERSYARAT (Wajib Pola SWR / TTL Pendek)
  // 10. Presensi & Cuti Karyawan (TTL 30–60 Detik, SWR: true)
  {
    pattern: /^\/dashboard\/attendance\/today$/,
    ttl: 30 * 1000,
    swr: true,
    tags: ['attendance', 'today'],
  },
  {
    pattern: /^\/dashboard\/attendance\/leaves$/,
    ttl: 30 * 1000,
    swr: true,
    tags: ['attendance', 'leaves'],
  },
  {
    pattern: /^\/dashboard\/attendance\/users$/,
    ttl: 30 * 1000,
    swr: true,
    tags: ['attendance', 'users'],
  },
  {
    pattern: /^\/dashboard\/attendance\/leave-balances$/,
    ttl: 60 * 1000,
    swr: true,
    tags: ['attendance', 'leave-balances'],
  },
  {
    pattern: /^\/dashboard\/attendance\/leave-balance-history$/,
    ttl: 60 * 1000,
    swr: true,
    tags: ['attendance', 'leave-balance-history'],
  },
  {
    pattern: /^\/dashboard\/attendance\/report$/,
    ttl: 30 * 1000,
    swr: true,
    tags: ['attendance', 'report'],
  },
  {
    pattern: /^\/dashboard\/attendance\/shifts\/roster$/,
    ttl: 30 * 1000,
    swr: true,
    tags: ['shifts', 'roster'],
  },
  {
    pattern: /^\/dashboard\/attendance\/holidays(\/preview-national)?$/,
    ttl: 60 * 1000,
    swr: true,
    tags: ['attendance', 'holidays'],
  },
  // 11. Inbox Struk Reimbursement (TTL 20 Detik, SWR: true)
  {
    pattern: /^\/dashboard\/receipts(\/\d+)?$/,
    ttl: 20 * 1000,
    swr: true,
    tags: ['receipts', 'inbox'],
  },
  // 12. Inbox Invoice Masuk (TTL 20 Detik, SWR: true)
  {
    pattern: /^\/dashboard\/invoices(\/\d+)?$/,
    ttl: 20 * 1000,
    swr: true,
    tags: ['invoices', 'invoice-inbox'],
  },
  // 13. Approval Lembur (TTL 20 Detik, SWR: true)
  {
    pattern: /^\/dashboard\/attendance\/overtime-approvals$/,
    ttl: 20 * 1000,
    swr: true,
    tags: ['overtime'],
  },
  // 14. Pindah Perangkat / Device Binding (TTL 20 Detik, SWR: true)
  {
    pattern: /^\/dashboard\/attendance\/device-changes$/,
    ttl: 20 * 1000,
    swr: true,
    tags: ['device-changes'],
  },
  // 15. Notifikasi Sistem (TTL 20 Detik, SWR: true)
  {
    pattern: /^\/dashboard\/notifications$/,
    ttl: 20 * 1000,
    swr: true,
    tags: ['notifications'],
  },
];

// ─── Mutation Auto-Invalidation Registry ─────────────────────────────
// Saat mutasi sukses dieksekusi (POST/PUT/PATCH/DELETE), daftar cache tag/prefix ini
// akan dihapus seketika dari RAM agar data tidak basi.
export interface MutationInvalidationRule {
  pattern: RegExp;
  invalidates: string[];
}

export const MUTATION_INVALIDATIONS: MutationInvalidationRule[] = [
  // Settings Kantor & Presensi
  {
    pattern: /^\/dashboard\/attendance\/settings/,
    invalidates: ['/dashboard/attendance/settings', '/dashboard/attendance/shifts', '/admin/users', '/dashboard/attendance/today'],
  },
  // Settings Finance
  {
    pattern: /^\/dashboard\/settings/,
    invalidates: ['/dashboard/settings'],
  },
  // Shifts, Roster, dan Assignment
  {
    pattern: /^\/dashboard\/attendance\/(shifts|assign-shift|bulk-assign|assignments)/,
    invalidates: ['/dashboard/attendance/shifts', '/dashboard/attendance/today'],
  },
  // Master Vendor
  {
    pattern: /^\/dashboard\/vendors/,
    invalidates: ['/dashboard/vendors', '/dashboard/invoices'],
  },
  // Users / Manajemen Karyawan
  {
    pattern: /^\/admin\/users/,
    invalidates: ['/admin/users', '/dashboard/attendance/users'],
  },
  // Rekrutmen
  {
    pattern: /^\/recruitment/,
    invalidates: ['/recruitment'],
  },
  // Struk (Approve, Reject, Pay, Bulk-Approve, Bulk-Pay)
  {
    pattern: /^\/dashboard\/receipts/,
    invalidates: ['/dashboard/receipts', '/dashboard/activity-logs'],
  },
  // Invoice (Create, Approve, Reject)
  {
    pattern: /^\/dashboard\/invoices/,
    invalidates: ['/dashboard/invoices', '/dashboard/activity-logs', '/dashboard/vendors'],
  },
  // Cuti & Izin
  {
    pattern: /^\/dashboard\/attendance\/leaves/,
    invalidates: [
      '/dashboard/attendance/leaves',
      '/dashboard/attendance/today',
      '/dashboard/attendance/leave-balances',
      '/dashboard/attendance/report',
    ],
  },
  // Kalender Libur
  {
    pattern: /^\/dashboard\/attendance\/holidays/,
    invalidates: [
      '/dashboard/attendance/holidays',
      '/dashboard/attendance/shifts/calendar',
      '/dashboard/attendance/today',
      '/dashboard/attendance/report',
    ],
  },
  // Approval Lembur
  {
    pattern: /^\/dashboard\/attendance\/overtime-approvals/,
    invalidates: [
      '/dashboard/attendance/overtime-approvals',
      '/dashboard/attendance/today',
      '/dashboard/attendance/summary',
      '/dashboard/attendance/report',
    ],
  },
  // Device Changes (Reset IMEI)
  {
    pattern: /^\/dashboard\/attendance\/device-changes/,
    invalidates: [
      '/dashboard/attendance/device-changes',
      '/admin/users',
      '/dashboard/attendance/users',
    ],
  },
  // Notifikasi
  {
    pattern: /^\/dashboard\/notifications/,
    invalidates: ['/dashboard/notifications'],
  },
  // Toggle WFH / Radius per user
  {
    pattern: /^\/dashboard\/attendance\/users\/.*\/toggle/,
    invalidates: [
      '/dashboard/attendance/users',
      '/admin/users',
      '/dashboard/attendance/today',
    ],
  },
  // Saldo Cuti
  {
    pattern: /^\/dashboard\/attendance\/leave-balances/,
    invalidates: [
      '/dashboard/attendance/leave-balances',
      '/dashboard/attendance/today',
    ],
  },
  {
    pattern: /^\/dashboard\/attendance\/settings\/.*\/reset-leave-balances/,
    invalidates: [
      '/dashboard/attendance/leave-balances',
      '/dashboard/attendance/leave-balance-history',
    ],
  },
];

// ─── In-Memory Storage ───────────────────────────────────────────────
// Tersimpan di RAM browser, hilang saat refresh / logout
const cacheStore = new Map<string, CacheEntry<any>>();

// Map request aktif untuk deduplikasi request GET identik secara paralel
const inFlightRequests = new Map<string, Promise<any>>();

// Listener untuk event update cache (mis. hasil background revalidation SWR)
type CacheUpdateListener = (key: string, path: string, data: any) => void;
const cacheUpdateListeners = new Set<CacheUpdateListener>();

// ─── Helper Functions ────────────────────────────────────────────────

/**
 * Buat kunci cache kanonikal berdasarkan path dan query params yang disortir.
 */
export function createCacheKey(path: string, query?: Record<string, unknown>): string {
  if (!query) return `GET:${path}`;
  const keys = Object.keys(query)
    .filter((k) => query[k] !== undefined && query[k] !== null && query[k] !== '')
    .sort();
  if (keys.length === 0) return `GET:${path}`;
  const qs = keys
    .map((k) => `${encodeURIComponent(k)}=${encodeURIComponent(String(query[k]))}`)
    .join('&');
  return `GET:${path}?${qs}`;
}

/**
 * Cari konfigurasi cache default berdasarkan path.
 */
export function resolveCacheConfig(path: string): { ttl: number; swr: boolean; tags: string[] } | null {
  for (const rule of ENDPOINT_CACHE_RULES) {
    if (rule.pattern.test(path)) {
      return { ttl: rule.ttl, swr: rule.swr, tags: rule.tags };
    }
  }
  return null;
}

/**
 * Baca entri dari cache. Mengembalikan status hit, fresh/stale, dan data.
 */
export function getCache<T = any>(key: string): { hit: boolean; isStale: boolean; data?: T; swr?: boolean } {
  const entry = cacheStore.get(key);
  if (!entry) return { hit: false, isStale: false };

  const now = Date.now();
  const isStale = now - entry.timestamp > entry.ttl;

  return {
    hit: true,
    isStale,
    data: entry.data as T,
    swr: entry.swr,
  };
}

/**
 * Simpan data ke in-memory cache.
 */
export function setCache<T = any>(
  key: string,
  data: T,
  ttl: number,
  swr: boolean = false,
  tags: string[] = [],
): void {
  cacheStore.set(key, {
    data,
    timestamp: Date.now(),
    ttl,
    swr,
    tags,
  });
}

/**
 * Hapus satu entri cache berdasarkan kunci spesifik.
 */
export function deleteCache(key: string): void {
  cacheStore.delete(key);
}

/**
 * Invalidate cache berdasarkan prefix atau pola regex.
 */
export function invalidateCache(prefixOrPattern: string | RegExp): void {
  for (const key of Array.from(cacheStore.keys())) {
    const rawPath = key.replace(/^GET:/, '');
    if (typeof prefixOrPattern === 'string') {
      if (rawPath.startsWith(prefixOrPattern) || key.includes(prefixOrPattern)) {
        cacheStore.delete(key);
      }
    } else if (prefixOrPattern.test(rawPath) || prefixOrPattern.test(key)) {
      cacheStore.delete(key);
    }
  }
}

/**
 * Invalidate cache berdasarkan tag.
 */
export function invalidateCacheByTag(tag: string): void {
  for (const [key, entry] of Array.from(cacheStore.entries())) {
    if (entry.tags.includes(tag)) {
      cacheStore.delete(key);
    }
  }
}

/**
 * Otomatis periksa aturan mutasi dan bersihkan cache terkait setelah aksi POST/PUT/PATCH/DELETE.
 */
export function handleMutationInvalidation(mutationPath: string): void {
  for (const rule of MUTATION_INVALIDATIONS) {
    if (rule.pattern.test(mutationPath)) {
      for (const prefix of rule.invalidates) {
        invalidateCache(prefix);
      }
    }
  }
}

/**
 * Kosongkan SELURUH cache dan in-flight requests (dipanggil saat logout / 401).
 */
export function clearAllCache(): void {
  cacheStore.clear();
  inFlightRequests.clear();
}

/**
 * Ambil promise in-flight yang sedang berjalan untuk deduplikasi request.
 */
export function getInFlight(key: string): Promise<any> | undefined {
  return inFlightRequests.get(key);
}

/**
 * Set promise in-flight untuk deduplikasi request.
 */
export function setInFlight(key: string, promise: Promise<any>): void {
  inFlightRequests.set(key, promise);
}

/**
 * Hapus promise in-flight setelah request selesai/gagal.
 */
export function clearInFlight(key: string): void {
  inFlightRequests.delete(key);
}

/**
 * Daftarkan listener untuk mendengarkan perubahan data cache (mis. saat SWR revalidasi).
 */
export function onCacheUpdate(listener: CacheUpdateListener): () => void {
  cacheUpdateListeners.add(listener);
  return () => {
    cacheUpdateListeners.delete(listener);
  };
}

/**
 * Notifikasi seluruh listener bahwa cache telah diperbarui dari background revalidation.
 */
export function notifyCacheUpdate(key: string, path: string, data: any): void {
  cacheUpdateListeners.forEach((fn) => {
    try {
      fn(key, path, data);
    } catch {
      /* abaikan error listener */
    }
  });
}

/**
 * Debug helper: jumlah item cache saat ini.
 */
export function getCacheStoreSize(): number {
  return cacheStore.size;
}
