import React, { useState, useEffect, useCallback } from 'react';
import {
  BookOpen,
  Pencil,
  RotateCcw,
  Check,
  X,
  AlertCircle,
  CheckCircle2,
  RefreshCw,
  Loader2,
  Sliders,
  ShieldAlert,
} from 'lucide-react';
import { payrollApi } from '../services/endpoints';
import type { GlAccount } from '../types';
import {
  errMsg,
  inputCls,
  btnPrimary,
  btnGhost,
  Banner,
  useBanner,
  Field,
  Modal,
  EmptyState,
  Spinner,
} from './PayrollManagement';

interface PayrollGlAccountsProps {
  canManage: boolean;
  onAccountsUpdated?: () => void;
}

export const PayrollGlAccounts: React.FC<PayrollGlAccountsProps> = ({
  canManage,
  onAccountsUpdated,
}) => {
  const { banner, show, clear } = useBanner();
  const [accounts, setAccounts] = useState<GlAccount[]>([]);
  const [loading, setLoading] = useState(true);

  // Edit Account Modal
  const [editingAccount, setEditingAccount] = useState<GlAccount | null>(null);
  const [editCode, setEditCode] = useState('');
  const [editName, setEditName] = useState('');
  const [saving, setSaving] = useState(false);

  // Reset Confirmation Modal
  const [showResetConfirm, setShowResetConfirm] = useState(false);
  const [resetting, setResetting] = useState(false);

  const loadAccounts = useCallback(async () => {
    setLoading(true);
    try {
      const res = await payrollApi.listGlAccounts();
      setAccounts(res?.data ?? []);
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal memuat pemetaan akun jurnal GL.'));
    } finally {
      setLoading(false);
    }
  }, [show]);

  useEffect(() => {
    loadAccounts();
  }, [loadAccounts]);

  const handleOpenEdit = (acc: GlAccount) => {
    setEditingAccount(acc);
    setEditCode(acc.account_code);
    setEditName(acc.account_name);
  };

  const handleSaveEdit = async () => {
    if (!editingAccount) return;
    if (!editCode.trim() || !editName.trim()) {
      show('error', 'Kode akun dan nama akun tidak boleh kosong.');
      return;
    }

    setSaving(true);
    try {
      const payload = [
        {
          key: editingAccount.key,
          account_code: editCode.trim(),
          account_name: editName.trim(),
        },
      ];
      const res = await payrollApi.saveGlAccounts(payload);
      show('success', res.message || `Akun ${editingAccount.label || editingAccount.key} berhasil diperbarui.`);
      setEditingAccount(null);
      await loadAccounts();
      if (onAccountsUpdated) onAccountsUpdated();
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal menyimpan perubahan akun jurnal.'));
    } finally {
      setSaving(false);
    }
  };

  const handleReset = async () => {
    setResetting(true);
    try {
      const res = await payrollApi.resetGlAccounts();
      show('success', res.message || 'Pemetaan akun jurnal berhasil dikembalikan ke standar default.');
      setShowResetConfirm(false);
      await loadAccounts();
      if (onAccountsUpdated) onAccountsUpdated();
    } catch (e: any) {
      show('error', errMsg(e, 'Gagal mengembalikan akun ke default.'));
    } finally {
      setResetting(false);
    }
  };

  const debitAccounts = accounts.filter((a) => a.side === 'debit');
  const creditAccounts = accounts.filter((a) => a.side === 'credit');

  return (
    <div className="space-y-5">
      <Banner banner={banner} onClose={clear} />

      {/* Header bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 shadow-xs">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 flex items-center justify-center text-indigo-600 dark:text-indigo-400">
            <Sliders className="w-5 h-5" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-slate-900 dark:text-white">
              Pemetaan Chart of Accounts (COA) Jurnal Payroll
            </h3>
            <p className="text-xs text-slate-500 dark:text-slate-400">
              Sesuaikan kode akun dan nama akun buku besar (GL) agar sinkron dengan sistem akuntansi perusahaan Anda.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={loadAccounts}
            disabled={loading}
            className="p-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition cursor-pointer"
            title="Segarkan data"
          >
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
          </button>
          {canManage && (
            <button
              onClick={() => setShowResetConfirm(true)}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-rose-200 dark:border-rose-900/60 bg-rose-50/50 dark:bg-rose-950/30 text-rose-600 dark:text-rose-400 hover:bg-rose-100/60 text-xs font-semibold transition cursor-pointer"
            >
              <RotateCcw className="w-3.5 h-3.5" />
              <span>Reset ke Default</span>
            </button>
          )}
        </div>
      </div>

      {loading ? (
        <Spinner label="Memuat pemetaan akun jurnal..." />
      ) : accounts.length === 0 ? (
        <EmptyState
          icon={<BookOpen className="w-8 h-8" />}
          title="Tidak Ada Akun Jurnal"
          subtitle="Pemetaan akun belum tersedia di sistem."
        />
      ) : (
        <div className="space-y-6">
          {/* Kelompok Sisi DEBIT */}
          <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-xs">
            <div className="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40 flex items-center justify-between">
              <span className="text-xs font-bold text-slate-700 dark:text-slate-200 flex items-center gap-2">
                <span className="w-2.5 h-2.5 rounded-full bg-sky-500" />
                SISI DEBIT — Beban Perusahaan ({debitAccounts.length} Pos)
              </span>
              <span className="text-[11px] text-slate-400">Pengeluaran & Tanggungan Perusahaan</span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800">
                    <th className="px-4 py-2.5 font-semibold">Pos Jurnal</th>
                    <th className="px-4 py-2.5 font-semibold">Kode Akun</th>
                    <th className="px-4 py-2.5 font-semibold">Nama Akun Buku Besar</th>
                    <th className="px-4 py-2.5 font-semibold text-center">Status</th>
                    {canManage && <th className="px-4 py-2.5 font-semibold text-right">Aksi</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {debitAccounts.map((acc) => (
                    <tr key={acc.key} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition">
                      <td className="px-4 py-3">
                        <span className="font-semibold text-slate-900 dark:text-slate-100 block">
                          {acc.label || acc.key}
                        </span>
                        <span className="font-mono text-[10px] text-slate-400 block">{acc.key}</span>
                      </td>
                      <td className="px-4 py-3 font-mono font-bold text-indigo-600 dark:text-indigo-400">
                        {acc.account_code}
                      </td>
                      <td className="px-4 py-3 font-medium text-slate-800 dark:text-slate-200">
                        {acc.account_name}
                      </td>
                      <td className="px-4 py-3 text-center">
                        {acc.is_overridden ? (
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-400 border border-amber-200 dark:border-amber-800/40">
                            Override
                          </span>
                        ) : (
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                            Standar
                          </span>
                        )}
                      </td>
                      {canManage && (
                        <td className="px-4 py-3 text-right">
                          <button
                            onClick={() => handleOpenEdit(acc)}
                            className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-semibold transition cursor-pointer"
                          >
                            <Pencil className="w-3 h-3 text-indigo-500" />
                            <span>Ubah</span>
                          </button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Kelompok Sisi KREDIT */}
          <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-xs">
            <div className="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40 flex items-center justify-between">
              <span className="text-xs font-bold text-slate-700 dark:text-slate-200 flex items-center gap-2">
                <span className="w-2.5 h-2.5 rounded-full bg-emerald-500" />
                SISI KREDIT — Utang, Pengurang & Kas Keluar ({creditAccounts.length} Pos)
              </span>
              <span className="text-[11px] text-slate-400">Kewajiban Disetor & Pembayaran Net</span>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-left text-slate-400 border-b border-slate-100 dark:border-slate-800">
                    <th className="px-4 py-2.5 font-semibold">Pos Jurnal</th>
                    <th className="px-4 py-2.5 font-semibold">Kode Akun</th>
                    <th className="px-4 py-2.5 font-semibold">Nama Akun Buku Besar</th>
                    <th className="px-4 py-2.5 font-semibold text-center">Status</th>
                    {canManage && <th className="px-4 py-2.5 font-semibold text-right">Aksi</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {creditAccounts.map((acc) => (
                    <tr key={acc.key} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition">
                      <td className="px-4 py-3">
                        <span className="font-semibold text-slate-900 dark:text-slate-100 block">
                          {acc.label || acc.key}
                        </span>
                        <span className="font-mono text-[10px] text-slate-400 block">{acc.key}</span>
                      </td>
                      <td className="px-4 py-3 font-mono font-bold text-emerald-600 dark:text-emerald-400">
                        {acc.account_code}
                      </td>
                      <td className="px-4 py-3 font-medium text-slate-800 dark:text-slate-200">
                        {acc.account_name}
                      </td>
                      <td className="px-4 py-3 text-center">
                        {acc.is_overridden ? (
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-400 border border-amber-200 dark:border-amber-800/40">
                            Override
                          </span>
                        ) : (
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                            Standar
                          </span>
                        )}
                      </td>
                      {canManage && (
                        <td className="px-4 py-3 text-right">
                          <button
                            onClick={() => handleOpenEdit(acc)}
                            className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-300 text-xs font-semibold transition cursor-pointer"
                          >
                            <Pencil className="w-3 h-3 text-indigo-500" />
                            <span>Ubah</span>
                          </button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* Modal Edit Akun */}
      {editingAccount && (
        <Modal
          open={Boolean(editingAccount)}
          onClose={() => setEditingAccount(null)}
          title={`Ubah Akun: ${editingAccount.label || editingAccount.key}`}
          icon={<Pencil className="w-4 h-4 text-indigo-500" />}
          maxW="max-w-md"
          footer={
            <>
              <button
                onClick={() => setEditingAccount(null)}
                className={btnGhost}
                disabled={saving}
              >
                Batal
              </button>
              <button
                onClick={handleSaveEdit}
                className={btnPrimary}
                disabled={saving}
              >
                {saving ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <Check className="w-3.5 h-3.5" />
                )}
                Simpan Perubahan
              </button>
            </>
          }
        >
          <div className="space-y-4">
            <div className="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/40 p-3 text-xs text-slate-600 dark:text-slate-300 space-y-1">
              <p>
                <strong className="text-slate-800 dark:text-slate-100">Pos Jurnal:</strong>{' '}
                {editingAccount.label || editingAccount.key}
              </p>
              <p>
                <strong className="text-slate-800 dark:text-slate-100">Sisi:</strong>{' '}
                {editingAccount.side === 'debit' ? 'DEBIT (Beban)' : 'KREDIT (Utang / Kas)'}
              </p>
              {editingAccount.default_code && (
                <p className="text-[11px] text-slate-400">
                  Standar default: {editingAccount.default_code} - {editingAccount.default_name}
                </p>
              )}
            </div>

            <Field label="Kode Akun" required hint="Contoh: 5100, 2101, 1101">
              <input
                type="text"
                value={editCode}
                onChange={(e) => setEditCode(e.target.value)}
                placeholder="Kode Akun GL"
                className={inputCls}
              />
            </Field>

            <Field label="Nama Akun Buku Besar" required hint="Contoh: Beban Gaji Karyawan, Utang PPh 21">
              <input
                type="text"
                value={editName}
                onChange={(e) => setEditName(e.target.value)}
                placeholder="Nama Akun GL"
                className={inputCls}
              />
            </Field>
          </div>
        </Modal>
      )}

      {/* Modal Konfirmasi Reset ke Default */}
      {showResetConfirm && (
        <Modal
          open={showResetConfirm}
          onClose={() => setShowResetConfirm(false)}
          title="Kembalikan Akun ke Default?"
          icon={<RotateCcw className="w-4 h-4 text-rose-500" />}
          maxW="max-w-md"
          footer={
            <>
              <button
                onClick={() => setShowResetConfirm(false)}
                className={btnGhost}
                disabled={resetting}
              >
                Batal
              </button>
              <button
                onClick={handleReset}
                className={`${btnPrimary} bg-rose-600 hover:bg-rose-700 shadow-rose-500/20`}
                disabled={resetting}
              >
                {resetting ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <Check className="w-3.5 h-3.5" />
                )}
                Ya, Reset ke Default
              </button>
            </>
          }
        >
          <div className="space-y-3 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
            <p>
              Seluruh penyesuaian (override) kode dan nama akun GL perusahaan Anda akan dihapus dan dikembalikan ke kode standar sistem bawaan.
            </p>
            <p className="text-amber-600 dark:text-amber-400 font-semibold">
              Tindakan ini tidak memengaruhi riwayat jurnal yang sudah diekspor sebelumnya.
            </p>
          </div>
        </Modal>
      )}
    </div>
  );
};
