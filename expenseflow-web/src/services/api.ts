import {
  createCacheKey,
  resolveCacheConfig,
  getCache,
  setCache,
  invalidateCache,
  invalidateCacheByTag,
  handleMutationInvalidation,
  clearAllCache,
  getInFlight,
  setInFlight,
  clearInFlight,
  notifyCacheUpdate,
  onCacheUpdate,
} from './apiCache';

export { invalidateCache, invalidateCacheByTag, clearAllCache, onCacheUpdate };

// Lapisan dasar HTTP untuk komunikasi dengan backend Laravel.
// Menangani: base URL, header Authorization (Bearer) + X-Platform, smart in-memory caching, dan error 401.

const BASE_URL =
  (import.meta.env.VITE_API_BASE_URL as string | undefined) ??
  'http://localhost:8000/api/v1';

const TOKEN_KEY = 'expenseflow_token';
const USER_KEY = 'expenseflow_user';
const TOKEN_EXPIRES_KEY = 'expenseflow_token_expires_at';

// ─── Token helpers ──────────────────────────────────────────
export const getToken = (): string | null => localStorage.getItem(TOKEN_KEY);
export const setToken = (token: string): void => localStorage.setItem(TOKEN_KEY, token);
export const clearToken = (): void => {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
  localStorage.removeItem(TOKEN_EXPIRES_KEY);
  clearAllCache();
};

export const getStoredUser = (): any | null => {
  const raw = localStorage.getItem(USER_KEY);
  return raw ? JSON.parse(raw) : null;
};
export const setStoredUser = (user: unknown): void =>
  localStorage.setItem(USER_KEY, JSON.stringify(user));

// Simpan kapan token web expired (ISO string dari backend)
export const setTokenExpiresAt = (iso: string | null): void => {
  if (iso) localStorage.setItem(TOKEN_EXPIRES_KEY, iso);
  else localStorage.removeItem(TOKEN_EXPIRES_KEY);
};

// Cek apakah token sudah expired secara lokal (tanpa hit server)
export const isTokenExpired = (): boolean => {
  const raw = localStorage.getItem(TOKEN_EXPIRES_KEY);
  if (!raw) return false; // mobile atau tidak ada expiry → anggap tidak expired
  return new Date() >= new Date(raw);
};

// Error khusus agar pemanggil bisa membaca status & pesan validasi.
export class ApiError extends Error {
  status: number;
  data: any;
  constructor(message: string, status: number, data?: any) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.data = data;
  }
}

// Dipanggil saat token tidak valid / kedaluwarsa (401).
// AuthContext mengganti handler ini agar bisa memaksa logout + redirect.
let onUnauthorized: (() => void) | null = null;
export const setUnauthorizedHandler = (fn: () => void): void => {
  onUnauthorized = fn;
};

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  // Jika true, jangan set Content-Type (dipakai untuk FormData / multipart).
  isFormData?: boolean;
  query?: Record<string, string | number | boolean | undefined | null>;
  // Caching options
  cache?: boolean; // default true untuk GET jika rule ditemukan
  forceRefresh?: boolean; // bypass read cache dan paksa fetch dari server, lalu update cache
  ttl?: number; // custom TTL dalam milidetik (override default rule)
  swr?: boolean; // custom SWR (override default rule)
};

function buildUrl(path: string, query?: RequestOptions['query']): string {
  // BASE_URL bisa absolute (http://localhost:8000/api/v1) atau relative (/api/v1).
  // Relative dipakai saat Vite preview/proxy agar request same-origin dan tidak memicu CORS preflight.
  const url = new URL(BASE_URL + path, window.location.origin);
  if (query) {
    Object.entries(query).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') {
        url.searchParams.set(k, String(v));
      }
    });
  }
  return url.toString();
}

/**
 * Eksekusi HTTP fetch aktual ke backend Laravel.
 */
async function executeFetch<T = any>(
  path: string,
  options: RequestOptions,
  triggerUnauthorized: boolean = true,
): Promise<T> {
  const { method = 'GET', body, isFormData = false, query } = options;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Platform': 'web',
  };

  const token = getToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  let payload: BodyInit | undefined;
  if (body !== undefined) {
    if (isFormData) {
      payload = body as FormData;
    } else {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
  }

  let res: Response;
  try {
    res = await fetch(buildUrl(path, query), { method, headers, body: payload });
  } catch (e) {
    throw new ApiError('Tidak dapat terhubung ke server. Pastikan backend berjalan.', 0);
  }

  // 401 → token invalid / kedaluwarsa → paksa logout & bersihkan cache
  if (res.status === 401) {
    clearToken();
    if (triggerUnauthorized && onUnauthorized) onUnauthorized();
    throw new ApiError('Sesi Anda telah berakhir. Silakan login kembali.', 401);
  }

  // 204 No Content
  if (res.status === 204) return undefined as T;

  let data: any = null;
  const text = await res.text();
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = text;
    }
  }

  if (!res.ok) {
    const message =
      (data && (data.message || data.error)) || `Permintaan gagal (${res.status}).`;
    throw new ApiError(message, res.status, data);
  }

  return data as T;
}

export async function request<T = any>(
  path: string,
  options: RequestOptions = {},
): Promise<T> {
  const {
    method = 'GET',
    cache: userCacheOption,
    forceRefresh = false,
    ttl: userTtl,
    swr: userSwr,
    query,
  } = options;

  const isGet = method === 'GET';

  // ─── Tangani Caching & Deduplikasi untuk request GET ──────────────
  if (isGet) {
    const cacheConfig = resolveCacheConfig(path);
    const shouldCache =
      userCacheOption !== false &&
      (userCacheOption === true || (cacheConfig !== null && cacheConfig.ttl > 0));
    const ttl = userTtl ?? cacheConfig?.ttl ?? 0;
    const swr = userSwr ?? cacheConfig?.swr ?? false;
    const tags = cacheConfig?.tags ?? [];

    if (shouldCache && ttl > 0) {
      const cacheKey = createCacheKey(path, query as Record<string, unknown>);

      // Cek apakah data sudah ada di in-memory cache
      if (!forceRefresh) {
        const cached = getCache<T>(cacheKey);
        if (cached.hit) {
          // Kasus 1: Cache masih fresh → kembalikan instan (0ms)
          if (!cached.isStale) {
            return cached.data!;
          }

          // Kasus 2: Cache stale tetapi SWR aktif → kembalikan data stale seketika,
          // lalu picu fetch latar belakang untuk memperbarui cache.
          if (cached.swr || swr) {
            if (!getInFlight(cacheKey)) {
              const bgPromise = executeFetch<T>(path, { ...options, forceRefresh: true }, false)
                .then((freshData) => {
                  setCache(cacheKey, freshData, ttl, swr, tags);
                  notifyCacheUpdate(cacheKey, path, freshData);
                  return freshData;
                })
                .catch(() => {
                  /* abaikan error revalidasi background */
                })
                .finally(() => {
                  clearInFlight(cacheKey);
                });
              setInFlight(cacheKey, bgPromise);
            }
            return cached.data!;
          }
        }
      }

      // Kasus 3: Tidak ada cache atau cache stale (non-SWR) atau forceRefresh
      // Deduplikasi request: jika request yang sama sedang in-flight, tunggu promise yang sama
      if (!forceRefresh) {
        const inFlight = getInFlight(cacheKey);
        if (inFlight) {
          return inFlight as Promise<T>;
        }
      }

      // Buat promise eksekusi fetch baru
      const fetchPromise = executeFetch<T>(path, options, true)
        .then((freshData) => {
          setCache(cacheKey, freshData, ttl, swr, tags);
          return freshData;
        })
        .finally(() => {
          clearInFlight(cacheKey);
        });

      setInFlight(cacheKey, fetchPromise);
      return fetchPromise;
    }
  }

  // Request non-GET atau GET yang tidak di-cache
  const result = await executeFetch<T>(path, options, true);

  // Jika mutasi berhasil (POST/PUT/PATCH/DELETE), jalankan auto-invalidation
  if (!isGet) {
    handleMutationInvalidation(path);
  }

  return result;
}

// Ambil waktu tunggu (detik) dari error rate-limit (429) — dipakai LoginPage
// untuk menampilkan countdown. Nilai bersumber dari body `retry_after`.
export const getRetryAfterSeconds = (err: unknown): number | null => {
  if (err instanceof ApiError) {
    const v = err.data?.retry_after;
    if (typeof v === 'number' && v > 0) return v;
  }
  return null;
};

// Format detik → "X menit Y detik" (atau "Y detik" bila < 60).
export const formatWaitTime = (seconds: number): string => {
  const s = Math.max(1, Math.ceil(seconds));
  const m = Math.floor(s / 60);
  const r = s % 60;
  if (m > 0) return r > 0 ? `${m} menit ${r} detik` : `${m} menit`;
  return `${s} detik`;
};

// Shortcut helpers
export const apiGet = <T = any>(
  path: string,
  query?: RequestOptions['query'],
  cacheOptions?: Pick<RequestOptions, 'cache' | 'forceRefresh' | 'ttl' | 'swr'>,
) => request<T>(path, { method: 'GET', query, ...cacheOptions });
export const apiPost = <T = any>(path: string, body?: unknown) =>
  request<T>(path, { method: 'POST', body });
export const apiPut = <T = any>(path: string, body?: unknown) =>
  request<T>(path, { method: 'PUT', body });
export const apiPatch = <T = any>(path: string, body?: unknown) =>
  request<T>(path, { method: 'PATCH', body });
export const apiDelete = <T = any>(path: string) =>
  request<T>(path, { method: 'DELETE' });

export const apiUpload = <T = any>(path: string, formData: FormData) =>
  request<T>(path, { method: 'POST', body: formData, isFormData: true });

// Unduh file (mis. CSV export) — memicu download di browser.
export async function apiDownload(
  path: string,
  filename: string,
  query?: RequestOptions['query'],
): Promise<void> {
  const headers: Record<string, string> = { 'X-Platform': 'web' };
  const token = getToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(buildUrl(path, query), { headers });
  if (res.status === 401) {
    clearToken();
    if (onUnauthorized) onUnauthorized();
    throw new ApiError('Sesi Anda telah berakhir. Silakan login kembali.', 401);
  }
  if (!res.ok) {
    throw new ApiError(`Gagal mengunduh file (${res.status}).`, res.status);
  }

  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

// Buka file langsung di tab baru browser (mis. preview PDF berkas CV)
export async function apiViewFile(
  path: string,
  title: string = 'Berkas CV',
  query?: RequestOptions['query'],
): Promise<void> {
  const headers: Record<string, string> = { 'X-Platform': 'web' };
  const token = getToken();
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const res = await fetch(buildUrl(path, query), { headers });
  if (res.status === 401) {
    clearToken();
    if (onUnauthorized) onUnauthorized();
    throw new ApiError('Sesi Anda telah berakhir. Silakan login kembali.', 401);
  }
  if (!res.ok) {
    let errMsg = `Gagal membuka berkas (${res.status}).`;
    try {
      const errData = await res.json();
      if (errData?.message) errMsg = errData.message;
    } catch { /* ignore */ }
    throw new ApiError(errMsg, res.status);
  }

  const blob = await res.blob();
  const pdfBlob = new Blob([blob], { type: 'application/pdf' });
  const blobUrl = URL.createObjectURL(pdfBlob);

  const newTab = window.open('', '_blank');
  if (newTab) {
    newTab.document.write(`
      <!DOCTYPE html>
      <html lang="id">
        <head>
          <meta charset="utf-8" />
          <meta name="viewport" content="width=device-width, initial-scale=1" />
          <title>${title}</title>
          <style>
            html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; background: #0f172a; }
            iframe { width: 100%; height: 100%; border: none; display: block; }
          </style>
        </head>
        <body>
          <iframe src="${blobUrl}#toolbar=1" type="application/pdf"></iframe>
        </body>
      </html>
    `);
    newTab.document.close();
  } else {
    // Fallback jika popup diblokir
    window.open(blobUrl, '_blank');
  }
}


export { BASE_URL };

