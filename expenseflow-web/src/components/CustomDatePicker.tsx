import React, { useState, useRef, useEffect } from 'react';
import { Calendar as CalendarIcon, ChevronLeft, ChevronRight, ChevronDown, X } from 'lucide-react';

interface CustomDatePickerProps {
  value: string; // YYYY-MM-DD format
  onChange: (value: string) => void;
  placeholder?: string;
  min?: string;
  max?: string;
  disabled?: boolean;
  className?: string;
  required?: boolean;
  id?: string;
  name?: string;
  align?: 'left' | 'right';
  showClear?: boolean;
  size?: 'sm' | 'md' | 'lg';
}

const MONTH_NAMES = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

const MONTH_NAMES_SHORT = [
  'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
  'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
];

const DAY_NAMES = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

export const CustomDatePicker: React.FC<CustomDatePickerProps> = ({
  value,
  onChange,
  placeholder = 'Pilih tanggal',
  min,
  max,
  disabled = false,
  className = '',
  required = false,
  id,
  name,
  align = 'left',
  showClear = true,
  size = 'md',
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  // Parse initial selected date
  const selectedDate = value ? new Date(value + 'T00:00:00') : null;

  // View state (which month/year is currently visible in popup)
  const initialDate = selectedDate || new Date();
  const [viewYear, setViewYear] = useState<number>(initialDate.getFullYear());
  const [viewMonth, setViewMonth] = useState<number>(initialDate.getMonth());
  const [dropdownAlign, setDropdownAlign] = useState<'left' | 'right'>(align);

  // Auto-detect best alignment on open
  useEffect(() => {
    if (isOpen && containerRef.current) {
      if (align === 'right') {
        setDropdownAlign('right');
      } else {
        const rect = containerRef.current.getBoundingClientRect();
        const spaceOnRight = window.innerWidth - rect.left;
        if (spaceOnRight < 330 || rect.right > window.innerWidth - 80) {
          setDropdownAlign('right');
        } else {
          setDropdownAlign('left');
        }
      }
    }
  }, [isOpen, align]);

  // Keep view in sync when value changes externally
  useEffect(() => {
    if (value) {
      const d = new Date(value + 'T00:00:00');
      if (!isNaN(d.getTime())) {
        setViewYear(d.getFullYear());
        setViewMonth(d.getMonth());
      }
    }
  }, [value]);

  // Click outside to close
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };
    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [isOpen]);

  const handlePrevMonth = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (viewMonth === 0) {
      setViewMonth(11);
      setViewYear(prev => prev - 1);
    } else {
      setViewMonth(prev => prev - 1);
    }
  };

  const handleNextMonth = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (viewMonth === 11) {
      setViewMonth(0);
      setViewYear(prev => prev + 1);
    } else {
      setViewMonth(prev => prev + 1);
    }
  };

  const handleSelectDate = (year: number, month: number, day: number) => {
    const formattedMonth = String(month + 1).padStart(2, '0');
    const formattedDay = String(day).padStart(2, '0');
    const dateStr = `${year}-${formattedMonth}-${formattedDay}`;

    if (min && dateStr < min) return;
    if (max && dateStr > max) return;

    onChange(dateStr);
    setIsOpen(false);
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    onChange('');
    setIsOpen(false);
  };

  const handleToday = (e: React.MouseEvent) => {
    e.stopPropagation();
    const today = new Date();
    const formattedMonth = String(today.getMonth() + 1).padStart(2, '0');
    const formattedDay = String(today.getDate()).padStart(2, '0');
    const dateStr = `${today.getFullYear()}-${formattedMonth}-${formattedDay}`;
    onChange(dateStr);
    setViewYear(today.getFullYear());
    setViewMonth(today.getMonth());
    setIsOpen(false);
  };

  // Generate calendar days
  const getDaysInMonth = (year: number, month: number) => new Date(year, month + 1, 0).getDate();
  const getFirstDayOfMonth = (year: number, month: number) => new Date(year, month, 1).getDay();

  const daysInCurrentMonth = getDaysInMonth(viewYear, viewMonth);
  const firstDayOfWeek = getFirstDayOfMonth(viewYear, viewMonth);

  const prevMonthDays = getDaysInMonth(
    viewMonth === 0 ? viewYear - 1 : viewYear,
    viewMonth === 0 ? 11 : viewMonth - 1
  );

  const calendarDays = [];

  // Previous month padding days
  for (let i = firstDayOfWeek - 1; i >= 0; i--) {
    const day = prevMonthDays - i;
    const m = viewMonth === 0 ? 11 : viewMonth - 1;
    const y = viewMonth === 0 ? viewYear - 1 : viewYear;
    calendarDays.push({ day, month: m, year: y, isCurrentMonth: false });
  }

  // Current month days
  for (let i = 1; i <= daysInCurrentMonth; i++) {
    calendarDays.push({ day: i, month: viewMonth, year: viewYear, isCurrentMonth: true });
  }

  // Next month padding days to complete 35 or 42 grid cells
  const remainingCells = (7 - (calendarDays.length % 7)) % 7;
  for (let i = 1; i <= remainingCells; i++) {
    const m = viewMonth === 11 ? 0 : viewMonth + 1;
    const y = viewMonth === 11 ? viewYear + 1 : viewYear;
    calendarDays.push({ day: i, month: m, year: y, isCurrentMonth: false });
  }

  // Format display label
  const formatDisplayValue = (dateStr: string) => {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    const [y, m, d] = parts;
    const monthIdx = parseInt(m, 10) - 1;
    return `${parseInt(d, 10)} ${MONTH_NAMES_SHORT[monthIdx] || m} ${y}`;
  };

  const todayStr = (() => {
    const t = new Date();
    return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
  })();

  // Year choices (from 1950 to currentYear + 10)
  const currentYear = new Date().getFullYear();
  const years = Array.from({ length: 85 }, (_, idx) => currentYear + 10 - idx);

  // Size variations
  const sizeClasses = {
    sm: 'px-3 py-1.5 text-xs',
    md: 'px-3.5 py-2.5 text-xs sm:text-sm',
    lg: 'px-4 py-3 text-sm sm:text-base',
  };

  return (
    <div className={`relative inline-block w-full ${className}`} ref={containerRef}>
      {/* Hidden input for HTML form compatibility */}
      <input
        type="hidden"
        id={id}
        name={name}
        value={value}
        required={required}
      />

      {/* Input Trigger Button */}
      <button
        type="button"
        disabled={disabled}
        onClick={() => !disabled && setIsOpen(!isOpen)}
        className={`w-full flex items-center justify-between gap-2.5 rounded-xl border transition-all cursor-pointer text-left ${
          sizeClasses[size]
        } ${
          isOpen
            ? 'border-indigo-500 ring-2 ring-indigo-500/20 bg-white dark:bg-slate-900'
            : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:border-slate-300 dark:hover:border-slate-600'
        } ${disabled ? 'opacity-50 cursor-not-allowed bg-slate-50 dark:bg-slate-950' : ''}`}
      >
        <div className="flex items-center gap-2.5 min-w-0 flex-1">
          <CalendarIcon className={`w-4 h-4 shrink-0 transition-colors ${isOpen || value ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-400'}`} />
          <span className={`truncate block font-medium ${value ? 'text-slate-900 dark:text-white' : 'text-slate-400'}`}>
            {value ? formatDisplayValue(value) : placeholder}
          </span>
        </div>

        <div className="flex items-center gap-1 shrink-0">
          {showClear && value && !disabled && (
            <span
              role="button"
              tabIndex={0}
              onClick={handleClear}
              className="p-1 rounded-md text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all"
              title="Hapus tanggal"
            >
              <X size={13} />
            </span>
          )}
          <ChevronDown size={14} className={`text-slate-400 transition-transform duration-200 ${isOpen ? 'rotate-180 text-indigo-600' : ''}`} />
        </div>
      </button>

      {/* Modern Popover Calendar (Exact Match to User Reference Image) */}
      {isOpen && (
        <div
          className={`absolute top-full mt-2 z-50 p-4 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl animate-in fade-in zoom-in-95 duration-150 w-[300px] sm:w-[320px] max-w-[calc(100vw-24px)] ${
            dropdownAlign === 'right' ? 'right-0' : 'left-0'
          }`}
        >
          {/* Header with Prev Button, Month Select, Year Select, Next Button */}
          <div className="flex items-center justify-between gap-1 mb-3">
            {/* Prev Button (Round style) */}
            <button
              type="button"
              onClick={handlePrevMonth}
              className="w-8 h-8 rounded-full flex items-center justify-center transition-all bg-slate-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white dark:hover:bg-indigo-600 text-slate-700 dark:text-slate-300 cursor-pointer shadow-xs active:scale-95"
              title="Bulan Sebelumnya"
            >
              <ChevronLeft size={16} />
            </button>

            {/* Month & Year Selectors */}
            <div className="flex items-center gap-1.5">
              {/* Month Dropdown */}
              <div className="relative">
                <select
                  value={viewMonth}
                  onChange={(e) => setViewMonth(parseInt(e.target.value, 10))}
                  className="appearance-none bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl px-2.5 py-1.5 pr-6 text-xs font-semibold text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer outline-none focus:ring-2 focus:ring-indigo-500/20"
                >
                  {MONTH_NAMES.map((name, idx) => (
                    <option key={idx} value={idx}>
                      {name}
                    </option>
                  ))}
                </select>
                <ChevronDown size={12} className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
              </div>

              {/* Year Dropdown */}
              <div className="relative">
                <select
                  value={viewYear}
                  onChange={(e) => setViewYear(parseInt(e.target.value, 10))}
                  className="appearance-none bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 rounded-xl px-2.5 py-1.5 pr-6 text-xs font-semibold text-slate-800 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer outline-none focus:ring-2 focus:ring-indigo-500/20"
                >
                  {years.map(y => (
                    <option key={y} value={y}>
                      {y}
                    </option>
                  ))}
                </select>
                <ChevronDown size={12} className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
              </div>
            </div>

            {/* Next Button (Round style) */}
            <button
              type="button"
              onClick={handleNextMonth}
              className="w-8 h-8 rounded-full flex items-center justify-center transition-all bg-slate-100 dark:bg-slate-800 hover:bg-indigo-600 hover:text-white dark:hover:bg-indigo-600 text-slate-700 dark:text-slate-300 cursor-pointer shadow-xs active:scale-95"
              title="Bulan Berikutnya"
            >
              <ChevronRight size={16} />
            </button>
          </div>

          {/* Days of Week Header */}
          <div className="grid grid-cols-7 gap-1 text-center mb-1.5">
            {DAY_NAMES.map((name, idx) => (
              <div
                key={idx}
                className="text-[11px] font-semibold text-slate-400 dark:text-slate-500 py-1"
              >
                {name}
              </div>
            ))}
          </div>

          {/* Days Grid */}
          <div className="grid grid-cols-7 gap-1.5">
            {calendarDays.map((item, idx) => {
              const formattedM = String(item.month + 1).padStart(2, '0');
              const formattedD = String(item.day).padStart(2, '0');
              const itemDateStr = `${item.year}-${formattedM}-${formattedD}`;

              const isSelected = value === itemDateStr;
              const isToday = itemDateStr === todayStr;
              const isDisabledMin = min ? itemDateStr < min : false;
              const isDisabledMax = max ? itemDateStr > max : false;
              const isDisabledDate = isDisabledMin || isDisabledMax;

              let tileClass = '';

              if (isSelected) {
                // Vibrant Purple / Indigo solid background with white text (matches screenshot)
                tileClass = 'bg-indigo-600 text-white font-bold shadow-md shadow-indigo-500/30 scale-105 ring-2 ring-indigo-400/40';
              } else if (isDisabledDate) {
                tileClass = 'text-slate-300 dark:text-slate-700 opacity-40 cursor-not-allowed';
              } else if (!item.isCurrentMonth) {
                // Faded previous/next month days
                tileClass = 'text-slate-300 dark:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800/40';
              } else {
                // Current month regular days (rounded soft square)
                tileClass = isToday
                  ? 'bg-indigo-50/80 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 font-bold border border-indigo-500/40 hover:bg-indigo-100 dark:hover:bg-indigo-900/60'
                  : 'bg-slate-50/80 dark:bg-slate-800/50 text-slate-700 dark:text-slate-200 font-medium hover:bg-indigo-50 dark:hover:bg-indigo-950/80 hover:text-indigo-600 dark:hover:text-indigo-400';
              }

              return (
                <button
                  key={idx}
                  type="button"
                  disabled={isDisabledDate}
                  onClick={() => handleSelectDate(item.year, item.month, item.day)}
                  className={`h-8 sm:h-9 rounded-xl flex items-center justify-center text-xs transition-all cursor-pointer select-none ${tileClass}`}
                >
                  {item.day}
                </button>
              );
            })}
          </div>

          {/* Quick Footer Action Bar */}
          {value && (
            <div className="flex items-center justify-end pt-3 mt-3 border-t border-slate-100 dark:border-slate-800 text-xs">
              <button
                type="button"
                onClick={handleClear}
                className="font-medium text-slate-400 hover:text-rose-500 dark:hover:text-rose-400 transition-colors cursor-pointer"
              >
                Kosongkan
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
};
export default CustomDatePicker;
