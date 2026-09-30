import React, { useState, useEffect, useRef, useMemo } from 'react';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { cn } from '@/lib/utils';
import { ChevronDown, Search, X, Check, Loader2, AlertCircle } from 'lucide-react';

export interface LocationOption {
  id: number | string;
  name: string;
  code?: string;
  subtext?: string;
  badge?: string;
  metadata?: any;
}

export interface OVSLocationSelectProps {
  label?: string;
  value?: number | string | null;
  onChange?: (value: number | string | null, option?: LocationOption) => void;
  options?: LocationOption[];
  placeholder?: string;
  searchPlaceholder?: string;
  emptyMessage?: string;
  disabled?: boolean;
  loading?: boolean;
  required?: boolean;
  error?: string | boolean;
  helperText?: string;
  className?: string;
  density?: 'compact' | 'default' | 'comfortable';
  icon?: React.ReactNode;
  id?: string;
  onClear?: () => void;
}

export const OVSLocationSelect: React.FC<OVSLocationSelectProps> = ({
  label,
  value,
  onChange,
  options = [],
  placeholder = 'Select option...',
  searchPlaceholder = 'Type to search...',
  emptyMessage = 'No options available',
  disabled = false,
  loading = false,
  required = false,
  error,
  helperText,
  className,
  density = 'default',
  icon,
  id = 'ovs-location-select',
  onClear,
}) => {
  const [open, setOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const searchInputRef = useRef<HTMLInputElement>(null);

  // Auto-focus search input when opened
  useEffect(() => {
    if (open) {
      setTimeout(() => {
        searchInputRef.current?.focus();
      }, 50);
    } else {
      setSearchTerm('');
    }
  }, [open]);

  const selectedOption = useMemo(() => {
    if (value === null || value === undefined || value === '') return null;
    return options.find((opt) => String(opt.id) === String(value) || opt.name === value) || null;
  }, [options, value]);

  const filteredOptions = useMemo(() => {
    if (!searchTerm.trim()) return options;
    const lower = searchTerm.toLowerCase();
    return options.filter(
      (opt) =>
        opt.name.toLowerCase().includes(lower) ||
        (opt.code && opt.code.toLowerCase().includes(lower)) ||
        (opt.subtext && opt.subtext.toLowerCase().includes(lower))
    );
  }, [options, searchTerm]);

  const handleSelect = (opt: LocationOption) => {
    if (onChange) {
      onChange(opt.id, opt);
    }
    setOpen(false);
    setSearchTerm('');
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    e.preventDefault();
    if (onChange) onChange(null);
    if (onClear) onClear();
    setSearchTerm('');
  };

  const heightClasses = {
    compact: 'h-8 text-xs',
    default: 'h-9 text-xs',
    comfortable: 'h-10 text-sm',
  }[density];

  const hasError = Boolean(error);
  const displayError = typeof error === 'string' ? error : null;

  return (
    <div className={cn('w-full space-y-1.5 font-sans', className)}>
      {label && (
        <label htmlFor={id} className="text-[11px] font-semibold text-slate-700 tracking-tight flex items-center gap-1">
          {label}
          {required && <span className="text-red-500 font-bold">*</span>}
        </label>
      )}

      <PopoverPrimitive.Root open={open} onOpenChange={disabled || loading ? undefined : setOpen}>
        <PopoverPrimitive.Trigger asChild>
          <button
            type="button"
            id={id}
            disabled={disabled || loading}
            aria-haspopup="listbox"
            aria-expanded={open}
            aria-invalid={hasError}
            className={cn(
              'flex w-full items-center justify-between rounded-lg border bg-white px-3 py-1.5 text-left text-slate-900 shadow-2xs transition-all duration-150',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/20 focus-visible:border-teal-500',
              'disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-slate-50 cursor-pointer',
              heightClasses,
              hasError
                ? 'border-red-400 text-red-900 focus-visible:ring-red-500/20 focus-visible:border-red-500'
                : open
                ? 'border-teal-500 ring-2 ring-teal-500/20'
                : 'border-slate-200 hover:border-slate-300'
            )}
          >
            <div className="flex items-center gap-2 min-w-0 truncate">
              {icon && <span className="text-slate-400 flex-shrink-0">{icon}</span>}
              {loading ? (
                <span className="text-slate-400 flex items-center gap-1.5 text-xs font-normal">
                  <Loader2 className="w-3.5 h-3.5 animate-spin text-teal-600" />
                  Loading options...
                </span>
              ) : selectedOption ? (
                <span className="truncate font-medium text-slate-900">
                  {selectedOption.name}
                  {selectedOption.code && (
                    <span className="text-slate-400 ml-1.5 text-[11px] font-normal font-mono">
                      ({selectedOption.code})
                    </span>
                  )}
                  {selectedOption.badge && (
                    <span className="ml-1.5 px-1.5 py-0.2 rounded text-[9.5px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                      {selectedOption.badge}
                    </span>
                  )}
                </span>
              ) : (
                <span className="text-slate-400 truncate font-normal">{placeholder}</span>
              )}
            </div>

            <div className="flex items-center gap-1 ml-2 flex-shrink-0">
              {selectedOption && !disabled && !loading && (
                <span
                  role="button"
                  tabIndex={0}
                  onClick={handleClear}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      handleClear(e as any);
                    }
                  }}
                  className="p-0.5 rounded hover:bg-slate-100 text-slate-400 hover:text-slate-600 transition-colors cursor-pointer"
                  title="Clear selection"
                  aria-label="Clear selection"
                >
                  <X className="w-3.5 h-3.5" />
                </span>
              )}
              <ChevronDown
                className={cn(
                  'w-3.5 h-3.5 text-slate-400 transition-transform duration-150',
                  open && 'rotate-180 text-teal-600'
                )}
              />
            </div>
          </button>
        </PopoverPrimitive.Trigger>

        {/* Canonical Radix Portal Overlay: escapes parent overflow-hidden & surfaces completely */}
        <PopoverPrimitive.Portal>
          <PopoverPrimitive.Content
            align="start"
            sideOffset={4}
            collisionPadding={8}
            className={cn(
              'z-50 rounded-xl border border-slate-200/90 bg-white p-0 text-slate-900 shadow-lg outline-none font-sans',
              'w-[var(--radix-popover-trigger-width)] min-w-[220px] max-w-[95vw]',
              'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-1 data-[side=top]:slide-in-from-bottom-1 duration-100'
            )}
          >
            {/* Search Filter Header */}
            <div className="p-2 border-b border-slate-100 bg-slate-50/50">
              <div className="relative flex items-center">
                <Search className="absolute left-2.5 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
                <input
                  ref={searchInputRef}
                  type="text"
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  placeholder={searchPlaceholder}
                  className="w-full h-8 pl-8 pr-3 text-xs bg-white border border-slate-200 rounded-md text-slate-900 placeholder:text-slate-400 focus:outline-none focus:border-teal-500 focus:ring-1 focus:ring-teal-500"
                />
                {searchTerm && (
                  <button
                    type="button"
                    onClick={() => setSearchTerm('')}
                    className="absolute right-2 text-slate-400 hover:text-slate-600"
                  >
                    <X className="w-3 h-3" />
                  </button>
                )}
              </div>
            </div>

            {/* Scrollable Options List */}
            <ul
              role="listbox"
              className="max-h-60 overflow-y-auto p-1 text-xs divide-y divide-slate-50 font-sans focus:outline-none"
            >
              {filteredOptions.length === 0 ? (
                <li className="py-6 text-center text-slate-400 font-normal text-xs">
                  {emptyMessage}
                </li>
              ) : (
                filteredOptions.map((opt) => {
                  const isSelected = selectedOption && String(selectedOption.id) === String(opt.id);
                  return (
                    <li
                      key={opt.id}
                      role="option"
                      aria-selected={isSelected}
                      onClick={() => handleSelect(opt)}
                      className={cn(
                        'flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer transition-colors select-none',
                        isSelected
                          ? 'bg-teal-50 text-teal-900 font-semibold'
                          : 'hover:bg-slate-50 text-slate-700 hover:text-slate-900'
                      )}
                    >
                      <div className="min-w-0 pr-2">
                        <div className="truncate text-xs flex items-center gap-1.5">
                          <span>{opt.name}</span>
                          {opt.code && (
                            <span className="text-slate-400 text-[10.5px] font-mono font-normal">
                              ({opt.code})
                            </span>
                          )}
                          {opt.badge && (
                            <span className="px-1.5 py-0.2 rounded text-[9.5px] font-semibold bg-slate-100 text-slate-600 border border-slate-200 uppercase font-mono">
                              {opt.badge}
                            </span>
                          )}
                        </div>
                        {opt.subtext && (
                          <div className="text-[10.5px] text-slate-400 font-normal truncate mt-0.5">
                            {opt.subtext}
                          </div>
                        )}
                      </div>
                      {isSelected && <Check className="w-3.5 h-3.5 text-teal-600 flex-shrink-0 ml-1" />}
                    </li>
                  );
                })
              )}
            </ul>
          </PopoverPrimitive.Content>
        </PopoverPrimitive.Portal>
      </PopoverPrimitive.Root>

      {hasError && displayError && (
        <p className="text-[11px] font-medium text-red-600 flex items-center gap-1 mt-1">
          <AlertCircle className="w-3 h-3 flex-shrink-0" />
          <span>{displayError}</span>
        </p>
      )}

      {!hasError && helperText && (
        <p className="text-[10.5px] text-slate-500 font-normal">{helperText}</p>
      )}
    </div>
  );
};
