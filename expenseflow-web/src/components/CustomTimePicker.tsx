import React, { useState, useRef, useEffect } from 'react';
import { Clock, ChevronDown, Check, Sparkles, X } from 'lucide-react';

export type TimezoneType = 'WIB' | 'WITA' | 'WIT';

export interface CustomTimePickerProps {
  value: string; // e.g. "10:00 WIB", "14:30", "09:00 WIB"
  onChange: (value: string) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  required?: boolean;
  id?: string;
  name?: string;
  align?: 'left' | 'right';
  showClear?: boolean;
  size?: 'sm' | 'md' | 'lg';
  includeTimezone?: boolean;
  defaultTimezone?: TimezoneType;
}

const PRESET_TIMES = [
  '08:00', '08:30',
  '09:00', '09:30',
  '10:00', '10:30',
  '11:00', '11:30',
  '13:00', '13:30',
  '14:00', '14:30',
  '15:00', '15:30',
  '16:00', '16:30',
  '17:00'
];

const HOURS_24 = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));
const MINUTES_5 = ['00', '05', '10', '15', '20', '25', '30', '35', '40', '45', '50', '55'];

export const CustomTimePicker: React.FC<CustomTimePickerProps> = ({
  value,
  onChange,
  placeholder = 'Pilih waktu (24 jam)',
  disabled = false,
  className = '',
  required = false,
  id,
  name,
  align = 'left',
  showClear = false,
  size = 'md',
  includeTimezone = true,
  defaultTimezone = 'WIB',
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  // Parse current value
  const parseTime = (str: string): { hour: string; minute: string; timezone: TimezoneType } => {
    const fallbackTz: TimezoneType = (defaultTimezone as TimezoneType) || 'WIB';
    if (!str) return { hour: '10', minute: '00', timezone: fallbackTz };
    
    // Check timezone
    let tz: TimezoneType = fallbackTz;
    if (str.toUpperCase().includes('WITA')) tz = 'WITA';
    else if (str.toUpperCase().includes('WIT')) tz = 'WIT';
    else if (str.toUpperCase().includes('WIB')) tz = 'WIB';

    // Extract HH:MM
    const match = str.match(/(\d{1,2})[:.](\d{2})/);
    if (match) {
      const h = String(Math.min(Math.max(parseInt(match[1], 10), 0), 23)).padStart(2, '0');
      const m = String(Math.min(Math.max(parseInt(match[2], 10), 0), 59)).padStart(2, '0');
      return { hour: h, minute: m, timezone: tz };
    }
    return { hour: '10', minute: '00', timezone: fallbackTz };
  };

  const parsed = parseTime(value);
  const [selectedHour, setSelectedHour] = useState(parsed.hour);
  const [selectedMinute, setSelectedMinute] = useState(parsed.minute);
  const [selectedTz, setSelectedTz] = useState<TimezoneType>(parsed.timezone);
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

  useEffect(() => {
    if (value) {
      const p = parseTime(value);
      setSelectedHour(p.hour);
      setSelectedMinute(p.minute);
      setSelectedTz(p.timezone);
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

  const emitChange = (h: string, m: string, tz: string) => {
    const res = includeTimezone ? `${h}:${m} ${tz}` : `${h}:${m}`;
    onChange(res);
  };

  const handleSelectPreset = (preset: string) => {
    const [h, m] = preset.split(':');
    setSelectedHour(h);
    setSelectedMinute(m);
    emitChange(h, m, selectedTz);
    setIsOpen(false);
  };

  const handleHourSelect = (h: string) => {
    setSelectedHour(h);
    emitChange(h, selectedMinute, selectedTz);
  };

  const handleMinuteSelect = (m: string) => {
    setSelectedMinute(m);
    emitChange(selectedHour, m, selectedTz);
  };

  const handleTzSelect = (tz: 'WIB' | 'WITA' | 'WIT') => {
    setSelectedTz(tz);
    emitChange(selectedHour, selectedMinute, tz);
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    onChange('');
    setIsOpen(false);
  };

  // Size styling
  const sizeClasses = {
    sm: 'text-xs py-1.5 px-3 rounded-lg',
    md: 'text-xs sm:text-sm py-2 px-3.5 rounded-xl',
    lg: 'text-sm py-2.5 px-4 rounded-xl',
  };

  const iconSizes = {
    sm: 13,
    md: 15,
    lg: 17,
  };

  const displayTime = value ? value : '';

  return (
    <div ref={containerRef} className={`relative inline-block w-full ${className}`}>
      {/* Hidden input for forms */}
      {name && (
        <input
          type="hidden"
          name={name}
          id={id}
          value={value}
          required={required}
        />
      )}

      {/* Trigger Button */}
      <button
        type="button"
        disabled={disabled}
        onClick={() => !disabled && setIsOpen(!isOpen)}
        className={`w-full flex items-center justify-between text-left transition-all duration-200 cursor-pointer select-none
          ${sizeClasses[size]}
          ${
            disabled
              ? 'bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-600 border-slate-200 dark:border-slate-800 cursor-not-allowed'
              : isOpen
              ? 'bg-white dark:bg-slate-900 border-indigo-500 ring-2 ring-indigo-500/20 text-slate-900 dark:text-white shadow-sm'
              : 'bg-slate-50 dark:bg-slate-950 hover:bg-white dark:hover:bg-slate-900 border-slate-200 dark:border-slate-800 text-slate-800 dark:text-slate-200 hover:border-slate-300 dark:hover:border-slate-700 shadow-2xs'
          }
          border font-medium
        `}
      >
        <div className="flex items-center gap-2 min-w-0 truncate">
          <Clock
            size={iconSizes[size]}
            className={`shrink-0 transition-colors ${
              isOpen
                ? 'text-indigo-600 dark:text-indigo-400'
                : 'text-slate-400 dark:text-slate-500 group-hover:text-slate-600'
            }`}
          />
          {displayTime ? (
            <span className="font-semibold text-slate-900 dark:text-white tracking-wide">
              {displayTime}
            </span>
          ) : (
            <span className="text-slate-400 dark:text-slate-500 font-normal truncate">
              {placeholder}
            </span>
          )}
        </div>

        <div className="flex items-center gap-1 shrink-0 ml-1.5">
          {showClear && value && !disabled && (
            <span
              onClick={handleClear}
              className="p-1 rounded-md hover:bg-slate-200 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors"
              title="Hapus waktu"
            >
              <X size={12} />
            </span>
          )}
          <ChevronDown
            size={iconSizes[size]}
            className={`text-slate-400 transition-transform duration-200 ${
              isOpen ? 'rotate-180 text-indigo-600 dark:text-indigo-400' : ''
            }`}
          />
        </div>
      </button>

      {/* Time Picker Dropdown Popup */}
      {isOpen && (
        <div
          className={`absolute z-50 mt-1.5 ${
            dropdownAlign === 'right' ? 'right-0' : 'left-0'
          } w-72 sm:w-80 max-w-[calc(100vw-24px)] bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-xl p-4 text-slate-800 dark:text-slate-100 animate-in fade-in zoom-in-95 duration-150 backdrop-blur-md`}
        >
          {/* Header Preview & Timezone Selector */}
          <div className="flex items-center justify-between pb-3 mb-3 border-b border-slate-100 dark:border-slate-800">
            <div className="flex items-center gap-2">
              <div className="p-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/70 text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-800">
                <Clock size={15} />
              </div>
              <div>
                <span className="text-[10px] uppercase font-bold text-slate-400 block tracking-wider">Format 24 Jam</span>
                <p className="text-base font-black text-indigo-600 dark:text-indigo-400 font-mono">
                  {selectedHour}:{selectedMinute} {includeTimezone ? selectedTz : ''}
                </p>
              </div>
            </div>

            {includeTimezone && (
              <div className="flex rounded-xl bg-slate-100 dark:bg-slate-950 p-0.5 border border-slate-200 dark:border-slate-800 text-[11px] font-bold">
                {(['WIB', 'WITA', 'WIT'] as const).map(tz => (
                  <button
                    key={tz}
                    type="button"
                    onClick={() => handleTzSelect(tz)}
                    className={`px-2 py-1 rounded-lg transition-all cursor-pointer ${
                      selectedTz === tz
                        ? 'bg-indigo-600 text-white shadow-xs'
                        : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'
                    }`}
                  >
                    {tz}
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Quick Presets for Interview / Work */}
          <div className="mb-3">
            <div className="flex items-center gap-1 text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">
              <Sparkles size={11} className="text-amber-500" />
              <span>Pilihan Cepat (Jam Kerja)</span>
            </div>
            <div className="grid grid-cols-4 gap-1.5 max-h-24 overflow-y-auto pr-1">
              {PRESET_TIMES.map(preset => {
                const isSelected = `${selectedHour}:${selectedMinute}` === preset;
                return (
                  <button
                    key={preset}
                    type="button"
                    onClick={() => handleSelectPreset(preset)}
                    className={`py-1.5 px-2 rounded-xl text-xs font-mono font-bold transition-all cursor-pointer border ${
                      isSelected
                        ? 'bg-indigo-600 text-white border-indigo-600 shadow-xs'
                        : 'bg-slate-50 dark:bg-slate-950/70 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 text-slate-700 dark:text-slate-300 border-slate-200/80 dark:border-slate-800 hover:border-indigo-300 dark:hover:border-indigo-800'
                    }`}
                  >
                    {preset}
                  </button>
                );
              })}
            </div>
          </div>

          {/* 24-Hour & Minute Selector Columns */}
          <div className="grid grid-cols-2 gap-3 pt-2 border-t border-slate-100 dark:border-slate-800">
            {/* Hours Column (00 - 23) */}
            <div>
              <span className="text-[11px] font-bold text-slate-400 uppercase block mb-1.5 text-center">
                Jam (00-23)
              </span>
              <div className="h-36 overflow-y-auto rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800 p-1.5 space-y-1">
                {HOURS_24.map(h => {
                  const isSel = selectedHour === h;
                  return (
                    <button
                      key={h}
                      type="button"
                      onClick={() => handleHourSelect(h)}
                      className={`w-full py-1.5 px-2 rounded-lg text-xs font-mono font-bold transition-all flex items-center justify-between cursor-pointer ${
                        isSel
                          ? 'bg-indigo-600 text-white shadow-xs'
                          : 'hover:bg-slate-200 dark:hover:bg-slate-800/80 text-slate-700 dark:text-slate-300'
                      }`}
                    >
                      <span>{h} : 00</span>
                      {isSel && <Check size={12} className="text-white" />}
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Minutes Column (00 - 55) */}
            <div>
              <span className="text-[11px] font-bold text-slate-400 uppercase block mb-1.5 text-center">
                Menit (00-55)
              </span>
              <div className="h-36 overflow-y-auto rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800 p-1.5 space-y-1">
                {MINUTES_5.map(m => {
                  const isSel = selectedMinute === m;
                  return (
                    <button
                      key={m}
                      type="button"
                      onClick={() => handleMinuteSelect(m)}
                      className={`w-full py-1.5 px-2 rounded-lg text-xs font-mono font-bold transition-all flex items-center justify-between cursor-pointer ${
                        isSel
                          ? 'bg-indigo-600 text-white shadow-xs'
                          : 'hover:bg-slate-200 dark:hover:bg-slate-800/80 text-slate-700 dark:text-slate-300'
                      }`}
                    >
                      <span>: {m}</span>
                      {isSel && <Check size={12} className="text-white" />}
                    </button>
                  );
                })}
              </div>
            </div>
          </div>

          {/* Footer Action */}
          <div className="pt-3 mt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <span className="text-[11px] text-slate-400">
              Terpilih: <strong className="text-slate-800 dark:text-slate-200 font-mono">{selectedHour}:{selectedMinute} {includeTimezone ? selectedTz : ''}</strong>
            </span>
            <button
              type="button"
              onClick={() => {
                emitChange(selectedHour, selectedMinute, selectedTz);
                setIsOpen(false);
              }}
              className="px-4 py-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition-all shadow-xs shadow-indigo-600/20 cursor-pointer"
            >
              Selesai
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default CustomTimePicker;
