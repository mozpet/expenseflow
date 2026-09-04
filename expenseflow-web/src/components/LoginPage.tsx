import React, { useState, useEffect, useRef } from 'react';
import { Lock, Mail, Eye, EyeOff, AlertCircle, LogIn, Timer } from 'lucide-react';
import { useAuth } from '../auth/AuthContext';
import { ApiError, getRetryAfterSeconds, formatWaitTime } from '../services/api';
import { ForgotPasswordModal } from './ForgotPasswordModal';

export const LoginPage: React.FC = () => {
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [showForgotModal, setShowForgotModal] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // Countdown rate-limit (detik tersisa). Saat > 0, tombol Masuk di-disable.
  const [retryCountdown, setRetryCountdown] = useState(0);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // Bersihkan interval saat unmount / saat countdown selesai.
  useEffect(() => {
    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
    };
  }, []);

  useEffect(() => {
    if (retryCountdown <= 0) {
      if (timerRef.current) {
        clearInterval(timerRef.current);
        timerRef.current = null;
      }
      return;
    }
    if (!timerRef.current) {
      timerRef.current = setInterval(() => {
        setRetryCountdown((s) => (s > 1 ? s - 1 : 0));
      }, 1000);
    }
  }, [retryCountdown]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (retryCountdown > 0) return; // masih menunggu — abaikan klik
    setError(null);
    setSubmitting(true);
    try {
      await login(email.trim(), password);
    } catch (err) {
      if (err instanceof ApiError) {
        // Rate limit (429) → tampilkan waktu tunggu & mulai countdown.
        const retryAfter = getRetryAfterSeconds(err);
        if (err.status === 429 && retryAfter != null) {
          setRetryCountdown(retryAfter);
          setError(`Terlalu banyak percobaan login. Coba lagi dalam ${formatWaitTime(retryAfter)}.`);
        } else {
          // Pesan validasi Laravel: { errors: { email: [...] } }
          const validation = err.data?.errors?.email?.[0];
          setError(validation ?? err.message);
        }
      } else {
        setError('Terjadi kesalahan tak terduga.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="min-h-screen bg-[#0f172a] flex items-center justify-center p-4 font-sans">
      <div className="w-full max-w-md">
        {/* Branding */}
        <div className="flex flex-col items-center mb-8">
          <div className="w-14 h-14 bg-indigo-500 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-500/30 mb-4">
            <div className="w-7 h-7 border-2 border-white rounded-md" />
          </div>
          <h1 className="text-2xl font-bold text-white tracking-tight">ExpenseFlow</h1>
          <p className="text-sm text-slate-400 mt-1">Finance Portal — Web Dashboard</p>
        </div>

        {/* Card */}
        <form
          onSubmit={handleSubmit}
          className="bg-white dark:bg-slate-900 rounded-2xl shadow-xl p-7 space-y-5 border border-slate-100 dark:border-slate-800"
        >
          <div>
            <h2 className="text-lg font-bold text-slate-900 dark:text-white">Masuk ke akun Anda</h2>
            <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
              Khusus tim Finance, HRD, Admin &amp; Super Admin.
            </p>
          </div>

          {error && (
            <div className="flex items-start gap-2 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 text-rose-700 dark:text-rose-400 rounded-lg px-3 py-2.5 text-xs">
              {retryCountdown > 0 ? (
                <Timer className="w-4 h-4 shrink-0 mt-0.5" />
              ) : (
                <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
              )}
              <span>{error}</span>
            </div>
          )}

          {/* Email */}
          <div className="space-y-1.5">
            <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Email</label>
            <div className="relative">
              <Mail className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="nama@perusahaan.co.id"
                className="w-full pl-9 pr-3 py-2.5 rounded-lg border border-slate-300 dark:border-slate-700 text-sm bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>
          </div>

          {/* Password */}
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">Password</label>
              <button
                type="button"
                onClick={() => setShowForgotModal(true)}
                className="text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 transition"
              >
                Lupa password?
              </button>
            </div>
            <div className="relative">
              <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type={showPassword ? 'text' : 'password'}
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                className="w-full pl-9 pr-10 py-2.5 rounded-lg border border-slate-300 dark:border-slate-700 text-sm bg-white dark:bg-slate-800 text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
              />
              <button
                type="button"
                onClick={() => setShowPassword((s) => !s)}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
              >
                {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
            </div>
          </div>

          <button
            type="submit"
            disabled={submitting || retryCountdown > 0}
            className="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold text-sm rounded-lg py-2.5 transition"
          >
            {submitting ? (
              <>
                <span className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                Memproses...
              </>
            ) : retryCountdown > 0 ? (
              <>
                <Timer className="w-4 h-4" />
                Tunggu {formatWaitTime(retryCountdown)}
              </>
            ) : (
              <>
                <LogIn className="w-4 h-4" />
                Masuk
              </>
            )}
          </button>
        </form>

        <p className="text-center text-[11px] text-slate-500 mt-5">
          Karyawan (employee) hanya bisa login lewat aplikasi mobile.
        </p>

        {/* Forgot Password Modal */}
        <ForgotPasswordModal
          isOpen={showForgotModal}
          onClose={() => setShowForgotModal(false)}
          initialEmail={email}
        />
      </div>
    </div>
  );
};
