import React, { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmSection,
  CrmRing,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmTextarea,
  CrmPagination,
  CrmAvatar,
  CrmTabs,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { InstagramIcon } from '@/components/icons/InstagramIcon';
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  Loader2,
  Pencil,
  Settings as SettingsIcon,
  Sparkles,
  XCircle,
} from 'lucide-react';

interface InstagramPost {
  id: number;
  topic: string | null;
  topic_source: 'auto' | 'manual';
  caption: string | null;
  image_url: string | null;
  status: 'generating' | 'pending_approval' | 'approved' | 'scheduled' | 'publishing' | 'published' | 'rejected' | 'failed';
  scheduled_at: string | null;
  published_at: string | null;
  rejection_reason: string | null;
  error_message: string | null;
  approved_by: { id: number; name: string } | null;
  created_at: string;
}

interface PaginatedPosts {
  data: InstagramPost[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

type TabKey = 'needs_review' | 'approved_scheduled' | 'history';

interface ActiveAccount {
  username: string | null;
  instagram_business_account_id: string | null;
  logo_url: string | null;
  status: 'connected' | 'disconnected' | 'error';
}

interface IndexProps {
  posts: PaginatedPosts;
  tab: TabKey;
  counts: {
    needs_review: number;
    approved_scheduled: number;
    history: number;
  };
  account: ActiveAccount | null;
  hasConnectedAccount: boolean;
  autoFillEnabled: boolean;
  bufferStatus: { ready: number; target: number };
}

/** Post status → pill tone. */
const POST_TONE: Record<string, CrmStatusTone> = {
  generating: 'neutral',
  pending_approval: 'warn',
  approved: 'good',
  scheduled: 'warn',
  publishing: 'warn',
  published: 'good',
  rejected: 'neutral',
  failed: 'bad',
};

/** … and what to call it, in words a person would use. */
const POST_LABEL: Record<string, string> = {
  generating: 'Drafting',
  pending_approval: 'Needs review',
  approved: 'Publishing',
  scheduled: 'Scheduled',
  publishing: 'Publishing',
  published: 'Published',
  rejected: 'Rejected',
  failed: 'Failed',
};

const TAB_COPY: Record<TabKey, { title: string; description: string; empty: string }> = {
  needs_review: {
    title: 'Needs review',
    description: 'Waiting on a decision — edit, schedule, reject, or publish',
    empty: 'Nothing waiting on you right now. Ask for a topic above, or switch auto top-up on in settings.',
  },
  approved_scheduled: {
    title: 'Approved & scheduled',
    description: 'Already decided — on its way out',
    empty: 'Nothing approved or scheduled yet — items land here once you approve a draft.',
  },
  history: {
    title: 'Published & history',
    description: 'What already happened',
    empty: 'Nothing has been published yet.',
  },
};

const formatDateTime = (iso: string) => {
  const d = new Date(iso);

  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
};

export default function InstagramQueue({
  posts,
  tab,
  counts,
  account,
  hasConnectedAccount,
  autoFillEnabled,
  bufferStatus,
}: IndexProps) {
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editCaption, setEditCaption] = useState('');
  const [schedulingId, setSchedulingId] = useState<number | null>(null);
  // Tracks which post's "Publish" request is in flight — router.post() here
  // has no useForm() of its own to carry a .processing flag, so this is
  // what the button's disabled/loading state reads instead.
  const [publishingId, setPublishingId] = useState<number | null>(null);

  const generateForm = useForm({ topic: '' });
  const scheduleForm = useForm({ scheduled_at: '' });
  const rejectForm = useForm({ rejection_reason: '' });
  const [rejectingId, setRejectingId] = useState<number | null>(null);

  const handleGenerate = (e: React.FormEvent) => {
    e.preventDefault();
    generateForm.post('/company/marketing/instagram/generate', {
      onSuccess: () => generateForm.reset(),
    });
  };

  const startEdit = (post: InstagramPost) => {
    setEditingId(post.id);
    setEditCaption(post.caption || '');
  };

  const saveEdit = (id: number) => {
    router.put(`/company/marketing/instagram/${id}`, { caption: editCaption }, {
      onSuccess: () => setEditingId(null),
      preserveScroll: true,
    });
  };

  const handleApproveNow = (id: number) => {
    if (publishingId === id) return;
    setPublishingId(id);
    router.post(`/company/marketing/instagram/${id}/approve`, {}, {
      preserveScroll: true,
      onFinish: () => setPublishingId(null),
    });
  };

  const handleSchedule = (id: number) => {
    scheduleForm.post(`/company/marketing/instagram/${id}/approve`, {
      preserveScroll: true,
      onSuccess: () => {
        setSchedulingId(null);
        scheduleForm.reset();
      },
    });
  };

  const handleReject = (id: number) => {
    rejectForm.post(`/company/marketing/instagram/${id}/reject`, {
      preserveScroll: true,
      onSuccess: () => {
        setRejectingId(null);
        rejectForm.reset();
      },
    });
  };

  /*
   * Tabs are a server round-trip, not a client-side filter — each one is its
   * own paginated slice, so history can grow without ever shipping the whole
   * table to the browser. Leaving `page` out of the request is what resets
   * pagination: a fresh tab always starts its own paginator at page 1.
   */
  const setTab = (nextTab: TabKey) => {
    if (nextTab === tab) return;
    router.get(
      '/company/marketing/instagram',
      { tab: nextTab },
      { preserveScroll: true, preserveState: true, replace: true, only: ['posts', 'tab'] }
    );
  };

  const bufferPct =
    bufferStatus.target > 0 ? Math.min(100, (bufferStatus.ready / bufferStatus.target) * 100) : 0;

  const copy = TAB_COPY[tab];

  return (
    <MarketingShell title="Instagram">
      <CrmPageHead
        eyebrow="Marketing"
        title="Instagram"
        stats={
          <>
            <CrmPageStat
              icon={Sparkles}
              value={counts.needs_review}
              caption="Need your decision"
            />
            <CrmPageStat
              icon={Clock}
              value={counts.approved_scheduled}
              caption="Approved or scheduled"
            />
            <CrmPageStat
              icon={CheckCircle2}
              value={counts.history}
              caption="Published so far"
            />
          </>
        }
      />

      {/*
        * Which account this page is actually managing. Everything below —
        * the buffer, the queue, publishing itself — happens against this one
        * account, so it leads the page rather than living one click away in
        * Settings.
        */}
      <CrmCard step={0} className="mb-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            {account?.logo_url ? (
              <img
                src={account.logo_url}
                alt=""
                className="h-9 w-9 shrink-0 rounded-full object-cover"
              />
            ) : (
              <CrmAvatar name={account?.username || 'Instagram'} size="md" />
            )}
            <div className="min-w-0">
              <h2 className="truncate text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                {account?.username ? `@${account.username}` : 'No account connected'}
              </h2>
              <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                {account?.instagram_business_account_id
                  ? `Business account ${account.instagram_business_account_id}`
                  : 'Connect an account to start publishing'}
              </p>
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <CrmStatusPill tone={hasConnectedAccount ? 'good' : 'neutral'}>
              {hasConnectedAccount ? 'Connected' : 'Not connected'}
            </CrmStatusPill>
            <CrmButton size="sm" icon={SettingsIcon} href="/company/marketing/instagram/settings">
              Settings
            </CrmButton>
          </div>
        </div>
      </CrmCard>

      {/*
        * Nothing here can reach Instagram until an account is connected. The
        * drafts still generate and can still be reviewed — they queue — so this
        * is stated as a fact to fix, not as an error blocking the page.
        */}
      {!hasConnectedAccount && (
        <div className="crm-rise mb-5 flex flex-wrap items-center gap-4 rounded-[24px] bg-ovs-crm-warning-soft p-5">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-ovs-crm-warning-text text-white">
            <AlertTriangle className="h-5 w-5" aria-hidden="true" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-[15px] font-semibold text-ovs-crm-warning-text">
              No Instagram account connected
            </p>
            <p className="mt-0.5 text-[13px] leading-relaxed text-ovs-crm-warning-text/80">
              Drafts still generate and you can still review them — approving one will queue it and
              publish as soon as an account is connected.
            </p>
          </div>
          <CrmButton href="/company/marketing/instagram/settings" icon={SettingsIcon}>
            Connect
          </CrmButton>
        </div>
      )}

      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        <CrmCard step={1} className="flex flex-col items-center lg:col-span-4">
          <div className="mb-4 w-full">
            <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              Draft buffer
            </h2>
            <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
              {autoFillEnabled
                ? 'Topped up automatically as you approve'
                : 'Auto top-up is off — drafts only appear when you ask'}
            </p>
          </div>
          <CrmRing
            value={bufferStatus.ready}
            max={Math.max(bufferStatus.target, 1)}
            color={bufferPct >= 60 ? '#2FA96B' : '#E0B21E'}
            label={`${bufferStatus.ready}`}
            caption={`of ${bufferStatus.target} kept ready`}
            size={150}
          />
        </CrmCard>

        {/*
          * The manual topic box. Routine content is the AI's own choice; this
          * is for the occasion posts — a festival, a launch — that need
          * steering, which is why it says so rather than sitting there as an
          * unexplained text field.
          */}
        <CrmSection
          step={2}
          title="Ask for something specific"
          description="For an occasion the AI would not think of on its own — a festival, a launch, an offer."
          className="lg:col-span-8"
        >
          <form onSubmit={handleGenerate} className="flex flex-wrap items-end gap-3">
            <div className="min-w-0 flex-1">
              <CrmInput
                value={generateForm.data.topic}
                onChange={(e) => generateForm.setData('topic', e.target.value)}
                placeholder="Diwali greeting for the clinic"
                aria-label="Topic for the post"
              />
            </div>
            <CrmButton
              tone="primary"
              type="submit"
              icon={Sparkles}
              loading={generateForm.processing}
              disabled={!generateForm.data.topic.trim()}
            >
              Draft it
            </CrmButton>
          </form>

          <p className="mt-3 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
            Drafting takes a moment — the caption and the image are generated in the background, and
            the draft appears below once it is ready. Nothing publishes without your approval.
          </p>
        </CrmSection>
      </div>

      {/* ═══ The content pipeline ══════════════════════════════════════ */}
      <div className="mb-4">
        <CrmTabs
          value={tab}
          onChange={setTab}
          tabs={[
            { key: 'needs_review', label: 'Needs review', count: counts.needs_review },
            { key: 'approved_scheduled', label: 'Approved & scheduled', count: counts.approved_scheduled },
            { key: 'history', label: 'Published & history', count: counts.history },
          ]}
        />
      </div>

      <CrmSection step={3} title={copy.title} description={copy.description}>
        {posts.data.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-[13px] text-ovs-crm-text-muted">{copy.empty}</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            {posts.data.map((post) => {
              const editing = editingId === post.id;
              const scheduling = schedulingId === post.id;
              const rejecting = rejectingId === post.id;
              const reviewable = post.status === 'pending_approval';

              return (
                <div
                  key={post.id}
                  className="flex flex-col overflow-hidden rounded-[24px] bg-ovs-crm-surface-inset"
                >
                  {post.image_url ? (
                    <img
                      src={post.image_url}
                      alt={post.topic ?? 'Generated post image'}
                      className="aspect-square w-full object-cover"
                    />
                  ) : (
                    <div className="flex aspect-square w-full items-center justify-center bg-ovs-crm-border/40">
                      {post.status === 'generating' ? (
                        <Loader2
                          className="h-6 w-6 animate-spin text-ovs-crm-text-muted"
                          aria-hidden="true"
                        />
                      ) : (
                        <InstagramIcon className="h-8 w-8 text-ovs-crm-text-muted" />
                      )}
                    </div>
                  )}

                  <div className="flex min-h-0 flex-1 flex-col gap-3 p-4">
                    <div className="flex items-start justify-between gap-2">
                      <p className="min-w-0 flex-1 truncate text-[11.5px] text-ovs-crm-text-muted">
                        {post.topic ?? 'AI picked the topic'}
                        {post.topic_source === 'auto' ? ' · auto' : ''}
                      </p>
                      <CrmStatusPill tone={POST_TONE[post.status] ?? 'neutral'}>
                        {POST_LABEL[post.status] ?? post.status}
                      </CrmStatusPill>
                    </div>

                    {editing ? (
                      <CrmTextarea
                        rows={5}
                        value={editCaption}
                        onChange={(e) => setEditCaption(e.target.value)}
                        aria-label="Caption"
                      />
                    ) : (
                      <p className="line-clamp-5 whitespace-pre-wrap text-[13px] leading-relaxed text-ovs-crm-text-primary">
                        {post.caption ?? 'Caption is still being written…'}
                      </p>
                    )}

                    {/* Monitoring tab: when this goes out and who signed off — no actions to take, just status. */}
                    {tab === 'approved_scheduled' && (
                      <p className="text-[11.5px] text-ovs-crm-text-muted">
                        {post.status === 'scheduled' && post.scheduled_at
                          ? `Scheduled for ${formatDateTime(post.scheduled_at)}`
                          : post.status === 'publishing'
                            ? 'Publishing now…'
                            : 'Approved — publishing shortly'}
                        {post.approved_by ? ` · approved by ${post.approved_by.name}` : ''}
                      </p>
                    )}

                    {/* History tab: the record — when it went out, or why it didn't. */}
                    {tab === 'history' && (
                      <p className="text-[11.5px] text-ovs-crm-text-muted">
                        {post.status === 'published' && post.published_at
                          ? `Published ${formatDateTime(post.published_at)}`
                          : POST_LABEL[post.status]}
                        {post.approved_by && post.status === 'published' ? ` · approved by ${post.approved_by.name}` : ''}
                      </p>
                    )}

                    {post.rejection_reason && (
                      <p className="rounded-[14px] bg-white p-2.5 text-[11.5px] leading-relaxed text-ovs-crm-text-secondary">
                        Rejected: {post.rejection_reason}
                      </p>
                    )}

                    {post.error_message && (
                      <p className="rounded-[14px] bg-ovs-crm-danger-soft p-2.5 text-[11.5px] leading-relaxed text-ovs-crm-danger-text">
                        {post.error_message}
                      </p>
                    )}

                    {scheduling && (
                      <div className="flex items-end gap-2">
                        <CrmInput
                          type="datetime-local"
                          value={scheduleForm.data.scheduled_at}
                          onChange={(e) => scheduleForm.setData('scheduled_at', e.target.value)}
                          aria-label="When to publish"
                          className="min-w-0 flex-1"
                        />
                        <CrmButton
                          size="sm"
                          tone="primary"
                          onClick={() => handleSchedule(post.id)}
                          loading={scheduleForm.processing}
                          disabled={!scheduleForm.data.scheduled_at || scheduleForm.processing}
                        >
                          Set
                        </CrmButton>
                      </div>
                    )}

                    {rejecting && (
                      <div className="flex items-end gap-2">
                        <CrmInput
                          value={rejectForm.data.rejection_reason}
                          onChange={(e) => rejectForm.setData('rejection_reason', e.target.value)}
                          placeholder="Why not?"
                          aria-label="Reason for rejecting"
                          className="min-w-0 flex-1"
                        />
                        <CrmButton
                          size="sm"
                          tone="danger"
                          onClick={() => handleReject(post.id)}
                          disabled={!rejectForm.data.rejection_reason.trim()}
                        >
                          Reject
                        </CrmButton>
                      </div>
                    )}

                    {reviewable && (
                      <div className="mt-auto flex flex-wrap items-center gap-1.5 pt-1">
                        {editing ? (
                          <>
                            <CrmButton size="sm" onClick={() => setEditingId(null)}>
                              Cancel
                            </CrmButton>
                            <CrmButton size="sm" tone="primary" onClick={() => saveEdit(post.id)}>
                              Save caption
                            </CrmButton>
                          </>
                        ) : (
                          <>
                            <CrmButton size="sm" icon={Pencil} onClick={() => startEdit(post)}>
                              Edit
                            </CrmButton>
                            <CrmButton
                              size="sm"
                              icon={Clock}
                              onClick={() => setSchedulingId(scheduling ? null : post.id)}
                            >
                              Schedule
                            </CrmButton>
                            <CrmButton
                              size="sm"
                              icon={XCircle}
                              onClick={() => setRejectingId(rejecting ? null : post.id)}
                            >
                              Reject
                            </CrmButton>
                            <CrmButton
                              size="sm"
                              tone="primary"
                              icon={CheckCircle2}
                              onClick={() => handleApproveNow(post.id)}
                              loading={publishingId === post.id}
                              disabled={publishingId === post.id}
                            >
                              Publish
                            </CrmButton>
                          </>
                        )}
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}

        <CrmPagination
          links={posts.links}
          from={posts.from}
          to={posts.to}
          total={posts.total}
          className="mt-4"
        />
      </CrmSection>
    </MarketingShell>
  );
}
