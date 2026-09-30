import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  Building2,
  HeartPulse,
  TrendingUp,
  AlertTriangle,
  CheckCircle2,
  Clock,
  ArrowLeft,
  Users,
  ShieldCheck,
  ShieldAlert,
  Activity,
  Layers,
  FileText,
  Calendar,
  Sparkles,
  RefreshCw,
  Award,
  CreditCard,
  MapPin,
  Mail,
  Phone,
  Building,
  Key,
  ExternalLink,
  Plus,
  Send,
  Download,
  Check,
  Briefcase,
  AlertCircle,
  FileSpreadsheet,
  Zap,
  LayoutDashboard,
  LifeBuoy,
} from 'lucide-react';
import {
  CrmPageHead,
  CrmValue,
  CrmCard,
  CrmButton,
  CrmModal,
  CrmHairlineBar,
  CrmRing,
  CrmSection,
  CrmDataState,
  CrmPageStat,
  CrmTabs,
  CrmTable,
  CrmStatusPill,
  CrmFormField,
  CrmInput,
  CrmSelect,
  useCrmConfirm,
  inr,
  healthTone,
  churnTone,
  humanize,
  statusTone,
  priorityTone,
  channelTone,
  lifecycleStageTone,
  type CrmStatusTone,
} from '@/pages/Company/design';

import { ContractActionModal, type ContractAction, type ContractPlan } from '../components/ContractActions';
import { CustomerSuccessShell } from '../components/CustomerSuccessShell';
import { cn } from '@/lib/utils';

/**
 * A person at the customer.
 *
 * Named rather than inlined because `CrmTable<T>` needs a type argument, and an
 * anonymous shape declared at the prop would have to be repeated at the column
 * definition — which is exactly how a component's copy of a payload type drifts
 * from the server's.
 */
export interface ContactRow {
  id: number;
  name: string;
  email?: string;
  phone?: string;
  designation?: string;
  role?: string;
  is_primary: boolean;
  decision_power?: string;
}

export interface CommercialDocumentRow {
  id: number;
  document_number: string;
  type: string;
  status: string;
  total_amount: number;
  created_at?: string;
}

export interface QuotationRow {
  id: number;
  quotation_number: string;
  status: string;
  total_amount: number;
  created_at?: string;
}

export interface InvoiceRow {
  id: number;
  invoice_number: string;
  status: string;
  total_amount: number;
  issued_at?: string;
  paid_at?: string;
}

export interface SubscriptionSummary {
  id: number;
  planName: string;
  status: string;
  mrr: string;
  arr: string;
  startsAt: string | null;
  endsAt: string | null;
  daysToRenewal: number;
  renewalDate: string | null;
}

/**
 * A support ticket raised against this customer.
 *
 * Customer Success keeps no case record of its own — `company_support_tickets`
 * already carries `organization_id`, and the Support module owns the workflow,
 * the SLA targets and the ticket's own timeline. This tab is the account's view
 * of that, not a second copy of it.
 */
export interface SupportTicketRow {
  id: number;
  reference: string;
  subject: string;
  status: string;
  priority: string;
  category?: string | null;
  opened_at?: string | null;
  resolved_at?: string | null;
  breaching_sla: boolean;
}

export interface ActivityRow {
  id: number;
  type: string;
  description: string;
  created_at?: string;
  user?: { id: number; name: string } | null;
}

export interface TaskRow {
  id: number;
  title: string;
  description?: string;
  status: string;
  priority: string;
  due_at?: string;
}

export interface AccountDetailProps {
  can?: {
    update?: boolean;
  };
  eligibleOwners?: Array<{ id: number; name: string; email: string }>;
  account: {
    id: number;
    name: string;
    legal_name?: string;
    type?: string;
    clinic_type?: string;
    organization_scale?: string;
    owner_name?: string;
    contact_person?: string;
    email?: string;
    mobile?: string;
    gst?: string;
    website?: string;
    address?: string;
    city?: string;
    state?: string;
    country?: string;
    timezone?: string;
    health_score?: number;
    renewal_probability?: number;
    churn_risk?: string;
    expansion_opportunity?: boolean;
    stage?: string;
    created_at?: string;
    assigned_user?: {
      id: number;
      name: string;
      email: string;
    } | null;
  };
  activeSubscription?: {
    id: number;
    planName: string;
    status: string;
    mrr: string;
    mrr_raw?: number | null;
    arr: string;
    arr_raw?: number | null;
    startsAt: string | null;
    endsAt: string | null;
    daysToRenewal: number | null;
    renewalDate: string | null;
    billingPeriod?: string;
  } | null;
  activeTab?: string;
  validTabs?: Record<string, string>;
  /**
   * What needs doing on this account, newest concern first.
   *
   * Assembled server-side from the projection so the page renders sentences
   * rather than deriving its own opinion — two places deciding what "at risk"
   * means is how they start disagreeing.
   */
  plans?: ContractPlan[];
  attention?: Array<{
    tone: 'bad' | 'warn' | 'info';
    title: string;
    detail?: string | null;
    action?: string | null;
    tab?: string | null;
  }>;
  healthData?: {
    total_score: number;
    status: string;
    dimensions: Record<string, { score: number; max: number; label: string }>;
    /** The signals the nightly pass counted when it set the churn risk. */
    churn_reasons?: string[];
    churn_overridden?: boolean;
    health_overridden?: boolean;
    overridden_by?: string | null;
  };
  expansionData?: {
    user_utilization_pct: number;
    branch_utilization_pct: number;
    active_users: number;
    max_users: number;
    active_branches: number;
    max_branches: number;
    has_expansion_opportunity: boolean;
    potential_arr_upsell: number;
  };
  entitlements?: {
    purchased_seats: number;
    used_seats: number;
    seat_utilization_pct: number | null;
    purchased_doctors: number | null;
    purchased_branches: number;
    active_branches: number;
    branch_utilization_pct: number | null;
    has_subscription: boolean;
  };
  subscriptions?: SubscriptionSummary[];
  /**
   * The onboarding record and the sequence it is measured against.
   *
   * `steps` arrives from the server because it belongs to the account's clinic
   * type — a hospital's sequence has seven steps, a dental clinic's five. It
   * used to be an array of labels held inside this component, which is why
   * every customer saw the same checklist regardless of what they had bought.
   */
  onboarding?: {
    id?: number;
    template_name?: string | null;
    steps?: Array<{ key: string; label: string; description?: string | null; is_required: boolean }>;
    status: string;
    current_step: string | null;
    completed_steps?: string[];
    required_steps_complete?: boolean;
  };
  primaryAdmin?: {
    id: number;
    name: string;
    email: string;
    user_type: string;
    email_verified_at?: string | null;
  } | null;
  contacts?: ContactRow[];
  clinics?: Array<{
    id: number;
    name: string;
    slug: string;
    status: string;
    branches: Array<{
      id: number;
      name: string;
      status?: string;
      is_main: boolean;
      address?: string;
      city?: string;
      state?: string;
      phone?: string;
      email?: string;
      chairs?: number;
    }>;
  }>;
  branches?: Array<{
    id: number;
    name: string;
    clinic_name: string;
    is_main: boolean;
    address?: string;
    city?: string;
    state?: string;
    phone?: string;
    email?: string;
  }>;
  commercialDocuments?: CommercialDocumentRow[];
  quotations?: QuotationRow[];
  invoices?: InvoiceRow[];
  attachedFiles?: Array<{
    id: number;
    title: string;
    file_path: string;
    file_type?: string;
    created_at?: string;
    uploader?: { name: string };
  }>;
  activities?: ActivityRow[];
  tasks?: TaskRow[];
  supportTickets?: SupportTicketRow[];
  license?: {
    id: number;
    license_key: string;
    license_type: string;
    max_users: number;
    max_doctors: number;
    max_branches: number;
    is_active: boolean;
    expires_at?: string;
    enabled_modules: string[];
  } | null;
}

function DeferredSkeleton({ rows = 3, height = 'h-24' }: { rows?: number; height?: string }) {
  return (
    <div className="space-y-4 animate-pulse w-full">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className={cn(height, 'bg-ovs-crm-surface-inset rounded-2xl border border-ovs-crm-border/50')} />
      ))}
    </div>
  );
}

/*
 * Deferred props carry no defaults, deliberately.
 *
 * Inertia leaves a deferred prop `undefined` until its group arrives, and that
 * is the only signal this page has that a zone is still loading. Defaulting
 * them to `[]` made every list "arrived and empty" from the first paint — so
 * the skeletons never appeared and each tab flashed "Nothing recorded yet"
 * before its data landed a moment later.
 *
 * `account`, `can`, `activeTab` and `validTabs` are eager and always present.
 */
export default function AccountShow({
  account,
  can,
  activeSubscription,
  activeTab = 'overview',
  validTabs = {},
  plans,
  attention,
  eligibleOwners,
  healthData,
  expansionData,
  entitlements,
  subscriptions,
  onboarding,
  primaryAdmin,
  contacts,
  clinics,
  branches,
  commercialDocuments,
  quotations,
  invoices,
  attachedFiles,
  activities,
  tasks,
  supportTickets,
  license,
}: AccountDetailProps) {
  const currentTab = activeTab;

  const [showHealthModal, setShowHealthModal] = useState(false);
  const [healthScore, setHealthScore] = useState(account.health_score ?? 90);
  const [churnRisk, setChurnRisk] = useState(account.churn_risk ?? 'Low');
  const [renewalProbability, setRenewalProbability] = useState(account.renewal_probability ?? 80);
  const [expansionOpp, setExpansionOpp] = useState(account.expansion_opportunity ?? false);
  const [notes, setNotes] = useState('');
  const [contractAction, setContractAction] = useState<ContractAction | null>(null);

  const canManageStructure = Boolean(can?.update);

  const [clinicForm, setClinicForm] = useState<{ open: boolean; id: number | null; name: string; status: string; primaryBranch: string; ownerId: string }>(
    { open: false, id: null, name: '', status: 'active', primaryBranch: '', ownerId: '' }
  );

  const [branchForm, setBranchForm] = useState<{ open: boolean; clinicId: number | null; id: number | null; name: string; status: string; isMain: boolean }>(
    { open: false, clinicId: null, id: null, name: '', status: 'active', isMain: false }
  );

  const openCreateClinic = () =>
    setClinicForm({ open: true, id: null, name: '', status: 'active', primaryBranch: '', ownerId: '' });

  const openEditClinic = (clinic: any) =>
    setClinicForm({ open: true, id: clinic.id, name: clinic.name, status: clinic.status ?? 'active', primaryBranch: '', ownerId: '' });

  const openCreateBranch = (clinic: any) =>
    setBranchForm({ open: true, clinicId: clinic.id, id: null, name: '', status: 'active', isMain: false });

  const openEditBranch = (clinic: any, branch: any) =>
    setBranchForm({
      open: true,
      clinicId: clinic.id,
      id: branch.id,
      name: branch.name,
      status: branch.status ?? 'active',
      isMain: Boolean(branch.is_main),
    });

  const submitClinic = (e: React.FormEvent) => {
    e.preventDefault();
    const done = { preserveScroll: true, onSuccess: () => setClinicForm((f) => ({ ...f, open: false })) };

    if (clinicForm.id) {
      router.put(
        route('company.customer-success.accounts.clinics.update', { organization: account.id, clinic: clinicForm.id }),
        { name: clinicForm.name, status: clinicForm.status },
        done
      );
      return;
    }

    router.post(
      route('company.customer-success.accounts.clinics.store', { organization: account.id }),
      {
        name: clinicForm.name,
        status: clinicForm.status,
        primary_branch_name: clinicForm.primaryBranch || null,
        owner_id: clinicForm.ownerId || null,
      },
      done
    );
  };

  const submitBranch = (e: React.FormEvent) => {
    e.preventDefault();
    const done = { preserveScroll: true, onSuccess: () => setBranchForm((f) => ({ ...f, open: false })) };
    const body = { name: branchForm.name, status: branchForm.status, is_main: branchForm.isMain };

    if (branchForm.id) {
      router.put(
        route('company.customer-success.accounts.clinics.branches.update', {
          organization: account.id,
          clinic: branchForm.clinicId!,
          branch: branchForm.id,
        }),
        body,
        done
      );
      return;
    }

    router.post(
      route('company.customer-success.accounts.clinics.branches.store', {
        organization: account.id,
        clinic: branchForm.clinicId!,
      }),
      body,
      done
    );
  };

  const { confirm, dialog: confirmDialog } = useCrmConfirm();

  const handleTabChange = (newTab: string) => {
    router.get(
      route('company.customer-success.accounts.show', { organization: account.id }),
      { tab: newTab },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  };

  const handleUpdateHealth = (e: React.FormEvent) => {
    e.preventDefault();
    router.put(
      route('company.customer-success.accounts.update', { organization: account.id }),
      {
        health_score: healthScore,
        renewal_probability: renewalProbability,
        churn_risk: churnRisk,
        expansion_opportunity: expansionOpp,
        notes: notes,
      },
      {
        preserveScroll: true,
        onSuccess: () => setShowHealthModal(false),
      }
    );
  };

  const handleStepComplete = (stepId: string, nextStepId?: string) => {
    router.post(
      route('company.customer-success.accounts.onboarding.complete-step', { organization: account.id }),
      { step: stepId, next_step: nextStepId },
      { preserveScroll: true }
    );
  };

  const handleResendInvite = () => {
    confirm({
      title: 'Resend Primary Admin Welcome Invite?',
      description: `${primaryAdmin?.email || account.email} will receive a fresh link to set their password. Any previous link will be invalidated.`,
      confirmLabel: 'Resend Invite',
      onConfirm: () =>
        router.post(
          route('company.customer-success.accounts.onboarding.resend-invite', { organization: account.id }),
          {},
          { preserveScroll: true }
        ),
    });
  };

  const handleMarkGoLive = () => {
    confirm({
      title: `Mark ${account.name} Officially LIVE?`,
      description:
        'Customer onboarding will be completed and the account will transition to full active production status.',
      confirmLabel: 'Mark Go-Live',
      onConfirm: () =>
        router.post(
          route('company.customer-success.accounts.onboarding.mark-go-live', { organization: account.id }),
          {},
          { preserveScroll: true }
        ),
    });
  };

  const healthScoreVal = account.health_score ?? healthData?.total_score ?? 100;
  /* Banded by the shared helpers, so this page and the Accounts list cannot
   * disagree about the same score. */
  const healthPillTone: CrmStatusTone = healthTone(healthScoreVal);
  const churnPillTone: CrmStatusTone = churnTone(account.churn_risk);

  /*
   * The sequence comes from the server, because it depends on what kind of
   * healthcare business this customer is. The numbering is applied here rather
   * than stored, so reordering a template does not mean renaming its steps.
   */
  const onboardingSteps = (onboarding?.steps ?? []).map((step, index) => ({
    id: step.key,
    label: `${index + 1}. ${step.label}`,
    desc: step.description ?? '',
    isRequired: step.is_required,
  }));

  const completedSteps = onboarding?.completed_steps || [];

  /*
   * Five, grouped by the question being asked rather than the table being read.
   *
   * Twelve tabs — one per table this page could reach — meant twelve places to
   * look before knowing anything, and the first one opened on two empty cards.
   * Every old tab id still resolves server-side, so bookmarks and deep links
   * land on the tab that absorbed them.
   */
  const tabList = [
    { id: 'overview', label: 'Overview', icon: LayoutDashboard },
    { id: 'contract', label: 'Contract', icon: CreditCard },
    { id: 'setup', label: 'Setup', icon: CheckCircle2 },
    { id: 'people', label: 'People', icon: Users },
    { id: 'records', label: 'Records', icon: FileText },
  ];

  return (
    <CustomerSuccessShell title={`${account.name} — Account 360`}>
      <Head title={`${account.name} | Account 360`} />

      <div className="flex w-full flex-col gap-5 pb-12">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <Link
            href={route('company.customer-success.accounts.index')}
            className="inline-flex items-center gap-1.5 text-[12.5px] font-medium text-ovs-crm-text-secondary transition-colors hover:text-ovs-crm-text-primary"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            <span>All accounts</span>
          </Link>

          <span className="text-[11.5px] text-ovs-crm-text-muted">
            Account <span className="tabular-nums text-ovs-crm-text-secondary">#{account.id}</span>
          </span>
        </div>

        {/*
         * The account's masthead, on the product's own page furniture.
         *
         * This was a hand-built card with raw `<button>` elements and pills
         * assembled inline — which is why it read as a different product from
         * CRM's Deal Profile. The title, the figures beside it and the actions
         * at the right now come from `CrmPageHead`, the same component every
         * other page in this module uses, and every control is a `CrmButton`.
         */}
        {/*
          * No eyebrow. The rail already says Customer Success and the
          * breadcrumb above says which account — "Customer success · Account
          * 360" sitting above the customer's own name was the module telling
          * you where you are for the third time on one screen.
          */}
        <CrmPageHead
          title={account.name}
          description={
            [
              account.legal_name,
              account.city ? `${account.city}${account.state ? `, ${account.state}` : ''}` : null,
              account.contact_person,
              account.assigned_user ? `Owner: ${account.assigned_user.name}` : 'Unassigned',
            ]
              .filter(Boolean)
              .join(' · ')
          }
          stats={
            <>
              <CrmPageStat icon={HeartPulse} value={`${healthScoreVal}`} caption="Health score" />
              <CrmPageStat
                icon={CreditCard}
                value={activeSubscription?.mrr ?? '—'}
                caption={activeSubscription?.planName ?? 'No active plan'}
              />
              <CrmPageStat
                icon={RefreshCw}
                value={activeSubscription?.endsAt ?? '—'}
                caption="Renews"
              />
            </>
          }
          actions={
            <>
              {can?.update && (
                <CrmButton tone="secondary" size="sm" icon={HeartPulse} onClick={() => setShowHealthModal(true)}>
                  Update metrics
                </CrmButton>
              )}
              {/*
                * Nothing here opens a CRM deal, and that is the point.
                *
                * This offered "Renewal deal" and "Expansion deal", both of which
                * pushed an existing customer into the pipeline built for winning
                * strangers. Once a deal is won the account belongs to Customer
                * Success: another year is a renewal, more seats is an
                * entitlement change, a bigger plan is an upgrade. All three are
                * contract verbs on this page now, so there is no sale to hand
                * anybody and no second module to wait on.
                */}
              <CrmButton
                tone="primary"
                size="sm"
                icon={RefreshCw}
                onClick={() => handleTabChange('subscription')}
              >
                Manage contract
              </CrmButton>
            </>
          }
        />

        {/* What this account is right now, said once rather than repeated per tab. */}
        <div className="flex flex-wrap items-center gap-2">
          <CrmStatusPill tone={healthPillTone}>Health {healthScoreVal}/100</CrmStatusPill>
          <CrmStatusPill tone={churnPillTone}>Churn {humanize(account.churn_risk ?? 'Low')}</CrmStatusPill>
          {account.stage && (
            <CrmStatusPill tone={lifecycleStageTone(account.stage)}>{humanize(account.stage)}</CrmStatusPill>
          )}
          {activeSubscription?.planName && <CrmStatusPill tone="info">{activeSubscription.planName}</CrmStatusPill>}
          {account.expansion_opportunity && <CrmStatusPill tone="good">Expansion potential</CrmStatusPill>}
          {account.email && (
            <a
              href={`mailto:${account.email}`}
              className="inline-flex items-center gap-1 text-[12px] text-ovs-crm-text-secondary underline-offset-2 hover:text-ovs-crm-text-primary hover:underline"
            >
              <Mail className="h-3.5 w-3.5" aria-hidden="true" />
              {account.email}
            </a>
          )}
        </div>

        {/*
         * Eleven destinations, on the shared tab control.
         *
         * `CrmTabs` owns the scrollport that makes a long strip reachable on a
         * phone — the hand-rolled row this replaces put `overflow-x-auto` on an
         * inline-flex box, which never scrolls, so the last four tabs could not
         * be reached on a narrow screen at all.
         */}
        <CrmTabs
          tabs={tabList.map((item) => ({ key: item.id, label: item.label }))}
          value={currentTab}
          onChange={handleTabChange}
        />

        {/* ── TAB 1: OVERVIEW ── */}
        {currentTab === 'overview' && (
          <div className="space-y-6">
            {/*
              * What is wrong, and what to do about it — before anything else.
              *
              * This page opened on two empty cards that said "Nothing recorded
              * yet". An account page's first screen has to answer the question
              * its reader arrived with: is this one in trouble, and what
              * happens next. Every line below is derived from a record that
              * already exists — the same signals the nightly projection counts
              * — said as a sentence with somewhere to click.
              */}
            {attention === undefined ? (
              <DeferredSkeleton rows={2} height="h-16" />
            ) : attention.length === 0 ? (
              <div className="flex items-center gap-2.5 rounded-[18px] border border-ovs-crm-success-border bg-ovs-crm-success-soft px-4 py-3">
                <CheckCircle2 className="h-4 w-4 shrink-0 text-ovs-crm-success-text" aria-hidden="true" />
                <p className="text-[13px] text-ovs-crm-text-primary">
                  Nothing needs attention. The contract is inside its term, the seats they bought are being used, and
                  setup is finished.
                </p>
              </div>
            ) : (
              <div className="space-y-2">
                {attention.map((item, index) => (
                  <div
                    key={`${item.title}-${index}`}
                    className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-[18px] border border-ovs-crm-border bg-ovs-crm-surface px-4 py-3"
                  >
                    <CrmStatusPill tone={item.tone}>
                      {item.tone === 'bad' ? 'Act now' : item.tone === 'warn' ? 'Soon' : 'Note'}
                    </CrmStatusPill>

                    <span className="min-w-[180px] flex-1">
                      <span className="block text-[13px] font-medium text-ovs-crm-text-primary">{item.title}</span>
                      {item.detail && (
                        <span className="block text-[11.5px] text-ovs-crm-text-muted">{item.detail}</span>
                      )}
                    </span>

                    {item.action && item.tab && (
                      <CrmButton size="sm" tone="secondary" onClick={() => handleTabChange(item.tab as string)}>
                        {item.action}
                      </CrmButton>
                    )}
                  </div>
                ))}
              </div>
            )}

            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <div className="bg-white p-5 rounded-2xl border border-ovs-crm-border shadow-2xs flex flex-col justify-between">
                <div className="flex items-center justify-between text-xs text-ovs-crm-text-secondary font-semibold uppercase tracking-wider">
                  <span>Health Score</span>
                  <CrmStatusPill tone={healthPillTone}>{healthScoreVal}/100</CrmStatusPill>
                </div>
                <div className="mt-3 flex items-baseline gap-2">
                  <span className="text-3xl font-black text-ovs-crm-text-primary">{healthScoreVal}</span>
                  <span className="text-xs text-ovs-crm-text-muted font-medium">/ 100</span>
                </div>
                <div className="mt-3 text-xs text-ovs-crm-text-secondary flex items-center justify-between pt-2 border-t border-ovs-crm-border">
                  <span>Risk Level:</span>
                  <span className="font-bold text-ovs-crm-text-primary">{account.churn_risk ?? 'Low'}</span>
                </div>
              </div>

              <div className="bg-white p-5 rounded-2xl border border-ovs-crm-border shadow-2xs flex flex-col justify-between">
                <div className="flex items-center justify-between text-xs text-ovs-crm-text-secondary font-semibold uppercase tracking-wider">
                  <span>Subscription MRR</span>
                  <span className="text-xs font-semibold text-ovs-crm-brand-text">{activeSubscription?.billingPeriod ?? 'Active'}</span>
                </div>
                <div className="mt-3 flex items-baseline gap-2">
                  <span className="text-3xl font-black text-ovs-crm-text-primary">{activeSubscription?.mrr ?? '—'}</span>
                </div>
                <div className="mt-3 text-xs text-ovs-crm-text-secondary flex items-center justify-between pt-2 border-t border-ovs-crm-border">
                  <span>ARR Run-rate:</span>
                  <span className="font-bold text-ovs-crm-text-primary">{activeSubscription?.arr ?? '—'}</span>
                </div>
              </div>

              <div className="bg-white p-5 rounded-2xl border border-ovs-crm-border shadow-2xs flex flex-col justify-between">
                <div className="flex items-center justify-between text-xs text-ovs-crm-text-secondary font-semibold uppercase tracking-wider">
                  <span>Next Renewal</span>
                  <Calendar className="w-4 h-4 text-ovs-crm-text-muted" />
                </div>
                <div className="mt-3 flex items-baseline gap-2">
                  <span className="text-2xl font-black text-ovs-crm-text-primary">{activeSubscription?.renewalDate ?? '—'}</span>
                </div>
                <div className="mt-3 text-xs text-ovs-crm-text-secondary flex items-center justify-between pt-2 border-t border-ovs-crm-border">
                  <span>Countdown:</span>
                  <span className="font-bold text-ovs-crm-text-primary">
                    {activeSubscription?.daysToRenewal !== null && activeSubscription?.daysToRenewal !== undefined
                      ? `${activeSubscription.daysToRenewal} days`
                      : '—'}
                  </span>
                </div>
              </div>

              <div className="bg-white p-5 rounded-2xl border border-ovs-crm-border shadow-2xs flex flex-col justify-between">
                <div className="flex items-center justify-between text-xs text-ovs-crm-text-secondary font-semibold uppercase tracking-wider">
                  <span>Lifecycle Stage</span>
                  <ShieldCheck className="w-4 h-4 text-ovs-crm-success-text" />
                </div>
                <div className="mt-3 flex items-baseline gap-2">
                  <span className="text-xl font-bold text-ovs-crm-text-primary uppercase tracking-tight">
                    {account.stage ? account.stage.replace('_', ' ') : 'Live'}
                  </span>
                </div>
                <div className="mt-3 text-xs text-ovs-crm-text-secondary flex items-center justify-between pt-2 border-t border-ovs-crm-border">
                  <span>Onboarding:</span>
                  <span className="font-bold text-ovs-crm-text-primary capitalize">{onboarding?.status ?? 'Completed'}</span>
                </div>
              </div>
            </div>

            {/* Quick 360 Health & Telemetry Section */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2 bg-white p-6 rounded-3xl border border-ovs-crm-border shadow-2xs space-y-5">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-bold text-ovs-crm-text-primary uppercase tracking-wider">
                    5-Dimensional Health Breakdown
                  </h3>
                  <button
                    type="button"
                    onClick={() => handleTabChange('health')}
                    className="text-xs font-bold text-ovs-crm-brand-text hover:text-ovs-crm-brand-text flex items-center gap-1"
                  >
                    <span>Deep Health Inspection</span>
                    <ExternalLink className="w-3.5 h-3.5" />
                  </button>
                </div>

                {!healthData ? (
                  <DeferredSkeleton rows={3} />
                ) : (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {Object.entries(healthData.dimensions).map(([key, dim]) => (
                      <div key={key} className="p-4 rounded-2xl bg-ovs-crm-surface-inset/80 border border-ovs-crm-border/60">
                        <div className="flex items-center justify-between text-xs mb-2">
                          <span className="font-medium text-ovs-crm-text-secondary">{dim.label}</span>
                          <span className="font-bold text-ovs-crm-text-primary">
                            {dim.score} / {dim.max}
                          </span>
                        </div>
                        <div className="w-full bg-ovs-crm-surface-muted h-2 rounded-full overflow-hidden">
                          <div
                            className="h-full bg-ovs-crm-brand rounded-full transition-all"
                            style={{ width: `${(dim.score / dim.max) * 100}%` }}
                          />
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div className="bg-white p-6 rounded-3xl border border-ovs-crm-border shadow-2xs space-y-5">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-bold text-ovs-crm-text-primary uppercase tracking-wider">Entitlements & Capacity</h3>
                  <button
                    type="button"
                    onClick={() => handleTabChange('usage')}
                    className="text-xs font-bold text-ovs-crm-brand-text hover:text-ovs-crm-brand-text flex items-center gap-1"
                  >
                    <span>Usage Center</span>
                    <ExternalLink className="w-3.5 h-3.5" />
                  </button>
                </div>

                {!entitlements ? (
                  <DeferredSkeleton rows={2} />
                ) : (
                  <div className="space-y-4">
                    <div className="p-4 rounded-2xl bg-ovs-crm-surface-inset border border-ovs-crm-border/60">
                      <div className="flex items-center justify-between text-xs mb-1.5">
                        <span className="text-ovs-crm-text-secondary font-medium">Doctor/Staff Seats</span>
                        <span className="font-bold text-ovs-crm-text-primary">
                          {entitlements.used_seats} / {entitlements.purchased_seats} ({entitlements.seat_utilization_pct ?? 0}%)
                        </span>
                      </div>
                      <div className="w-full bg-ovs-crm-surface-muted h-2 rounded-full overflow-hidden">
                        <div
                          className="h-full bg-ovs-crm-success rounded-full"
                          style={{ width: `${Math.min(100, entitlements.seat_utilization_pct ?? 0)}%` }}
                        />
                      </div>
                    </div>

                    <div className="p-4 rounded-2xl bg-ovs-crm-surface-inset border border-ovs-crm-border/60">
                      <div className="flex items-center justify-between text-xs mb-1.5">
                        <span className="text-ovs-crm-text-secondary font-medium">Active Branches</span>
                        <span className="font-bold text-ovs-crm-text-primary">
                          {entitlements.active_branches} / {entitlements.purchased_branches} ({entitlements.branch_utilization_pct ?? 0}%)
                        </span>
                      </div>
                      <div className="w-full bg-ovs-crm-surface-muted h-2 rounded-full overflow-hidden">
                        <div
                          className="h-full bg-ovs-crm-brand rounded-full"
                          style={{ width: `${Math.min(100, entitlements.branch_utilization_pct ?? 0)}%` }}
                        />
                      </div>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        )}

        {/* ── TAB 2: HEALTH & CHURN ── */}
        {currentTab === 'overview' && (
          <div className="space-y-6">
            {!healthData ? (
              <DeferredSkeleton rows={4} height="h-32" />
            ) : (
              <>
              {/*
                * Why the risk reads the way it does.
                *
                * Churn used to be a field somebody typed into, so there was
                * never anything to show. It is derived now, and a risk with no
                * reasoning behind it is just a colour — a rep opening an account
                * to find "High" would have to reconstruct the case themselves.
                */}
              <CrmSection
                title={healthData.churn_overridden ? 'Churn risk — set by hand' : 'Why this risk'}
                description={
                  healthData.churn_overridden
                    ? `Pinned${healthData.overridden_by ? ` by ${healthData.overridden_by}` : ''}. The nightly review leaves it alone until the override is cleared.`
                    : 'Derived nightly from what this account records. Nothing here is entered by hand.'
                }
              >
                {(healthData.churn_reasons ?? []).length > 0 ? (
                  <ul className="space-y-2">
                    {(healthData.churn_reasons ?? []).map((reason) => (
                      <li key={reason} className="flex items-start gap-2 text-[13px] text-ovs-crm-text-secondary">
                        <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-ovs-crm-warning-text" aria-hidden="true" />
                        <span>{reason}</span>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="text-[13px] text-ovs-crm-text-secondary">
                    Nothing is flagging on this account: the contract is inside its window, the seats they bought are
                    being used, onboarding finished and somebody has spoken to them recently.
                  </p>
                )}
              </CrmSection>

              <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="bg-white p-6 rounded-3xl border border-ovs-crm-border shadow-2xs flex flex-col justify-between">
                  <div>
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-bold uppercase tracking-wider text-ovs-crm-text-muted">
                        Overall Health Rating
                      </span>
                      <CrmStatusPill tone={healthPillTone}>{healthData.status}</CrmStatusPill>
                    </div>
                    <div className="mt-4 flex items-baseline gap-2">
                      <span className="text-5xl font-black text-ovs-crm-text-primary">{healthScoreVal}</span>
                      <span className="text-sm font-semibold text-ovs-crm-text-muted">/ 100</span>
                    </div>
                    <p className="text-xs text-ovs-crm-text-secondary mt-2">
                      Algorithmic 5D scoring based on relationship telemetry, seat utilisation, module engagement, and contract status.
                    </p>
                  </div>
                  <div className="mt-6 pt-4 border-t border-ovs-crm-border flex items-center justify-between text-xs">
                    <span className="text-ovs-crm-text-secondary">Churn Risk Level</span>
                    <CrmStatusPill tone={churnPillTone}>{account.churn_risk ?? 'Low'}</CrmStatusPill>
                  </div>
                </div>

                <div className="lg:col-span-2 bg-white p-6 rounded-3xl border border-ovs-crm-border shadow-2xs space-y-4">
                  <h3 className="text-xs font-bold uppercase tracking-wider text-ovs-crm-text-muted">5-Dimensional Breakdown</h3>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {Object.entries(healthData.dimensions).map(([key, dim]) => (
                      <div key={key} className="p-4 rounded-2xl bg-ovs-crm-surface-inset border border-ovs-crm-border/60">
                        <div className="flex items-center justify-between text-xs font-medium mb-2">
                          <span className="text-ovs-crm-text-secondary">{dim.label}</span>
                          <span className="font-bold text-ovs-crm-text-primary">
                            {dim.score} / {dim.max}
                          </span>
                        </div>
                        <div className="w-full bg-ovs-crm-surface-muted h-2 rounded-full overflow-hidden">
                          <div
                            className="h-full bg-ovs-crm-brand rounded-full"
                            style={{ width: `${(dim.score / dim.max) * 100}%` }}
                          />
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
              </>
            )}
          </div>
        )}

        {/* ── TAB 3: ONBOARDING ── */}
        {currentTab === 'setup' && (
          <div className="space-y-6">
            {!onboarding ? (
              <DeferredSkeleton rows={3} height="h-32" />
            ) : (
              <div className="bg-white p-6 rounded-3xl border border-ovs-crm-border shadow-2xs space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-ovs-crm-border pb-4">
                  <div>
                    <h2 className="text-base font-bold text-ovs-crm-text-primary">Onboarding Pipeline & Activation</h2>
                    <p className="text-xs text-ovs-crm-text-secondary mt-1">
                      Sequential activation track for primary clinic administration and operational readiness.
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      onClick={handleResendInvite}
                      className="px-3.5 py-2 rounded-xl text-xs font-semibold bg-white border border-ovs-crm-border text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset transition-all flex items-center gap-1.5 shadow-2xs"
                    >
                      <Send className="w-3.5 h-3.5 text-ovs-crm-brand-text" />
                      <span>Resend Welcome Invite</span>
                    </button>
                    {can?.update && (
                      <button
                        type="button"
                        onClick={handleMarkGoLive}
                        className="px-3.5 py-2 rounded-xl text-xs font-bold bg-ovs-crm-success-text text-white hover:bg-ovs-crm-success-text transition-all flex items-center gap-1.5"
                      >
                        <CheckCircle2 className="w-3.5 h-3.5" />
                        <span>Mark Go-Live</span>
                      </button>
                    )}
                  </div>
                </div>

                <div className="space-y-4">
                  {onboardingSteps.map((step, idx) => {
                    const isDone = completedSteps.includes(step.id);
                    const isCurrent = onboarding.current_step === step.id;
                    const nextStep = onboardingSteps[idx + 1]?.id;

                    return (
                      <div
                        key={step.id}
                        className={cn(
                          'p-4 rounded-2xl border transition-all flex flex-col sm:flex-row sm:items-center justify-between gap-4',
                          isDone
                            ? 'bg-ovs-crm-success-soft/40 border-ovs-crm-success-border'
                            : isCurrent
                            ? 'bg-ovs-crm-brand-soft/40 border-ovs-crm-brand-border shadow-xs'
                            : 'bg-ovs-crm-surface-inset/60 border-ovs-crm-border'
                        )}
                      >
                        <div className="flex items-start gap-3">
                          <div
                            className={cn(
                              'w-8 h-8 rounded-xl flex items-center justify-center font-bold text-xs shrink-0',
                              isDone
                                ? 'bg-ovs-crm-success-text text-white'
                                : isCurrent
                                ? 'bg-ovs-crm-brand text-white'
                                : 'bg-ovs-crm-surface-muted text-ovs-crm-text-secondary'
                            )}
                          >
                            {isDone ? <Check className="w-4 h-4" /> : idx + 1}
                          </div>
                          <div>
                            <h4 className="text-xs font-bold text-ovs-crm-text-primary">{step.label}</h4>
                            <p className="text-xs text-ovs-crm-text-secondary mt-0.5">{step.desc}</p>
                          </div>
                        </div>

                        <div className="flex items-center gap-2">
                          {isDone ? (
                            <CrmStatusPill tone="good">Completed</CrmStatusPill>
                          ) : can?.update ? (
                            <button
                              type="button"
                              onClick={() => handleStepComplete(step.id, nextStep)}
                              className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white border border-ovs-crm-border text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset shadow-2xs"
                            >
                              Mark Done
                            </button>
                          ) : null}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        )}

        {currentTab === 'contract' && (
          <div className="space-y-6">
            {/*
              * The four verbs, on the tab that shows the contract.
              *
              * Upgrading, adding seats, renewing and cancelling used to mean
              * opening a CRM *deal* against a customer who had already bought —
              * the pipeline built for winning strangers. None of them is a sale.
              */}
            {can?.update && activeSubscription && (
              <div className="flex flex-wrap items-center gap-2 rounded-[18px] border border-ovs-crm-border bg-ovs-crm-surface-inset px-4 py-3">
                <span className="mr-1 text-[12.5px] font-semibold text-ovs-crm-text-primary">Change this contract</span>

                <CrmButton
                  size="sm"
                  tone="primary"
                  icon={RefreshCw}
                  onClick={() => setContractAction('renew')}
                >
                  Renew
                </CrmButton>
                <CrmButton
                  size="sm"
                  tone="secondary"
                  icon={Sparkles}
                  onClick={() => setContractAction('change-plan')}
                >
                  Change plan
                </CrmButton>
                <CrmButton
                  size="sm"
                  tone="secondary"
                  icon={Users}
                  onClick={() => setContractAction('entitlements')}
                >
                  Adjust seats
                </CrmButton>
                <CrmButton
                  size="sm"
                  tone="ghost"
                  onClick={() => setContractAction('cancel')}
                >
                  Cancel
                </CrmButton>
              </div>
            )}

            <div className="bg-white p-6 rounded-3xl border border-ovs-crm-border shadow-2xs space-y-4">
              <h3 className="text-base font-bold text-ovs-crm-text-primary">Active Contract & Billing</h3>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="p-4 rounded-2xl bg-ovs-crm-surface-inset border border-ovs-crm-border">
                  <div className="text-xs text-ovs-crm-text-muted uppercase font-semibold">Plan Tier</div>
                  <div className="text-lg font-black text-ovs-crm-text-primary mt-1">{activeSubscription?.planName ?? 'Standard'}</div>
                  <div className="text-xs text-ovs-crm-text-secondary mt-0.5">{activeSubscription?.billingPeriod ?? 'Monthly'}</div>
                </div>
                <div className="p-4 rounded-2xl bg-ovs-crm-surface-inset border border-ovs-crm-border">
                  <div className="text-xs text-ovs-crm-text-muted uppercase font-semibold">Contracted MRR</div>
                  <div className="text-lg font-black text-ovs-crm-text-primary mt-1">{activeSubscription?.mrr ?? '—'}</div>
                  <div className="text-xs text-ovs-crm-text-secondary mt-0.5">ARR: {activeSubscription?.arr ?? '—'}</div>
                </div>
                <div className="p-4 rounded-2xl bg-ovs-crm-surface-inset border border-ovs-crm-border">
                  <div className="text-xs text-ovs-crm-text-muted uppercase font-semibold">Validity Term</div>
                  <div className="text-lg font-black text-ovs-crm-text-primary mt-1">{activeSubscription?.renewalDate ?? '—'}</div>
                  <div className="text-xs text-ovs-crm-text-secondary mt-0.5">
                    Starts: {activeSubscription?.startsAt ?? '—'}
                  </div>
                </div>
              </div>
            </div>

            <CrmSection flush title="Contract history" description="Every subscription this account has held.">
              <CrmTable<SubscriptionSummary>
                columns={[
                  {
                    key: 'plan',
                    header: 'Plan',
                    mobileLabel: 'Plan',
                    primary: true,
                    width: '30%',
                    cell: (row) => (
                      <span className="text-[12.5px] font-semibold text-ovs-crm-text-primary">{row.planName}</span>
                    ),
                  },
                  {
                    key: 'status',
                    header: 'Status',
                    mobileLabel: 'Status',
                    width: '16%',
                    cell: (row) => <CrmStatusPill tone={statusTone(row.status)}>{humanize(row.status)}</CrmStatusPill>,
                  },
                  {
                    key: 'mrr',
                    header: 'MRR',
                    mobileLabel: 'MRR',
                    width: '18%',
                    align: 'right',
                    cell: (row) => (
                      <span className="tabular-nums text-[12.5px] font-medium text-ovs-crm-text-primary">{row.mrr}</span>
                    ),
                  },
                  {
                    key: 'arr',
                    header: 'ARR',
                    mobileLabel: 'ARR',
                    width: '18%',
                    align: 'right',
                    hideOnMobile: true,
                    cell: (row) => (
                      <span className="tabular-nums text-[12.5px] text-ovs-crm-text-secondary">{row.arr}</span>
                    ),
                  },
                  {
                    key: 'renews',
                    header: 'Renews',
                    mobileLabel: 'Renews',
                    width: '18%',
                    cell: (row) => (
                      <span className="text-[12.5px] text-ovs-crm-text-secondary">{row.renewalDate}</span>
                    ),
                  },
                ]}
                rows={subscriptions ?? []}
                rowKey={(row) => row.id}
                isLoading={! subscriptions}
                empty={{
                  icon: CreditCard,
                  title: 'No contract history',
                  description: 'A subscription is written when this account is provisioned from a won deal.',
                }}
              />
            </CrmSection>
          </div>
        )}

        {/* ── TAB 5: USAGE & CAPACITY ── */}
        {currentTab === 'contract' && (
          <div className="space-y-6">
            {!entitlements ? (
              <DeferredSkeleton rows={3} height="h-28" />
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="bg-white p-6 rounded-3xl border border-ovs-crm-border shadow-2xs space-y-4">
                  <h3 className="text-sm font-bold text-ovs-crm-text-primary uppercase tracking-wider">Seat Allocation</h3>
                  <div className="flex items-baseline justify-between">
                    <span className="text-3xl font-black text-ovs-crm-text-primary">
                      {entitlements.used_seats} / {entitlements.purchased_seats}
                    </span>
                    <CrmStatusPill tone={entitlements.seat_utilization_pct && entitlements.seat_utilization_pct >= 90 ? 'warn' : 'good'}>
                      {entitlements.seat_utilization_pct ?? 0}% Utilized
                    </CrmStatusPill>
                  </div>
                  <div className="w-full bg-ovs-crm-surface-inset h-3 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-ovs-crm-brand rounded-full"
                      style={{ width: `${Math.min(100, entitlements.seat_utilization_pct ?? 0)}%` }}
                    />
                  </div>
                  <p className="text-xs text-ovs-crm-text-secondary">
                    Allocated user licenses across clinicians, dentists, front-desk and support teams.
                  </p>
                </div>

                <div className="bg-white p-6 rounded-3xl border border-ovs-crm-border shadow-2xs space-y-4">
                  <h3 className="text-sm font-bold text-ovs-crm-text-primary uppercase tracking-wider">Branch Capacity</h3>
                  <div className="flex items-baseline justify-between">
                    <span className="text-3xl font-black text-ovs-crm-text-primary">
                      {entitlements.active_branches} / {entitlements.purchased_branches}
                    </span>
                    <CrmStatusPill tone={entitlements.branch_utilization_pct && entitlements.branch_utilization_pct >= 90 ? 'warn' : 'good'}>
                      {entitlements.branch_utilization_pct ?? 0}% Utilized
                    </CrmStatusPill>
                  </div>
                  <div className="w-full bg-ovs-crm-surface-inset h-3 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-ovs-crm-success-text rounded-full"
                      style={{ width: `${Math.min(100, entitlements.branch_utilization_pct ?? 0)}%` }}
                    />
                  </div>
                  <p className="text-xs text-ovs-crm-text-secondary">
                    Physical clinic locations actively operating on the company instance.
                  </p>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Contract tab, continued: the licence this contract entitles them to. */}
        {currentTab === 'contract' && (
          <div className="space-y-6">
            {!license ? (
              <DeferredSkeleton rows={2} height="h-28" />
            ) : (
              <div className="bg-white p-6 rounded-3xl border border-ovs-crm-border shadow-2xs space-y-6">
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="text-base font-bold text-ovs-crm-text-primary">Software License & Modules</h3>
                    <p className="text-xs text-ovs-crm-text-secondary mt-0.5">Instance licensing entitlements and module provisioning.</p>
                  </div>
                  <CrmStatusPill tone={license.is_active ? 'good' : 'bad'}>
                    {license.is_active ? 'License Active' : 'Suspended'}
                  </CrmStatusPill>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div className="p-4 rounded-2xl bg-ovs-crm-surface-inset border border-ovs-crm-border">
                    <div className="text-xs text-ovs-crm-text-muted font-semibold uppercase">License Key</div>
                    <div className="text-xs font-mono font-bold text-ovs-crm-text-primary mt-1 break-all">{license.license_key}</div>
                  </div>
                  <div className="p-4 rounded-2xl bg-ovs-crm-surface-inset border border-ovs-crm-border">
                    <div className="text-xs text-ovs-crm-text-muted font-semibold uppercase">License Type</div>
                    <div className="text-sm font-bold text-ovs-crm-text-primary mt-1 uppercase">{license.license_type}</div>
                  </div>
                  <div className="p-4 rounded-2xl bg-ovs-crm-surface-inset border border-ovs-crm-border">
                    <div className="text-xs text-ovs-crm-text-muted font-semibold uppercase">Maximum Chairs / Branches</div>
                    <div className="text-sm font-bold text-ovs-crm-text-primary mt-1">{license.max_branches} locations</div>
                  </div>
                </div>

                <div>
                  <h4 className="text-xs font-bold text-ovs-crm-text-secondary uppercase tracking-wider mb-2">Enabled Modules</h4>
                  <div className="flex flex-wrap gap-2">
                    {license.enabled_modules.length === 0 ? (
                      <span className="text-xs text-ovs-crm-text-muted">No custom modules activated.</span>
                    ) : (
                      license.enabled_modules.map((m, idx) => (
                        <span
                          key={idx}
                          className="px-3 py-1 rounded-xl text-xs font-semibold bg-ovs-crm-brand-soft text-ovs-crm-brand-text border border-ovs-crm-brand-border"
                        >
                          {m}
                        </span>
                      ))
                    )}
                  </div>
                </div>
              </div>
            )}
          </div>
        )}

        {/* ── TAB 8: CONTACTS ── */}
        {currentTab === 'people' && (
          <CrmSection
            flush
            title="Contacts"
            description="The people at this customer, and who can actually decide."
          >
            <CrmTable<ContactRow>
              columns={[
                {
                  key: 'name',
                  header: 'Contact',
                  mobileLabel: 'Contact',
                  primary: true,
                  width: '24%',
                  cell: (row) => (
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="text-[13px] font-semibold text-ovs-crm-text-primary">{row.name}</span>
                      {row.is_primary && <CrmStatusPill tone="info">Primary</CrmStatusPill>}
                    </span>
                  ),
                },
                {
                  key: 'role',
                  header: 'Role',
                  mobileLabel: 'Role',
                  width: '18%',
                  cell: (row) => (
                    <span className="text-[12.5px] text-ovs-crm-text-secondary">
                      {row.designation || humanize(row.role ?? '') || '—'}
                    </span>
                  ),
                },
                {
                  key: 'email',
                  header: 'Email',
                  mobileLabel: 'Email',
                  width: '24%',
                  cell: (row) =>
                    row.email ? (
                      <a
                        href={`mailto:${row.email}`}
                        onClick={(event) => event.stopPropagation()}
                        className="truncate text-[12.5px] text-ovs-crm-text-secondary underline-offset-2 hover:text-ovs-crm-text-primary hover:underline"
                      >
                        {row.email}
                      </a>
                    ) : (
                      <span className="text-ovs-crm-text-muted">—</span>
                    ),
                },
                {
                  key: 'phone',
                  header: 'Phone',
                  mobileLabel: 'Phone',
                  width: '15%',
                  hideOnMobile: true,
                  cell: (row) => (
                    <span className="tabular-nums text-[12.5px] text-ovs-crm-text-secondary">{row.phone || '—'}</span>
                  ),
                },
                {
                  key: 'authority',
                  header: 'Authority',
                  mobileLabel: 'Authority',
                  width: '15%',
                  cell: (row) => (
                    <CrmStatusPill tone={row.decision_power === 'decision_maker' ? 'good' : 'neutral'}>
                      {humanize(row.decision_power ?? '') || 'Stakeholder'}
                    </CrmStatusPill>
                  ),
                },
              ]}
              rows={contacts ?? []}
              rowKey={(row) => row.id}
              isLoading={! contacts}
              empty={{
                icon: Users,
                title: 'No contacts on this account',
                description:
                  'Contacts arrive with the deal that won this customer, and can be added on the Deal Profile in CRM.',
              }}
            />
          </CrmSection>
        )}

        {/* ── TAB 9: CLINICS & BRANCHES ── */}
        {currentTab === 'setup' && (
          <div className="space-y-6">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="text-base font-bold text-ovs-crm-text-primary">Operational Clinic Hierarchy</h3>
                <p className="text-xs text-ovs-crm-text-secondary mt-0.5">Clinics and physical branch locations mapped to this account.</p>
              </div>
              {canManageStructure && (
                <button
                  type="button"
                  onClick={openCreateClinic}
                  className="px-3.5 py-2 rounded-xl text-xs font-bold bg-ovs-crm-brand text-white hover:bg-ovs-crm-brand-strong transition-all flex items-center gap-1.5"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>Add Clinic</span>
                </button>
              )}
            </div>

            {!clinics ? (
              <DeferredSkeleton rows={3} height="h-32" />
            ) : clinics.length === 0 ? (
              <div className="bg-white p-12 text-center rounded-3xl border border-ovs-crm-border">
                <Building className="w-12 h-12 text-ovs-crm-text-muted mx-auto mb-3" />
                <h4 className="text-sm font-bold text-ovs-crm-text-primary">No clinics provisioned yet</h4>
                <p className="text-xs text-ovs-crm-text-secondary mt-1 max-w-sm mx-auto">
                  Create the primary clinic entity to begin configuring branches and chair allocations.
                </p>
              </div>
            ) : (
              <div className="grid grid-cols-1 gap-6">
                {clinics.map((clinic) => (
                  <div key={clinic.id} className="bg-white rounded-3xl border border-ovs-crm-border shadow-2xs overflow-hidden">
                    <div className="p-5 border-b border-ovs-crm-border flex items-center justify-between bg-ovs-crm-surface-inset/50">
                      <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-xl bg-ovs-crm-brand-soft border border-ovs-crm-brand-border flex items-center justify-center text-ovs-crm-brand-text font-bold">
                          <Building className="w-4 h-4" />
                        </div>
                        <div>
                          <h4 className="text-sm font-bold text-ovs-crm-text-primary">{clinic.name}</h4>
                          <span className="text-[11px] font-mono text-ovs-crm-text-muted">/{clinic.slug}</span>
                        </div>
                      </div>
                      <div className="flex items-center gap-2">
                        <CrmStatusPill tone={clinic.status === 'active' ? 'good' : 'neutral'}>
                          {clinic.status}
                        </CrmStatusPill>
                        {canManageStructure && (
                          <>
                            <button
                              type="button"
                              onClick={() => openEditClinic(clinic)}
                              className="px-2.5 py-1 text-xs font-semibold text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary bg-white border border-ovs-crm-border rounded-lg shadow-2xs"
                            >
                              Edit
                            </button>
                            <button
                              type="button"
                              onClick={() => openCreateBranch(clinic)}
                              className="px-2.5 py-1 text-xs font-semibold text-ovs-crm-brand-text hover:text-ovs-crm-brand-text bg-ovs-crm-brand-soft border border-ovs-crm-brand-border rounded-lg"
                            >
                              + Branch
                            </button>
                          </>
                        )}
                      </div>
                    </div>

                    <div className="p-5">
                      <h5 className="text-xs font-bold uppercase tracking-wider text-ovs-crm-text-muted mb-3">Branches</h5>
                      <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                        {clinic.branches.map((b) => (
                          <div
                            key={b.id}
                            className="p-4 rounded-2xl border border-ovs-crm-border bg-white shadow-2xs flex flex-col justify-between"
                          >
                            <div>
                              <div className="flex items-center justify-between mb-1.5">
                                <span className="font-bold text-xs text-ovs-crm-text-primary">{b.name}</span>
                                {b.is_main && <CrmStatusPill tone="info">Main</CrmStatusPill>}
                              </div>
                              <div className="text-xs text-ovs-crm-text-secondary">
                                {b.city ? `${b.city}, ${b.state ?? ''}` : 'Location pending'}
                              </div>
                              <div className="text-xs text-ovs-crm-brand-text font-semibold mt-1">
                                {b.chairs ?? 1} Dental Chairs
                              </div>
                            </div>
                            {canManageStructure && (
                              <div className="mt-3 pt-2 border-t border-ovs-crm-border flex justify-end">
                                <button
                                  type="button"
                                  onClick={() => openEditBranch(clinic, b)}
                                  className="text-[11px] font-semibold text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
                                >
                                  Edit Branch
                                </button>
                              </div>
                            )}
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* ── TAB 10: COMMERCIAL RECORD ── */}
        {currentTab === 'records' && (
          <div className="space-y-4">
            {/*
             * The paper trail, in the order it is actually produced: a quotation
             * is raised, an approved one settles into an invoice, and documents
             * are whatever else was attached along the way. Three lists rather
             * than one, because they answer three different questions.
             */}
            <CrmSection flush title="Invoices" description="Raised once an approved quotation settles in full.">
              <CrmTable<InvoiceRow>
                columns={[
                  {
                    key: 'number',
                    header: 'Invoice',
                    mobileLabel: 'Invoice',
                    primary: true,
                    width: '24%',
                    cell: (row) => (
                      <span className="font-mono text-[12.5px] font-semibold text-ovs-crm-text-primary">
                        {row.invoice_number}
                      </span>
                    ),
                  },
                  {
                    key: 'status',
                    header: 'Status',
                    mobileLabel: 'Status',
                    width: '16%',
                    cell: (row) => <CrmStatusPill tone={statusTone(row.status)}>{humanize(row.status)}</CrmStatusPill>,
                  },
                  {
                    key: 'amount',
                    header: 'Amount',
                    mobileLabel: 'Amount',
                    width: '18%',
                    align: 'right',
                    cell: (row) => (
                      <span className="tabular-nums text-[12.5px] font-medium text-ovs-crm-text-primary">
                        {inr(row.total_amount)}
                      </span>
                    ),
                  },
                  {
                    key: 'issued',
                    header: 'Issued',
                    mobileLabel: 'Issued',
                    width: '16%',
                    hideOnMobile: true,
                    cell: (row) => <span className="text-[12.5px] text-ovs-crm-text-secondary">{row.issued_at || '—'}</span>,
                  },
                  {
                    key: 'paid',
                    header: 'Paid',
                    mobileLabel: 'Paid',
                    width: '16%',
                    cell: (row) =>
                      row.paid_at ? (
                        <span className="text-[12.5px] text-ovs-crm-text-secondary">{row.paid_at}</span>
                      ) : (
                        <CrmStatusPill tone="warn">Outstanding</CrmStatusPill>
                      ),
                  },
                ]}
                rows={invoices ?? []}
                rowKey={(row) => row.id}
                isLoading={! invoices}
                empty={{
                  icon: FileText,
                  title: 'No invoices yet',
                  description: 'An invoice is raised when an approved quotation is settled in full.',
                }}
              />
            </CrmSection>

            <CrmSection flush title="Quotations" description="What was priced for this customer.">
              <CrmTable<QuotationRow>
                columns={[
                  {
                    key: 'number',
                    header: 'Quotation',
                    mobileLabel: 'Quotation',
                    primary: true,
                    width: '30%',
                    cell: (row) => (
                      <span className="font-mono text-[12.5px] font-semibold text-ovs-crm-text-primary">
                        {row.quotation_number}
                      </span>
                    ),
                  },
                  {
                    key: 'status',
                    header: 'Status',
                    mobileLabel: 'Status',
                    width: '22%',
                    cell: (row) => <CrmStatusPill tone={statusTone(row.status)}>{humanize(row.status)}</CrmStatusPill>,
                  },
                  {
                    key: 'amount',
                    header: 'Amount',
                    mobileLabel: 'Amount',
                    width: '24%',
                    align: 'right',
                    cell: (row) => (
                      <span className="tabular-nums text-[12.5px] font-medium text-ovs-crm-text-primary">
                        {inr(row.total_amount)}
                      </span>
                    ),
                  },
                  {
                    key: 'raised',
                    header: 'Raised',
                    mobileLabel: 'Raised',
                    width: '24%',
                    hideOnMobile: true,
                    cell: (row) => (
                      <span className="text-[12.5px] text-ovs-crm-text-secondary">{row.created_at || '—'}</span>
                    ),
                  },
                ]}
                rows={quotations ?? []}
                rowKey={(row) => row.id}
                isLoading={! quotations}
                empty={{
                  icon: FileText,
                  title: 'No quotations',
                  description: 'Quotations are raised on the deal in CRM and appear here once this customer exists.',
                }}
              />
            </CrmSection>

            <CrmSection flush title="Documents" description="Agreements and other paper attached to this account.">
              <CrmTable<CommercialDocumentRow>
                columns={[
                  {
                    key: 'number',
                    header: 'Document',
                    mobileLabel: 'Document',
                    primary: true,
                    width: '30%',
                    cell: (row) => (
                      <span className="font-mono text-[12.5px] font-semibold text-ovs-crm-text-primary">
                        {row.document_number}
                      </span>
                    ),
                  },
                  {
                    key: 'type',
                    header: 'Type',
                    mobileLabel: 'Type',
                    width: '22%',
                    cell: (row) => (
                      <span className="text-[12.5px] text-ovs-crm-text-secondary">{humanize(row.type)}</span>
                    ),
                  },
                  {
                    key: 'status',
                    header: 'Status',
                    mobileLabel: 'Status',
                    width: '24%',
                    cell: (row) => <CrmStatusPill tone={statusTone(row.status)}>{humanize(row.status)}</CrmStatusPill>,
                  },
                  {
                    key: 'amount',
                    header: 'Amount',
                    mobileLabel: 'Amount',
                    width: '24%',
                    align: 'right',
                    hideOnMobile: true,
                    cell: (row) => (
                      <span className="tabular-nums text-[12.5px] text-ovs-crm-text-primary">{inr(row.total_amount)}</span>
                    ),
                  },
                ]}
                rows={commercialDocuments ?? []}
                rowKey={(row) => row.id}
                isLoading={! commercialDocuments}
                empty={{
                  icon: FileText,
                  title: 'No documents',
                  description: 'Agreements raised against this customer’s deals appear here.',
                }}
              />
            </CrmSection>
          </div>
        )}

        {currentTab === 'records' && (
          <CrmSection
            flush
            title="Support"
            description="Tickets raised against this customer. The workflow lives in Support; this is the account's view of it."
            actions={
              <CrmButton
                size="sm"
                tone="secondary"
                icon={ExternalLink}
                href={`${route('company.support.index')}?search=${encodeURIComponent(account.name)}`}
              >
                Open in Support
              </CrmButton>
            }
          >
            <CrmTable<SupportTicketRow>
              columns={[
                {
                  key: 'ticket',
                  header: 'Ticket',
                  mobileLabel: 'Ticket',
                  primary: true,
                  width: '34%',
                  cell: (row) => (
                    <span className="block min-w-0">
                      <span className="block truncate text-[13px] font-semibold text-ovs-crm-text-primary">
                        {row.subject}
                      </span>
                      <span className="block truncate font-mono text-[11.5px] text-ovs-crm-text-muted">
                        {row.reference}
                        {row.category ? ` · ${humanize(row.category)}` : ''}
                      </span>
                    </span>
                  ),
                },
                {
                  key: 'status',
                  header: 'Status',
                  mobileLabel: 'Status',
                  width: '140px',
                  cell: (row) => (
                    <span className="flex flex-wrap items-center gap-1.5">
                      <CrmStatusPill tone={statusTone(row.status)}>{humanize(row.status)}</CrmStatusPill>
                      {row.breaching_sla && <CrmStatusPill tone="bad">SLA breached</CrmStatusPill>}
                    </span>
                  ),
                },
                {
                  key: 'priority',
                  header: 'Priority',
                  mobileLabel: 'Priority',
                  width: '120px',
                  cell: (row) => (
                    <CrmStatusPill tone={priorityTone(row.priority)}>{humanize(row.priority)}</CrmStatusPill>
                  ),
                },
                {
                  key: 'opened',
                  header: 'Opened',
                  mobileLabel: 'Opened',
                  width: '130px',
                  hideOnMobile: true,
                  cell: (row) => (
                    <span className="text-[12.5px] text-ovs-crm-text-secondary">{row.opened_at ?? '—'}</span>
                  ),
                },
                {
                  key: 'resolved',
                  header: 'Resolved',
                  mobileLabel: 'Resolved',
                  width: '130px',
                  cell: (row) =>
                    row.resolved_at ? (
                      <span className="text-[12.5px] text-ovs-crm-text-secondary">{row.resolved_at}</span>
                    ) : (
                      <CrmStatusPill tone="warn">Open</CrmStatusPill>
                    ),
                },
              ]}
              rows={supportTickets ?? []}
              rowKey={(row) => row.id}
              isLoading={! supportTickets}
              onRowClick={(row) => router.visit(route('company.support.show', row.id))}
              empty={{
                icon: LifeBuoy,
                title: 'No tickets from this customer',
                description: 'Support tickets raised against this account appear here as they are opened.',
              }}
            />
          </CrmSection>
        )}

        {/* ── TAB 11: TIMELINE & TASKS ── */}
        {currentTab === 'overview' && (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div className="lg:col-span-2">
              <CrmSection
                flush
                title="Activity"
                description="Everything recorded against this account, newest first."
              >
                <CrmTable<ActivityRow>
                  columns={[
                    {
                      key: 'what',
                      header: 'What happened',
                      mobileLabel: 'What happened',
                      primary: true,
                      cell: (row) => (
                        <span className="block min-w-0">
                          <span className="block text-[12.5px] text-ovs-crm-text-primary">{row.description}</span>
                          <span className="block text-[11.5px] text-ovs-crm-text-muted">
                            {row.user?.name ?? 'System'}
                            {row.created_at ? ` · ${new Date(row.created_at).toLocaleString()}` : ''}
                          </span>
                        </span>
                      ),
                    },
                    {
                      key: 'type',
                      header: 'Type',
                      mobileLabel: 'Type',
                      width: '150px',
                      cell: (row) => (
                        <CrmStatusPill tone={channelTone(row.type)}>{humanize(row.type)}</CrmStatusPill>
                      ),
                    },
                  ]}
                  rows={activities ?? []}
                  rowKey={(row) => row.id}
                  isLoading={! activities}
                  empty={{
                    icon: Activity,
                    title: 'Nothing recorded yet',
                    description:
                      'Health changes, onboarding steps, structure changes and deals raised from here all land on this timeline.',
                  }}
                />
              </CrmSection>
            </div>

            <CrmSection flush title="Tasks" description="Open work on this account.">
              <CrmTable<TaskRow>
                columns={[
                  {
                    key: 'title',
                    header: 'Task',
                    mobileLabel: 'Task',
                    primary: true,
                    cell: (row) => (
                      <span className="block min-w-0">
                        <span className="block text-[12.5px] font-medium text-ovs-crm-text-primary">{row.title}</span>
                        {row.due_at && (
                          <span className="block text-[11.5px] text-ovs-crm-text-muted">
                            Due {new Date(row.due_at).toLocaleDateString()}
                          </span>
                        )}
                      </span>
                    ),
                  },
                  {
                    key: 'status',
                    header: 'Status',
                    mobileLabel: 'Status',
                    width: '110px',
                    cell: (row) => <CrmStatusPill tone={statusTone(row.status)}>{humanize(row.status)}</CrmStatusPill>,
                  },
                ]}
                rows={tasks ?? []}
                rowKey={(row) => row.id}
                isLoading={! tasks}
                empty={{
                  icon: CheckCircle2,
                  title: 'No open tasks',
                  description: 'Tasks assigned against this account appear here.',
                }}
              />
            </CrmSection>
          </div>
        )}
      </div>

      {/* Health Update Modal */}
      <CrmModal
        open={showHealthModal}
        onClose={() => setShowHealthModal(false)}
        title={`Update Health Metrics — ${account.name}`}
      >
        <form onSubmit={handleUpdateHealth} className="space-y-4">
          <div>
            <label className="block text-xs font-semibold text-ovs-crm-text-secondary mb-1">Health Score (0 - 100)</label>
            <input
              type="number"
              min="0"
              max="100"
              value={healthScore}
              onChange={(e) => setHealthScore(parseInt(e.target.value) || 0)}
              className="w-full px-3 py-2 rounded-xl text-xs border border-ovs-crm-border"
              required
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-ovs-crm-text-secondary mb-1">Churn Risk Assessment</label>
            <select
              value={churnRisk}
              onChange={(e) => setChurnRisk(e.target.value)}
              className="w-full px-3 py-2 rounded-xl text-xs border border-ovs-crm-border"
            >
              <option value="Low">Low Risk</option>
              <option value="Medium">Medium Risk</option>
              <option value="High">High Risk</option>
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-ovs-crm-text-secondary mb-1">Renewal Probability (%)</label>
            <input
              type="number"
              min="0"
              max="100"
              value={renewalProbability}
              onChange={(e) => setRenewalProbability(parseInt(e.target.value) || 0)}
              className="w-full px-3 py-2 rounded-xl text-xs border border-ovs-crm-border"
              required
            />
          </div>

          <div className="flex items-center gap-2 pt-1">
            <input
              type="checkbox"
              id="expansion_opp"
              checked={expansionOpp}
              onChange={(e) => setExpansionOpp(e.target.checked)}
              className="rounded text-ovs-crm-brand-text focus:ring-ovs-crm-brand"
            />
            <label htmlFor="expansion_opp" className="text-xs font-semibold text-ovs-crm-text-secondary">
              Flag as Expansion Opportunity (+seats/branches)
            </label>
          </div>

          <div>
            <label className="block text-xs font-semibold text-ovs-crm-text-secondary mb-1">Notes / Rationale</label>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Reason for metric changes..."
              rows={3}
              className="w-full px-3 py-2 rounded-xl text-xs border border-ovs-crm-border"
            />
          </div>

          <div className="flex justify-end gap-2 pt-3 border-t border-ovs-crm-border">
            <button
              type="button"
              onClick={() => setShowHealthModal(false)}
              className="px-4 py-2 rounded-xl text-xs font-semibold text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="px-4 py-2 rounded-xl text-xs font-bold bg-ovs-crm-brand text-white hover:bg-ovs-crm-brand-strong"
            >
              Save Changes
            </button>
          </div>
        </form>
      </CrmModal>

      {/* Clinic Create / Edit Modal */}
      <CrmModal
        open={clinicForm.open}
        onClose={() => setClinicForm((f) => ({ ...f, open: false }))}
        title={clinicForm.id ? 'Edit Clinic' : 'Create Clinic'}
      >
        <form onSubmit={submitClinic} className="space-y-4">
          <div>
            <label className="block text-xs font-semibold text-ovs-crm-text-secondary mb-1">Clinic Name</label>
            <input
              type="text"
              value={clinicForm.name}
              onChange={(e) => setClinicForm((f) => ({ ...f, name: e.target.value }))}
              placeholder="e.g. Apex South Clinic"
              className="w-full px-3 py-2 rounded-xl text-xs border border-ovs-crm-border"
              required
            />
          </div>

          {!clinicForm.id && (
            <div>
              <label className="block text-xs font-semibold text-ovs-crm-text-secondary mb-1">Primary Branch Name (Optional)</label>
              <input
                type="text"
                value={clinicForm.primaryBranch}
                onChange={(e) => setClinicForm((f) => ({ ...f, primaryBranch: e.target.value }))}
                placeholder="Default: Main Branch"
                className="w-full px-3 py-2 rounded-xl text-xs border border-ovs-crm-border"
              />
            </div>
          )}

          {! clinicForm.id && (eligibleOwners?.length ?? 0) > 0 && (
            <div>
              <label className="block text-xs font-semibold text-ovs-crm-text-secondary mb-1">Primary Owner (Optional)</label>
              <select
                value={clinicForm.ownerId}
                onChange={(e) => setClinicForm((f) => ({ ...f, ownerId: e.target.value }))}
                className="w-full px-3 py-2 rounded-xl text-xs border border-ovs-crm-border"
              >
                <option value="">Unassigned</option>
                {(eligibleOwners ?? []).map((owner) => (
                  <option key={owner.id} value={owner.id}>
                    {owner.name} ({owner.email})
                  </option>
                ))}
              </select>
            </div>
          )}

          <div>
            <label className="block text-xs font-semibold text-ovs-crm-text-secondary mb-1">Operational Status</label>
            <select
              value={clinicForm.status}
              onChange={(e) => setClinicForm((f) => ({ ...f, status: e.target.value }))}
              className="w-full px-3 py-2 rounded-xl text-xs border border-ovs-crm-border"
            >
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>

          <div className="flex justify-end gap-2 pt-3 border-t border-ovs-crm-border">
            <button
              type="button"
              onClick={() => setClinicForm((f) => ({ ...f, open: false }))}
              className="px-4 py-2 rounded-xl text-xs font-semibold text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="px-4 py-2 rounded-xl text-xs font-bold bg-ovs-crm-brand text-white hover:bg-ovs-crm-brand-strong"
            >
              {clinicForm.id ? 'Update Clinic' : 'Create Clinic'}
            </button>
          </div>
        </form>
      </CrmModal>

      {/* Branch Create / Edit Modal */}
      <CrmModal
        open={branchForm.open}
        onClose={() => setBranchForm((f) => ({ ...f, open: false }))}
        title={branchForm.id ? 'Edit Branch' : 'Add Branch Location'}
      >
        <form onSubmit={submitBranch} className="space-y-4">
          <div>
            <label className="block text-xs font-semibold text-ovs-crm-text-secondary mb-1">Branch Name</label>
            <input
              type="text"
              value={branchForm.name}
              onChange={(e) => setBranchForm((f) => ({ ...f, name: e.target.value }))}
              placeholder="e.g. Andheri West Main"
              className="w-full px-3 py-2 rounded-xl text-xs border border-ovs-crm-border"
              required
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-ovs-crm-text-secondary mb-1">Operational Status</label>
            <select
              value={branchForm.status}
              onChange={(e) => setBranchForm((f) => ({ ...f, status: e.target.value }))}
              className="w-full px-3 py-2 rounded-xl text-xs border border-ovs-crm-border"
            >
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>

          <div className="flex items-center gap-2 pt-1">
            <input
              type="checkbox"
              id="is_main_branch"
              checked={branchForm.isMain}
              onChange={(e) => setBranchForm((f) => ({ ...f, isMain: e.target.checked }))}
              className="rounded text-ovs-crm-brand-text focus:ring-ovs-crm-brand"
            />
            <label htmlFor="is_main_branch" className="text-xs font-semibold text-ovs-crm-text-secondary">
              Set as Main Branch for this Clinic
            </label>
          </div>

          <div className="flex justify-end gap-2 pt-3 border-t border-ovs-crm-border">
            <button
              type="button"
              onClick={() => setBranchForm((f) => ({ ...f, open: false }))}
              className="px-4 py-2 rounded-xl text-xs font-semibold text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset"
            >
              Cancel
            </button>
            <button
              type="submit"
              className="px-4 py-2 rounded-xl text-xs font-bold bg-ovs-crm-brand text-white hover:bg-ovs-crm-brand-strong"
            >
              {branchForm.id ? 'Save Branch' : 'Add Branch'}
            </button>
          </div>
        </form>
      </CrmModal>

      {confirmDialog}
      {/*
        * One modal for all four contract verbs, shared with the renewals queue
        * — four copies of a money form is four places for them to drift.
        */}
      <ContractActionModal
        action={contractAction}
        contract={
          activeSubscription
            ? {
              id: activeSubscription.id,
              account: account.name,
              planName: activeSubscription.planName,
              mrr: activeSubscription.mrr_raw ?? null,
              maxUsers: entitlements?.purchased_seats ?? null,
              maxBranches: entitlements?.purchased_branches ?? null,
              endsAt: activeSubscription.endsAt ?? null,
            }
            : null
        }
        plans={plans ?? []}
        onClose={() => setContractAction(null)}
      />
    </CustomerSuccessShell>
  );
}
