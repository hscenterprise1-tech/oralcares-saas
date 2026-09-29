import React, { useState } from 'react';
import { Send, Sparkles, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';

export interface PromptSuggestion {
  label: string;
  prompt?: string;
}

export interface OVSPromptBarProps {
  onSend: (prompt: string) => void;
  placeholder?: string;
  suggestions?: Array<string | PromptSuggestion>;
  isLoading?: boolean;
  error?: string;
  size?: 'sm' | 'md';
  autoFocus?: boolean;
  disabled?: boolean;
  className?: string;
}

export function OVSPromptBar({
  onSend,
  placeholder = 'Ask AI Copilot for intelligence, analysis, or next action…',
  suggestions = [],
  isLoading = false,
  error,
  size = 'md',
  autoFocus = false,
  disabled = false,
  className,
}: OVSPromptBarProps) {
  const [value, setValue] = useState('');

  const handleSubmit = (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!value.trim() || isLoading || disabled) return;
    onSend(value.trim());
    setValue('');
  };

  const handleSuggestionClick = (sug: string | PromptSuggestion) => {
    if (isLoading || disabled) return;
    const promptText = typeof sug === 'string' ? sug : (sug.prompt || sug.label);
    onSend(promptText);
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Escape') {
      setValue('');
    }
  };

  const sizeClasses = {
    sm: {
      bar: 'h-8 text-xs',
      input: 'px-2.5 text-xs',
      btn: 'h-6 w-6',
      icon: 'w-3 h-3',
      chip: 'px-2 py-0.5 text-[10.5px]',
    },
    md: {
      bar: 'h-9 text-xs',
      input: 'px-3 text-xs',
      btn: 'h-7 w-7',
      icon: 'w-3.5 h-3.5',
      chip: 'px-2.5 py-1 text-[11px]',
    },
  }[size];

  return (
    <div className={cn('space-y-2 w-full font-sans text-slate-900', className)}>
      {/* Suggestion Chips (Max 3-4) */}
      {suggestions.length > 0 && (
        <div className="flex items-center gap-1.5 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden pb-0.5">
          <div className="flex items-center gap-1 text-[10.5px] font-mono text-slate-400 shrink-0 select-none">
            <Sparkles className="w-3 h-3 text-teal-700" />
            <span>Suggested:</span>
          </div>
          {suggestions.slice(0, 4).map((sug, i) => {
            const label = typeof sug === 'string' ? sug : sug.label;
            return (
              <button
                key={i}
                type="button"
                disabled={isLoading || disabled}
                onClick={() => handleSuggestionClick(sug)}
                className={cn(
                  'rounded-full bg-slate-100/90 border border-slate-200/80 font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-200/80 transition-all shrink-0 whitespace-nowrap cursor-pointer select-none',
                  sizeClasses.chip,
                  'disabled:opacity-40 disabled:pointer-events-none'
                )}
              >
                {label}
              </button>
            );
          })}
        </div>
      )}

      {/* Input Container */}
      <form onSubmit={handleSubmit} className="relative flex items-center w-full">
        <input
          type="text"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={handleKeyDown}
          placeholder={placeholder}
          disabled={isLoading || disabled}
          autoFocus={autoFocus}
          className={cn(
            'w-full bg-white border border-slate-200/90 rounded-lg text-slate-900 placeholder:text-slate-400 pr-10 focus:outline-none focus:border-teal-600 focus:ring-1 focus:ring-teal-500/30 transition-all',
            sizeClasses.bar,
            sizeClasses.input,
            error && 'border-rose-300 focus:border-rose-500 focus:ring-rose-500/20'
          )}
        />

        <div className="absolute right-1 top-1/2 -translate-y-1/2 flex items-center gap-1">
          <button
            type="submit"
            disabled={!value.trim() || isLoading || disabled}
            aria-label="Send Prompt"
            className={cn(
              'rounded-md bg-slate-900 text-white hover:bg-slate-800 disabled:opacity-30 disabled:pointer-events-none flex items-center justify-center transition-colors cursor-pointer',
              sizeClasses.btn
            )}
          >
            <Send className={sizeClasses.icon} />
          </button>
        </div>
      </form>

      {/* Inline Error Message */}
      {error && (
        <div className="flex items-center gap-1.5 text-[11px] text-rose-600 font-medium pt-0.5">
          <AlertCircle className="w-3.5 h-3.5 shrink-0" />
          <span>{error}</span>
        </div>
      )}
    </div>
  );
}
