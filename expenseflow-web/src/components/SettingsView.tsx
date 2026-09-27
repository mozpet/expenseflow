import React, { useState } from 'react';
import { AppSettings } from '../types';
import {
  Save,
  CheckCircle2,
  FileSpreadsheet,
  Building2,
} from 'lucide-react';

interface SettingsViewProps {
  currentSettings: AppSettings;
  onSaveSettings: (settings: AppSettings) => void;
}

export const SettingsView: React.FC<SettingsViewProps> = ({
  currentSettings,
  onSaveSettings,
}) => {
  const [thresholdSingle, setThresholdSingle] = useState(currentSettings.thresholdSingle);
  const [thresholdTwo, setThresholdTwo] = useState(currentSettings.thresholdTwo);
  const [thresholdThree, setThresholdThree] = useState(currentSettings.thresholdThree);

  const [saving, setSaving] = useState(false);
  const [savedSuccess, setSavedSuccess] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    setSaving(true);
    setSavedSuccess(false);

    setTimeout(() => {
      onSaveSettings({
        ...currentSettings,
        thresholdSingle,
        thresholdTwo,
        thresholdThree,
      });
      setSaving(false);
      setSavedSuccess(true);
      setTimeout(() => setSavedSuccess(false), 3000);
    }, 800);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6 max-w-2xl">
      {/* Banner success */}
      {savedSuccess && (
        <div className="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900 rounded-xl p-4 flex items-center gap-2.5 text-xs text-emerald-800 dark:text-emerald-400 animate-in fade-in slide-in-from-top-3 duration-300">
          <CheckCircle2 className="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0" />
          <div>
            <span className="font-bold block">Konfigurasi Berhasil Diperbarui</span>
            <span className="p-0 text-slate-500 block dark:text-slate-400">Aturan batas approval invoice vendor telah diperbarui.</span>
          </div>
        </div>
      )}

      {/* Invoice Thresholds Card */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-6 shadow-sm space-y-5">
        <div className="flex items-center gap-3 pb-3 border-b border-slate-100 dark:border-slate-800">
          <div className="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
            <FileSpreadsheet className="w-5 h-5" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
              Threshold Approval Invoice Vendor
            </h3>
            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
              Tentukan batas nominal berjenjang untuk proses persetujuan faktur/invoice vendor.
            </p>
          </div>
        </div>

        <div className="space-y-4 text-xs font-sans">
          <div className="space-y-1.5">
            <label className="text-slate-600 dark:text-slate-400 font-semibold block">
              Finance Manager (Persetujuan Tunggal)
            </label>
            <input
              type="text"
              value={thresholdSingle}
              onChange={(e) => setThresholdSingle(e.target.value)}
              className="w-full text-xs p-3 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
              required
            />
            <p className="text-[11px] text-slate-400">Nominal di bawah batas ini cukup disetujui oleh Finance Manager.</p>
          </div>

          <div className="space-y-1.5">
            <label className="text-slate-600 dark:text-slate-400 font-semibold block">
              Finance + Direksi (2-Level Berjenjang)
            </label>
            <input
              type="text"
              value={thresholdTwo}
              onChange={(e) => setThresholdTwo(e.target.value)}
              className="w-full text-xs p-3 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
              required
            />
            <p className="text-[11px] text-slate-400">Nominal pada rentang ini memerlukan persetujuan dari Finance dan Direksi.</p>
          </div>

          <div className="space-y-1.5">
            <label className="text-slate-600 dark:text-slate-400 font-semibold block">
              Finance + Dir + Komisaris (3-Level Ekstrim)
            </label>
            <input
              type="text"
              value={thresholdThree}
              onChange={(e) => setThresholdThree(e.target.value)}
              className="w-full text-xs p-3 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
              required
            />
            <p className="text-[11px] text-slate-400">Nominal di atas batas ini mewajibkan persetujuan hingga Komisaris.</p>
          </div>
        </div>

        <div className="flex justify-end pt-3 border-t border-slate-100 dark:border-slate-800">
          <button
            type="submit"
            disabled={saving}
            className="flex items-center justify-center gap-1.5 py-2.5 px-6 font-semibold bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs shadow-md shadow-indigo-500/15 disabled:opacity-50 transition cursor-pointer"
          >
            {saving ? 'Menyimpan...' : (
              <>
                <Save className="w-4 h-4" />
                <span>Simpan Pengaturan Invoice</span>
              </>
            )}
          </button>
        </div>
      </div>
    </form>
  );
};
