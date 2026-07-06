import React, { useEffect } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { AlertTriangle, Check, X, ShieldAlert, CreditCard, Trash2 } from 'lucide-react';

export type ConfirmationType = 'danger' | 'warning' | 'success' | 'info';

interface ConfirmationDialogProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  message: string | React.ReactNode;
  confirmText?: string;
  cancelText?: string;
  type?: ConfirmationType;
  isLoading?: boolean;
}

export const ConfirmationDialog: React.FC<ConfirmationDialogProps> = ({
  isOpen,
  onClose,
  onConfirm,
  title,
  message,
  confirmText = 'Ya, Lanjutkan',
  cancelText = 'Batal',
  type = 'warning',
  isLoading = false,
}) => {
  // Prevent scrolling when backing is active
  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
    return () => {
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  // Map types to theme colors and icons
  const iconMap = {
    danger: <Trash2 className="w-5.5 h-5.5 text-rose-600 dark:text-rose-455" />,
    warning: <AlertTriangle className="w-5.5 h-5.5 text-amber-600 dark:text-amber-400" />,
    success: <Check className="w-5.5 h-5.5 text-emerald-600 dark:text-emerald-400" />,
    info: <ShieldAlert className="w-5.5 h-5.5 text-indigo-600 dark:text-indigo-400" />,
  };

  const bgIconMap = {
    danger: 'bg-rose-50 dark:bg-rose-950/40 border-rose-100 dark:border-rose-900/30',
    warning: 'bg-amber-50 dark:bg-amber-950/40 border-amber-100 dark:border-amber-900/30',
    success: 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-100 dark:border-emerald-900/30',
    info: 'bg-indigo-50 dark:bg-indigo-950/40 border-indigo-150 dark:border-indigo-900/40',
  };

  const confirmBtnClass = {
    danger: 'bg-rose-600 hover:bg-rose-700 text-white focus:ring-rose-550 shadow-rose-500/10',
    warning: 'bg-amber-600 hover:bg-amber-700 text-white focus:ring-amber-550 shadow-amber-500/10',
    success: 'bg-emerald-600 hover:bg-emerald-700 text-white focus:ring-emerald-550 shadow-emerald-500/10',
    info: 'bg-indigo-600 hover:bg-indigo-700 text-white focus:ring-indigo-550 shadow-indigo-500/10',
  };

  return (
    <AnimatePresence>
      {isOpen && (
        <div className="fixed inset-0 z-55 flex items-center justify-center p-4">
          {/* Backdrop */}
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
            className="fixed inset-0 bg-slate-900/60 dark:bg-slate-950/80 backdrop-blur-xs z-50"
          />

          {/* Dialog Card Container */}
          <motion.div
            initial={{ opacity: 0, scale: 0.95, y: 15 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.95, y: 15 }}
            transition={{ type: 'spring', duration: 0.35 }}
            className="bg-white dark:bg-slate-905 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800 w-full max-w-md p-6 shadow-2xl relative z-55 overflow-hidden font-sans"
          >
            {/* Upper Right Close Button */}
            <button
              onClick={onClose}
              disabled={isLoading}
              className="absolute right-4 top-4 hover:bg-slate-100 dark:hover:bg-slate-800 p-1.5 rounded-full text-slate-400 dark:text-slate-500 transition cursor-pointer disabled:opacity-50"
              aria-label="Tutup dialog"
            >
              <X className="w-4 h-4" />
            </button>

            <div className="flex gap-4 items-start pt-2">
              {/* Type Accent Icon */}
              <div className={`w-11 h-11 rounded-xl flex items-center justify-center shrink-0 border ${bgIconMap[type]}`}>
                {iconMap[type]}
              </div>

              {/* Text Fields */}
              <div className="flex-1 space-y-1.5">
                <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100 leading-tight">
                  {title}
                </h3>
                <div className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed font-normal">
                  {message}
                </div>
              </div>
            </div>

            {/* Actions Bar */}
            <div className="flex gap-2.5 mt-6 justify-end pt-3 border-t border-slate-100 dark:border-slate-800/80">
              <button
                type="button"
                onClick={onClose}
                disabled={isLoading}
                className="py-2.5 px-4 bg-slate-50 hover:bg-slate-100 dark:bg-slate-800/50 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-350 font-semibold rounded-xl text-xs transition cursor-pointer border border-slate-100 dark:border-slate-805 disabled:opacity-50"
              >
                {cancelText}
              </button>
              <button
                type="button"
                onClick={() => {
                  onConfirm();
                }}
                disabled={isLoading}
                className={`py-2.5 px-5 font-semibold rounded-xl text-xs shadow-sm transition cursor-pointer flex items-center justify-center gap-1.5 focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-slate-900 disabled:opacity-50 ${confirmBtnClass[type]}`}
              >
                {isLoading ? (
                  <span className="w-4.5 h-4.5 border-2 border-white/30 border-t-white rounded-full animate-spin shrink-0" />
                ) : null}
                <span>{confirmText}</span>
              </button>
            </div>
          </motion.div>
        </div>
      )}
    </AnimatePresence>
  );
};
