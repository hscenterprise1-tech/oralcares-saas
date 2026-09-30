import * as React from 'react';
import { Sparkles, Bot, Minimize2, Maximize2, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ChatMessage, ChatMessageProps } from '@/ovs/ai/chat-message';
import { PromptInput } from '@/ovs/ai/prompt-input';
import { TypingIndicator } from '@/ovs/ai/typing-indicator';

export interface CopilotPanelProps {
  title?: string;
  subtitle?: string;
  messages: Array<{ sender: 'user' | 'ai' | 'system'; content: string; timestamp?: string }>;
  onSendMessage: (prompt: string) => void;
  suggestions?: string[];
  isLoading?: boolean;
  onRefresh?: () => void;
  className?: string;
}

export function CopilotPanel({
  title = 'AI Copilot Assistant',
  subtitle = 'Contextual operational briefing & recommendation engine',
  messages,
  onSendMessage,
  suggestions = ['Analyze next best action', 'Generate proposal summary', 'Audit risk health score'],
  isLoading = false,
  onRefresh,
  className,
}: CopilotPanelProps) {
  const messagesEndRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, isLoading]);

  return (
    <div className={cn('flex flex-col h-[520px] rounded-2xl border border-ovs-border-subtle bg-white shadow-xl overflow-hidden', className)}>
      {/* Panel Header */}
      <div className="flex items-center justify-between px-4 py-3 bg-gradient-to-r from-ovs-primary to-ovs-primary-hover text-white border-b border-ovs-border-subtle">
        <div className="flex items-center gap-2.5">
          <div className="w-7 h-7 rounded-lg bg-white/10 border border-white/20 flex items-center justify-center text-ovs-accent">
            <Sparkles className="w-4 h-4" />
          </div>
          <div>
            <h4 className="text-xs font-bold tracking-tight">{title}</h4>
            <p className="text-[10px] text-white/70">{subtitle}</p>
          </div>
        </div>
        {onRefresh && (
          <button
            type="button"
            onClick={onRefresh}
            className="p-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-white/80 transition-colors"
          >
            <RefreshCw className="w-3.5 h-3.5" />
          </button>
        )}
      </div>

      {/* Messages Stream */}
      <div className="flex-1 p-4 overflow-y-auto bg-ovs-background/50 space-y-2">
        {messages.map((m, idx) => (
          <ChatMessage key={idx} sender={m.sender} content={m.content} timestamp={m.timestamp} />
        ))}
        {isLoading && <TypingIndicator />}
        <div ref={messagesEndRef} />
      </div>

      {/* Footer Prompt Input */}
      <div className="p-3 bg-white border-t border-ovs-border-subtle">
        <PromptInput
          onSend={onSendMessage}
          suggestions={suggestions}
          isLoading={isLoading}
        />
      </div>
    </div>
  );
}
