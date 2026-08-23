import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { authApi } from '../services/endpoints';
import { getToken, getStoredUser, clearToken, setUnauthorizedHandler, isTokenExpired } from '../services/api';

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  role: string;
  company_id: number;
  [key: string]: unknown;
}

interface AuthContextValue {
  user: AuthUser | null;
  isAuthenticated: boolean;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<AuthUser | null>(getStoredUser());
  const [loading, setLoading] = useState<boolean>(!!getToken());

  // Logout lokal (tanpa panggil server) — dipakai saat 401 atau token expired.
  const forceLogout = useCallback(() => {
    clearToken();
    setUser(null);
    setLoading(false);
  }, []);

  // Daftarkan handler 401 ke lapisan API.
  useEffect(() => {
    setUnauthorizedHandler(forceLogout);
  }, [forceLogout]);

  // Saat ada token tersimpan, verifikasi ke /me sekali di awal.
  useEffect(() => {
    let active = true;
    if (getToken()) {
      // Cek dulu secara lokal sebelum hit server — hindari request dengan token expired.
      if (isTokenExpired()) {
        forceLogout();
        return;
      }
      authApi
        .me()
        .then((res) => {
          if (active) setUser(res.user as AuthUser);
        })
        .catch(() => {
          if (active) forceLogout();
        })
        .finally(() => {
          if (active) setLoading(false);
        });
    } else {
      setLoading(false);
    }
    return () => {
      active = false;
    };
  }, [forceLogout]);

  // ─── Proactive token expiry check ─────────────────────────────────────────
  // Cek setiap 60 detik apakah token web sudah expired (berdasarkan expires_at
  // yang disimpan di localStorage saat login). Jika expired → paksa logout
  // tanpa menunggu request berikutnya.
  useEffect(() => {
    if (!user) return; // Tidak perlu cek jika sudah logout

    const interval = setInterval(() => {
      if (isTokenExpired()) {
        clearToken();
        setUser(null);
      }
    }, 60_000); // Cek setiap 1 menit

    return () => clearInterval(interval);
  }, [user]);

  const login = useCallback(async (email: string, password: string) => {
    const res = await authApi.login(email, password);
    setUser(res.user as AuthUser);
  }, []);

  const logout = useCallback(async () => {
    await authApi.logout();
    setUser(null);
  }, []);

  return (
    <AuthContext.Provider
      value={{ user, isAuthenticated: !!user, loading, login, logout }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth harus dipakai di dalam <AuthProvider>');
  return ctx;
}
