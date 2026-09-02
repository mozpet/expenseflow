import React, { useState, useEffect, useRef } from 'react';
import { X, Mail, ShieldCheck, Lock, Eye, EyeOff, AlertCircle, CheckCircle2, ArrowRight, RefreshCw, KeyRound } from 'lucide-react';
import { authApi } from '../services/endpoints';
import { ApiError } from '../services/api';

interface ForgotPasswordModalProps {
  isOpen: boolean;
  onClose: () => void;
  initialEmail?: string;
}

export const ForgotPasswordModal: React.FC<ForgotPasswordModalProps> = ({
  isOpen,
  onClose,
  initialEmail = '',
}) => {
  const [step, setStep] = useState<1 | 2 | 3 | 4>(1);
  const [email, setEmail] = useState(initialEmail);
  const [otp, setOtp] = useState(['', '', '', '', '', '']);
  const [resetToken, setResetToken] = useState<string | null>(null);
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [debugOtp, setDebugOtp] = useState<string | null>(null);

  // Anti-spam resend cooldown timer
  const [resendCooldown, setResendCooldown] = useState(0);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const otpInputsRef = useRef<(HTMLInputElement | null)[]>([]);

  useEffect(() => {
    if (isOpen) {
      if (initialEmail) setEmail(initialEmail);
      setStep(1);
      setError(null);
      setSuccessMessage(null);
      setOtp(['', '', '', '', '', '']);
      setPassword('');
      setConfirmPassword('');
      setResetToken(null);
    }
  }, [isOpen, initialEmail]);

  useEffect(() => {
    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
    };
  }, []);

  useEffect(() => {
    if (resendCooldown <= 0) {
      if (timerRef.current) {
        clearInterval(timerRef.current);
        timerRef.current = null;
      }
      return;
    }
    if (!timerRef.current) {
      timerRef.current = setInterval(() => {
        setResendCooldown((s) => (s > 1 ? s - 1 : 0));
      }, 1000);
    }
  }, [resendCooldown]);

  if (!isOpen) return null;

  // ─── STEP 1: Send OTP ───
  const handleSendOtp = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!email.trim() || !email.includes('@')) {
      setError('Masukkan alamat email yang valid.');
      return;
    }

    setError(null);
    setLoading(true);

    try {
      const res = await authApi.sendForgotPasswordOtp(email.trim());
      setSuccessMessage(res.message);
      setResendCooldown(res.cooldown_seconds ?? 60);
      if (res.debug_otp) {
        setDebugOtp(res.debug_otp);
      }
      setStep(2);
      setTimeout(() => {
        otpInputsRef.current[0]?.focus();
      }, 100);
    } catch (err: any) {
      if (err instanceof ApiError) {
        setError(err.message);
        if (err.data?.retry_after) {
          setResendCooldown(err.data.retry_after);
        }
      } else {
        setError('Gagal mengirim kode OTP. Silakan coba lagi.');
      }
    } finally {
      setLoading(false);
    }
  };

  // ─── STEP 2: OTP Input Handling & Verify ───
  const handleOtpChange = (index: number, value: string) => {
    if (value.length > 1) {
      // Paste handling
      const digits = value.replace(/\D/g, '').slice(0, 6).split('');
      const newOtp = [...otp];
      digits.forEach((d, i) => {
        newOtp[i] = d;
      });
      setOtp(newOtp);
      const nextIndex = Math.min(digits.length, 5);
      otpInputsRef.current[nextIndex]?.focus();
      return;
    }

    const newOtp = [...otp];
    newOtp[index] = value.replace(/\D/g, '');
    setOtp(newOtp);

    if (value && index < 5) {
      otpInputsRef.current[index + 1]?.focus();
    }
  };

  const handleOtpKeyDown = (index: number, e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Backspace' && !otp[index] && index > 0) {
      otpInputsRef.current[index - 1]?.focus();
    }
  };

  const handleVerifyOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    const fullOtp = otp.join('');
    if (fullOtp.length < 6) {
      setError('Masukkan 6 digit kode OTP secara lengkap.');
      return;
    }

    setError(null);
    setLoading(true);

    try {
      const res = await authApi.verifyForgotPasswordOtp(email.trim(), fullOtp);
      setResetToken(res.reset_token);
      setSuccessMessage('Kode OTP berhasil diverifikasi.');
      setStep(3);
    } catch (err: any) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError('Kode OTP salah atau telah kadaluarsa.');
      }
    } finally {
      setLoading(false);
    }
  };

  // ─── STEP 3: Reset Password ───
  const handleResetPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (password.length < 8) {
      setError('Password baru minimal 8 karakter.');
      return;
    }
    if (password !== confirmPassword) {
      setError('Konfirmasi password tidak cocok.');
      return;
    }
    if (!resetToken) {
      setError('Sesi reset tidak valid. Silakan ulangi proses.');
      setStep(1);
      return;
    }

    setError(null);
    setLoading(true);

    try {
      const res = await authApi.resetPassword({
        email: email.trim(),
        reset_token: resetToken,
        password,
        password_confirmation: confirmPassword,
      });
      setSuccessMessage(res.message);
      setStep(4);
    } catch (err: any) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError('Gagal mereset password. Silakan coba lagi.');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4 animate-in fade-in duration-200">
      <div className="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden border border-slate-100 font-sans">
        {/* Modal Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/50">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center text-indigo-600">
              <KeyRound className="w-4 h-4" />
            </div>
            <div>
              <h3 className="text-sm font-bold text-slate-800">
                {step === 4 ? 'Reset Selesai' : 'Reset Password'}
              </h3>
              <p className="text-[11px] text-slate-500">
                {step === 1 && 'Langkah 1: Masukkan Email Akun'}
                {step === 2 && 'Langkah 2: Verifikasi Kode OTP'}
                {step === 3 && 'Langkah 3: Buat Password Baru'}
                {step === 4 && 'Kata sandi berhasil diperbarui'}
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Progress Bar */}
        {step < 4 && (
          <div className="w-full bg-slate-100 h-1">
            <div
              className="bg-indigo-600 h-1 transition-all duration-300"
              style={{ width: `${(step / 3) * 100}%` }}
            />
          </div>
        )}

        <div className="p-6">
          {/* Alert Error */}
          {error && (
            <div className="flex items-start gap-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl px-3.5 py-2.5 text-xs mb-5 animate-in fade-in">
              <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
              <span>{error}</span>
            </div>
          )}

          {/* ─── STEP 1: Input Email ─── */}
          {step === 1 && (
            <form onSubmit={handleSendOtp} className="space-y-4">
              <div>
                <label className="text-xs font-semibold text-slate-700 block mb-1.5">
                  Email Terdaftar
                </label>
                <div className="relative">
                  <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                  <input
                    type="email"
                    required
                    autoFocus
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    placeholder="nama@perusahaan.co.id"
                    className="w-full pl-10 pr-3.5 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                  />
                </div>
                <p className="text-[11px] text-slate-500 mt-2 leading-relaxed">
                  Kami akan mengirimkan 6 digit kode verifikasi OTP ke email ini untuk memvalidasi identitas Anda.
                </p>
              </div>

              <div className="pt-2">
                <button
                  type="submit"
                  disabled={loading}
                  className="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold text-sm rounded-xl py-2.5 transition shadow-sm"
                >
                  {loading ? (
                    <>
                      <span className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                      Mengirim OTP...
                    </>
                  ) : (
                    <>
                      Kirim Kode OTP
                      <ArrowRight className="w-4 h-4" />
                    </>
                  )}
                </button>
              </div>
            </form>
          )}

          {/* ─── STEP 2: Input OTP ─── */}
          {step === 2 && (
            <form onSubmit={handleVerifyOtp} className="space-y-5">
              <div className="text-center">
                <div className="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
                  <ShieldCheck className="w-6 h-6" />
                </div>
                <h4 className="text-sm font-bold text-slate-800">Masukkan 6 Digit OTP</h4>
                <p className="text-xs text-slate-500 mt-1">
                  Kode verifikasi telah dikirim ke <span className="font-semibold text-slate-700">{email}</span>
                </p>
              </div>

              {/* 6 Digit Inputs */}
              <div className="flex justify-center gap-2">
                {otp.map((digit, idx) => (
                  <input
                    key={idx}
                    ref={(el) => { otpInputsRef.current[idx] = el; }}
                    type="text"
                    maxLength={1}
                    value={digit}
                    onChange={(e) => handleOtpChange(idx, e.target.value)}
                    onKeyDown={(e) => handleOtpKeyDown(idx, e)}
                    className="w-11 h-12 text-center text-lg font-bold text-slate-800 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                  />
                ))}
              </div>

              {/* Dev Auto-fill helper */}
              {debugOtp && (
                <div
                  onClick={() => {
                    const digits = debugOtp.split('').slice(0, 6);
                    setOtp(digits);
                  }}
                  className="bg-amber-50 border border-amber-200 text-amber-800 text-[11px] p-2 rounded-lg text-center cursor-pointer hover:bg-amber-100 transition font-mono"
                >
                  ⚡ Dev Auto-fill OTP: <span className="font-bold underline">{debugOtp}</span> (Klik untuk isi)
                </div>
              )}

              <div className="space-y-3 pt-2">
                <button
                  type="submit"
                  disabled={loading || otp.join('').length < 6}
                  className="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold text-sm rounded-xl py-2.5 transition shadow-sm"
                >
                  {loading ? (
                    <>
                      <span className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                      Memverifikasi...
                    </>
                  ) : (
                    'Verifikasi Kode OTP'
                  )}
                </button>

                <div className="text-center">
                  {resendCooldown > 0 ? (
                    <span className="text-xs text-slate-400">
                      Kirim ulang kode dalam <span className="font-semibold text-slate-600">{resendCooldown} detik</span>
                    </span>
                  ) : (
                    <button
                      type="button"
                      disabled={loading}
                      onClick={() => handleSendOtp()}
                      className="text-xs font-semibold text-indigo-600 hover:text-indigo-700 inline-flex items-center gap-1"
                    >
                      <RefreshCw className="w-3.5 h-3.5" />
                      Kirim Ulang Kode OTP
                    </button>
                  )}
                </div>
              </div>
            </form>
          )}

          {/* ─── STEP 3: Input New Password ─── */}
          {step === 3 && (
            <form onSubmit={handleResetPassword} className="space-y-4">
              <div>
                <label className="text-xs font-semibold text-slate-700 block mb-1.5">
                  Password Baru
                </label>
                <div className="relative">
                  <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                  <input
                    type={showPassword ? 'text' : 'password'}
                    required
                    autoFocus
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    placeholder="Minimal 8 karakter"
                    className="w-full pl-10 pr-10 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <div>
                <label className="text-xs font-semibold text-slate-700 block mb-1.5">
                  Konfirmasi Password Baru
                </label>
                <div className="relative">
                  <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                  <input
                    type={showConfirmPassword ? 'text' : 'password'}
                    required
                    value={confirmPassword}
                    onChange={(e) => setConfirmPassword(e.target.value)}
                    placeholder="Ulangi password baru"
                    className="w-full pl-10 pr-10 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                  />
                  <button
                    type="button"
                    onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                    className="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                  >
                    {showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>

              <div className="pt-2">
                <button
                  type="submit"
                  disabled={loading}
                  className="w-full flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold text-sm rounded-xl py-2.5 transition shadow-sm"
                >
                  {loading ? (
                    <>
                      <span className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                      Menyimpan...
                    </>
                  ) : (
                    'Simpan Password Baru'
                  )}
                </button>
              </div>
            </form>
          )}

          {/* ─── STEP 4: Success State ─── */}
          {step === 4 && (
            <div className="text-center py-4 space-y-4">
              <div className="w-14 h-14 bg-emerald-50 text-emerald-600 rounded-full flex items-center justify-center mx-auto">
                <CheckCircle2 className="w-8 h-8" />
              </div>
              <div>
                <h4 className="text-base font-bold text-slate-800">Password Berhasil Diperbarui!</h4>
                <p className="text-xs text-slate-500 mt-1 max-w-xs mx-auto leading-relaxed">
                  Kata sandi akun Anda telah diperbarui. Silakan gunakan kata sandi baru untuk masuk ke dashboard.
                </p>
              </div>
              <div className="pt-2">
                <button
                  onClick={onClose}
                  className="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-sm rounded-xl py-2.5 transition"
                >
                  Kembali ke Halaman Login
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
