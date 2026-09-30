import * as React from 'react';
import { Sparkles, User } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface ChatMessageProps {
  sender: 'user' | 'ai' | 'system';
  content: string;
  timestamp?: string;
  actions?: React.ReactNode;
  className?: string;
}

export function ChatMessage({
  sender,
  content,
  timestamp,
  actions,
  className,
}: ChatMessageProps) {
  const isUser = sender === 'user';
  const isSystem = sender === 'system';

  if (isSystem) {
    return (
      <div className={cn('flex justify-center my-2', className)}>
        <span className="text-[11px] font-medium text-ovs-secondary bg-ovs-background px-3 py-1 rounded-full border border-ovs-border-subtle">
          {content}
        </span>
      </div>
    );
  }

  return (
    <div className={cn('flex items-start gap-3 my-3', isUser && 'flex-row-reverse', className)}>
      {/* Avatar */}
      <div
        className={cn(
          'w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold shrink-0 shadow-2xs',
          isUser
            ? 'bg-ovs-primary text-white'
            : 'bg-gradient-to-br from-ovs-primary to-ovs-accent text-white'
        )}
      >
        {isUser ? <User className="w-4 h-4" /> : <Sparkles className="w-4 h-4" />}
      </div>

      {/* Bubble */}
      <div className={cn('max-w-[80%] space-y-1.5', isUser && 'text-right')}>
        <div
          className={cn(
            'p-3.5 rounded-2xl text-xs leading-relaxed text-left shadow-2xs',
            isUser
              ? 'bg-ovs-primary text-white rounded-tr-xs'
              : 'bg-white border border-ovs-border-subtle text-ovs-primary rounded-tl-xs'
          )}
        >
          <p className="whitespace-pre-wrap">{content}</p>
        </div>

        {actions && <div className="pt-1 flex flex-wrap gap-1.5">{actions}</div>}

        {timestamp && (
          <p className="text-[10px] text-ovs-secondary/60 px-1">{timestamp}</p>
        )}
      </div>
    </div>
  );
}
