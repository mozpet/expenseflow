import React, { useState } from 'react';
import { AppSettings } from '../types';
import {
  Save,
  CheckCircle2
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

      // Reset success banner after 3 seconds
      setTimeout(() => setSavedSuccess(false), 3000);
    }, 1000);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      {/* Banner success */}
      {savedSuccess && (
        <div className="bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-900 rounded-xl p-4 flex items-center gap-2.5 text-xs text-emerald-800 dark:text-emerald-400 animate-in fade-in slide-in-from-top-3 duration-300">
          <CheckCircle2 className="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0" />
          <div>
            <span className="font-bold block">Konfigurasi Berhasil Diperbarui</span>
            <span className="p-0 text-slate-500 block dark:text-slate-400">Aturan batas fraud & approval berjenjang telah diperbarui di seluruh ekosistem finance.</span>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Invoice Thresholds */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
          <div className="flex justify-between items-center pb-2 border-b border-slate-100 dark:border-slate-800">
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 font-sans">
              Threshold Approval Invoice Vendor
            </h3>
          </div>

          <div className="space-y-4 text-xs font-sans">
            <div className="space-y-1">
              <label className="text-slate-600 dark:text-slate-400 font-medium block">
                Finance Manager (Persetujuan Tunggal)
              </label>
              <input
                type="text"
                value={thresholdSingle}
                onChange={(e) => setThresholdSingle(e.target.value)}
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none"
                required
              />
            </div>

            <div className="space-y-1">
              <label className="text-slate-600 dark:text-slate-400 font-medium block">
                Finance + Direksi (2-Level Berjenjang)
              </label>
              <input
                type="text"
                value={thresholdTwo}
                onChange={(e) => setThresholdTwo(e.target.value)}
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none"
                required
              />
            </div>

            <div className="space-y-1">
              <label className="text-slate-600 dark:text-slate-400 font-medium block">
                Finance + Dir + Komisaris (3-Level Ekstrim)
              </label>
              <input
                type="text"
                value={thresholdThree}
                onChange={(e) => setThresholdThree(e.target.value)}
                className="w-full text-xs p-2.5 border border-slate-200 dark:border-slate-700 rounded-xl bg-slate-50/50 dark:bg-slate-800/10 text-slate-800 dark:text-slate-100 focus:outline-none"
                required
              />
            </div>
          </div>
        </div>
      </div>

      <div className="flex justify-end pt-2">
        <button
          type="submit"
          disabled={saving}
          className="flex items-center justify-center gap-1.5 py-2.5 px-6 font-semibold bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs shadow-md shadow-indigo-500/15 disabled:opacity-50 transition"
        >
          {saving ? 'Menyimpan...' : (
            <>
              <Save className="w-4 h-4" />
              <span>Simpan Aturan Pengaturan</span>
            </>
          )}
        </button>
      </div>
    </form>
  );
};
