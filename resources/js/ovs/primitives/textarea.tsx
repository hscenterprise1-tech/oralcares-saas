import * as React from 'react';
import { cn } from '@/lib/utils';

export interface TextareaProps
  extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  error?: boolean;
}

const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(
  ({ className, error, ...props }, ref) => {
    return (
      <textarea
        className={cn(
          'flex min-h-[80px] w-full rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-900 shadow-2xs transition-all duration-150 font-sans',
          'placeholder:text-slate-400 font-normal tracking-tight',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/20 focus-visible:border-teal-500',
          'disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-slate-50',
          error
            ? 'border-red-400 text-red-900 focus-visible:ring-red-500/20 focus-visible:border-red-500'
            : 'border-slate-200 hover:border-slate-300',
          className
        )}
        ref={ref}
        aria-invalid={error ? true : props['aria-invalid']}
        {...props}
      />
    );
  }
);
Textarea.displayName = 'Textarea';

export { Textarea };
