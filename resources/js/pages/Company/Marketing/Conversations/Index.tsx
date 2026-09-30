import React, { useEffect, useRef, useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmSection,
  CrmButton,
  CrmInput,
  CrmSelect,
  CrmFormField,
  CrmAvatar,
  CrmBadge,
  CrmModal,
  CrmFilterBar,
  CrmPagination,
} from '@/pages/Company/design';
import {
  ArrowUp,
  ExternalLink,
  Link2,
  Megaphone,
  MessageSquare,
  Send,
  ShieldAlert,
  Smartphone,
  UserCheck,
  UserPlus,
} from 'lucide-react';

interface MarketingTag {
  id: number;
  name: string;
  slug: string;
}

interface MarketingContact {
  id: number;
  name: string;
  phone: string;
  organization: string | null;
  city: string | null;
  marketing_status: 'active' | 'opted_out' | 'suppressed';
  tags: MarketingTag[];
}

interface CrmLead {
  id: number;
  company_name: string;
  contact_name: string;
  phone: string;
  status: string;
  priority: string;
  source: string;
}

interface AssignedUser {
  id: number;
  name: string;
}

interface WhatsAppAccountRef {
  id: number;
  verified_name: string | null;
  display_phone_number: string;
  /** What the line is for — marketing, sales, onboarding, support, clinic pool. */
  purpose: string | null;
  /** Label, else verified name, else the number. Never empty. */
  display_name: string;
}

/*
 * The five line purposes, and the hue each one wears.
 *
 * Mirrors the WhatsAppNumberPurpose enum on the server. Kept as a small map
 * rather than fetched, because these are a fixed vocabulary the product is
 * built around — but the server remains the authority on which one a line has.
 */
const PURPOSE_LABEL: Record<string, string> = {
  marketing: 'Marketing',
  sales: 'Sales',
  onboarding: 'Onboarding',
  support: 'Support',
  clinic_pool: 'Clinic pool',
};

const PURPOSE_TONE: Record<string, 'violet' | 'amber' | 'teal' | 'coral' | 'slate'> = {
  marketing: 'violet',
  sales: 'amber',
  onboarding: 'teal',
  support: 'coral',
  clinic_pool: 'slate',
};

interface CampaignContext {
  id: number;
  name: string;
}

interface ConversationItem {
  id: number;
  phone: string;
  normalized_phone: string;
  unread_count: number;
  status: string;
  last_message_at: string | null;
  assigned_user_id: number | null;
  contact?: MarketingContact | null;
  /*
   * Snake_case, because that is what Eloquent sends.
   *
   * These were declared in camelCase and read in camelCase, so they typechecked
   * and were always undefined — the sender number, the assignee's name and the
   * "already a lead" marker were dead on the page for as long as the file has
   * existed. The server is the contract; the interface follows it.
   */
  crm_lead?: CrmLead | null;
  assigned_user?: AssignedUser | null;
  whatsapp_account?: WhatsAppAccountRef | null;
  campaign_context?: CampaignContext | null;
}

interface MessageItem {
  id: number;
  provider_message_id: string;
  direction: 'inbound' | 'outbound';
  message_type: string;
  body: string;
  status: 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'received';
  created_at: string;
}

interface PaginatedData<T> {
  data: T[];
  current_page: number;
  total: number;
  last_page: number;
  from: number | null;
  to: number | null;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface PaginatedMessages {
  data: MessageItem[];
  current_page: number;
  last_page: number;
}

interface ConversationStats {
  total: number;
  unread: number;
  needs_reply: number;
  mine: number;
  unclaimed: number;
}

type ConversationFilter = 'all' | 'unread' | 'needs_reply';

interface IndexProps {
  conversations: PaginatedData<ConversationItem>;
  activeConversation: ConversationItem | null;
  messages: PaginatedMessages | null;
  activeLead: CrmLead | null;
  currentUserId: number | null;
  isMasterAdmin: boolean;
  assignableUsers: AssignedUser[];
  stats: ConversationStats;
  /** Every WhatsApp line, so a reader can pick the one they want. */
  lineOptions: {
    value: string;
    label: string;
    purpose: string | null;
    purpose_label: string | null;
  }[];
  filters: {
    search: string;
    filter: ConversationFilter;
    purpose: string | null;
    account: string | null;
    conversation_id: number | null;
  };
}

/** Every contact state that means "do not send them a marketing message" — the one place this UI reads that rule from. */
const COMPLIANCE_BLOCKED_STATUSES: MarketingContact['marketing_status'][] = ['opted_out', 'suppressed'];

export default function ConversationsWorkspace({
  conversations,
  activeConversation,
  messages,
  activeLead,
  currentUserId,
  isMasterAdmin,
  assignableUsers,
  stats,
  lineOptions = [],
  filters,
}: IndexProps) {
  const [search, setSearch] = useState(filters.search || '');
  const activeFilter = filters.filter || 'all';
  const activeLine = filters.account ? String(filters.account) : null;
  const [showCreateLeadModal, setShowCreateLeadModal] = useState(false);
  const [showLinkLeadModal, setShowLinkLeadModal] = useState(false);

  // The visible message list — seeded from the page's initial (most recent)
  // page, then grown backward as "Load earlier" pulls older pages in. Reset
  // whenever the active conversation itself changes.
  const [visibleMessages, setVisibleMessages] = useState<MessageItem[]>(messages?.data ?? []);
  const [earlierPage, setEarlierPage] = useState<{ hasMore: boolean; nextPage: number }>({
    hasMore: (messages?.current_page ?? 1) < (messages?.last_page ?? 1),
    nextPage: (messages?.current_page ?? 1) + 1,
  });
  const [loadingEarlier, setLoadingEarlier] = useState(false);
  const lastConversationId = useRef<number | null>(activeConversation?.id ?? null);

  useEffect(() => {
    if (activeConversation?.id !== lastConversationId.current) {
      lastConversationId.current = activeConversation?.id ?? null;
      setVisibleMessages(messages?.data ?? []);
      setEarlierPage({
        hasMore: (messages?.current_page ?? 1) < (messages?.last_page ?? 1),
        nextPage: (messages?.current_page ?? 1) + 1,
      });
    }
  }, [activeConversation?.id, messages]);

  const loadEarlierMessages = async () => {
    if (!activeConversation || loadingEarlier || !earlierPage.hasMore) return;
    setLoadingEarlier(true);
    try {
      const res = await fetch(
        `/company/marketing/conversations/${activeConversation.id}/messages?message_page=${earlierPage.nextPage}`
      );
      if (res.ok) {
        const page: PaginatedMessages = await res.json();
        setVisibleMessages((prev) => [...page.data, ...prev]);
        setEarlierPage({ hasMore: page.current_page < page.last_page, nextPage: page.current_page + 1 });
      }
    } finally {
      setLoadingEarlier(false);
    }
  };

  const complianceBlocked = activeConversation?.contact
    ? COMPLIANCE_BLOCKED_STATUSES.includes(activeConversation.contact.marketing_status)
    : false;

  // Reply Form
  const replyForm = useForm({
    message: '',
  });

  // Create Lead Form
  const createLeadForm = useForm({
    company_name: '',
    contact_name: '',
    phone: '',
    status: 'new',
    priority: 'medium',
    source: 'whatsapp',
  });

  // Link Lead Form
  const linkLeadForm = useForm({
    lead_id: '',
  });

  const [searchedLeads, setSearchedLeads] = useState<CrmLead[]>([]);
  const [isSearchingLeads, setIsSearchingLeads] = useState(false);

  useEffect(() => {
    if (activeConversation) {
      createLeadForm.setData({
        company_name: activeConversation.contact?.organization || activeConversation.contact?.name || '',
        contact_name: activeConversation.contact?.name || '',
        phone: activeConversation.phone,
        status: 'new',
        priority: 'medium',
        source: 'whatsapp',
      });
    }
  }, [activeConversation]);

  // Typing waits for a pause before asking the server anything — same
  // debounced-search idiom as Contacts, so the field stays instant while
  // the request is held back.
  const searchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  const handleSearchChange = (value: string) => {
    setSearch(value);
    clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => {
      // Search kept dropping the chosen line, same as the segment did.
      go({ search: value });
    }, 300);
  };

  useEffect(() => () => clearTimeout(searchTimer.current), []);

  /*
   * Every navigation carries the whole filter state.
   *
   * Changing the segment used to drop the purpose, and choosing a line used to
   * drop the segment — so the two controls quietly undid each other and a
   * reader could not hold "unread, on the support line" at once.
   */
  const go = (changes: { search?: string; filter?: string; account?: string | null }) => {
    const next = {
      search: changes.search ?? search,
      filter: changes.filter ?? activeFilter,
      account: changes.account === undefined ? activeLine : changes.account,
    };

    router.get(
      '/company/marketing/conversations',
      Object.fromEntries(Object.entries(next).filter(([, value]) => value)),
      { preserveState: true }
    );
  };

  const handleFilterChange = (key: string) => go({ filter: key });

  const handleLineChange = (value: string) => go({ account: value === 'all' ? null : value });

  const handleSelectConversation = (id: number) => {
    router.get(
      '/company/marketing/conversations',
      { search, filter: activeFilter, conversation_id: id },
      { preserveState: true }
    );
  };

  const handleSendReply = (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeConversation || !replyForm.data.message.trim()) return;

    replyForm.post(`/company/marketing/conversations/${activeConversation.id}/reply`, {
      preserveScroll: true,
      onSuccess: () => replyForm.reset('message'),
    });
  };

  const handleCreateLead = (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeConversation) return;

    createLeadForm.post(`/company/marketing/conversations/${activeConversation.id}/create-lead`, {
      onSuccess: () => setShowCreateLeadModal(false),
    });
  };

  const handleAssign = (conversationId: number, assignedUserId: number | null) => {
    router.post(
      `/company/marketing/conversations/${conversationId}/assign`,
      { assigned_user_id: assignedUserId },
      { preserveScroll: true }
    );
  };

  const handleLinkLead = (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeConversation) return;

    linkLeadForm.post(`/company/marketing/conversations/${activeConversation.id}/link-lead`, {
      onSuccess: () => setShowLinkLeadModal(false),
    });
  };

  const searchLeadsQuery = async (term: string) => {
    if (!term.trim()) return;
    setIsSearchingLeads(true);
    try {
      const res = await fetch(`/company/marketing/conversations/search-leads?q=${encodeURIComponent(term)}`);
      if (res.ok) {
        const json = await res.json();
        setSearchedLeads(json);
      }
    } finally {
      setIsSearchingLeads(false);
    }
  };

  const formatMessageTime = (isoString: string) => {
    try {
      const d = new Date(isoString);
      return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch {
      return isoString;
    }
  };

  /*
   * The inbox stays two panes — threads on the left, the conversation on the
   * right — because that is what an inbox is, and any other shape makes you
   * lose your place every time you answer someone.
   *
   * What changes is everything else: the reply bar knows about Meta's 24-hour
   * window and says so, the assignment state is legible without opening a
   * dropdown, and the two lead actions are the product's own dialogs.
   */
  const initials = (value: string) => value.slice(0, 2).toUpperCase();

  return (
    <MarketingShell title="Conversations">
      <CrmPageHead
        eyebrow="Marketing"
        title="Conversations"
        stats={
          <>
            <CrmPageStat
              icon={MessageSquare}
              value={stats.unread}
              caption="Messages waiting on a reply"
            />
            <CrmPageStat
              icon={UserCheck}
              value={stats.mine}
              chip={stats.unclaimed > 0 ? `${stats.unclaimed} unclaimed` : undefined}
              chipHue="#E0B21E"
              caption="Threads assigned to you"
            />
          </>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        {/* ═══ Threads ══════════════════════════════════════════════════ */}
        <CrmSection
          step={0}
          flush
          title="Threads"
          description={`${conversations.total} conversation${conversations.total === 1 ? '' : 's'}`}
          className="lg:col-span-4"
        >
          <CrmFilterBar
            className="mb-3"
            searchValue={search}
            onSearchChange={handleSearchChange}
            searchPlaceholder="Search name or number"
            segments={[
              { key: 'all', label: 'All', count: stats.total },
              { key: 'unread', label: 'Unread', count: stats.unread },
              { key: 'needs_reply', label: 'Needs reply', count: stats.needs_reply },
            ]}
            segmentValue={activeFilter}
            onSegmentChange={handleFilterChange}
            inlineSegments
          />

          {/*
            * Only shown once there is more than one line to choose between.
            * A filter with a single option is a control that cannot change
            * anything, and it takes up the same room as one that can.
            */}
          {/*
            * Shown whenever there is more than one line to choose between.
            *
            * An earlier version only appeared when two *purposes* existed, so
            * with two marketing numbers the control vanished — which is exactly
            * when someone would want it. Two numbers are two numbers, whatever
            * they are for.
            */}
          {lineOptions.length > 1 && (
            <div className="mb-3 flex items-center gap-2">
              <CrmSelect
                aria-label="Show conversations on one line"
                value={activeLine ?? 'all'}
                onValueChange={handleLineChange}
                className="w-[220px] border border-ovs-crm-border bg-white"
                placeholder="All lines"
                options={[
                  { value: 'all', label: 'All lines' },
                  ...lineOptions.map((option) => ({
                    value: option.value,
                    label: option.purpose_label
                      ? `${option.label} · ${option.purpose_label}`
                      : option.label,
                  })),
                ]}
              />
              {activeLine && (
                <button
                  type="button"
                  onClick={() => handleLineChange('all')}
                  className="text-[12px] text-ovs-crm-text-secondary underline underline-offset-2"
                >
                  Clear
                </button>
              )}
            </div>
          )}

          {conversations.data.length === 0 ? (
            <p className="py-16 text-center text-[13px] text-ovs-crm-text-muted">
              {search ? 'No thread matches that search.' : 'No conversations yet.'}
            </p>
          ) : (
            <div className="-mx-2.5 max-h-[320px] overflow-y-auto lg:max-h-[calc(100vh-320px)]">
              {conversations.data.map((conversation) => {
                const active = activeConversation?.id === conversation.id;
                const name = conversation.contact?.name || conversation.phone;

                return (
                  <button
                    key={conversation.id}
                    type="button"
                    onClick={() => handleSelectConversation(conversation.id)}
                    aria-current={active ? 'true' : undefined}
                    className={cn(
                      'flex w-full items-center gap-3 rounded-[14px] px-2.5 py-3 text-left transition-colors',
                      active ? 'bg-ovs-crm-ink text-white' : 'hover:bg-ovs-crm-surface-inset'
                    )}
                  >
                    <CrmAvatar name={name} size="sm" />

                    <div className="min-w-0 flex-1">
                      <p
                        className={cn(
                          'truncate text-[13px] font-medium',
                          active ? 'text-white' : 'text-ovs-crm-text-primary'
                        )}
                      >
                        {name}
                      </p>
                      <p
                        className={cn(
                          'truncate text-[11.5px]',
                          active ? 'text-white/50' : 'text-ovs-crm-text-muted'
                        )}
                      >
                        {conversation.assigned_user_id === currentUserId
                          ? 'Yours'
                          : conversation.assigned_user
                            ? conversation.assigned_user.name
                            : 'Unclaimed'}
                        {conversation.whatsapp_account
                          ? ` · ${conversation.whatsapp_account.display_name}`
                          : ''}
                        {conversation.campaign_context ? ` · ${conversation.campaign_context.name}` : ''}
                        {conversation.crm_lead ? ' · already a lead' : ''}
                      </p>
                    </div>

                    {conversation.contact &&
                      COMPLIANCE_BLOCKED_STATUSES.includes(conversation.contact.marketing_status) && (
                        <ShieldAlert
                          className={cn('h-3.5 w-3.5 shrink-0', active ? 'text-white/70' : 'text-ovs-semantic-danger')}
                          aria-label="Opted out or suppressed"
                        />
                      )}

                    {conversation.unread_count > 0 && (
                      <span
                        className={cn(
                          'shrink-0 rounded-full px-2 py-0.5 text-[11.5px] tabular-nums',
                          active ? 'bg-white/20 text-white' : 'bg-ovs-crm-ink text-white'
                        )}
                      >
                        {conversation.unread_count}
                      </span>
                    )}
                  </button>
                );
              })}
            </div>
          )}

          {/*
            * The server has always paginated this list at twenty; the panel
            * just had no way to ask for the next page, so past the first
            * twenty the rest of the inbox was unreachable. Scrolling inside
            * the panel moves through the current page — this moves between
            * pages.
            */}
          {conversations.last_page > 1 && (
            <CrmPagination
              className="mt-3"
              links={conversations.links}
              from={conversations.from}
              to={conversations.to}
              total={conversations.total}
            />
          )}
        </CrmSection>

        {/* ═══ The conversation ═════════════════════════════════════════ */}
        <div className="lg:col-span-8">
          {activeConversation ? (
            <CrmCard step={1} className="flex h-[calc(100vh-240px)] flex-col !p-0">
              {/* Who, and who owns them */}
              <div className="flex flex-wrap items-center gap-3 border-b border-ovs-crm-border px-5 py-4">
                <CrmAvatar name={activeConversation.contact?.name || activeConversation.phone} size="sm" />
                <div className="min-w-0 flex-1">
                  <h2 className="truncate text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                    {activeConversation.contact?.name || activeConversation.phone}
                  </h2>
                  <p className="truncate text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                    {activeConversation.phone}
                    {activeConversation.contact?.organization ? ` · ${activeConversation.contact.organization}` : ''}
                  </p>
                  <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-ovs-crm-text-muted">
                    {activeConversation.whatsapp_account && (
                      <span className="inline-flex items-center gap-1.5">
                        <Smartphone className="h-3 w-3" aria-hidden="true" />
                        {activeConversation.whatsapp_account.display_name}
                        {activeConversation.whatsapp_account.purpose && (
                          /*
                           * With four or five lines running, the number alone
                           * does not say whether this is a marketing enquiry or
                           * a clinic asking for help. The purpose does, and it
                           * keeps the same colour on every screen.
                           */
                          <CrmBadge
                            tone={PURPOSE_TONE[activeConversation.whatsapp_account.purpose] ?? 'slate'}
                          >
                            {PURPOSE_LABEL[activeConversation.whatsapp_account.purpose] ??
                              activeConversation.whatsapp_account.purpose}
                          </CrmBadge>
                        )}
                      </span>
                    )}
                    {activeConversation.campaign_context && (
                      <span className="inline-flex items-center gap-1">
                        <Megaphone className="h-3 w-3" aria-hidden="true" />
                        {activeConversation.campaign_context.name}
                      </span>
                    )}
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                  {isMasterAdmin ? (
                    <CrmSelect
                      aria-label="Assign this conversation"
                      value={activeConversation.assigned_user ? String(activeConversation.assigned_user.id) : 'unassigned'}
                      onChange={(e) =>
                        handleAssign(
                          activeConversation.id,
                          e.target.value === 'unassigned' ? null : Number(e.target.value)
                        )
                      }
                      className="w-auto"
                    >
                      <option value="unassigned">Unassigned</option>
                      {assignableUsers.map((user) => (
                        <option key={user.id} value={String(user.id)}>
                          {user.name}
                        </option>
                      ))}
                    </CrmSelect>
                  ) : (
                    !activeConversation.assigned_user_id && (
                      <CrmButton
                        size="sm"
                        icon={UserCheck}
                        onClick={() => handleAssign(activeConversation.id, currentUserId)}
                      >
                        Claim
                      </CrmButton>
                    )
                  )}

                  {activeLead ? (
                    <CrmButton size="sm" icon={ExternalLink} href={`/company/crm/leads/${activeLead.id}`}>
                      Open lead
                    </CrmButton>
                  ) : (
                    <>
                      <CrmButton size="sm" icon={Link2} onClick={() => setShowLinkLeadModal(true)}>
                        Link a lead
                      </CrmButton>
                      <CrmButton
                        size="sm"
                        tone="primary"
                        icon={UserPlus}
                        onClick={() => setShowCreateLeadModal(true)}
                      >
                        Create lead
                      </CrmButton>
                    </>
                  )}
                </div>
              </div>

              {/*
                * Shown up front, not just as an error after a failed send —
                * a rep should know before typing, not after being told no.
                */}
              {complianceBlocked && activeConversation.contact && (
                <div className="flex items-center gap-2.5 border-b border-ovs-crm-danger-border bg-ovs-crm-danger-soft px-5 py-3 text-[12px] text-ovs-crm-danger-text">
                  <ShieldAlert className="h-4 w-4 shrink-0" aria-hidden="true" />
                  <span className="flex-1">
                    {activeConversation.contact.marketing_status === 'opted_out'
                      ? 'This contact opted out — no marketing message can be sent to them.'
                      : 'This contact is suppressed — no marketing message can be sent to them.'}
                  </span>
                  <Link
                    href={`/company/marketing/suppression?search=${encodeURIComponent(activeConversation.phone)}`}
                    className="shrink-0 font-semibold underline hover:no-underline"
                  >
                    Review in Suppression
                  </Link>
                </div>
              )}

              {/* The messages */}
              <div className="min-h-0 flex-1 space-y-3 overflow-y-auto px-5 py-4">
                {earlierPage.hasMore && (
                  <div className="flex justify-center pb-1">
                    <CrmButton size="sm" icon={ArrowUp} loading={loadingEarlier} onClick={loadEarlierMessages}>
                      Load earlier messages
                    </CrmButton>
                  </div>
                )}

                {visibleMessages.length === 0 ? (
                  <p className="py-16 text-center text-[13px] text-ovs-crm-text-muted">
                    Nothing has been said yet.
                  </p>
                ) : (
                  visibleMessages.map((message) => {
                    const outbound = message.direction === 'outbound';

                    return (
                      <div
                        key={message.id}
                        className={cn('flex', outbound ? 'justify-end' : 'justify-start')}
                      >
                        <div
                          className={cn(
                            'max-w-[78%] rounded-[18px] px-3.5 py-2.5',
                            outbound
                              ? 'bg-ovs-crm-ink text-white'
                              : 'bg-ovs-crm-surface-inset text-ovs-crm-text-primary'
                          )}
                        >
                          <p className="whitespace-pre-wrap text-[13px] leading-relaxed">{message.body}</p>
                          <p
                            className={cn(
                              'mt-1 text-[11.5px] tabular-nums',
                              outbound ? 'text-white/45' : 'text-ovs-crm-text-muted'
                            )}
                          >
                            {formatMessageTime(message.created_at)}
                            {outbound ? ` · ${message.status}` : ''}
                          </p>
                        </div>
                      </div>
                    );
                  })
                )}
              </div>

              {/*
                * Meta only allows a free-text reply inside 24 hours of the
                * contact's last message; outside that window a message has to
                * be an approved template. The composer says so rather than
                * letting the send fail with a provider error.
                */}
              <form
                onSubmit={handleSendReply}
                className="flex items-center gap-2 border-t border-ovs-crm-border px-5 py-4"
              >
                <CrmInput
                  value={replyForm.data.message}
                  onChange={(e) => replyForm.setData('message', e.target.value)}
                  placeholder={complianceBlocked ? 'Sending is blocked for this contact' : 'Write a reply…'}
                  aria-label="Reply message"
                  disabled={complianceBlocked}
                  className="min-w-0 flex-1"
                />
                <CrmButton
                  tone="primary"
                  type="submit"
                  icon={Send}
                  loading={replyForm.processing}
                  disabled={complianceBlocked || !replyForm.data.message.trim()}
                >
                  Send
                </CrmButton>
              </form>
            </CrmCard>
          ) : (
            <CrmCard step={1} className="flex h-[calc(100vh-240px)] flex-col items-center justify-center">
              <MessageSquare className="mb-3 h-8 w-8 text-ovs-crm-text-muted" aria-hidden="true" />
              <p className="text-[13px] text-ovs-crm-text-muted">
                Pick a thread on the left to read it and reply.
              </p>
            </CrmCard>
          )}
        </div>
      </div>

      {/* ═══ Create a lead ══════════════════════════════════════════════ */}
      <CrmModal
        open={showCreateLeadModal}
        onClose={() => setShowCreateLeadModal(false)}
        size="sm"
        title="Hand this over to the CRM"
        description={
          activeConversation
            ? `${activeConversation.contact?.name || activeConversation.phone} becomes a lead a rep can work.`
            : undefined
        }
        footer={
          <>
            <CrmButton onClick={() => setShowCreateLeadModal(false)}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              type="submit"
              form="create-lead"
              icon={UserPlus}
              loading={createLeadForm.processing}
            >
              Create lead
            </CrmButton>
          </>
        }
      >
        <form id="create-lead" onSubmit={handleCreateLead}>
          <CrmFormField label="Organisation" hint="What the lead will be called in the CRM.">
            <CrmInput
              required
              value={createLeadForm.data.company_name}
              onChange={(e) => createLeadForm.setData('company_name', e.target.value)}
              placeholder="Sunrise Dental Clinic"
            />
          </CrmFormField>

          <CrmFormField label="Contact name">
            <CrmInput
              value={createLeadForm.data.contact_name}
              onChange={(e) => createLeadForm.setData('contact_name', e.target.value)}
              placeholder="Dr. Ramesh Rao"
            />
          </CrmFormField>
        </form>
      </CrmModal>

      {/* ═══ Link an existing lead ══════════════════════════════════════ */}
      <CrmModal
        open={showLinkLeadModal}
        onClose={() => setShowLinkLeadModal(false)}
        size="sm"
        title="Link an existing lead"
        description="Use this when the CRM already has this organisation, so you do not create a duplicate."
        footer={
          <>
            <CrmButton onClick={() => setShowLinkLeadModal(false)}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              type="submit"
              form="link-lead"
              icon={Link2}
              loading={linkLeadForm.processing}
              disabled={!linkLeadForm.data.lead_id}
            >
              Link it
            </CrmButton>
          </>
        }
      >
        <form id="link-lead" onSubmit={handleLinkLead}>
          <CrmFormField label="Find the lead" hint="Search by organisation, contact or phone.">
            <CrmInput
              type="search"
              onChange={(e) => searchLeadsQuery(e.target.value)}
              placeholder="Start typing…"
              aria-label="Search CRM leads"
            />
          </CrmFormField>

          {isSearchingLeads ? (
            <p className="py-6 text-center text-[13px] text-ovs-crm-text-muted">Searching…</p>
          ) : searchedLeads.length === 0 ? (
            <p className="py-6 text-center text-[13px] text-ovs-crm-text-muted">
              No matching lead yet — type to search.
            </p>
          ) : (
            <div className="-mx-2.5 max-h-64 overflow-y-auto">
              {searchedLeads.map((lead) => {
                const chosen = String(linkLeadForm.data.lead_id) === String(lead.id);

                return (
                  <button
                    key={lead.id}
                    type="button"
                    onClick={() => linkLeadForm.setData('lead_id', String(lead.id))}
                    aria-pressed={chosen}
                    className={cn(
                      'flex w-full items-center gap-3 rounded-[14px] px-2.5 py-2.5 text-left transition-colors',
                      chosen ? 'bg-ovs-crm-ink text-white' : 'hover:bg-ovs-crm-surface-inset'
                    )}
                  >
                    <div className="min-w-0 flex-1">
                      <p
                        className={cn(
                          'truncate text-[13px] font-medium',
                          chosen ? 'text-white' : 'text-ovs-crm-text-primary'
                        )}
                      >
                        {lead.company_name}
                      </p>
                      <p
                        className={cn(
                          'truncate text-[11.5px]',
                          chosen ? 'text-white/50' : 'text-ovs-crm-text-muted'
                        )}
                      >
                        {[lead.contact_name, lead.phone].filter(Boolean).join(' · ')}
                      </p>
                    </div>
                  </button>
                );
              })}
            </div>
          )}
        </form>
      </CrmModal>
    </MarketingShell>
  );
}
