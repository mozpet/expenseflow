// Lapisan dasar HTTP untuk komunikasi dengan backend Laravel.
// Menangani: base URL, header Authorization (Bearer) + X-Platform, dan error 401.

const BASE_URL =
  (import.meta.env.VITE_API_BASE_URL as string | undefined) ??
  'http://localhost:8000/api/v1';

const TOKEN_KEY = 'expenseflow_token';
const USER_KEY = 'expenseflow_user';

// ─── Token helpers ──────────────────────────────────────────
export const getToken = (): string | null => localStorage.getItem(TOKEN_KEY);
export const setToken = (token: string): void => localStorage.setItem(TOKEN_KEY, token);
export const clearToken = (): void => {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
};

export const getStoredUser = (): any | null => {
  const raw = localStorage.getItem(USER_KEY);
  return raw ? JSON.parse(raw) : null;
};
export const setStoredUser = (user: unknown): void =>
  localStorage.setItem(USER_KEY, JSON.stringify(user));

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

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  // Jika true, jangan set Content-Type (dipakai untuk FormData / multipart).
  isFormData?: boolean;
  query?: Record<string, string | number | boolean | undefined | null>;
};

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = new URL(BASE_URL + path);
  if (query) {
    Object.entries(query).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') {
        url.searchParams.set(k, String(v));
      }
    });
  }
  return url.toString();
}

export async function request<T = any>(
  path: string,
  options: RequestOptions = {},
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

  // 401 → token invalid / kedaluwarsa → paksa logout.
  if (res.status === 401) {
    clearToken();
    if (onUnauthorized) onUnauthorized();
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

// Shortcut helpers
export const apiGet = <T = any>(path: string, query?: RequestOptions['query']) =>
  request<T>(path, { method: 'GET', query });
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

export { BASE_URL };
