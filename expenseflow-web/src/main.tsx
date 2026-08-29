import {StrictMode} from 'react';
import {createRoot} from 'react-dom/client';
import App from './App.tsx';
import {AuthProvider} from './auth/AuthContext.tsx';
import './index.css';

// Nonaktifkan scroll mouse wheel pada semua input angka di aplikasi web
document.addEventListener(
  'wheel',
  (e: WheelEvent) => {
    const target = e.target as HTMLElement | null;
    const activeEl = document.activeElement;
    if (
      (target instanceof HTMLInputElement && target.type === 'number') ||
      (activeEl instanceof HTMLInputElement && activeEl.type === 'number' && target === activeEl)
    ) {
      if (activeEl instanceof HTMLInputElement && activeEl.type === 'number') {
        activeEl.blur();
      }
      e.preventDefault();
    }
  },
  { passive: false }
);

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <AuthProvider>
      <App />
    </AuthProvider>
  </StrictMode>,
);
