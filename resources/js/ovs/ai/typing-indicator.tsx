import * as React from 'react';
import { Sparkles } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface TypingIndicatorProps {
  label?: string;
  className?: string;
}

export function TypingIndicator({
  label = 'AI Copilot is thinking…',
  className,
}: TypingIndicatorProps) {
  return (
    <div className={cn('flex items-center gap-2.5 p-3 rounded-2xl bg-white border border-ovs-border-subtle max-w-xs shadow-2xs', className)}>
      <div className="w-6 h-6 rounded-full bg-gradient-to-br from-ovs-primary to-ovs-accent flex items-center justify-center text-white shrink-0">
        <Sparkles className="w-3.5 h-3.5 animate-spin" />
      </div>
      <span className="text-xs font-semibold text-ovs-primary">{label}</span>
      <div className="flex gap-1 ml-auto">
        <span className="w-1.5 h-1.5 rounded-full bg-ovs-accent animate-bounce [animation-delay:-0.3s]" />
        <span className="w-1.5 h-1.5 rounded-full bg-ovs-accent animate-bounce [animation-delay:-0.15s]" />
        <span className="w-1.5 h-1.5 rounded-full bg-ovs-accent animate-bounce" />
      </div>
    </div>
  );
}
