import React, { useState, useEffect } from 'react';
import { NotificationItem } from '../types';
import { 
  Bell, 
  Clock, 
  AlertTriangle, 
  CheckCircle2, 
  X, 
  Info,
  CreditCard
} from 'lucide-react';
import { ConfirmationDialog } from './ConfirmationDialog';

interface NotificationsViewProps {
  notifications: NotificationItem[];
  onMarkAllRead: () => void;
  onDismiss: (id: string) => void;
  onNavigate?: (pageName: string) => void;
  onMarkRead?: (id: string) => void;
}

export const NotificationsView: React.FC<NotificationsViewProps> = ({
  notifications,
  onMarkAllRead,
  onDismiss,
  onNavigate,
  onMarkRead,
}) => {
  // Pagination state
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [pageSize, setPageSize] = useState<number>(25);

  const [confirmState, setConfirmState] = useState<{
    isOpen: boolean;
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    type: 'danger' | 'warning' | 'success' | 'info';
    onConfirm: () => void;
  } | null>(null);

  const openConfirm = (opts: {
    title: string;
    message: string | React.ReactNode;
    confirmText?: string;
    type: 'danger' | 'warning' | 'success' | 'info';
    onConfirm: () => void;
  }) => {
    setConfirmState({
      isOpen: true,
      ...opts
    });
  };

  const handleNotificationClick = (notif: NotificationItem) => {
    if (!notif.read && onMarkRead) {
      onMarkRead(notif.id);
    }
    if (notif.targetPage && onNavigate) {
      onNavigate(notif.targetPage);
    }
  };

  const unreadCount = notifications.filter(n => !n.read).length;
  const totalPages = Math.max(1, Math.ceil(notifications.length / pageSize));
  const paginatedNotifications = notifications.slice((currentPage - 1) * pageSize, currentPage * pageSize);

  return (
    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
      {/* Header section */}
      <div className="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-4">
        <div>
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2 font-sans">
            <div className="relative flex items-center justify-center">
              <Bell className="w-4.5 h-4.5 text-indigo-600" />
              {unreadCount > 0 && (
                <span className="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-rose-500 animate-pulse" />
              )}
            </div>
            Notifikasi Sistem Real-Time
            {unreadCount > 0 && (
              <span className="px-2 py-0.5 rounded-full bg-rose-500/15 text-rose-600 dark:text-rose-400 text-xs font-bold font-mono">
                {unreadCount} Baru
              </span>
            )}
          </h3>
          <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
            Klik notifikasi untuk langsung menuju halaman approval, pengajuan cuti/lembur, invoice, atau struk terkait.
          </p>
        </div>

        {unreadCount > 0 && (
          <button 
            onClick={onMarkAllRead}
            className="px-3 py-1.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-lg text-xs font-medium transition cursor-pointer"
          >
            Tandai Semua Dibaca
          </button>
        )}
      </div>

      {/* Notifications stack */}
      <div className="divide-y divide-slate-100 dark:divide-slate-800/60 font-sans">
        {notifications.length === 0 ? (
          <div className="py-12 text-center text-slate-400 dark:text-slate-500 text-xs font-semibold">
            Kamu berada di inbox kosong! Tidak ada notifikasi baru.
          </div>
        ) : (
          paginatedNotifications.map((notif) => {
            // Pick corresponding icon
            let IconComponent = Info;
            let iconBg = 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400';
            
            if (notif.type === 'due') {
              IconComponent = Clock;
              iconBg = 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400';
            } else if (notif.type === 'flag') {
              IconComponent = AlertTriangle;
              iconBg = 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400';
            } else if (notif.type === 'success') {
              IconComponent = CheckCircle2;
              iconBg = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-400';
            }

            return (
              <div 
                key={notif.id} 
                onClick={() => handleNotificationClick(notif)}
                className={`py-3.5 px-3 rounded-xl flex gap-3 items-start justify-between select-none transition-all cursor-pointer group ${
                  !notif.read 
                    ? 'bg-indigo-50/40 dark:bg-indigo-950/20 hover:bg-indigo-50/70 dark:hover:bg-indigo-950/40 border border-indigo-100 dark:border-indigo-900/40' 
                    : 'hover:bg-slate-50 dark:hover:bg-slate-800/30'
                }`}
              >
                <div className="flex gap-3 items-start min-w-0 flex-1">
                  {/* Titik Notifikasi Unread (Hilang setelah diinteraksi/diklik) */}
                  <div className="pt-2 shrink-0 flex items-center justify-center">
                    {!notif.read ? (
                      <span className="relative flex h-2.5 w-2.5">
                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                        <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-indigo-600"></span>
                      </span>
                    ) : (
                      <span className="w-2.5 h-2.5" />
                    )}
                  </div>

                  {/* Responsive Icon container */}
                  <span className={`w-8 h-8 rounded-full flex items-center justify-center shrink-0 ${iconBg}`}>
                    <IconComponent className="w-4.5 h-4.5" />
                  </span>
                  
                  <div className="text-xs space-y-1 min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <p className={`text-slate-800 dark:text-slate-200 ${!notif.read ? 'font-bold' : 'font-medium'}`}>
                        {notif.title}
                      </p>
                      {notif.targetLabel && (
                        <span className="text-[10px] px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-semibold group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/60 group-hover:text-indigo-600 dark:group-hover:text-indigo-300 transition-colors">
                          {notif.targetLabel} →
                        </span>
                      )}
                    </div>
                    <p className="text-slate-500 text-[11px] leading-relaxed dark:text-slate-400">
                      {notif.subtitle}
                    </p>
                    <span className="text-[10px] text-slate-400 block pt-0.5">{notif.time}</span>
                  </div>
                </div>

                <div className="flex items-center gap-1 shrink-0 ml-2" onClick={(e) => e.stopPropagation()}>
                  <button 
                    onClick={() => {
                      openConfirm({
                        title: 'Hapus Notifikasi',
                        message: (
                          <span>Apakah Anda yakin ingin menghapus notifikasi: <strong>"{notif.title}"</strong>?</span>
                        ),
                        confirmText: 'Ya, Hapus',
                        type: 'danger',
                        onConfirm: () => onDismiss(notif.id)
                      });
                    }}
                    className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-rose-500 dark:hover:text-rose-400 transition cursor-pointer"
                    title="Hapus"
                  >
                    <X className="w-4 h-4" />
                  </button>
                </div>
              </div>
            );
          })
        )}
      </div>

      {/* Pagination footer */}
      {notifications.length >= 25 && (
        <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-4 border-t border-slate-100 dark:border-slate-800 text-xs">
          <div className="flex items-center gap-2 text-slate-500 dark:text-slate-400">
            <span>
              Menampilkan <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">
                {Math.min((currentPage - 1) * pageSize + 1, notifications.length)} - {Math.min(currentPage * pageSize, notifications.length)}
              </strong> dari <strong className="text-slate-800 dark:text-slate-200 font-bold font-mono">{notifications.length}</strong> notifikasi
            </span>
            <span className="hidden sm:inline">•</span>
            <div className="flex items-center gap-1.5">
              <span className="hidden sm:inline">Per hal:</span>
              <select
                value={pageSize}
                onChange={(e) => {
                  setPageSize(Number(e.target.value));
                  setCurrentPage(1);
                }}
                className="py-0.5 px-2 text-xs font-semibold border border-slate-200 dark:border-slate-700 rounded-lg bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-300 focus:outline-none cursor-pointer"
              >
                <option value={25}>25</option>
                <option value={50}>50</option>
                <option value={100}>100</option>
              </select>
            </div>
          </div>

          <div className="flex items-center gap-1.5">
            <button
              type="button"
              onClick={() => setCurrentPage(1)}
              disabled={currentPage === 1}
              className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
              title="Halaman Pertama"
            >
              «
            </button>
            <button
              type="button"
              onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
              disabled={currentPage === 1}
              className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
              title="Halaman Sebelumnya"
            >
              ‹
            </button>
            <span className="px-2 font-semibold text-slate-700 dark:text-slate-300">
              Hal <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">{currentPage}</span> / <span className="font-mono">{totalPages}</span>
            </span>
            <button
              type="button"
              onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
              disabled={currentPage === totalPages}
              className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
              title="Halaman Berikutnya"
            >
              ›
            </button>
            <button
              type="button"
              onClick={() => setCurrentPage(totalPages)}
              disabled={currentPage === totalPages}
              className="p-1 px-2 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition font-medium cursor-pointer"
              title="Halaman Terakhir"
            >
              »
            </button>
          </div>
        </div>
      )}

      {/* Reusable Confirmation Dialog */}
      {confirmState && (
        <ConfirmationDialog
          isOpen={confirmState.isOpen}
          onClose={() => setConfirmState(null)}
          onConfirm={() => {
            confirmState.onConfirm();
            setConfirmState(null);
          }}
          title={confirmState.title}
          message={confirmState.message}
          confirmText={confirmState.confirmText}
          type={confirmState.type}
        />
      )}
    </div>
  );
};
