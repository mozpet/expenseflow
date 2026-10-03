import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
  Code,
  Sparkles,
  CheckCircle2,
  AlertTriangle,
  Play,
  RotateCcw,
  BookOpen,
  HelpCircle,
  Variable,
  Layers,
  Sigma,
  Info,
  Check,
  ChevronDown,
  ChevronUp,
} from 'lucide-react';
import { payrollApi } from '../services/endpoints';
import { FormulaValidationResult } from '../types';

interface FormulaDslEditorProps {
  value: string;
  onChange: (val: string) => void;
  componentCode?: string;
  availableComponents?: Array<{
    id: number;
    code: string;
    name: string;
    type: string;
    calc_type?: string;
    is_active?: boolean;
  }>;
  disabled?: boolean;
  onValidationChange?: (isValid: boolean, errorMsg?: string | null) => void;
}

// ── Katalog Variabel Sistem (Berdasarkan Spesifikasi FormulaEngine) ──
const SYSTEM_VARIABLES_CATALOG: Array<{
  name: string;
  label: string;
  description: string;
  example: string;
  category: 'upah' | 'presensi' | 'lainnya';
}> = [
  {
    name: 'BASIC_SALARY',
    label: 'Gaji Pokok',
    description: 'Gaji pokok efektif karyawan pada periode berjalan.',
    example: '5.000.000',
    category: 'upah',
  },
  {
    name: 'FIXED_ALLOWANCE',
    label: 'Tunjangan Tetap (Kena Pajak)',
    description: 'Total tunjangan tetap kena pajak selain gaji pokok.',
    example: '1.000.000',
    category: 'upah',
  },
  {
    name: 'FIXED_ALLOWANCE_ALL',
    label: 'Total Seluruh Tunjangan Tetap',
    description: 'Total seluruh tunjangan tetap termasuk yang non-pajak.',
    example: '1.500.000',
    category: 'upah',
  },
  {
    name: 'GROSS_TAXABLE',
    label: 'Bruto Kena Pajak Berjalan',
    description: 'Penghasilan bruto kena pajak sebelum komponen formula ini dihitung.',
    example: '6.500.000',
    category: 'upah',
  },
  {
    name: 'PRESENT_DAYS',
    label: 'Jumlah Hari Hadir',
    description: 'Jumlah hari karyawan hadir kerja (termasuk WFH & dinas).',
    example: '20',
    category: 'presensi',
  },
  {
    name: 'ABSENT_DAYS',
    label: 'Jumlah Hari Alpa/Absen',
    description: 'Jumlah hari tidak masuk tanpa keterangan dalam periode.',
    example: '2',
    category: 'presensi',
  },
  {
    name: 'WORKING_DAYS',
    label: 'Hari Kerja Seharusnya',
    description: 'Jumlah hari kerja standar dalam satu bulan (biasanya 21 atau 22).',
    example: '22',
    category: 'presensi',
  },
  {
    name: 'OVERTIME_HOURS',
    label: 'Total Jam Lembur',
    description: 'Total jam lembur yang telah disetujui (SPKL) dalam periode.',
    example: '10',
    category: 'presensi',
  },
  {
    name: 'TENURE_MONTHS',
    label: 'Masa Kerja (Bulan)',
    description: 'Lama kerja karyawan terhitung sejak tanggal bergabung (bulan penuh).',
    example: '14',
    category: 'lainnya',
  },
  {
    name: 'UMR_AMOUNT',
    label: 'Nilai UMR/UMK',
    description: 'Upah minimum regional/kota yang berlaku untuk perusahaan.',
    example: '5.067.381',
    category: 'lainnya',
  },
];

// ── Katalog Fungsi Bawaan (Mathematical & Logical Functions) ──
const FUNCTIONS_CATALOG: Array<{
  name: string;
  syntax: string;
  snippet: string;
  description: string;
  example: string;
}> = [
  {
    name: 'ROUND',
    syntax: 'ROUND(angka, desimal)',
    snippet: 'ROUND(, 0)',
    description: 'Membulatkan angka ke desimal tertentu (default 0 desimal/angka bulat).',
    example: 'ROUND((BASIC_SALARY / 22) * PRESENT_DAYS, 0)',
  },
  {
    name: 'IF',
    syntax: 'IF(kondisi, jika_benar, jika_salah)',
    snippet: 'IF(, , )',
    description: 'Percabangan logika. Menghasilkan nilai A jika benar, atau nilai B jika salah.',
    example: 'IF(TENURE_MONTHS >= 12, 1000000, 500000)',
  },
  {
    name: 'MIN',
    syntax: 'MIN(a, b, ...)',
    snippet: 'MIN(, )',
    description: 'Mengambil nilai terkecil di antara daftar angka (cocok untuk membatasi plafon/cap).',
    example: 'MIN(BASIC_SALARY * 10%, 1500000)',
  },
  {
    name: 'MAX',
    syntax: 'MAX(a, b, ...)',
    snippet: 'MAX(, )',
    description: 'Mengambil nilai terbesar di antara daftar angka (cocok untuk lantai dasar/minimum).',
    example: 'MAX(BASIC_SALARY * 5%, 250000)',
  },
  {
    name: 'CLAMP',
    syntax: 'CLAMP(nilai, batas_bawah, batas_atas)',
    snippet: 'CLAMP(, , )',
    description: 'Membatasi nilai agar selalu berada di antara batas bawah dan batas atas.',
    example: 'CLAMP(BASIC_SALARY * 8%, 500000, 2000000)',
  },
  {
    name: 'FLOOR',
    syntax: 'FLOOR(angka)',
    snippet: 'FLOOR()',
    description: 'Pembulatan ke bawah ke bilangan bulat terdekat.',
    example: 'FLOOR(BASIC_SALARY / WORKING_DAYS)',
  },
  {
    name: 'CEIL',
    syntax: 'CEIL(angka)',
    snippet: 'CEIL()',
    description: 'Pembulatan ke atas ke bilangan bulat terdekat.',
    example: 'CEIL(BASIC_SALARY / WORKING_DAYS)',
  },
  {
    name: 'ABS',
    syntax: 'ABS(angka)',
    snippet: 'ABS()',
    description: 'Menghasilkan nilai mutlak (selalu positif).',
    example: 'ABS(GROSS_TAXABLE - 5000000)',
  },
  {
    name: 'AND',
    syntax: 'AND(kondisi1, kondisi2, ...)',
    snippet: 'AND(, )',
    description: 'Bernilai TRUE jika seluruh kondisi terpenuhi.',
    example: 'AND(PRESENT_DAYS >= 20, ABSENT_DAYS == 0)',
  },
  {
    name: 'OR',
    syntax: 'OR(kondisi1, kondisi2, ...)',
    snippet: 'OR(, )',
    description: 'Bernilai TRUE jika salah satu kondisi terpenuhi.',
    example: 'OR(TENURE_MONTHS >= 24, BASIC_SALARY > 10000000)',
  },
  {
    name: 'NOT',
    syntax: 'NOT(kondisi)',
    snippet: 'NOT()',
    description: 'Membalikkan nilai logika (TRUE menjadi FALSE dan sebaliknya).',
    example: 'NOT(ABSENT_DAYS > 0)',
  },
];

// ── Template Rumus Populer Siap Pakai ──
const PRESET_TEMPLATES: Array<{
  title: string;
  formula: string;
  category: string;
  description: string;
}> = [
  {
    title: 'Tunjangan Uang Makan Harian',
    formula: '50000 * PRESENT_DAYS',
    category: 'Kehadiran',
    description: 'Rp 50.000 per hari masuk kerja aktual.',
  },
  {
    title: 'Insentif Lembur Flat Per Jam',
    formula: 'OVERTIME_HOURS * 35000',
    category: 'Lembur',
    description: 'Rp 35.000 per jam lembur yang disetujui.',
  },
  {
    title: 'Potongan Alpa Prorata Hari Kerja',
    formula: 'ROUND((BASIC_SALARY / WORKING_DAYS) * ABSENT_DAYS)',
    category: 'Potongan',
    description: 'Potong gaji pokok prorata per hari absen tanpa keterangan.',
  },
  {
    title: 'Tunjangan Transport Persentase (Maksimal Cap)',
    formula: 'MIN(BASIC_SALARY * 10%, 1500000)',
    category: 'Tunjangan',
    description: '10% dari gaji pokok dengan batas maksimal (cap) Rp 1.500.000.',
  },
  {
    title: 'Bonus Loyalitas Masa Kerja',
    formula: 'IF(TENURE_MONTHS >= 12, 1000000, 500000)',
    category: 'Bonus',
    description: 'Rp 1.000.000 untuk masa kerja ≥ 1 tahun, atau Rp 500.000 jika < 1 tahun.',
  },
  {
    title: 'Tunjangan Kehadiran Sempurna (Full Attendance)',
    formula: 'IF(ABSENT_DAYS == 0, 500000, 0)',
    category: 'Kehadiran',
    description: 'Bonus Rp 500.000 jika tidak pernah alpa dalam periode berjalan.',
  },
  {
    title: 'Uang Transport Prorata Hari Hadir',
    formula: 'ROUND((BASIC_SALARY * 5%) / WORKING_DAYS) * PRESENT_DAYS',
    category: 'Tunjangan',
    description: '5% gaji pokok dibagi hari kerja standar, dikalikan hari masuk.',
  },
];

const formatCurrency = (val: unknown) => {
  const n = typeof val === 'number' ? val : Number(val);
  if (isNaN(n)) return 'Rp 0';
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(n);
};

export const FormulaDslEditor: React.FC<FormulaDslEditorProps> = ({
  value,
  onChange,
  componentCode,
  availableComponents = [],
  disabled = false,
  onValidationChange,
}) => {
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const [activeTab, setActiveTab] = useState<'variables' | 'components' | 'functions' | 'presets'>('variables');
  const [validating, setValidating] = useState(false);
  const [validation, setValidation] = useState<FormulaValidationResult | null>(null);
  const [validationError, setValidationError] = useState<string | null>(null);

  // State untuk Sandbox Simulasi Uji Coba
  const [sampleValues, setSampleValues] = useState<Record<string, number>>({});
  const [sampleResult, setSampleResult] = useState<number | null>(null);
  const [simulating, setSimulating] = useState(false);
  const [showSandbox, setShowSandbox] = useState(true);

  // Saring komponen lain agar tidak menampilkan kode komponen ini sendiri
  const otherComponents = availableComponents.filter(
    (c) => c.is_active !== false && (!componentCode || c.code.toUpperCase() !== componentCode.toUpperCase())
  );

  // ── Fungsi Sisipkan Teks ke Posisi Cursor Textarea ──
  const insertTextAtCursor = useCallback(
    (textToInsert: string) => {
      const textarea = textareaRef.current;
      if (!textarea) {
        onChange(value ? `${value} ${textToInsert}` : textToInsert);
        return;
      }

      const start = textarea.selectionStart ?? value.length;
      const end = textarea.selectionEnd ?? value.length;
      const before = value.substring(0, start);
      const after = value.substring(end, value.length);

      // Tambahkan spasi bila diperlukan
      const needsSpaceBefore = before.length > 0 && !before.endsWith(' ') && !before.endsWith('(') && !textToInsert.startsWith(' ') && !textToInsert.startsWith(',') && !textToInsert.startsWith(')');
      const needsSpaceAfter = after.length > 0 && !after.startsWith(' ') && !after.startsWith(')') && !after.startsWith(',') && !textToInsert.endsWith(' ') && !textToInsert.endsWith('(');

      const insertion = (needsSpaceBefore ? ' ' : '') + textToInsert + (needsSpaceAfter ? ' ' : '');
      const newValue = before + insertion + after;

      if (newValue.length > 500) {
        return;
      }

      onChange(newValue);

      // Kembalikan fokus ke textarea
      setTimeout(() => {
        textarea.focus();
        const newCursorPos = start + insertion.length;
        textarea.setSelectionRange(newCursorPos, newCursorPos);
      }, 10);
    },
    [value, onChange]
  );

  // ── Validasi Rumus (Debounced) ──
  const runValidation = useCallback(
    async (formulaToTest: string, customSample?: Record<string, number>) => {
      const clean = formulaToTest.trim();
      if (!clean) {
        setValidation(null);
        setValidationError(null);
        setSampleResult(null);
        if (onValidationChange) onValidationChange(false, 'Formula masih kosong');
        return;
      }

      setValidating(true);
      try {
        const payload: { formula: string; code?: string; sample?: Record<string, number> } = {
          formula: clean,
          code: componentCode ? componentCode.toUpperCase() : undefined,
        };
        if (customSample && Object.keys(customSample).length > 0) {
          payload.sample = customSample;
        }

        const res = await payrollApi.validateFormula(payload);
        const data = res?.data;

        if (data) {
          setValidation(data);
          if (data.ok) {
            setValidationError(null);
            if (data.sample_result !== undefined) {
              setSampleResult(data.sample_result);
            }
            if (onValidationChange) onValidationChange(true, null);

            // Inisialisasi default nilai sample bila belum ada
            if (data.variables && data.variables.length > 0) {
              setSampleValues((prev) => {
                const next = { ...prev };
                data.variables.forEach((v) => {
                  if (next[v] === undefined) {
                    if (v === 'BASIC_SALARY') next[v] = 5000000;
                    else if (v === 'FIXED_ALLOWANCE' || v === 'FIXED_ALLOWANCE_ALL') next[v] = 1000000;
                    else if (v === 'GROSS_TAXABLE') next[v] = 6000000;
                    else if (v === 'WORKING_DAYS') next[v] = 22;
                    else if (v === 'PRESENT_DAYS') next[v] = 20;
                    else if (v === 'ABSENT_DAYS') next[v] = 2;
                    else if (v === 'OVERTIME_HOURS') next[v] = 6;
                    else if (v === 'TENURE_MONTHS') next[v] = 14;
                    else if (v === 'UMR_AMOUNT') next[v] = 5000000;
                    else next[v] = 500000;
                  }
                });
                return next;
              });
            }
          } else {
            const err = data.error || 'Sintaks formula tidak valid.';
            setValidationError(err);
            setSampleResult(null);
            if (onValidationChange) onValidationChange(false, err);
          }
        }
      } catch (e: any) {
        const msg = e?.response?.data?.message || e?.message || 'Gagal memvalidasi formula.';
        setValidationError(msg);
        setValidation(null);
        setSampleResult(null);
        if (onValidationChange) onValidationChange(false, msg);
      } finally {
        setValidating(false);
      }
    },
    [componentCode, onValidationChange]
  );

  // Debounce otomatis saat nilai formula berubah
  useEffect(() => {
    const timer = setTimeout(() => {
      runValidation(value);
    }, 450);
    return () => clearTimeout(timer);
  }, [value, runValidation]);

  // ── Simulasi Hitung Uji Coba ──
  const handleSimulate = async () => {
    if (!value.trim() || !validation?.ok) return;
    setSimulating(true);
    try {
      const res = await payrollApi.validateFormula({
        formula: value.trim(),
        code: componentCode ? componentCode.toUpperCase() : undefined,
        sample: sampleValues,
      });
      if (res?.data?.sample_result !== undefined) {
        setSampleResult(res.data.sample_result);
      }
    } catch {
      // ignore
    } finally {
      setSimulating(false);
    }
  };

  const isMaxLength = value.length >= 500;

  return (
    <div className="space-y-3.5 rounded-2xl border border-indigo-100 dark:border-indigo-950/60 bg-gradient-to-b from-indigo-50/40 via-white to-white dark:from-slate-900/80 dark:via-slate-900 dark:to-slate-900 p-4 shadow-sm">
      {/* Header Info */}
      <div className="flex items-center justify-between gap-3 flex-wrap border-b border-indigo-100/60 dark:border-slate-800/80 pb-3">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-xl bg-indigo-600/10 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold">
            <Sigma className="w-4 h-4" />
          </div>
          <div>
            <h4 className="text-xs font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5">
              Mesin Formula Dinamis (DSL)
              <span className="text-[10px] px-2 py-0.5 rounded-full font-bold bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200/50 dark:border-indigo-800/50">
                FormulaEngine
              </span>
            </h4>
            <p className="text-[11px] text-slate-500 dark:text-slate-400">
              Rumus dievaluasi otomatis tanpa <code>eval()</code> dengan perlindungan ketergantungan melingkar.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <span
            className={`text-[10px] font-mono font-semibold px-2 py-1 rounded-lg border ${
              isMaxLength
                ? 'bg-rose-50 text-rose-600 border-rose-200 dark:bg-rose-950/50 dark:text-rose-400'
                : value.length > 400
                ? 'bg-amber-50 text-amber-600 border-amber-200 dark:bg-amber-950/50 dark:text-amber-400'
                : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-700'
            }`}
          >
            {value.length} / 500 karakter
          </span>
          {value && (
            <button
              type="button"
              onClick={() => onChange('')}
              className="text-xs text-slate-400 hover:text-rose-500 transition p-1 cursor-pointer"
              title="Kosongkan rumus"
            >
              <RotateCcw className="w-3.5 h-3.5" />
            </button>
          )}
        </div>
      </div>

      {/* Textarea Input Editor */}
      <div className="space-y-1.5">
        <div className="relative">
          <textarea
            ref={textareaRef}
            value={value}
            onChange={(e) => onChange(e.target.value.substring(0, 500))}
            disabled={disabled}
            rows={3}
            placeholder="Contoh: 50000 * PRESENT_DAYS atau IF(TENURE_MONTHS >= 12, 1000000, 500000)"
            className="w-full font-mono text-xs rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-950/80 px-3.5 py-2.5 text-slate-800 dark:text-slate-100 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 transition disabled:opacity-50"
            spellCheck={false}
          />
        </div>

        {/* Quick Operator Toolbar */}
        <div className="flex items-center gap-1 flex-wrap pt-0.5">
          <span className="text-[10px] uppercase font-bold tracking-wider text-slate-400 mr-1 flex items-center gap-1">
            <Code className="w-3 h-3" /> Sisip Cepat:
          </span>
          {[
            { label: '+', insert: '+' },
            { label: '-', insert: '-' },
            { label: '×', insert: '*' },
            { label: '÷', insert: '/' },
            { label: '%', insert: '%' },
            { label: '(', insert: '(' },
            { label: ')', insert: ')' },
            { label: ',', insert: ',' },
            { label: '>', insert: '>' },
            { label: '<', insert: '<' },
            { label: '≥', insert: '>=' },
            { label: '≤', insert: '<=' },
            { label: '==', insert: '==' },
            { label: '!=', insert: '!=' },
          ].map((op) => (
            <button
              key={op.label}
              type="button"
              onClick={() => insertTextAtCursor(op.insert)}
              className="px-2 py-0.5 rounded-md font-mono text-[11px] font-semibold bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:bg-indigo-50 hover:border-indigo-300 dark:hover:bg-indigo-950/60 transition cursor-pointer shadow-xs active:scale-95"
            >
              {op.label}
            </button>
          ))}
          <button
            type="button"
            onClick={() => runValidation(value, sampleValues)}
            disabled={validating || !value.trim()}
            className="ml-auto inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 transition cursor-pointer disabled:opacity-50"
          >
            <Sparkles className="w-3 h-3" />
            {validating ? 'Memvalidasi...' : 'Cek Sintaks'}
          </button>
        </div>
      </div>

      {/* Validation Status Indicator */}
      {value.trim() && (
        <div className="text-xs">
          {validationError ? (
            <div className="flex items-start gap-2.5 p-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 text-rose-700 dark:text-rose-300">
              <AlertTriangle className="w-4 h-4 text-rose-500 shrink-0 mt-0.5" />
              <div className="space-y-0.5">
                <p className="font-semibold text-xs">Rumus Belum Valid</p>
                <p className="text-[11px] font-mono leading-relaxed">{validationError}</p>
              </div>
            </div>
          ) : validation?.ok ? (
            <div className="p-3 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/80 dark:border-emerald-900/60 text-emerald-800 dark:text-emerald-300 space-y-2">
              <div className="flex items-center justify-between flex-wrap gap-2">
                <div className="flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
                  <span className="font-bold text-xs">Sintaks Valid & Siap Digunakan</span>
                </div>
                {validation.variables?.length > 0 && (
                  <div className="flex items-center gap-1 flex-wrap">
                    <span className="text-[10px] text-emerald-700/80 dark:text-emerald-400/80 font-medium">Variabel:</span>
                    {validation.variables.map((v) => (
                      <span key={v} className="font-mono text-[10px] px-1.5 py-0.2 rounded bg-emerald-100/80 dark:bg-emerald-900/60 font-semibold">
                        {v}
                      </span>
                    ))}
                  </div>
                )}
              </div>

              {/* Uji Sandbox Simulasi Nilai */}
              <div className="pt-2 border-t border-emerald-200/60 dark:border-emerald-900/40">
                <button
                  type="button"
                  onClick={() => setShowSandbox(!showSandbox)}
                  className="w-full flex items-center justify-between text-[11px] font-semibold text-emerald-700 dark:text-emerald-400 hover:text-emerald-900 dark:hover:text-emerald-200 transition cursor-pointer"
                >
                  <span className="flex items-center gap-1.5">
                    <Play className="w-3 h-3 text-emerald-600" />
                    Simulasi Hitung (Uji Coba dengan Angka)
                  </span>
                  {showSandbox ? <ChevronUp className="w-3.5 h-3.5" /> : <ChevronDown className="w-3.5 h-3.5" />}
                </button>

                {showSandbox && (
                  <div className="mt-2.5 space-y-3 bg-white/80 dark:bg-slate-900/90 rounded-xl p-3 border border-emerald-100 dark:border-slate-800 shadow-xs">
                    {validation.variables?.length > 0 ? (
                      <div>
                        <p className="text-[10px] text-slate-500 dark:text-slate-400 mb-2">
                          Sesuaikan contoh angka variabel berikut untuk menguji hasil evaluasi:
                        </p>
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                          {validation.variables.map((v) => (
                            <div key={v} className="space-y-0.5">
                              <label className="text-[10px] font-mono font-medium text-slate-600 dark:text-slate-400 block truncate" title={v}>
                                {v}
                              </label>
                              <input
                                type="number"
                                value={sampleValues[v] ?? 0}
                                onChange={(e) => {
                                  const n = Number(e.target.value);
                                  setSampleValues((prev) => ({ ...prev, [v]: n }));
                                }}
                                className="w-full text-xs font-mono rounded-lg border border-slate-200 dark:border-slate-700 px-2 py-1 bg-white dark:bg-slate-950 text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                              />
                            </div>
                          ))}
                        </div>
                      </div>
                    ) : (
                      <p className="text-[11px] text-slate-500 dark:text-slate-400">
                        Formula berupa konstanta / tanpa variabel variabel bebas.
                      </p>
                    )}

                    <div className="flex items-center justify-between gap-3 pt-1 border-t border-slate-100 dark:border-slate-800">
                      <button
                        type="button"
                        onClick={handleSimulate}
                        disabled={simulating}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-600 hover:bg-emerald-700 text-white transition cursor-pointer shadow-xs disabled:opacity-50"
                      >
                        <Play className="w-3 h-3" />
                        {simulating ? 'Menghitung...' : 'Hitung Simulasi'}
                      </button>

                      {sampleResult !== null && (
                        <div className="text-right">
                          <span className="text-[10px] uppercase tracking-wide text-slate-400 font-semibold block">Hasil Perhitungan:</span>
                          <span className="text-sm font-bold font-mono text-emerald-600 dark:text-emerald-400">
                            {formatCurrency(sampleResult)}
                          </span>
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>
            </div>
          ) : null}
        </div>
      )}

      {/* Tabs Palet Bantuan: Variabel, Komponen Lain, Fungsi, & Presets */}
      <div className="space-y-2 pt-1 border-t border-slate-100 dark:border-slate-800">
        <div className="flex items-center gap-1 overflow-x-auto pb-1">
          {[
            { key: 'variables', label: 'Variabel Sistem', icon: Variable },
            { key: 'components', label: 'Komponen Lain', icon: Layers },
            { key: 'functions', label: 'Fungsi Bawaan', icon: Code },
            { key: 'presets', label: 'Template Populer', icon: BookOpen },
          ].map((tab) => {
            const Icon = tab.icon;
            const active = activeTab === tab.key;
            return (
              <button
                key={tab.key}
                type="button"
                onClick={() => setActiveTab(tab.key as any)}
                className={`inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap transition cursor-pointer ${
                  active
                    ? 'bg-indigo-600 text-white shadow-xs'
                    : 'bg-white dark:bg-slate-800/80 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80'
                }`}
              >
                <Icon className="w-3.5 h-3.5" />
                {tab.label}
              </button>
            );
          })}
        </div>

        {/* Tab 1: Variabel Sistem */}
        {activeTab === 'variables' && (
          <div className="space-y-1.5 max-h-56 overflow-y-auto pr-1">
            <p className="text-[11px] text-slate-500 dark:text-slate-400 pb-1">
              Klik nama variabel untuk menyisipkannya langsung ke posisi kursor rumus:
            </p>
            <div className="grid sm:grid-cols-2 gap-1.5">
              {SYSTEM_VARIABLES_CATALOG.map((v) => (
                <button
                  key={v.name}
                  type="button"
                  onClick={() => insertTextAtCursor(v.name)}
                  className="flex items-start justify-between gap-2 p-2 rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900/60 hover:border-indigo-300 dark:hover:border-indigo-700 hover:bg-indigo-50/30 dark:hover:bg-indigo-950/30 transition text-left cursor-pointer group"
                >
                  <div className="space-y-0.5">
                    <span className="font-mono text-[11px] font-bold text-indigo-600 dark:text-indigo-400 group-hover:underline">
                      {v.name}
                    </span>
                    <p className="text-[10px] text-slate-500 dark:text-slate-400 leading-tight">
                      {v.description}
                    </p>
                  </div>
                  <span className="text-[9px] px-1.5 py-0.5 rounded font-mono font-medium bg-slate-100 dark:bg-slate-800 text-slate-500 shrink-0">
                    {v.example}
                  </span>
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Tab 2: Komponen Lain */}
        {activeTab === 'components' && (
          <div className="space-y-2 max-h-56 overflow-y-auto pr-1">
            <div className="flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
              <Info className="w-3.5 h-3.5 text-indigo-500 shrink-0" />
              <span>
                Komponen lain dapat dirujuk sebagai leaf dalam rumus. Pastikan tidak membentuk lingkaran ketergantungan.
              </span>
            </div>
            {otherComponents.length === 0 ? (
              <p className="text-xs text-slate-400 italic p-3 text-center">
                Belum ada komponen lain yang terdaftar.
              </p>
            ) : (
              <div className="grid sm:grid-cols-2 gap-1.5">
                {otherComponents.map((c) => (
                  <button
                    key={c.id}
                    type="button"
                    onClick={() => insertTextAtCursor(c.code)}
                    className="flex items-center justify-between gap-2 p-2 rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900/60 hover:border-indigo-300 dark:hover:border-indigo-700 hover:bg-indigo-50/30 dark:hover:bg-indigo-950/30 transition text-left cursor-pointer group"
                  >
                    <div>
                      <span className="font-mono text-[11px] font-bold text-slate-800 dark:text-slate-200 group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                        {c.code}
                      </span>
                      <p className="text-[10px] text-slate-500 dark:text-slate-400 truncate max-w-[170px]">{c.name}</p>
                    </div>
                    <span
                      className={`text-[9px] font-bold px-1.5 py-0.5 rounded-full shrink-0 ${
                        c.type === 'earning'
                          ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                          : 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                      }`}
                    >
                      {c.type === 'earning' ? 'Pendapatan' : 'Potongan'}
                    </span>
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Tab 3: Fungsi Bawaan */}
        {activeTab === 'functions' && (
          <div className="space-y-1.5 max-h-56 overflow-y-auto pr-1">
            <p className="text-[11px] text-slate-500 dark:text-slate-400 pb-1">
              Klik nama fungsi untuk menyisipkan sintaks ke kursor:
            </p>
            <div className="grid sm:grid-cols-2 gap-1.5">
              {FUNCTIONS_CATALOG.map((f) => (
                <button
                  key={f.name}
                  type="button"
                  onClick={() => insertTextAtCursor(f.snippet)}
                  className="p-2 rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900/60 hover:border-indigo-300 dark:hover:border-indigo-700 hover:bg-indigo-50/30 dark:hover:bg-indigo-950/30 transition text-left cursor-pointer group space-y-0.5"
                >
                  <div className="flex items-center justify-between">
                    <span className="font-mono text-[11px] font-bold text-indigo-600 dark:text-indigo-400 group-hover:underline">
                      {f.syntax}
                    </span>
                  </div>
                  <p className="text-[10px] text-slate-500 dark:text-slate-400 leading-tight">
                    {f.description}
                  </p>
                  <p className="text-[9px] font-mono text-slate-400 truncate pt-0.5">
                    Misal: {f.example}
                  </p>
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Tab 4: Template Populer */}
        {activeTab === 'presets' && (
          <div className="space-y-1.5 max-h-56 overflow-y-auto pr-1">
            <p className="text-[11px] text-slate-500 dark:text-slate-400 pb-1">
              Pilih salah satu template rumus di bawah untuk langsung menggantikan isi formula:
            </p>
            <div className="space-y-1.5">
              {PRESET_TEMPLATES.map((p) => (
                <div
                  key={p.title}
                  className="p-2.5 rounded-xl border border-slate-200/70 dark:border-slate-800 bg-white dark:bg-slate-900/60 flex items-center justify-between gap-3 hover:border-indigo-200 dark:hover:border-indigo-900 transition"
                >
                  <div className="space-y-0.5 min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-bold text-slate-800 dark:text-slate-200">{p.title}</span>
                      <span className="text-[9px] font-medium px-1.5 py-0.2 rounded bg-slate-100 dark:bg-slate-800 text-slate-500">
                        {p.category}
                      </span>
                    </div>
                    <p className="text-[10px] text-slate-500 dark:text-slate-400">{p.description}</p>
                    <code className="text-[11px] font-mono font-semibold text-indigo-600 dark:text-indigo-400 block pt-0.5 truncate">
                      {p.formula}
                    </code>
                  </div>
                  <button
                    type="button"
                    onClick={() => onChange(p.formula)}
                    className="shrink-0 px-2.5 py-1.5 rounded-lg text-[11px] font-semibold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-600 hover:text-white transition cursor-pointer border border-indigo-200/50 dark:border-indigo-800/50"
                  >
                    Gunakan
                  </button>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default FormulaDslEditor;
