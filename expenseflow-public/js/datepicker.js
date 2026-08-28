/**
 * ExpenseFlow Career Portal - Modern Custom DatePicker
 * Replaces standard browser datepicker with high-fidelity UI matching the reference design.
 */
(function() {
  const MONTH_NAMES = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
  ];

  const MONTH_NAMES_SHORT = [
    'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
    'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
  ];

  const DAY_NAMES = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

  class ModernDatePicker {
    constructor(inputElement) {
      this.input = inputElement;
      this.currentDate = this.input.value ? new Date(this.input.value + 'T00:00:00') : null;
      this.viewYear = this.currentDate ? this.currentDate.getFullYear() : new Date().getFullYear();
      this.viewMonth = this.currentDate ? this.currentDate.getMonth() : new Date().getMonth();
      this.isOpen = false;

      this.init();
    }

    init() {
      // Hide native picker behavior by setting type to text with readonly trigger
      this.input.type = 'text';
      this.input.readOnly = true;
      this.input.style.cursor = 'pointer';
      this.input.placeholder = this.input.placeholder || 'YYYY-MM-DD';

      // Create Wrapper
      const wrapper = document.createElement('div');
      wrapper.className = 'relative w-full custom-datepicker-wrapper';
      this.input.parentNode.insertBefore(wrapper, this.input);
      wrapper.appendChild(this.input);

      // Add Calendar Icon to Right/Left
      const icon = document.createElement('div');
      icon.className = 'absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none transition-colors';
      icon.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>`;
      wrapper.appendChild(icon);

      // Create Popover
      this.popover = document.createElement('div');
      this.popover.className = 'custom-datepicker-popover absolute left-0 top-full mt-2 z-50 p-4 bg-[#181824] border border-white/10 rounded-3xl shadow-2xl backdrop-blur-xl w-[300px] sm:w-[320px] hidden animate-in fade-in zoom-in-95 duration-150';
      wrapper.appendChild(this.popover);

      // Events
      this.input.addEventListener('click', (e) => {
        e.stopPropagation();
        this.toggle();
      });

      document.addEventListener('click', (e) => {
        if (!wrapper.contains(e.target)) {
          this.close();
        }
      });

      this.updateDisplay();
    }

    toggle() {
      if (this.isOpen) {
        this.close();
      } else {
        this.open();
      }
    }

    open() {
      if (this.input.value) {
        const d = new Date(this.input.value + 'T00:00:00');
        if (!isNaN(d.getTime())) {
          this.currentDate = d;
          this.viewYear = d.getFullYear();
          this.viewMonth = d.getMonth();
        }
      }
      this.render();
      this.popover.classList.remove('hidden');
      this.isOpen = true;
    }

    close() {
      this.popover.classList.add('hidden');
      this.isOpen = false;
    }

    updateDisplay() {
      if (this.input.value) {
        const parts = this.input.value.split('-');
        if (parts.length === 3) {
          const [y, m, d] = parts;
          const monthIdx = parseInt(m, 10) - 1;
          this.input.setAttribute('data-formatted', `${parseInt(d, 10)} ${MONTH_NAMES_SHORT[monthIdx] || m} ${y}`);
        }
      }
    }

    render() {
      const currentYear = new Date().getFullYear();
      const years = Array.from({ length: 85 }, (_, idx) => currentYear + 5 - idx);

      const daysInMonth = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
      const firstDayOfWeek = new Date(this.viewYear, this.viewMonth, 1).getDay();
      const prevMonthDays = new Date(this.viewYear, this.viewMonth, 0).getDate();

      const today = new Date();
      const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
      const selectedStr = this.input.value;

      // Header UI
      let html = `
        <div class="flex items-center justify-between gap-1 mb-3">
          <!-- Prev Button -->
          <button type="button" class="btn-prev-month w-8 h-8 rounded-full flex items-center justify-center transition-all bg-white/5 hover:bg-brand-600 text-slate-300 hover:text-white cursor-pointer active:scale-95">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
          </button>

          <!-- Month & Year Selector -->
          <div class="flex items-center gap-1.5">
            <div class="relative">
              <select class="select-month appearance-none bg-white/5 border border-white/10 rounded-xl px-2.5 py-1.5 pr-6 text-xs font-semibold text-slate-200 hover:bg-white/10 transition-colors cursor-pointer outline-none focus:ring-2 focus:ring-brand-500/30">
                ${MONTH_NAMES.map((m, idx) => `<option value="${idx}" ${idx === this.viewMonth ? 'selected' : ''} class="bg-slate-900 text-white">${m}</option>`).join('')}
              </select>
              <svg class="w-3 h-3 absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
            </div>

            <div class="relative">
              <select class="select-year appearance-none bg-white/5 border border-white/10 rounded-xl px-2.5 py-1.5 pr-6 text-xs font-semibold text-slate-200 hover:bg-white/10 transition-colors cursor-pointer outline-none focus:ring-2 focus:ring-brand-500/30">
                ${years.map(y => `<option value="${y}" ${y === this.viewYear ? 'selected' : ''} class="bg-slate-900 text-white">${y}</option>`).join('')}
              </select>
              <svg class="w-3 h-3 absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
            </div>
          </div>

          <!-- Next Button -->
          <button type="button" class="btn-next-month w-8 h-8 rounded-full flex items-center justify-center transition-all bg-white/5 hover:bg-brand-600 text-slate-300 hover:text-white cursor-pointer active:scale-95">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
          </button>
        </div>

        <!-- Day Names Header -->
        <div class="grid grid-cols-7 gap-1 text-center mb-1.5">
          ${DAY_NAMES.map(name => `<div class="text-[11px] font-semibold text-slate-400 py-1">${name}</div>`).join('')}
        </div>

        <!-- Calendar Grid -->
        <div class="grid grid-cols-7 gap-1.5">
      `;

      // Prev month padding days
      for (let i = firstDayOfWeek - 1; i >= 0; i--) {
        const d = prevMonthDays - i;
        const m = this.viewMonth === 0 ? 11 : this.viewMonth - 1;
        const y = this.viewMonth === 0 ? this.viewYear - 1 : this.viewYear;
        html += `
          <button type="button" data-day="${d}" data-month="${m}" data-year="${y}" class="btn-day h-8 sm:h-9 rounded-xl flex items-center justify-center text-xs text-slate-600 hover:bg-white/5 transition-all cursor-pointer">
            ${d}
          </button>
        `;
      }

      // Current month days
      for (let d = 1; d <= daysInMonth; d++) {
        const formattedM = String(this.viewMonth + 1).padStart(2, '0');
        const formattedD = String(d).padStart(2, '0');
        const dateStr = `${this.viewYear}-${formattedM}-${formattedD}`;

        const isSelected = dateStr === selectedStr;
        const isToday = dateStr === todayStr;

        let cls = 'btn-day h-8 sm:h-9 rounded-xl flex items-center justify-center text-xs font-semibold transition-all cursor-pointer ';

        if (isSelected) {
          cls += 'bg-brand-600 text-white font-bold shadow-md shadow-brand-500/40 scale-105 ring-2 ring-brand-400/40';
        } else if (isToday) {
          cls += 'bg-brand-500/15 text-brand-400 font-bold border border-brand-500/40 hover:bg-brand-500/25';
        } else {
          cls += 'bg-white/[0.04] text-slate-200 hover:bg-brand-500/20 hover:text-brand-300';
        }

        html += `
          <button type="button" data-day="${d}" data-month="${this.viewMonth}" data-year="${this.viewYear}" data-date="${dateStr}" class="${cls}">
            ${d}
          </button>
        `;
      }

      // Next month padding days
      const totalCells = firstDayOfWeek + daysInMonth;
      const remaining = (7 - (totalCells % 7)) % 7;
      for (let d = 1; d <= remaining; d++) {
        const m = this.viewMonth === 11 ? 0 : this.viewMonth + 1;
        const y = this.viewMonth === 11 ? this.viewYear + 1 : this.viewYear;
        html += `
          <button type="button" data-day="${d}" data-month="${m}" data-year="${y}" class="btn-day h-8 sm:h-9 rounded-xl flex items-center justify-center text-xs text-slate-600 hover:bg-white/5 transition-all cursor-pointer">
            ${d}
          </button>
        `;
      }

      html += `
        </div>

        <!-- Footer Actions -->
        ${this.input.value ? `
        <div class="flex items-center justify-end pt-3 mt-3 border-t border-white/10 text-xs">
          <button type="button" class="btn-clear font-medium text-slate-400 hover:text-rose-400 transition-colors cursor-pointer">Kosongkan</button>
        </div>` : ''}
      `;

      this.popover.innerHTML = html;
      this.attachPopoverEvents();
    }

    attachPopoverEvents() {
      // Prev month
      const btnPrev = this.popover.querySelector('.btn-prev-month');
      btnPrev?.addEventListener('click', (e) => {
        e.stopPropagation();
        if (this.viewMonth === 0) {
          this.viewMonth = 11;
          this.viewYear--;
        } else {
          this.viewMonth--;
        }
        this.render();
      });

      // Next month
      const btnNext = this.popover.querySelector('.btn-next-month');
      btnNext?.addEventListener('click', (e) => {
        e.stopPropagation();
        if (this.viewMonth === 11) {
          this.viewMonth = 0;
          this.viewYear++;
        } else {
          this.viewMonth++;
        }
        this.render();
      });

      // Month select
      const selectMonth = this.popover.querySelector('.select-month');
      selectMonth?.addEventListener('change', (e) => {
        e.stopPropagation();
        this.viewMonth = parseInt(e.target.value, 10);
        this.render();
      });

      // Year select
      const selectYear = this.popover.querySelector('.select-year');
      selectYear?.addEventListener('change', (e) => {
        e.stopPropagation();
        this.viewYear = parseInt(e.target.value, 10);
        this.render();
      });

      // Day buttons
      const dayBtns = this.popover.querySelectorAll('.btn-day');
      dayBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const day = btn.getAttribute('data-day');
          const month = btn.getAttribute('data-month');
          const year = btn.getAttribute('data-year');

          const formattedM = String(parseInt(month, 10) + 1).padStart(2, '0');
          const formattedD = String(parseInt(day, 10)).padStart(2, '0');
          const finalDateStr = `${year}-${formattedM}-${formattedD}`;

          this.input.value = finalDateStr;
          this.updateDisplay();

          // Dispatch native change & input events
          this.input.dispatchEvent(new Event('input', { bubbles: true }));
          this.input.dispatchEvent(new Event('change', { bubbles: true }));

          this.close();
        });
      });

      // Today button
      const btnToday = this.popover.querySelector('.btn-today');
      btnToday?.addEventListener('click', (e) => {
        e.stopPropagation();
        const today = new Date();
        const formattedM = String(today.getMonth() + 1).padStart(2, '0');
        const formattedD = String(today.getDate()).padStart(2, '0');
        const dateStr = `${today.getFullYear()}-${formattedM}-${formattedD}`;

        this.input.value = dateStr;
        this.updateDisplay();
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
        this.input.dispatchEvent(new Event('change', { bubbles: true }));
        this.close();
      });

      // Clear button
      const btnClear = this.popover.querySelector('.btn-clear');
      btnClear?.addEventListener('click', (e) => {
        e.stopPropagation();
        this.input.value = '';
        this.updateDisplay();
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
        this.input.dispatchEvent(new Event('change', { bubbles: true }));
        this.close();
      });
    }
  }

  // Auto-initialize on DOM ready
  document.addEventListener('DOMContentLoaded', () => {
    const dateInputs = document.querySelectorAll('input[type="date"], input[data-datepicker]');
    dateInputs.forEach(input => {
      new ModernDatePicker(input);
    });
  });

  window.ModernDatePicker = ModernDatePicker;
})();
