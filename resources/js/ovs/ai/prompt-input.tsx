import * as React from 'react';
import { Send, Sparkles } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Input } from '@/ovs/primitives/input';
import { Button } from '@/ovs/primitives/button';

export interface PromptInputProps {
  onSend: (prompt: string) => void;
  placeholder?: string;
  suggestions?: string[];
  isLoading?: boolean;
  className?: string;
}

export function PromptInput({
  onSend,
  placeholder = 'Ask AI Copilot for recommendations, analysis, or next best action…',
  suggestions = [],
  isLoading = false,
  className,
}: PromptInputProps) {
  const [value, setValue] = React.useState('');

  const handleSubmit = (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (!value.trim() || isLoading) return;
    onSend(value);
    setValue('');
  };

  const handleSuggestionClick = (sug: string) => {
    onSend(sug);
  };

  return (
    <div className={cn('space-y-2.5 w-full', className)}>
      {suggestions.length > 0 && (
        <div className="flex items-center gap-1.5 overflow-x-auto pb-1 no-scrollbar">
          <Sparkles className="w-3.5 h-3.5 text-ovs-accent shrink-0 ml-1" />
          {suggestions.map((sug, i) => (
            <button
              key={i}
              type="button"
              onClick={() => handleSuggestionClick(sug)}
              className="px-2.5 py-1 rounded-full bg-white border border-ovs-border-subtle text-[11px] font-medium text-ovs-secondary hover:text-ovs-primary hover:bg-ovs-background transition-all shrink-0 whitespace-nowrap shadow-2xs"
            >
              {sug}
            </button>
          ))}
        </div>
      )}

      <form onSubmit={handleSubmit} className="relative flex items-center">
        <Input
          type="text"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          placeholder={placeholder}
          disabled={isLoading}
          className="pr-12"
        />
        <div className="absolute right-1 top-1/2 -translate-y-1/2">
          <Button
            type="submit"
            size="icon"
            variant="primary"
            disabled={!value.trim() || isLoading}
            aria-label="Send prompt"
            className="h-8 w-8 min-h-0 min-w-0"
          >
            <Send className="w-3.5 h-3.5" />
          </Button>
        </div>
      </form>
    </div>
  );
}
