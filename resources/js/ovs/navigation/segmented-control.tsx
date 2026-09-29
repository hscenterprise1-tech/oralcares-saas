import React, { useRef } from 'react';
import { cn } from '@/lib/utils';

export interface SegmentedControlOption<T extends string = string> {
  value: T;
  label: string;
  icon?: React.ElementType;
  count?: number | string;
  disabled?: boolean;
}

export interface OVSSegmentedControlProps<T extends string = string> {
  value: T;
  onChange: (value: T) => void;
  options: SegmentedControlOption<T>[];
  size?: 'sm' | 'md' | 'lg';
  fullWidth?: boolean;
  disabled?: boolean;
  className?: string;
  'aria-label'?: string;
}

export function OVSSegmentedControl<T extends string = string>({
  value,
  onChange,
  options,
  size = 'md',
  fullWidth = false,
  disabled = false,
  className,
  'aria-label': ariaLabel = 'Display mode',
}: OVSSegmentedControlProps<T>) {
  const containerRef = useRef<HTMLDivElement>(null);

  const sizeClasses = {
    sm: {
      container: 'h-7 p-0.5 gap-0.5 rounded-lg',
      item: 'px-2 py-0.5 text-[11px]',
      icon: 'w-3 h-3',
    },
    md: {
      container: 'h-8 p-0.5 gap-0.5 rounded-lg',
      item: 'px-2.5 py-1 text-xs',
      icon: 'w-3.5 h-3.5',
    },
    lg: {
      container: 'h-9 p-1 gap-1 rounded-xl',
      item: 'px-3 py-1.5 text-[12.5px]',
      icon: 'w-4 h-4',
    },
  }[size];

  const handleKeyDown = (e: React.KeyboardEvent, currentIndex: number) => {
    let nextIndex = -1;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
      e.preventDefault();
      nextIndex = (currentIndex + 1) % options.length;
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
      e.preventDefault();
      nextIndex = (currentIndex - 1 + options.length) % options.length;
    } else if (e.key === 'Home') {
      e.preventDefault();
      nextIndex = 0;
    } else if (e.key === 'End') {
      e.preventDefault();
      nextIndex = options.length - 1;
    }

    if (nextIndex >= 0 && !options[nextIndex].disabled) {
      onChange(options[nextIndex].value);
      const buttons = containerRef.current?.querySelectorAll<HTMLButtonElement>('button');
      buttons?.[nextIndex]?.focus();
    }
  };

  return (
    <div
      ref={containerRef}
      role="radiogroup"
      aria-label={ariaLabel}
      className={cn(
        'inline-flex items-center bg-slate-100/90 border border-slate-200/80 select-none font-sans text-slate-900',
        sizeClasses.container,
        fullWidth && 'w-full flex',
        disabled && 'opacity-50 pointer-events-none',
        className
      )}
    >
      {options.map((opt, idx) => {
        const isSelected = opt.value === value;
        const Icon = opt.icon;

        return (
          <button
            key={opt.value}
            type="button"
            role="radio"
            aria-checked={isSelected}
            disabled={disabled || opt.disabled}
            tabIndex={isSelected ? 0 : -1}
            onClick={() => !opt.disabled && onChange(opt.value)}
            onKeyDown={(e) => handleKeyDown(e, idx)}
            className={cn(
              'inline-flex items-center justify-center whitespace-nowrap rounded-md font-medium tracking-tight transition-all duration-150 cursor-pointer select-none gap-1.5',
              sizeClasses.item,
              fullWidth && 'flex-1',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/30 focus-visible:ring-offset-1',
              'disabled:pointer-events-none disabled:opacity-40',
              isSelected
                ? 'bg-white text-slate-900 font-semibold shadow-2xs border border-slate-200/80'
                : 'border border-transparent text-slate-500 hover:text-slate-800'
            )}
          >
            {Icon && (
              <Icon
                className={cn(
                  sizeClasses.icon,
                  'shrink-0',
                  isSelected ? 'text-slate-900' : 'text-slate-400'
                )}
              />
            )}
            <span>{opt.label}</span>
            {opt.count !== undefined && (
              <span
                className={cn(
                  'text-[10px] font-mono font-bold px-1.5 py-0.2 rounded-full tabular-nums shrink-0',
                  isSelected
                    ? 'bg-slate-100 text-slate-900'
                    : 'bg-slate-200/60 text-slate-500'
                )}
              >
                {opt.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
