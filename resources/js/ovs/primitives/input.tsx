import React from 'react';
import { cn } from '@/lib/utils';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  error?: boolean;
  iconPrefix?: React.ReactNode;
  iconSuffix?: React.ReactNode;
}

export const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ className, type, error, iconPrefix, iconSuffix, ...props }, ref) => {
    return (
      <div className="relative flex items-center w-full">
        {iconPrefix && (
          <div className="absolute left-3 flex items-center pointer-events-none text-slate-400">
            {iconPrefix}
          </div>
        )}
        <input
          type={type}
          className={cn(
            "flex h-9 w-full rounded-lg border bg-white px-3 py-1.5 text-xs text-slate-900 shadow-2xs transition-all duration-150",
            "placeholder:text-slate-400 font-normal tracking-tight",
            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/20 focus-visible:border-teal-500",
            "disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-slate-50",
            iconPrefix && "pl-9",
            iconSuffix && "pr-9",
            error
              ? "border-red-400 text-red-900 focus-visible:ring-red-500/20 focus-visible:border-red-500"
              : "border-slate-200 hover:border-slate-300",
            className
          )}
          ref={ref}
          aria-invalid={error ? true : props['aria-invalid']}
          {...props}
        />
        {iconSuffix && (
          <div className="absolute right-3 flex items-center pointer-events-none text-slate-400">
            {iconSuffix}
          </div>
        )}
      </div>
    );
  }
);
Input.displayName = "Input";
