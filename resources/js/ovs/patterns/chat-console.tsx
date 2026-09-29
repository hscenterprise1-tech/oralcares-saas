import React, { useState } from 'react';
import {
  MessageSquare,
  Mail,
  Phone,
  Send,
  Search,
  MessageCircle,
  Clock,
  MoreVertical,
  CheckCheck,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Avatar } from '@/ovs/primitives/avatar';
import { Input } from '@/ovs/primitives/input';
import { Button } from '@/ovs/primitives/button';
import { Badge } from '@/ovs/primitives/badge';

export type CommunicationChannel = 'whatsapp' | 'email' | 'sms' | 'call';

export interface ChatConversationItem {
  id: string;
  name: string;
  avatarUrl?: string;
  avatarFallback?: string;
  lastMessage: string;
  lastMessageTime: string;
  unreadCount?: number;
  channel: CommunicationChannel;
  subtitle?: string;
}

export interface ChatMessageItem {
  id: string;
  sender: 'user' | 'contact';
  text: string;
  timestamp: string;
  channel?: CommunicationChannel;
}

export interface ChatConsoleProps {
  conversations: ChatConversationItem[];
  activeConversationId?: string;
  messages: ChatMessageItem[];
  onSelectConversation: (id: string) => void;
  onSendMessage: (text: string) => void;
  className?: string;
}

const CHANNEL_ICON_MAP: Record<CommunicationChannel, React.ElementType> = {
  whatsapp: MessageCircle,
  email: Mail,
  sms: MessageSquare,
  call: Phone,
};

export function ChatConsole({
  conversations,
  activeConversationId,
  messages,
  onSelectConversation,
  onSendMessage,
  className,
}: ChatConsoleProps) {
  const [inputText, setInputText] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState<string>('');

  const activeConversation = conversations.find((c) => c.id === activeConversationId) || conversations[0];

  const filteredConversations = conversations.filter((c) =>
    c.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    c.lastMessage.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const handleSend = (e: React.FormEvent) => {
    e.preventDefault();
    if (!inputText.trim()) return;
    onSendMessage(inputText.trim());
    setInputText('');
  };

  return (
    <div className={cn('w-full bg-white border border-ovs-border-subtle rounded-2xl shadow-2xs overflow-hidden flex flex-col md:flex-row h-[560px]', className)}>
      {/* LEFT PANE: CONVERSATION LIST (w-80) */}
      <div className="w-full md:w-80 border-r border-ovs-border-subtle flex flex-col bg-ovs-background/50">
        {/* Search Header */}
        <div className="p-3 border-b border-ovs-border-subtle space-y-2">
          <div className="flex items-center justify-between">
            <h3 className="text-xs font-bold text-ovs-primary font-mono uppercase tracking-wider">
              Omnichannel Inbox
            </h3>
            <span className="text-[10px] font-bold font-mono px-2 py-0.5 rounded-full bg-white border border-ovs-border-subtle text-ovs-secondary">
              {conversations.length} Threads
            </span>
          </div>
          <Input
            placeholder="Search conversations..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="text-xs bg-white h-8"
          />
        </div>

        {/* Conversation Items List */}
        <div className="flex-1 overflow-y-auto divide-y divide-ovs-border-subtle/50">
          {filteredConversations.map((conv) => {
            const isActive = conv.id === activeConversation?.id;
            const ChannelIcon = CHANNEL_ICON_MAP[conv.channel] || MessageSquare;

            return (
              <button
                key={conv.id}
                type="button"
                onClick={() => onSelectConversation(conv.id)}
                className={cn(
                  'w-full p-3 flex items-start gap-3 text-left transition-colors hover:bg-ovs-background',
                  isActive && 'bg-white border-l-4 border-ovs-accent shadow-2xs'
                )}
              >
                <div className="relative">
                  <Avatar
                    size="md"
                    src={conv.avatarUrl}
                    fallback={conv.avatarFallback || conv.name.slice(0, 2).toUpperCase()}
                  />
                  <span className="absolute -bottom-1 -right-1 p-0.5 rounded-full bg-white border border-ovs-border-subtle text-ovs-secondary">
                    <ChannelIcon className="w-3 h-3 text-ovs-accent" />
                  </span>
                </div>

                <div className="flex-1 min-w-0">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold text-ovs-primary truncate">{conv.name}</span>
                    <span className="text-[10px] font-mono text-ovs-tertiary flex-shrink-0">{conv.lastMessageTime}</span>
                  </div>
                  <p className="text-[11px] text-ovs-secondary truncate mt-0.5">{conv.lastMessage}</p>
                </div>

                {conv.unreadCount && conv.unreadCount > 0 ? (
                  <Badge variant="warning" className="text-[9px] py-0.5 px-1.5 rounded-full font-bold self-center">
                    {conv.unreadCount}
                  </Badge>
                ) : null}
              </button>
            );
          })}
        </div>
      </div>

      {/* RIGHT PANE: ACTIVE MESSAGE THREAD */}
      {activeConversation ? (
        <div className="flex-1 flex flex-col bg-white">
          {/* Thread Top Bar */}
          <div className="p-3 bg-ovs-background border-b border-ovs-border-subtle flex items-center justify-between">
            <div className="flex items-center gap-3">
              <Avatar
                size="sm"
                src={activeConversation.avatarUrl}
                fallback={activeConversation.avatarFallback || activeConversation.name.slice(0, 2).toUpperCase()}
              />
              <div>
                <h4 className="text-xs font-bold text-ovs-primary leading-none">{activeConversation.name}</h4>
                <span className="text-[10px] text-ovs-secondary font-mono capitalize">
                  {activeConversation.channel} Thread · {activeConversation.subtitle || 'Active Lead'}
                </span>
              </div>
            </div>
            <Button size="icon" variant="ghost" className="w-7 h-7 text-ovs-secondary">
              <MoreVertical className="w-4 h-4" />
            </Button>
          </div>

          {/* Messages Scroll Area */}
          <div className="flex-1 p-4 overflow-y-auto space-y-3 bg-ovs-background/20">
            {messages.map((msg) => {
              const isUser = msg.sender === 'user';
              return (
                <div
                  key={msg.id}
                  className={cn('flex flex-col', isUser ? 'items-end' : 'items-start')}
                >
                  <div
                    className={cn(
                      'max-w-[75%] p-3 rounded-2xl text-xs space-y-1 shadow-2xs',
                      isUser
                        ? 'bg-ovs-primary text-white rounded-br-none font-medium'
                        : 'bg-white text-ovs-primary border border-ovs-border-subtle rounded-bl-none'
                    )}
                  >
                    <p className="leading-relaxed whitespace-pre-wrap">{msg.text}</p>
                    <div className={cn('flex items-center gap-1 justify-end text-[9px]', isUser ? 'text-white/70' : 'text-ovs-tertiary')}>
                      <span>{msg.timestamp}</span>
                      {isUser && <CheckCheck className="w-3 h-3 text-ovs-accent" />}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>

          {/* Bottom Send Input Bar */}
          <form onSubmit={handleSend} className="p-3 border-t border-ovs-border-subtle bg-white flex items-center gap-2">
            <Input
              placeholder={`Type message via ${activeConversation.channel}...`}
              value={inputText}
              onChange={(e) => setInputText(e.target.value)}
              className="flex-1 text-xs"
            />
            <Button type="submit" size="sm" variant="primary" className="gap-1.5 text-xs">
              <Send className="w-3.5 h-3.5" /> Send
            </Button>
          </form>
        </div>
      ) : (
        <div className="flex-1 flex items-center justify-center text-xs text-ovs-secondary p-8">
          Select a conversation from the left inbox thread.
        </div>
      )}
    </div>
  );
}
