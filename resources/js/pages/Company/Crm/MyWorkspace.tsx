import {
  CrmShell,
  CrmDisclosure,
  CrmPageHead,
  humanize,
  CrmPageStat,
  CrmTabs,
  CrmButton,
  CrmSelect,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmTextarea,
  CrmTable,
  CrmBadge as CrmBadgeCanonical,
  type CrmColumn,
} from './components';
import React, { useState, useMemo } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Card } from './components/CrmCardPrimitives';
import { MyWorkUnclaimed, type UnclaimedLead } from './components/MyWorkUnclaimed';
import { Badge } from './components/CrmBadgePrimitives';
import { TaskModal, type Task as EditableTask } from './components/TaskModal';
import { Button } from './components/CrmFormPrimitives';
import {
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
  TablePrimaryEntity,
} from './components/CrmDataTable';
import {
  Calendar,
  Clock,
  Phone,
  MessageSquare,
  AlertTriangle,
  CheckCircle2,
  ExternalLink,
  Plus,
  Search,
  Filter,
  FileText,
  Video,
  Layers,
  ArrowRight,
  TrendingUp,
  RefreshCw,
  UserCheck,
  ChevronRight,
  Target,
  Users,
  Check,
  X,
  ShieldAlert,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * A ten-digit Indian mobile number into a `wa.me` address.
 *
 * Mirrors `DealPageService::outreach()`'s `wa_number` composition: strip
 * everything but digits, prefix `91` for a bare ten-digit number, and pass an
 * already-longer number through as-is. Anything shorter is left null rather
 * than guessed at.
 */
function waNumber(phone?: string | null): string | null {
  if (!phone) return null;
  const digits = phone.replace(/\D/g, '');
  if (digits.length === 10) return `91${digits}`;
  if (digits.length >= 11) return digits;
  return null;
}

interface FollowUpItem {
  id: number;
  lead_id: number;
  company_name: string;
  contact_name: string;
  city?: string | null;
  phone?: string;
  stage: string;
  stage_key: string;
  priority: string;
  due_date: string;
  time_ago: string;
  notes?: string;
}

interface DemoItem {
  id: number;
  lead_id: number;
  company_name: string;
  contact_name: string;
  phone?: string;
  presenter?: string | null;
  scheduled_at: string;
  is_today: boolean;
  demo_mode: string;
  meeting_platform: string | null;
  meeting_platform_label: string | null;
  /** In person. Drives which of the two demo tabs this lands in. */
  is_field_visit: boolean;
  meeting_link?: string;
  meeting_id?: string | null;
  passcode?: string | null;
  location?: string | null;
  confirmation_status: string;
  notes?: string;
}

interface TrialTelemetry {
  patients: number;
  prescriptions: number;
  invoices: number;
  appointments: number;
  users_total: number;
  logins_7d: number;
}

interface TrialItem {
  id: number;
  lead_id: number;
  company_name: string;
  contact_name: string;
  phone?: string;
  plan: string;
  start_date: string;
  end_date: string;
  days_remaining: number;
  is_ending_soon: boolean;
  hours_remaining?: number | null;
  is_expiring_48h?: boolean;
  usage_percent: number;
  telemetry?: TrialTelemetry | null;
  next_action?: string;
}

interface PendingApprovalItem {
  id: number;
  type: 'trial_extension' | 'quotation_discount';
  lead_id: number;
  company_name: string;
  contact_name: string;
  city?: string | null;
  requested_by: string;
  time_ago: string;
  /** trial_extension only */
  trial_id?: number;
  additional_days?: number;
  reason?: string;
  /** quotation_discount only */
  quotation_id?: number;
  quotation_number?: string;
  discount?: number;
  discount_percent?: number;
}

interface QuotationItem {
  id: number;
  lead_id: number;
  company_name: string;
  contact_name: string;
  quotation_number: string;
  version: number;
  grand_total: number;
  status: string;
  valid_until?: string;
  is_expiring_soon: boolean;
}

interface NeedsActionItem {
  id: number;
  company_name: string;
  contact_name: string;
  phone?: string;
  stage: string;
  stage_key: string;
  priority: string;
  last_contact: string;
  expected_mrr: number;
}

interface WinbackItem {
  id: number;
  company_name: string;
  contact_name: string;
  phone?: string;
  loss_reason: string;
  last_contact: string;
  scheduled_winback: string;
}

interface AssignedLeadItem {
  id: number;
  company_name: string;
  contact_name: string;
  email?: string;
  phone?: string;
  stage: string;
  stage_key: string;
  priority: string;
  expected_mrr: number;
  health_score: number;
  next_followup_at?: string;
  last_contact: string;
}

interface WorkloadCounts {
  overdue_followups: number;
  due_today_followups: number;
  upcoming_followups: number;
  demos: number;
  active_trials: number;
  quotations: number;
  needs_next_action: number;
  winbacks: number;
  total_assigned_leads: number;
}

interface MyWorkspaceProps {
  /** Leads in the organization that nobody owns — the work when none is yours. */
  unclaimed?: { count: number; items: UnclaimedLead[] };
  /** Who is looking, so a lead can be claimed without a round trip to pick a name. */
  currentUserId?: number | null;
  workload: {
    counts: WorkloadCounts;
    followups: {
      overdue: FollowUpItem[];
      due_today: FollowUpItem[];
      upcoming: FollowUpItem[];
    };
    demos: DemoItem[];
    trials: TrialItem[];
    quotations: QuotationItem[];
    needs_next_action: NeedsActionItem[];
    winbacks: WinbackItem[];
    assigned_leads: AssignedLeadItem[];
  };
  filters?: {
    search?: string;
    stage?: string;
  };
  todays_focus?: TodaysFocus;
  tasks?: TaskItem[];
  /** Open tasks belonging to somebody else — only ever populated for an admin. */
  other_tasks?: Array<{
    id: number;
    title: string;
    type: string;
    due_label: string;
    is_overdue: boolean;
    owner?: string | null;
  }>;
  upcoming?: UpcomingItem[];
  recent_activity?: RecentActivityItem[];
  /**
   * The one queue on this page that is not assigned-to-me-only: whoever can
   * approve a trial extension sees every pending request their tenant holds,
   * because a manager cannot act on their team's asks by only ever seeing
   * their own leads.
   */
  pending_approvals?: { items: PendingApprovalItem[]; count: number };
  /** Whether the acting user holds `crm.trials.approve-extension`. */
  can_approve_extensions?: boolean;
}

interface FocusItem {
  kind: string;
  rank: number;
  reason: string;
  title: string;
  detail: string;
  lead_id: number | null;
}

interface TodaysFocus {
  items: FocusItem[];
  total: number;
  is_clear: boolean;
}

interface TaskItem {
  id: number;
  title: string;
  description: string | null;
  type: string;
  priority: string;
  due_at: string | null;
  due_label: string;
  is_overdue: boolean;
  is_due_today: boolean;
  lead_id: number | null;
}

interface UpcomingItem {
  kind: string;
  title: string;
  occurs_at: string | null;
  date_label: string;
  time_label: string;
  is_today: boolean;
  lead_id: number | null;
}

interface RecentActivityItem {
  id: number;
  lead_id: number;
  type: string;
  description: string;
  time: string;
}

const FOCUS_TONE: Record<string, string> = {
  pending_approval: 'border-l-ovs-crm-accent-coral',
  overdue_followup: 'border-l-ovs-crm-danger',
  demo_today: 'border-l-ovs-crm-info',
  trial_action: 'border-l-ovs-crm-warning',
  quotation_followup: 'border-l-ovs-crm-accent-teal',
  new_assignment: 'border-l-ovs-crm-success',
  task_due: 'border-l-ovs-crm-text-secondary',
};

const UPCOMING_LABEL: Record<string, string> = {
  demo: 'Demo',
  followup: 'Follow-up',
  task: 'Task',
  trial_deadline: 'Trial ends',
  quotation_expiry: 'Quotation expires',
};

/*
 * Three tabs lead, and they are the three halves of a rep's day that need
 * different things of them: a call to join, a phone to pick up, a car to get
 * into. `demos` used to hold the first and the third together, which meant a
 * rep scanning for "what am I driving to" read past four Meet links to find it.
 *
 * The remaining four are unchanged and still follow, because each is a real
 * queue somebody works — they are just not what you open this page for.
 */
type TabKey =
  | 'approvals'
  | 'demos'
  | 'followups'
  | 'field_visits'
  | 'trials'
  | 'quotations'
  | 'needs_action'
  | 'winbacks'
  | 'leads';

export default function MyWorkspace({
  unclaimed,
  currentUserId = null,
  workload,
  filters = {},
  todays_focus,
  tasks = [],
  other_tasks = [],
  upcoming = [],
  recent_activity = [],
  pending_approvals = { items: [], count: 0 },
  can_approve_extensions = false,
}: MyWorkspaceProps) {
  /*
   * Upcoming and My tasks were listing the same rows.
   *
   * Every open task appeared in the upcoming feed by date and again, in full,
   * in the tasks card right beneath it — the same title twice, labelled "Task"
   * in one and "Follow Up" in the other. Tasks have their own card now, so the
   * feed carries the things that have no other home.
   */
  const upcomingWithoutTasks = upcoming.filter((item) => item.kind !== 'task' && item.kind !== 'followup');

  const [taskModalOpen, setTaskModalOpen] = useState(false);
  const [taskType, setTaskType] = useState('all');
  const [taskPriority, setTaskPriority] = useState('all');
  const [editingTask, setEditingTask] = useState<EditableTask | null>(null);
  const [searchQuery, setSearchQuery] = useState(filters.search || '');
  const [selectedStage, setSelectedStage] = useState(filters.stage || 'all');

  /* Approving is one click; rejecting asks why, same as Deal 360's own extend hero does. */
  const [rejectingApproval, setRejectingApproval] = useState<PendingApprovalItem | null>(null);
  /*
   * Both endpoints validate a required reason under a different name
   * (`rejection_reason` for a trial extension, `reason` for a quotation
   * discount) — one textarea sets both keys so the reject button does not
   * need to know which one the server is about to read.
   */
  const rejectForm = useForm({ reason: '', rejection_reason: '' });

  const approveApproval = (item: PendingApprovalItem) => {
    const url = item.type === 'quotation_discount'
      ? `/company/crm/quotations/${item.quotation_id}/approve`
      : `/company/crm/trials/${item.trial_id}/extension-requests/${item.id}/approve`;
    router.post(url, {}, { preserveScroll: true });
  };

  /* All-optional on the server, so a bare post is enough to close the loop out. */
  const markFollowupDone = (leadId: number) => {
    router.post(`/company/crm/leads/${leadId}/followup/complete`, {}, { preserveScroll: true });
  };

  const [reschedulingDemo, setReschedulingDemo] = useState<DemoItem | null>(null);
  const rescheduleForm = useForm({ scheduled_at: '', notes: '' });

  const counts = workload?.counts || {
    overdue_followups: 0,
    due_today_followups: 0,
    upcoming_followups: 0,
    demos: 0,
    active_trials: 0,
    quotations: 0,
    needs_next_action: 0,
    winbacks: 0,
    total_assigned_leads: 0,
  };

  const totalUrgentFollowups = counts.overdue_followups + counts.due_today_followups;

  /*
   * "Nothing of my own today" — not "no leads at all". Someone can own a
   * hundred deals and still have an empty day, and it is the day this page is
   * about.
   */
  /*
   * Only the queues that have something in them.
   *
   * All seven rendered unconditionally, so a rep with a clear day was shown
   * "Follow-ups 0 · Demos 0 · Trials 0 · Quotations 0 · Needs an action 0 ·
   * Win-backs 0 · My leads 0" — seven controls that could each only open an
   * empty list. A tab that cannot show anything is not information, it is a
   * dead end with a number on it.
   */
  /*
   * Split by how the rep gets there, not by record type.
   *
   * A Meet link and a clinic address are answers to different questions asked
   * at different times of day, and they were the same list.
   */
  const remoteDemos = (workload?.demos ?? []).filter((d) => !d.is_field_visit);
  const fieldVisits = (workload?.demos ?? []).filter((d) => d.is_field_visit);
  const demosToday = (workload?.demos ?? []).filter((d) => d.is_today).length;
  const expiringTrials48h = (workload?.trials ?? []).filter((t) => t.is_expiring_48h).length;

  const visibleTabs = (
    [
      /*
       * Leads even when empty, for anyone who can act on it — an approver
       * checks this queue the way they check email, not only when it has
       * something in it.
       */
      { key: 'approvals' as const, label: 'Approvals', count: pending_approvals.items.length, alwaysVisible: can_approve_extensions },
      { key: 'demos' as const, label: "Today's demos", count: remoteDemos.length },
      { key: 'followups' as const, label: 'Calls & follow-ups', count: totalUrgentFollowups },
      { key: 'field_visits' as const, label: 'Field visits', count: fieldVisits.length },
      { key: 'trials' as const, label: 'Trials', count: counts.active_trials },
      { key: 'quotations' as const, label: 'Quotations', count: counts.quotations },
      { key: 'needs_action' as const, label: 'Needs an action', count: counts.needs_next_action },
      { key: 'winbacks' as const, label: 'Win-backs', count: counts.winbacks },
      { key: 'leads' as const, label: 'My leads', count: counts.total_assigned_leads },
    ]
  ).filter((tab) => tab.count > 0 || tab.alwaysVisible);

  const [activeTab, setActiveTab] = useState<TabKey>(visibleTabs[0]?.key ?? 'followups');

  const hasNothingAssigned =
    (todays_focus?.total ?? 0) === 0 && tasks.length === 0 && counts.total_assigned_leads === 0;

  /* The task list after the kind/priority filters the Tasks page used to own. */
  const visibleTasks = useMemo(
    () =>
      tasks.filter(
        (task) =>
          (taskType === 'all' || task.type === taskType) &&
          (taskPriority === 'all' || task.priority === taskPriority)
      ),
    [tasks, taskType, taskPriority]
  );



  /**
   * Open a row.
   *
   * This used to fill a right-hand context sheet with a summary of the lead,
   * whose own primary action was "open the full page" — so the sheet was a
   * detour on the way to somewhere the reader was already going. It now goes
   * straight there, which also gives the destination a real URL.
   */
  const openLead = (lead: { id: number }) => {
    router.visit(`/company/crm/leads/${lead.id}`);
  };

  // Filter assigned leads locally for instant search
  const filteredAssignedLeads = (workload?.assigned_leads || []).filter((lead) => {
    const matchesSearch =
      !searchQuery ||
      lead.company_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      lead.contact_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      (lead.phone && lead.phone.includes(searchQuery));
    const matchesStage = selectedStage === 'all' || lead.stage_key === selectedStage;
    return matchesSearch && matchesStage;
  });

  /*
   * Figures earn their place by being non-zero.
   *
   * On a clear morning this row read "0 / 0 / 0" across the most prominent
   * line on the screen, repeating what the empty sections below already said.
   */
  const headStats = [
    /*
     * The cockpit's own four counters, always on — including at zero —
     * because "how many calls are due" is what this row exists to answer,
     * not a figure that only shows up on a busy day. Approvals is the one
     * exception: it stays off this row entirely for anyone without approval
     * authority, the same way "Leads assigned to you" stays off for someone
     * with none — a count nobody here can act on is not information.
     */
    <CrmPageStat
      key="calls-due"
      icon={Phone}
      value={totalUrgentFollowups}
      chip={counts.overdue_followups > 0 ? `${counts.overdue_followups} overdue` : undefined}
      chipHue="#EF5B39"
      caption="Calls due today"
    />,
    <CrmPageStat key="demos-today" icon={Video} value={demosToday} caption="Demos today" />,
    <CrmPageStat
      key="expiring-trials"
      icon={AlertTriangle}
      value={expiringTrials48h}
      chipHue={expiringTrials48h > 0 ? '#EF5B39' : undefined}
      caption="Trials expiring in 48h"
    />,
    can_approve_extensions && (
      <CrmPageStat key="approvals-pending" icon={ShieldAlert} value={pending_approvals.count} caption="Approvals pending" />
    ),
    (todays_focus?.total ?? 0) > 0 && (
      <CrmPageStat key="focus" icon={Target} value={todays_focus?.total ?? 0} caption="On today's queue" />
    ),
    counts.total_assigned_leads > 0 && (
      <CrmPageStat key="leads" icon={Users} value={counts.total_assigned_leads} caption="Leads assigned to you" />
    ),
    tasks.length > 0 && (
      <CrmPageStat key="tasks" icon={CheckCircle2} value={tasks.length} caption="Open tasks" />
    ),
  ].filter(Boolean);

  return (
    <CrmShell title="My work">
      <CrmPageHead
        eyebrow="Sales"
        title="My work"
        stats={headStats.length > 0 ? <>{headStats}</> : undefined}
        // Deals directly, not through the /company/crm/leads redirect.
        actions={
          <CrmButton icon={Users} href="/company/crm/deals">
            All deals
          </CrmButton>
        }
      />


      <div className="space-y-6 pb-12">
        {/*
          * Unclaimed leads open the page only when there is nothing of your own
          * to do.
          *
          * Putting them first unconditionally was wrong: a page called "My work"
          * that greets you with other people's unowned leads is not your work,
          * and it pushed the thing you actually came for below the fold. When
          * you do have a day's work, this belongs after it — still visible,
          * because an unowned lead going cold is worth knowing about, just not
          * ahead of your own.
          */}
        {unclaimed && hasNothingAssigned && (
          <MyWorkUnclaimed unclaimed={unclaimed} currentUserId={currentUserId ?? null} />
        )}

        {/* TODAY'S FOCUS — the single prioritised queue for the day */}
        {todays_focus && (
          <Card className="p-5">
            <div className="mb-4 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Target className="h-4 w-4 text-ovs-crm-text-primary" />
                <h2 className="text-[15px] font-semibold text-ovs-crm-text-primary">Today's Focus</h2>
              </div>
              {! todays_focus.is_clear && (
                <Badge variant="warning">
                  {todays_focus.total} item{todays_focus.total === 1 ? '' : 's'}
                </Badge>
              )}
            </div>

            {todays_focus.is_clear ? (
              <p className="text-[13px] text-ovs-crm-text-secondary">
                Nothing is overdue or due today. Your queue is clear.
              </p>
            ) : (
              <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
                {todays_focus.items.map((item, index) => (
                  <Link
                    key={`${item.kind}-${item.lead_id}-${index}`}
                    href={item.lead_id ? `/company/crm/leads/${item.lead_id}` : '#'}
                    className={cn(
                      'flex items-start justify-between gap-3 rounded-[16px] border border-ovs-crm-border border-l-4 px-3 py-2.5 transition-colors hover:border-ovs-crm-border',
                      FOCUS_TONE[item.kind] ?? 'border-l-ovs-crm-text-secondary',
                    )}
                  >
                    <div className="min-w-0">
                      <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">{item.title}</p>
                      <p className="text-[11.5px] text-ovs-crm-text-secondary">{item.reason}</p>
                    </div>
                    <span className="shrink-0 text-[11.5px] text-ovs-crm-text-secondary">{item.detail}</span>
                  </Link>
                ))}
              </div>
            )}
          </Card>
        )}

        {/* UPCOMING + MY TASKS */}
        <div className={cn('grid grid-cols-1 gap-6', upcomingWithoutTasks.length > 0 && 'lg:grid-cols-2')}>
          <Card className={cn('p-5', upcomingWithoutTasks.length === 0 && 'hidden')}>
            <div className="mb-4 flex items-center gap-2">
              <Calendar className="h-4 w-4 text-ovs-crm-text-primary" />
              <h2 className="text-[15px] font-semibold text-ovs-crm-text-primary">Upcoming</h2>
            </div>
            {upcomingWithoutTasks.length === 0 ? (
              <p className="text-[13px] text-ovs-crm-text-secondary">Nothing scheduled in the next two weeks.</p>
            ) : (
              <div className="space-y-2">
                {upcomingWithoutTasks.slice(0, 12).map((item, index) => (
                  <Link
                    key={`${item.kind}-${index}`}
                    href={item.lead_id ? `/company/crm/leads/${item.lead_id}` : '#'}
                    className="flex items-center justify-between gap-3 rounded-[16px] border border-ovs-crm-border px-3 py-2 hover:border-ovs-crm-border"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-[13px] text-ovs-crm-text-primary">{item.title}</p>
                      <p className="text-[11.5px] text-ovs-crm-text-secondary">{UPCOMING_LABEL[item.kind] ?? item.kind}</p>
                    </div>
                    <span className={cn('shrink-0 text-[11.5px]', item.is_today ? 'font-semibold text-ovs-crm-text-primary' : 'text-ovs-crm-text-secondary')}>
                      {item.is_today ? 'Today' : item.date_label}
                    </span>
                  </Link>
                ))}
              </div>
            )}
          </Card>

          {/*
            * Tasks live here now, and they are editable here.
            *
            * There used to be a separate Tasks page with its own filter bar,
            * its own table and its own create button, while this card showed
            * a read-only copy of the same rows — and quietly stopped at
            * twelve, so anyone with more than that was never told the rest
            * existed. One place, the full list, and the create and edit that
            * used to require going somewhere else.
            */}
          <Card className="p-5">
            <div className="mb-4 flex items-center justify-between gap-3">
              <div className="flex items-center gap-2">
                <CheckCircle2 className="h-4 w-4 text-ovs-crm-text-primary" />
                <h2 className="text-[15px] font-semibold text-ovs-crm-text-primary">My tasks</h2>
              </div>
              <CrmButton
                size="sm"
                tone="primary"
                icon={Plus}
                onClick={() => {
                  setEditingTask(null);
                  setTaskModalOpen(true);
                }}
              >
                New task
              </CrmButton>
            </div>

            {/*
              * Kind and urgency, which the retired Tasks page filtered on.
              *
              * Filtered here rather than on the server: this list is already
              * only your open tasks and is capped, so narrowing it is a
              * question about rows that are on the page. Status is not offered
              * because there is nothing to choose — the payload is open tasks
              * by definition, and a "Completed" option that always returned
              * nothing would be a worse answer than no option.
              */}
            {tasks.length > 0 && (
              <div className="mb-3 flex flex-wrap items-center gap-2">
                <CrmSelect
                  value={taskType}
                  onChange={(e) => setTaskType(e.target.value)}
                  aria-label="Filter tasks by kind"
                  className="h-8 min-w-[130px] text-[12px]"
                >
                  <option value="all">Any kind</option>
                  <option value="call">Call</option>
                  <option value="email">Email</option>
                  <option value="demo">Demo</option>
                  <option value="follow_up">Follow-up</option>
                  <option value="to_do">To-do</option>
                </CrmSelect>

                <CrmSelect
                  value={taskPriority}
                  onChange={(e) => setTaskPriority(e.target.value)}
                  aria-label="Filter tasks by priority"
                  className="h-8 min-w-[130px] text-[12px]"
                >
                  <option value="all">Any priority</option>
                  <option value="high">High</option>
                  <option value="medium">Medium</option>
                  <option value="low">Low</option>
                </CrmSelect>

                {(taskType !== 'all' || taskPriority !== 'all') && (
                  <button
                    type="button"
                    onClick={() => {
                      setTaskType('all');
                      setTaskPriority('all');
                    }}
                    className="text-[12px] text-ovs-crm-text-muted underline underline-offset-2 hover:text-ovs-crm-text-primary"
                  >
                    Clear
                  </button>
                )}
              </div>
            )}

            {tasks.length === 0 ? (
              <p className="text-[13px] text-ovs-crm-text-secondary">
                Nothing on your list. Schedule a follow-up against a deal, or add one here.
              </p>
            ) : visibleTasks.length === 0 ? (
              <p className="text-[13px] text-ovs-crm-text-secondary">
                No open task matches that filter.
              </p>
            ) : (
              <div className="space-y-2">
                {visibleTasks.map((task) => (
                  <div
                    key={task.id}
                    role="button"
                    tabIndex={0}
                    onClick={() => {
                      setEditingTask(task as unknown as EditableTask);
                      setTaskModalOpen(true);
                    }}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        setEditingTask(task as unknown as EditableTask);
                        setTaskModalOpen(true);
                      }
                    }}
                    className="flex cursor-pointer items-center justify-between gap-3 rounded-[16px] border border-ovs-crm-border px-3 py-2 transition hover:border-ovs-crm-accent/40 hover:bg-ovs-crm-surface-2"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-[13px] text-ovs-crm-text-primary">{task.title}</p>
                      {/* `follow_up` is a column value, not a word. */}
                      <p className="text-[11.5px] text-ovs-crm-text-secondary">{humanize(task.type)}</p>
                    </div>
                    <Badge variant={task.is_overdue ? 'danger' : task.is_due_today ? 'warning' : 'neutral'}>
                      {task.is_overdue ? 'Overdue' : task.due_label}
                    </Badge>
                  </div>
                ))}
              </div>
            )}
          </Card>
        </div>

        {/*
          * Everyone else's open tasks.
          *
          * The standalone Tasks page showed an admin every task in the
          * company. Folding it in here would have dropped that view, so it
          * comes along — collapsed, below your own, and absent entirely for
          * anyone whose tasks are only their own.
          */}
        {other_tasks.length > 0 && (
          <CrmDisclosure
            label="Other people's tasks"
            count={other_tasks.length}
            hint="Open across the team, including unassigned"
          >
            <div className="space-y-2">
              {other_tasks.map((task) => (
                <div
                  key={task.id}
                  className="flex items-center justify-between gap-3 rounded-[16px] border border-ovs-crm-border px-3 py-2"
                >
                  <div className="min-w-0">
                    <p className="truncate text-[13px] text-ovs-crm-text-primary">{task.title}</p>
                    <p className="text-[11.5px] text-ovs-crm-text-secondary">
                      {[humanize(task.type), task.owner ?? 'Nobody assigned'].join(' · ')}
                    </p>
                  </div>
                  <Badge variant={task.is_overdue ? 'danger' : 'neutral'}>
                    {task.is_overdue ? 'Overdue' : task.due_label}
                  </Badge>
                </div>
              ))}
            </div>
          </CrmDisclosure>
        )}

        {/*
          * The workload, split by the kind of thing it is. Counts come straight
          * off `workload.counts`, so a tab and the list under it always agree.
          */}
        {/*
          * The queue lists and what you have already done, side by side.
          *
          * The log used to sit alone at the foot of the page — the last thing
          * the eye landed on, and the least useful. Beside the lists it is
          * what it should be: reference you glance at while working, not a
          * destination.
          */}
        <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-12">
          {/*
            * The pairing only exists while there is something to pair with.
            *
            * With every queue empty the strip and its panels render nothing, so
            * a fixed 8/4 split left two thirds of the row blank and pushed the
            * activity log into a narrow column beside dead space. When there is
            * no list, the log simply takes the width.
            */}
          <div className={cn('space-y-6', visibleTabs.length > 0 ? 'lg:col-span-8' : 'hidden')}>
          {/*
            * The strip disappears entirely when every queue is empty, rather than
            * rendering a row of controls that can only open nothing.
            */}
          {visibleTabs.length > 0 && (
            <CrmTabs value={activeTab} onChange={(key) => setActiveTab(key)} tabs={visibleTabs} />
          )}

          {/* ------------------------------------------------------------- */}
          {/* TAB 0: PENDING MANAGEMENT APPROVALS */}
          {/* ------------------------------------------------------------- */}
          {visibleTabs.length > 0 && activeTab === 'approvals' && (
            <ApprovalsTab
              items={pending_approvals.items}
              onApprove={approveApproval}
              onReject={(item) => setRejectingApproval(item)}
            />
          )}

          {/* ------------------------------------------------------------- */}
          {/* TAB 1: FOLLOW-UPS & OVERDUE QUEUE */}
          {/* ------------------------------------------------------------- */}
          {visibleTabs.length > 0 && activeTab === 'followups' && (
            <div className="space-y-6">
              {/* OVERDUE FOLLOW-UPS */}
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <span className="w-2.5 h-2.5 rounded-full bg-ovs-crm-danger" />
                    <h3 className="text-[13px] font-bold text-ovs-crm-text-primary uppercase tracking-wider">
                      Overdue Follow-ups ({workload.followups.overdue.length})
                    </h3>
                  </div>
                  {workload.followups.overdue.length > 0 && (
                    <Badge variant="danger">Requires Immediate Contact</Badge>
                  )}
                </div>

                {workload.followups.overdue.length === 0 ? (
                  <Card elevation="surface" className="p-5 text-center text-ovs-crm-text-secondary text-[13px] border-dashed">
                    <div className="flex items-center justify-center gap-2 text-ovs-crm-success font-medium">
                      <CheckCircle2 className="w-4 h-4" />
                      <span>Great job! You have no overdue follow-ups.</span>
                    </div>
                  </Card>
                ) : (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {workload.followups.overdue.map((item) => (
                      <Card
                        key={item.id}
                        elevation="surface"
                        className="p-4 border-l-4 border-l-rose-500 flex flex-col justify-between gap-3 hover:border-ovs-crm-border-strong transition-all"
                      >
                        <div className="space-y-1.5">
                          <div className="flex items-start justify-between gap-2">
                            <div>
                              <button
                                type="button"
                                onClick={() => openLead(item)}
                                className="text-[15px] font-bold text-ovs-crm-text-primary hover:text-ovs-crm-brand transition-colors text-left"
                              >
                                {item.company_name}
                              </button>
                              <p className="text-[11.5px] text-ovs-crm-text-secondary font-medium">
                                {item.contact_name} {item.phone && `• ${item.phone}`} {item.city && `• ${item.city}`}
                              </p>
                            </div>
                            <Badge variant="danger">{item.time_ago}</Badge>
                          </div>

                          <div className="text-[11.5px] text-ovs-crm-text-secondary bg-ovs-crm-danger-soft/50 p-2 rounded border border-ovs-crm-danger-soft line-clamp-2">
                            {item.notes}
                          </div>
                        </div>

                        <div className="flex items-center justify-between pt-2 border-t border-ovs-crm-border gap-2">
                          <span className="text-[11.5px] font-semibold text-ovs-crm-text-secondary uppercase">
                            Stage: {item.stage}
                          </span>
                          <div className="flex flex-wrap items-center gap-1.5">
                            {item.phone && (
                              <a
                                href={`tel:${item.phone}`}
                                className="h-7 px-2.5 text-[11.5px] font-semibold rounded bg-ovs-crm-success-soft text-ovs-crm-success-text hover:bg-ovs-crm-success-soft flex items-center gap-1 transition-colors"
                              >
                                <Phone className="w-3 h-3" />
                                <span>Call</span>
                              </a>
                            )}
                            {waNumber(item.phone) && (
                              <a
                                href={`https://wa.me/${waNumber(item.phone)}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="h-7 px-2.5 text-[11.5px] font-semibold rounded bg-[#25D366]/15 text-[#0f7a3d] hover:bg-[#25D366]/25 flex items-center gap-1 transition-colors"
                              >
                                <MessageSquare className="w-3 h-3" />
                                <span>WhatsApp</span>
                              </a>
                            )}
                            <button
                              type="button"
                              onClick={() => markFollowupDone(item.lead_id)}
                              className="h-7 px-2.5 text-[11.5px] font-semibold rounded bg-ovs-crm-border text-ovs-crm-text-primary hover:bg-ovs-crm-border-strong flex items-center gap-1 transition-colors"
                            >
                              <CheckCircle2 className="w-3 h-3" />
                              <span>Mark Done</span>
                            </button>
                            <Link
                              href={`/company/crm/leads/${item.lead_id}`}
                              className="h-7 px-2.5 text-[11.5px] font-semibold rounded bg-ovs-crm-brand-soft text-ovs-crm-brand-hover hover:bg-ovs-crm-brand-soft flex items-center gap-1 transition-colors"
                            >
                              <span>Open Lead</span>
                              <ChevronRight className="w-3 h-3" />
                            </Link>
                          </div>
                        </div>
                      </Card>
                    ))}
                  </div>
                )}
              </div>

              {/* DUE TODAY FOLLOW-UPS */}
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <span className="w-2.5 h-2.5 rounded-full bg-ovs-crm-warning" />
                    <h3 className="text-[13px] font-bold text-ovs-crm-text-primary uppercase tracking-wider">
                      Due Today ({workload.followups.due_today.length})
                    </h3>
                  </div>
                </div>

                {workload.followups.due_today.length === 0 ? (
                  <Card elevation="surface" className="p-5 text-center text-ovs-crm-text-secondary text-[13px] border-dashed">
                    <span>No more follow-ups scheduled for today.</span>
                  </Card>
                ) : (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {workload.followups.due_today.map((item) => (
                      <Card
                        key={item.id}
                        elevation="surface"
                        className="p-4 border-l-4 border-l-amber-500 flex flex-col justify-between gap-3 hover:border-ovs-crm-border-strong transition-all"
                      >
                        <div className="space-y-1.5">
                          <div className="flex items-start justify-between gap-2">
                            <div>
                              <button
                                type="button"
                                onClick={() => openLead(item)}
                                className="text-[15px] font-bold text-ovs-crm-text-primary hover:text-ovs-crm-brand transition-colors text-left"
                              >
                                {item.company_name}
                              </button>
                              <p className="text-[11.5px] text-ovs-crm-text-secondary font-medium">
                                {item.contact_name} {item.phone && `• ${item.phone}`} {item.city && `• ${item.city}`}
                              </p>
                            </div>
                            <Badge variant="warning">Today</Badge>
                          </div>

                          <div className="text-[11.5px] text-ovs-crm-text-secondary bg-ovs-crm-warning-soft/50 p-2 rounded border border-ovs-crm-warning-soft line-clamp-2">
                            {item.notes}
                          </div>
                        </div>

                        <div className="flex items-center justify-between pt-2 border-t border-ovs-crm-border gap-2">
                          <span className="text-[11.5px] font-semibold text-ovs-crm-text-secondary uppercase">
                            Stage: {item.stage}
                          </span>
                          <div className="flex flex-wrap items-center gap-1.5">
                            {item.phone && (
                              <a
                                href={`tel:${item.phone}`}
                                className="h-7 px-2.5 text-[11.5px] font-semibold rounded bg-ovs-crm-success-soft text-ovs-crm-success-text hover:bg-ovs-crm-success-soft flex items-center gap-1 transition-colors"
                              >
                                <Phone className="w-3 h-3" />
                                <span>Call</span>
                              </a>
                            )}
                            {waNumber(item.phone) && (
                              <a
                                href={`https://wa.me/${waNumber(item.phone)}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="h-7 px-2.5 text-[11.5px] font-semibold rounded bg-[#25D366]/15 text-[#0f7a3d] hover:bg-[#25D366]/25 flex items-center gap-1 transition-colors"
                              >
                                <MessageSquare className="w-3 h-3" />
                                <span>WhatsApp</span>
                              </a>
                            )}
                            <button
                              type="button"
                              onClick={() => markFollowupDone(item.lead_id)}
                              className="h-7 px-2.5 text-[11.5px] font-semibold rounded bg-ovs-crm-border text-ovs-crm-text-primary hover:bg-ovs-crm-border-strong flex items-center gap-1 transition-colors"
                            >
                              <CheckCircle2 className="w-3 h-3" />
                              <span>Mark Done</span>
                            </button>
                            <Link
                              href={`/company/crm/leads/${item.lead_id}`}
                              className="h-7 px-2.5 text-[11.5px] font-semibold rounded bg-ovs-crm-border text-ovs-crm-text-primary hover:bg-ovs-crm-border flex items-center gap-1 transition-colors"
                            >
                              <span>Open</span>
                              <ChevronRight className="w-3 h-3" />
                            </Link>
                          </div>
                        </div>
                      </Card>
                    ))}
                  </div>
                )}
              </div>

              {/* UPCOMING FOLLOW-UPS */}
              {workload.followups.upcoming.length > 0 && (
                <div className="space-y-3">
                  <h3 className="text-[13px] font-bold text-ovs-crm-text-primary uppercase tracking-wider">
                    Upcoming (Next 7 Days) ({workload.followups.upcoming.length})
                  </h3>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                    {workload.followups.upcoming.map((item) => (
                      <Card key={item.id} elevation="surface" className="p-3.5 space-y-2">
                        <div className="flex items-start justify-between gap-1">
                          <button
                            type="button"
                            onClick={() => openLead(item)}
                            className="font-bold text-[13px] text-ovs-crm-text-primary hover:text-ovs-crm-brand text-left line-clamp-1"
                          >
                            {item.company_name}
                          </button>
                          <span className="text-[11.5px] font-medium text-ovs-crm-text-secondary whitespace-nowrap">
                            {item.due_date}
                          </span>
                        </div>
                        <p className="text-[11.5px] text-ovs-crm-text-secondary line-clamp-1">
                          {item.contact_name} • {item.stage}
                        </p>
                      </Card>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          {/* ------------------------------------------------------------- */}
          {/* TAB 2: DEMOS */}
          {/* ------------------------------------------------------------- */}
          {visibleTabs.length > 0 && activeTab === 'demos' && (
            <DemoTab
              demos={remoteDemos}
              heading="Today's demos"
              emptyTitle="No online demos scheduled for you."
              emptyHint="A demo is scheduled from the deal it belongs to."
              onReschedule={setReschedulingDemo}
            />
          )}

          {visibleTabs.length > 0 && activeTab === 'field_visits' && (
            <DemoTab
              demos={fieldVisits}
              heading="Field visits"
              emptyTitle="No clinic visits scheduled for you."
              emptyHint="Book one from a deal and choose &lsquo;In person&rsquo;."
              onReschedule={setReschedulingDemo}
            />
          )}
          {/* ------------------------------------------------------------- */}
          {/* TAB 3: ACTIVE TRIALS */}
          {/* ------------------------------------------------------------- */}
          {visibleTabs.length > 0 && activeTab === 'trials' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <h3 className="text-[13px] font-bold text-ovs-crm-text-primary uppercase tracking-wider">
                  My Active Trials ({workload.trials.length})
                </h3>
              </div>

              {workload.trials.length === 0 ? (
                <Card elevation="surface" className="p-8 text-center text-ovs-crm-text-secondary space-y-2">
                  <Layers className="w-8 h-8 mx-auto text-ovs-crm-text-secondary opacity-50" />
                  <p className="text-[13px] font-medium">No active trials assigned to you currently.</p>
                </Card>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {workload.trials.map((trial) => (
                    <Card key={trial.id} elevation="surface" className="p-4 space-y-3">
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <button
                            type="button"
                            onClick={() => openLead(trial)}
                            className="text-[15px] font-bold text-ovs-crm-text-primary hover:text-ovs-crm-brand text-left"
                          >
                            {trial.company_name}
                          </button>
                          <p className="text-[11.5px] text-ovs-crm-text-secondary font-medium">
                            {trial.contact_name} {trial.phone && `• ${trial.phone}`}
                          </p>
                        </div>
                        <Badge variant={trial.is_expiring_48h ? 'danger' : trial.is_ending_soon ? 'warning' : 'success'}>
                          {trial.is_expiring_48h ? `${trial.hours_remaining}h left` : `${trial.days_remaining} Days Left`}
                        </Badge>
                      </div>

                      <div className="space-y-1">
                        <div className="flex justify-between text-[11.5px] font-semibold text-ovs-crm-text-secondary">
                          <span>Usage Telemetry</span>
                          <span>{trial.usage_percent}%</span>
                        </div>
                        <div className="w-full h-2 rounded-full bg-ovs-crm-border overflow-hidden">
                          <div
                            className="h-full bg-ovs-crm-brand rounded-full transition-all"
                            style={{ width: `${Math.min(100, trial.usage_percent)}%` }}
                          />
                        </div>
                        {trial.telemetry && (
                          <div className="grid grid-cols-3 gap-1.5 pt-1 text-center">
                            <div className="rounded bg-ovs-crm-surface-inset py-1">
                              <div className="text-[13px] font-bold text-ovs-crm-text-primary">{trial.telemetry.patients}</div>
                              <div className="text-[10px] uppercase text-ovs-crm-text-secondary">Patients</div>
                            </div>
                            <div className="rounded bg-ovs-crm-surface-inset py-1">
                              <div className="text-[13px] font-bold text-ovs-crm-text-primary">{trial.telemetry.prescriptions}</div>
                              <div className="text-[10px] uppercase text-ovs-crm-text-secondary">Rx</div>
                            </div>
                            <div className="rounded bg-ovs-crm-surface-inset py-1">
                              <div className="text-[13px] font-bold text-ovs-crm-text-primary">{trial.telemetry.invoices}</div>
                              <div className="text-[10px] uppercase text-ovs-crm-text-secondary">Invoices</div>
                            </div>
                          </div>
                        )}
                      </div>

                      <div className="text-[11.5px] text-ovs-crm-text-secondary bg-ovs-crm-surface-inset p-2 rounded">
                        <strong className="text-ovs-crm-text-primary font-bold">Next Action:</strong>{' '}
                        {trial.next_action}
                      </div>

                      <div className="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-ovs-crm-border">
                        <span className="text-[11.5px] text-ovs-crm-text-secondary">
                          Expires: {trial.end_date}
                        </span>
                        <div className="flex items-center gap-1.5">
                          <Link
                            href={`/company/crm/leads/${trial.lead_id}`}
                            className="h-8 px-3 text-[11.5px] font-semibold rounded-[16px] bg-ovs-crm-border text-ovs-crm-text-primary hover:bg-ovs-crm-border-strong flex items-center gap-1 transition-colors"
                          >
                            <span>View Trial 360</span>
                          </Link>
                          <Link
                            href={`/company/crm/leads/${trial.lead_id}`}
                            className="h-8 px-3 text-[11.5px] font-semibold rounded-[16px] bg-ovs-crm-brand-soft text-ovs-crm-brand-hover hover:bg-ovs-crm-brand-soft flex items-center gap-1 transition-colors"
                          >
                            <span>Extend Request</span>
                            <ChevronRight className="w-3.5 h-3.5" />
                          </Link>
                        </div>
                      </div>
                    </Card>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* ------------------------------------------------------------- */}
          {/* TAB 4: QUOTATIONS */}
          {/* ------------------------------------------------------------- */}
          {visibleTabs.length > 0 && activeTab === 'quotations' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                {/*
                  * There is no "new quotation" button here any more.
                  *
                  * It navigated to /company/crm/quotations?create=1, and nothing
                  * reads that parameter — the Commercials list has no
                  * create-from-query handling — so it opened the list and
                  * stopped. A quotation is raised against a deal, and this page
                  * has no deal in hand; every row below links to the deal where
                  * the action actually lives.
                  */}
                <h3 className="text-[13px] font-bold text-ovs-crm-text-primary uppercase tracking-wider">
                  My Quotations ({workload.quotations.length})
                </h3>
              </div>

              {workload.quotations.length === 0 ? (
                <Card elevation="surface" className="p-8 text-center text-ovs-crm-text-secondary space-y-2">
                  <FileText className="w-8 h-8 mx-auto text-ovs-crm-text-secondary opacity-50" />
                  <p className="text-[13px] font-medium">No quotations currently in progress.</p>
                </Card>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  {workload.quotations.map((quote) => (
                    <Card key={quote.id} elevation="surface" className="p-4 space-y-2.5">
                      <div className="flex items-start justify-between gap-1">
                        <span className="text-[11.5px] font-mono font-bold text-ovs-crm-text-secondary">
                          {quote.quotation_number} (v{quote.version})
                        </span>
                        <Badge
                          variant={
                            quote.status === 'approved'
                              ? 'success'
                              : quote.is_expiring_soon
                              ? 'danger'
                              : 'brand'
                          }
                        >
                          {quote.status.toUpperCase()}
                        </Badge>
                      </div>

                      <button
                        type="button"
                        onClick={() => openLead(quote)}
                        className="text-[15px] font-bold text-ovs-crm-text-primary hover:text-ovs-crm-brand text-left line-clamp-1 block"
                      >
                        {quote.company_name}
                      </button>

                      <div className="text-[18px] font-extrabold text-ovs-crm-text-primary font-mono">
                        ₹{quote.grand_total.toLocaleString('en-IN')}
                      </div>

                      {quote.valid_until && (
                        <p className="text-[11.5px] text-ovs-crm-text-secondary">
                          Valid until: {quote.valid_until}
                        </p>
                      )}

                      <div className="pt-2 border-t border-ovs-crm-border flex items-center justify-between">
                        <Link
                          href={`/company/crm/leads/${quote.lead_id}`}
                          className="text-[11.5px] font-semibold text-ovs-crm-brand hover:text-ovs-crm-brand-hover flex items-center gap-1"
                        >
                          <span>View Deal</span>
                          <ChevronRight className="w-3 h-3" />
                        </Link>
                      </div>
                    </Card>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* ------------------------------------------------------------- */}
          {/* TAB 5: NEEDS NEXT ACTION */}
          {/* ------------------------------------------------------------- */}
          {visibleTabs.length > 0 && activeTab === 'needs_action' && (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-[13px] font-bold text-ovs-crm-text-primary uppercase tracking-wider">
                    Active Leads Without Next Action ({workload.needs_next_action.length})
                  </h3>
                  <p className="text-[11.5px] text-ovs-crm-text-secondary">
                    These active assigned leads do not have a scheduled follow-up date. Set one immediately to maintain pipeline velocity.
                  </p>
                </div>
              </div>

              {workload.needs_next_action.length === 0 ? (
                <Card elevation="surface" className="p-8 text-center text-ovs-crm-text-secondary space-y-2">
                  <CheckCircle2 className="w-8 h-8 mx-auto text-ovs-crm-success" />
                  <p className="text-[13px] font-medium text-ovs-crm-success-text">
                    Every active assigned lead has a scheduled next action!
                  </p>
                </Card>
              ) : (
                <Table density="default">
                  <TableHeader>
                    <TableRow isClickable={false}>
                      <TableHead className="min-w-[240px]">Clinic / Lead</TableHead>
                      <TableHead className="w-[180px]">Contact</TableHead>
                      <TableHead className="w-[140px]">Stage</TableHead>
                      <TableHead className="w-[140px]">Last Contact</TableHead>
                      <TableHead align="right" className="w-[160px]">Action</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {workload.needs_next_action.map((lead) => (
                      <TableRow key={lead.id} isClickable>
                        <TableCell>
                          <button
                            type="button"
                            onClick={() => openLead(lead)}
                            className="hover:text-ovs-crm-accent-teal-text text-left font-bold text-ovs-crm-text-primary block"
                          >
                            <TablePrimaryEntity
                              title={lead.company_name}
                              subtitle={`Lead #${lead.id}`}
                            />
                          </button>
                        </TableCell>
                        <TableCell className="text-[11.5px] text-ovs-crm-text-secondary font-normal">
                          {lead.contact_name} {lead.phone && `• ${lead.phone}`}
                        </TableCell>
                        <TableCell>
                          <Badge variant="neutral">{lead.stage}</Badge>
                        </TableCell>
                        <TableCell className="text-[11.5px] text-ovs-crm-text-secondary font-normal">
                          {lead.last_contact}
                        </TableCell>
                        <TableCell align="right">
                          <Link
                            href={`/company/crm/leads/${lead.id}`}
                            className="inline-flex items-center gap-1 px-2.5 py-1 text-[11.5px] font-semibold rounded-[16px] bg-ovs-crm-text-primary hover:bg-ovs-crm-text-primary text-white transition-colors"
                          >
                            <Calendar className="w-3 h-3" />
                            <span>Schedule Follow-up</span>
                          </Link>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </div>
          )}

          {/* ------------------------------------------------------------- */}
          {/* TAB 6: WIN-BACKS */}
          {/* ------------------------------------------------------------- */}
          {visibleTabs.length > 0 && activeTab === 'winbacks' && (
            <div className="space-y-4">
              <h3 className="text-[13px] font-bold text-ovs-crm-text-primary uppercase tracking-wider">
                Win-back Re-engagements ({workload.winbacks.length})
              </h3>

              {workload.winbacks.length === 0 ? (
                <Card elevation="surface" className="p-8 text-center text-ovs-crm-text-secondary space-y-2">
                  <RefreshCw className="w-8 h-8 mx-auto text-ovs-crm-text-secondary opacity-50" />
                  <p className="text-[13px] font-medium">No lost leads currently assigned for win-back.</p>
                </Card>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  {workload.winbacks.map((item) => (
                    <Card key={item.id} elevation="surface" className="p-4 space-y-2.5">
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <button
                            type="button"
                            onClick={() => openLead(item)}
                            className="text-[15px] font-bold text-ovs-crm-text-primary hover:text-ovs-crm-brand text-left"
                          >
                            {item.company_name}
                          </button>
                          <p className="text-[11.5px] text-ovs-crm-text-secondary font-medium">
                            {item.contact_name} {item.phone && `• ${item.phone}`}
                          </p>
                        </div>
                        <Badge variant="neutral">Lost Re-engagement</Badge>
                      </div>

                      <p className="text-[11.5px] text-ovs-crm-text-secondary bg-ovs-crm-surface-inset p-2 rounded">
                        <strong className="text-ovs-crm-text-primary font-bold">Loss Reason:</strong>{' '}
                        {item.loss_reason}
                      </p>

                      <div className="pt-2 border-t border-ovs-crm-border flex items-center justify-between">
                        <span className="text-[11.5px] text-ovs-crm-text-secondary">
                          Win-back Date: {item.scheduled_winback}
                        </span>
                        <Link
                          href={`/company/crm/leads/${item.id}`}
                          className="h-7 px-3 text-[11.5px] font-semibold rounded-[16px] bg-ovs-crm-brand-soft text-ovs-crm-brand-hover hover:bg-ovs-crm-brand-soft flex items-center gap-1 transition-colors"
                        >
                          <span>Re-engage</span>
                          <ChevronRight className="w-3 h-3" />
                        </Link>
                      </div>
                    </Card>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* ------------------------------------------------------------- */}
          {/* TAB 7: ALL ASSIGNED LEADS */}
          {/* ------------------------------------------------------------- */}
          {visibleTabs.length > 0 && activeTab === 'leads' && (
            <div className="space-y-4">
              {/* Search & Stage Filter Bar */}
              <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-ovs-crm-surface p-3 rounded-[16px] shadow-ovs-crmPanel">
                <div className="relative w-full sm:w-80">
                  <Search className="w-4 h-4 text-ovs-crm-text-secondary absolute left-3 top-1/2 -translate-y-1/2" />
                  <input
                    type="text"
                    placeholder="Search my leads by name, contact, phone..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full pl-9 pr-3 py-1.5 text-[11.5px] bg-ovs-crm-surface border border-ovs-crm-border rounded-[16px] focus:outline-none focus:ring-1 focus:ring-ovs-crm-brand font-sans"
                  />
                </div>

                <div className="flex items-center gap-2 w-full sm:w-auto">
                  <Filter className="w-3.5 h-3.5 text-ovs-crm-text-secondary" />
                  <select
                    value={selectedStage}
                    onChange={(e) => setSelectedStage(e.target.value)}
                    className="text-[11.5px] bg-ovs-crm-surface border border-ovs-crm-border rounded-[16px] px-2.5 py-1.5 focus:outline-none focus:ring-1 focus:ring-ovs-crm-brand font-medium text-ovs-crm-text-secondary"
                  >
                    <option value="all">All Stages</option>
                    <option value="lead">New Lead</option>
                    <option value="qualified">Qualified</option>
                    <option value="demo_scheduled">Demo Scheduled</option>
                    <option value="demo_completed">Demo Done</option>
                    <option value="quotation">Quotation Sent</option>
                    <option value="trial">Active Trial</option>
                  </select>
                </div>
              </div>

              {filteredAssignedLeads.length === 0 ? (
                <Card elevation="surface" className="p-8 text-center text-ovs-crm-text-secondary space-y-2">
                  <UserCheck className="w-8 h-8 mx-auto text-ovs-crm-text-secondary opacity-50" />
                  <p className="text-[13px] font-medium">No leads match your filter criteria.</p>
                </Card>
              ) : (
                <Table density="default">
                  <TableHeader>
                    <TableRow isClickable={false}>
                      <TableHead className="min-w-[240px]">Clinic / Lead</TableHead>
                      <TableHead className="w-[180px]">Primary Contact</TableHead>
                      <TableHead className="w-[140px]">Stage</TableHead>
                      <TableHead align="center" className="w-[130px]">AI Health</TableHead>
                      <TableHead className="w-[150px]">Next Action Due</TableHead>
                      <TableHead align="right" className="w-[160px]">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {filteredAssignedLeads.map((lead) => (
                      <TableRow key={lead.id} isClickable>
                        <TableCell>
                          <Link
                            href={`/company/crm/leads/${lead.id}`}
                            className="hover:text-ovs-crm-accent-teal-text transition-colors block"
                          >
                            <TablePrimaryEntity
                              title={lead.company_name}
                              subtitle={`Lead #${lead.id}`}
                            />
                          </Link>
                        </TableCell>
                        <TableCell className="text-[11.5px] text-ovs-crm-text-secondary font-normal">
                          {lead.contact_name} {lead.phone && `• ${lead.phone}`}
                        </TableCell>
                        <TableCell>
                          <Badge variant="neutral">{lead.stage}</Badge>
                        </TableCell>
                        <TableCell align="center">
                          <span
                            className={cn(
                              'px-2.5 py-0.5 rounded-full font-mono font-bold text-[11.5px] tabular-nums',
                              lead.health_score >= 80
                                ? 'bg-ovs-crm-success-soft text-ovs-crm-success-text border border-ovs-crm-success-border'
                                : lead.health_score >= 50
                                ? 'bg-ovs-crm-warning-soft text-ovs-crm-warning-text border border-ovs-crm-warning-border'
                                : 'bg-ovs-crm-danger-soft text-ovs-crm-danger-text border border-ovs-crm-danger-border',
                            )}
                          >
                            {lead.health_score}/100
                          </span>
                        </TableCell>
                        <TableCell className="text-[11.5px] font-normal text-ovs-crm-text-secondary">
                          {lead.next_followup_at || (
                            <span className="text-ovs-crm-warning-text font-semibold">Unscheduled</span>
                          )}
                        </TableCell>
                        <TableCell align="right">
                          <div className="flex items-center justify-end gap-1.5">
                            <Button
                              variant="secondary"
                              size="sm"
                              onClick={() => openLead(lead)}
                              className="h-7 text-[11.5px]"
                            >
                              Inspect
                            </Button>
                            <Link
                              href={`/company/crm/leads/${lead.id}`}
                              className="h-7 px-2.5 text-[11.5px] font-semibold rounded-[16px] bg-ovs-crm-accent-teal-soft text-ovs-crm-accent-teal-text hover:bg-ovs-crm-accent-teal-soft border border-ovs-crm-accent-teal-border flex items-center gap-1 transition-colors"
                            >
                              <span>Open</span>
                              <ChevronRight className="w-3 h-3" />
                            </Link>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </div>
          )}

          </div>

          <div
            className={cn(
              visibleTabs.length > 0 ? 'lg:sticky lg:top-4 lg:col-span-4' : 'lg:col-span-12'
            )}
          >
            {/*
              * What you already did, one line high.
              *
              * A full-height log at the foot of a page about what to do next is the
              * least useful thing on it and the loudest — it was the last thing the
              * eye landed on and had nothing to say. It stays, because "did I
              * already call them" is a real question; it stops taking a screen to
              * answer it.
              */}
            <CrmDisclosure label="My recent activity" count={recent_activity.length}>
              {/*
                * The heading moved into the disclosure's own label, which left an
                * icon floating alone above the list with nothing to label. An icon
                * on its own is decoration pretending to be a control.
                */}
              {recent_activity.length === 0 ? (
                <p className="text-[13px] text-ovs-crm-text-secondary">You have not logged any CRM activity yet.</p>
              ) : (
                <div className="space-y-0.5">
                  {/*
                    * Rows, not boxes. Each entry is one line of text and a time, and
                    * wrapping every one in its own bordered card made a plain list
                    * look like ten separate things demanding attention.
                    */}
                  {recent_activity.slice(0, 10).map((item) => (
                    <Link
                      key={item.id}
                      href={`/company/crm/leads/${item.lead_id}`}
                      className="-mx-2 flex items-baseline justify-between gap-4 rounded-[10px] px-2 py-1.5 transition-colors hover:bg-ovs-crm-surface-inset"
                    >
                      <span className="min-w-0 truncate text-[13px] text-ovs-crm-text-secondary">
                        {item.description}
                      </span>
                      <span className="shrink-0 text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                        {item.time}
                      </span>
                    </Link>
                  ))}
                </div>
              )}
            </CrmDisclosure>
          </div>
        </div>

        {unclaimed && ! hasNothingAssigned && (
          <MyWorkUnclaimed unclaimed={unclaimed} currentUserId={currentUserId ?? null} />
        )}

      </div>

      <TaskModal


        isOpen={taskModalOpen}


        onClose={() => setTaskModalOpen(false)}


        task={editingTask}


      />

      <CrmModal
        open={rejectingApproval !== null}
        onClose={() => setRejectingApproval(null)}
        title={rejectingApproval?.type === 'quotation_discount' ? 'Reject the discount' : 'Reject the extension request'}
        description="The person who asked will see why."
        footer={
          <>
            <CrmButton onClick={() => setRejectingApproval(null)}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              loading={rejectForm.processing}
              onClick={() => {
                if (!rejectingApproval) return;

                const url = rejectingApproval.type === 'quotation_discount'
                  ? `/company/crm/quotations/${rejectingApproval.quotation_id}/reject`
                  : `/company/crm/trials/${rejectingApproval.trial_id}/extension-requests/${rejectingApproval.id}/reject`;

                rejectForm.post(url, {
                  preserveScroll: true,
                  onSuccess: () => {
                    setRejectingApproval(null);
                    rejectForm.reset();
                  },
                });
              }}
            >
              Reject
            </CrmButton>
          </>
        }
      >
        <CrmFormField label="Reason" required error={rejectForm.errors.reason || rejectForm.errors.rejection_reason}>
          <CrmTextarea
            rows={2}
            value={rejectForm.data.reason}
            onChange={(e) => rejectForm.setData((data) => ({ ...data, reason: e.target.value, rejection_reason: e.target.value }))}
          />
        </CrmFormField>
      </CrmModal>

      <CrmModal
        open={reschedulingDemo !== null}
        onClose={() => setReschedulingDemo(null)}
        title="Reschedule this demo"
        description={reschedulingDemo?.company_name}
        footer={
          <>
            <CrmButton onClick={() => setReschedulingDemo(null)}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              loading={rescheduleForm.processing}
              onClick={() => {
                if (!reschedulingDemo) return;

                rescheduleForm.post(`/company/crm/demos/${reschedulingDemo.id}/reschedule`, {
                  preserveScroll: true,
                  onSuccess: () => {
                    setReschedulingDemo(null);
                    rescheduleForm.reset();
                  },
                });
              }}
            >
              Reschedule
            </CrmButton>
          </>
        }
      >
        <div className="space-y-4">
          <CrmFormField label="New date & time" required error={rescheduleForm.errors.scheduled_at}>
            <CrmInput
              type="datetime-local"
              value={rescheduleForm.data.scheduled_at}
              onChange={(e) => rescheduleForm.setData('scheduled_at', e.target.value)}
            />
          </CrmFormField>
          <CrmFormField label="Notes" hint="Optional — visible on the deal's activity log.">
            <CrmTextarea
              rows={2}
              value={rescheduleForm.data.notes}
              onChange={(e) => rescheduleForm.setData('notes', e.target.value)}
            />
          </CrmFormField>
        </div>
      </CrmModal>

    </CrmShell>
  );
}

/**
 * One demo list, rendered twice.
 *
 * Today's demos and Field visits are the same record shown to a rep with a
 * different question in mind, so they are one component with different copy
 * rather than two near-identical blocks that drift apart the first time
 * somebody fixes a bug in one of them.
 *
 * The join control is the only part that genuinely differs: a remote demo gets
 * a link, a clinic visit gets the address. Showing "No meeting link set" on a
 * visit — which the single combined list used to do — reads as a fault when
 * nothing is wrong.
 */
function DemoTab({
  demos,
  heading,
  emptyTitle,
  emptyHint,
  onReschedule,
}: {
  demos: DemoItem[];
  heading: string;
  emptyTitle: string;
  emptyHint: string;
  onReschedule: (demo: DemoItem) => void;
}) {
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-[13px] font-bold text-ovs-crm-text-primary uppercase tracking-wider">
          {heading} ({demos.length})
        </h3>
      </div>

      {demos.length === 0 ? (
        <Card elevation="surface" className="p-8 text-center text-ovs-crm-text-secondary space-y-3">
          <Video className="w-8 h-8 mx-auto text-ovs-crm-text-secondary opacity-50" />
          <p className="text-[13px] font-medium">{emptyTitle}</p>
          <p className="text-[12px]">{emptyHint}</p>
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {demos.map((demo) => (
            <Card key={demo.id} elevation="surface" className="p-4 space-y-3">
              <div className="flex items-start justify-between gap-2">
                <div>
                  {/*
                    * By `lead_id`, not `id`. The old tab called the page's
                    * `openLead(demo)` helper, which reads `.id` — the demo's own
                    * id — so clicking a company name opened whichever deal
                    * happened to share that number, or 404'd.
                    */}
                  <Link
                    href={`/company/crm/leads/${demo.lead_id}`}
                    className="text-[15px] font-bold text-ovs-crm-text-primary hover:text-ovs-crm-brand text-left"
                  >
                    {demo.company_name}
                  </Link>
                  <p className="text-[11.5px] text-ovs-crm-text-secondary font-medium">
                    Contact: {demo.contact_name} {demo.phone && `• ${demo.phone}`}
                  </p>
                  {demo.presenter && (
                    <p className="text-[11.5px] text-ovs-crm-text-secondary">Presenter: {demo.presenter}</p>
                  )}
                </div>
                <Badge variant={demo.is_today ? 'brand' : 'neutral'}>
                  {demo.scheduled_at}
                </Badge>
              </div>

              <div className="flex flex-wrap items-center gap-2 text-[11.5px] font-semibold text-ovs-crm-text-secondary">
                <span className="px-2 py-0.5 rounded bg-ovs-crm-border">
                  {demo.meeting_platform_label ?? demo.demo_mode}
                </span>
                <span
                  className={cn(
                    'px-2 py-0.5 rounded capitalize',
                    demo.confirmation_status === 'confirmed'
                      ? 'bg-ovs-crm-success-soft text-ovs-crm-success-text'
                      : 'bg-ovs-crm-warning-soft text-ovs-crm-warning-text',
                  )}
                >
                  {demo.confirmation_status}
                </span>
                {/* Only shown when there is one — most Meet calls have neither. */}
                {demo.passcode && (
                  <span className="px-2 py-0.5 rounded bg-ovs-crm-surface-inset">
                    Passcode {demo.passcode}
                  </span>
                )}
              </div>

              {demo.notes && (
                <p className="text-[11.5px] text-ovs-crm-text-secondary bg-ovs-crm-surface-inset p-2 rounded line-clamp-2">
                  {demo.notes}
                </p>
              )}

              <div className="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-ovs-crm-border">
                {demo.is_field_visit ? (
                  <span className="text-[11.5px] text-ovs-crm-text-secondary">
                    {demo.location || 'No address recorded'}
                  </span>
                ) : demo.meeting_link ? (
                  <a
                    href={demo.meeting_link}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="h-8 px-3 text-[11.5px] font-bold rounded-[16px] bg-ovs-crm-brand hover:bg-ovs-crm-brand-hover text-white flex items-center gap-1.5 transition-colors"
                  >
                    <ExternalLink className="w-3.5 h-3.5" />
                    <span>Join {demo.meeting_platform_label ?? 'meeting'}</span>
                  </a>
                ) : (
                  <span className="text-[11.5px] text-ovs-crm-text-secondary">No meeting link set</span>
                )}

                <div className="flex items-center gap-1.5">
                  <button
                    type="button"
                    onClick={() => onReschedule(demo)}
                    className="h-8 px-3 text-[11.5px] font-semibold rounded-[16px] bg-ovs-crm-border hover:bg-ovs-crm-border-strong text-ovs-crm-text-primary flex items-center gap-1 transition-colors shrink-0"
                  >
                    <RefreshCw className="w-3.5 h-3.5" />
                    <span>Reschedule</span>
                  </button>
                  <Link
                    href={`/company/crm/leads/${demo.lead_id}`}
                    className="h-8 px-3 text-[11.5px] font-semibold rounded-[16px] bg-ovs-crm-border hover:bg-ovs-crm-border text-ovs-crm-text-primary flex items-center gap-1 transition-colors shrink-0"
                  >
                    <span>Lead Record</span>
                    <ChevronRight className="w-3.5 h-3.5" />
                  </Link>
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * Pending Management Approvals.
 *
 * The one queue on this page built from the canonical design system rather
 * than the page's older, local `Card`/`Badge`/`Button` set — every other tab
 * predates that system and stays in its own established style rather than
 * being rewritten as a side effect of this feature; this tab is new, so it
 * uses the primitives directly, per the brief.
 */
function ApprovalsTab({
  items,
  onApprove,
  onReject,
}: {
  items: PendingApprovalItem[];
  onApprove: (item: PendingApprovalItem) => void;
  onReject: (item: PendingApprovalItem) => void;
}) {
  const [approvingKey, setApprovingKey] = useState<string | null>(null);

  const columns: CrmColumn<PendingApprovalItem>[] = [
    {
      key: 'clinic',
      header: 'Clinic',
      primary: true,
      cell: (item) => (
        <div className="min-w-0">
          <Link href={`/company/crm/leads/${item.lead_id}`} className="font-semibold text-ovs-crm-text-primary hover:text-ovs-crm-brand">
            {item.company_name}
          </Link>
          <p className="truncate text-[11.5px] text-ovs-crm-text-secondary">
            {item.contact_name} {item.city && `• ${item.city}`}
          </p>
        </div>
      ),
    },
    {
      key: 'requested_by',
      header: 'Requested by',
      cell: (item) => <span className="text-[13px] text-ovs-crm-text-primary">{item.requested_by}</span>,
      hideOnMobile: true,
    },
    {
      key: 'detail',
      header: 'Requesting',
      align: 'center',
      cell: (item) => (
        <CrmBadgeCanonical tone="amber">
          {item.type === 'quotation_discount'
            ? `₹${item.discount?.toLocaleString('en-IN')} (${item.discount_percent?.toFixed(1)}%)`
            : `+${item.additional_days} days`}
        </CrmBadgeCanonical>
      ),
    },
    {
      key: 'reason',
      header: 'Reason',
      cell: (item) => (
        <span className="line-clamp-2 text-[12.5px] text-ovs-crm-text-secondary">
          {item.type === 'quotation_discount' ? item.quotation_number : item.reason}
        </span>
      ),
      hideOnMobile: true,
    },
    {
      key: 'age',
      header: 'Asked',
      cell: (item) => <span className="whitespace-nowrap text-[11.5px] text-ovs-crm-text-secondary">{item.time_ago}</span>,
      hideOnMobile: true,
    },
  ];

  return (
    <div className="space-y-4">
      <h3 className="text-[13px] font-bold text-ovs-crm-text-primary uppercase tracking-wider">
        Pending Approvals ({items.length})
      </h3>

      <CrmTable
        rows={items}
        columns={columns}
        rowKey={(item) => `${item.type}-${item.id}`}
        rowActions={(item) => (
          <>
            <CrmButton
              size="sm"
              tone="primary"
              icon={Check}
              loading={approvingKey === `${item.type}-${item.id}`}
              disabled={approvingKey !== null}
              onClick={() => {
                setApprovingKey(`${item.type}-${item.id}`);
                onApprove(item);
              }}
            >
              Approve
            </CrmButton>
            <CrmButton
              size="sm"
              tone="danger"
              icon={X}
              disabled={approvingKey !== null}
              onClick={() => onReject(item)}
            >
              Reject
            </CrmButton>
          </>
        )}
        empty={{
          title: 'Nothing waiting on you.',
          description: 'A trial extension or quotation discount appears here the moment someone asks for approval.',
        }}
      />
    </div>
  );
}
