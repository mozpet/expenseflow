import React, { useState } from 'react';
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
}

export const NotificationsView: React.FC<NotificationsViewProps> = ({
  notifications,
  onMarkAllRead,
  onDismiss,
}) => {
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

  return (
    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-sm space-y-4">
      {/* Header section */}
      <div className="flex justify-between items-center border-b border-slate-100 dark:border-slate-800 pb-4">
        <div>
          <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-1.5 font-sans">
            <Bell className="w-4.5 h-4.5 text-indigo-600 animate-bounce" />
            Notifikasi Sistem Real-Time ({notifications.filter(n => !n.read).length})
          </h3>
          <p className="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
            Pengingat jatuh tempo, deteksi fraud, dan pembaruan approval transfer
          </p>
        </div>

        <button 
          onClick={onMarkAllRead}
          className="px-2.5 py-1.5 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 rounded-lg text-xs font-medium transition"
        >
          Tandai Semua Dibaca
        </button>
      </div>

      {/* Notifications stack */}
      <div className="divide-y divide-slate-100 dark:divide-slate-800/60 font-sans">
        {notifications.length === 0 ? (
          <div className="py-12 text-center text-slate-400 dark:text-slate-500 text-xs font-semibold">
            Kamu berada di inbox kosong! Tidak ada notifikasi baru.
          </div>
        ) : (
          notifications.map((notif) => {
            // Pick corresponding icon
            let IconComponent = Info;
            let iconBg = 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400';
            
            if (notif.type === 'due') {
              IconComponent = Clock;
              iconBg = 'bg-rose-105 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400';
            } else if (notif.type === 'flag') {
              IconComponent = AlertTriangle;
              iconBg = 'bg-amber-100 text-amber-700 dark:bg-amber-955/40 dark:text-amber-400';
            } else if (notif.type === 'success') {
              IconComponent = CheckCircle2;
              iconBg = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-400';
            }

            return (
              <div 
                key={notif.id} 
                className={`py-4 flex gap-3 items-start justify-between select-none hover:bg-slate-50/50 dark:hover:bg-slate-850/10 transition-colors px-1 ${
                  !notif.read ? 'bg-indigo-50/10 dark:bg-indigo-950/2' : ''
                }`}
              >
                <div className="flex gap-3 items-start">
                  {/* Bullet indicator is unread */}
                  {!notif.read && (
                    <span className="w-2 h-2 rounded-full bg-indigo-600 mt-2 shrink-0 animate-ping" />
                  )}
                  {/* Responsive Icon container */}
                  <span className={`w-8 h-8 rounded-full flex items-center justify-center shrink-0 ${iconBg}`}>
                    <IconComponent className="w-4.5 h-4.5" />
                  </span>
                  
                  <div className="text-xs space-y-0.5">
                    <p className={`text-slate-800 dark:text-slate-200 ${!notif.read ? 'font-bold' : 'font-medium'}`}>
                      {notif.title}
                    </p>
                    <p className="text-slate-455 text-[11px] leading-relaxed dark:text-slate-400">
                      {notif.subtitle}
                    </p>
                    <span className="text-[10px] text-slate-400 block pt-1">{notif.time}</span>
                  </div>
                </div>

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
                  className="p-1 hover:bg-slate-105 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 shrink-0 transition"
                  title="Hapus"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            );
          })
        )}
      </div>

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
