import { Link } from '@inertiajs/react';
import { Building2, MapPin } from 'lucide-react';
import { cn } from '@/lib/utils';
import { clinicDisplayName, isCurrent } from '../context';
import type { ClinicContextProps, NavDestination, OwnerSubscription } from '../types';
import { iconFor } from '../ui/visuals';

const SIDEBAR_SECTIONS = ['main', 'additional'] as const;

/** The server's sidebar entries (`ClinicNavigation`) grouped by section, in order; `admin` belongs to the account menu. */
function bySection(entries: NavDestination[]): { key: string; label: string; items: NavDestination[] }[] {
  return SIDEBAR_SECTIONS.map((key) => {
    const items = entries.filter((entry) => entry.section === key);

    return { key, label: items[0]?.section_label ?? '', items };
  }).filter((section) => section.items.length > 0);
}

const FOCUS ='focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal focus-visible:ring-offset-1 focus-visible:ring-offset-cx-nav-bg';

/**
 * The portal's navigation (the approved design's sidebar): the OralCares
 * wordmark (type alone, no symbol), the Main and Additional destinations this
 * user may open, each with a plain line icon, and the clinic they are working
 * in. Neutral for every specialty: no tooth or specialty marks here, those
 * belong inside their own modules. A destination whose new
 * screen is not built yet is listed as coming soon instead of linking to a
 * placeholder. The list is the server's, and every route checks again.
 *
 * The column scrolls as one only when the window is too short to hold it
 * (the list first, then the clinic), without a visible scrollbar; nothing is
 * ever hidden.
 */
export function Sidebar({ clinic, currentUrl, onNavigate }: {
  clinic: ClinicContextProps;
  currentUrl: string;
  onNavigate?: () => void;
}) {
  const name = clinicDisplayName(clinic);
  const sections = bySection(clinic.navigation);
  // The branch being worked, from the server's active branch (its display name, else its name). With one branch,
  // only a name somebody chose is worth saying — not provisioning's fallback (`name_is_default`); with several, always.
  // With none: the owner's all-branches view says so; anyone else shows no branch rather than a made-up one.
  const branch = clinic.active_branch;
  const branchName = branch
    ? clinic.accessible_branch_count > 1 || !branch.name_is_default
      ? branch.display_name?.trim() || branch.name
      : null
    : clinic.is_owner && clinic.accessible_branch_count > 1
      ? 'All branches'
      : null;

  return (
    <div className="flex h-full flex-col overflow-y-auto overscroll-contain border-r border-cx-nav-line bg-cx-nav-bg text-cx-nav-ink [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      {/* The wordmark: type alone, no symbol. */}
      <div className="flex h-[77px] shrink-0 flex-col justify-center border-b border-cx-nav-line px-6">
        <span className="text-[21px] font-bold leading-none tracking-[-0.025em] text-cx-nav-ink">OralCares</span>
        <span className="mt-1.5 truncate text-[11px] font-medium tracking-[0.02em] text-cx-nav-muted">Smarter Healthcare. Better Care.</span>
      </div>

      <nav
        aria-label="Main"
        className="flex-1 px-3 pb-4 pt-2"
      >
        {sections.map((section, index) => (
          <div key={section.key} className={cn(index > 0 && 'mt-6')}>
            <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-cx-nav-muted">{section.label}</p>
            <ul className="space-y-0.5">
              {section.items.map((item) => (
                <li key={item.key}>
                  <SidebarItem item={item} current={item.available && item.href !== null && isCurrent(item.href, currentUrl)} onNavigate={onNavigate} />
                </li>
              ))}
            </ul>
          </div>
        ))}
      </nav>

      {/* The clinic being worked, from the server's clinic and branch context; the plan only for the owner in Owner Mode. */}
      <ClinicIdentity
        name={name}
        logoUrl={clinic.logo_url}
        branchName={branchName}
        subscription={clinic.owner_subscription ?? null}
        supportHref={clinic.navigation.find((item) => item.key === 'support' && item.available)?.href ?? null}
      />
    </div>
  );
}

function SidebarItem({ item, current, onNavigate }: { item: NavDestination; current: boolean; onNavigate?: () => void }) {
  const Icon = iconFor(item.icon);
  const base = 'relative flex h-10 w-full items-center gap-3 rounded-md px-3 text-[14px]';
  const icon = (className: string) => <Icon className={cn('h-[18px] w-[18px] shrink-0', className)} strokeWidth={1.75} aria-hidden="true" />;

  if (!item.available || item.href === null) {
    return (
      <span aria-disabled="true" title={`${item.label}: coming soon`} className={cn(base, 'cursor-default text-cx-nav-muted')}>
        {icon('opacity-80')}
        <span className="min-w-0 flex-1 truncate">{item.label}</span>
        <span className="text-[10.5px] font-medium uppercase tracking-wide text-cx-nav-muted">Soon</span>
      </span>
    );
  }

  return (
    <Link
      href={item.href}
      onClick={onNavigate}
      aria-current={current ? 'page' : undefined}
      className={cn(base, FOCUS, current ? 'bg-cx-nav-active font-semibold text-cx-nav-ink' : 'font-medium text-cx-nav-ink hover:bg-cx-nav-hover')}
    >
      {current && <span className="absolute inset-y-2 left-0 w-[3px] rounded-r bg-cx-nav-teal" aria-hidden="true" />}
      {icon(current ? 'text-cx-nav-teal-strong' : 'text-cx-nav-muted')}
      <span className="min-w-0 flex-1 truncate">{item.label}</span>
    </Link>
  );
}

const STATE_CHIP: Record<Exclude<OwnerSubscription['state'], 'none'>, { label: string; tone: string }> = {
  active: { label: 'Active', tone: 'bg-cx-green-bg text-cx-green-ink' },
  trial: { label: 'Trial', tone: 'bg-cx-amber-bg text-cx-amber-ink' },
  grace: { label: 'Grace', tone: 'bg-cx-rose-bg text-cx-rose-ink' },
  lapsed: { label: 'Ended', tone: 'bg-cx-rose-bg text-cx-rose-ink' },
};

/** Days left as a person says them. */
function daysLeft(days: number | null): string | null {
  if (days === null) {
    return null;
  }

  return days === 0 ? 'ends today' : days === 1 ? '1 day left' : `${days} days left`;
}

/** The plan's one line: what the state is until when, in the resolver's own terms; no date is invented. */
function subscriptionLine(subscription: OwnerSubscription): string {
  const date = subscription.ends_on
    ? new Date(`${subscription.ends_on}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
    : null;
  const left = daysLeft(subscription.days_remaining);
  const join = (lead: string) => [lead, left].filter(Boolean).join(' · ');

  switch (subscription.state) {
    case 'active':
      return date ? join(`Paid until ${date}`) : 'No end date on record';
    case 'trial':
      return date ? join(`Trial ends ${date}`) : 'Trial';
    case 'grace':
      return date ? join(`Access ends ${date}`) : 'Grace period';
    case 'lapsed':
      return 'Access has ended';
    default:
      return 'No subscription on record';
  }
}

/**
 * The bottom of the sidebar: the clinic's name in capitals (type only — the
 * stored name is unchanged), the branch where there is more than one, and,
 * for the owner in Owner Mode, the plan: its state, the date it ends and the
 * days left, as the server's entitlement resolver states them. A way to ask
 * support about the plan appears when it is close to ending or has ended.
 */
function ClinicIdentity({ name, logoUrl, branchName, subscription, supportHref }: {
  name: string;
  logoUrl: string | null;
  branchName: string | null;
  subscription: OwnerSubscription | null;
  supportHref: string | null;
}) {
  const chip = subscription && subscription.state !== 'none' ? STATE_CHIP[subscription.state] : null;
  const needsAttention = subscription !== null
    && (subscription.state === 'grace' || subscription.state === 'lapsed' || (subscription.days_remaining !== null && subscription.days_remaining <= 14));

  return (
    <section aria-label="Clinic" className="shrink-0 border-t border-cx-nav-line bg-white px-4 pb-4 pt-4">
      <div className="flex items-start gap-3">
        {/* The clinic's own logo where it has one; otherwise a plain building mark, never a medical symbol. */}
        <span className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-cx-nav-bg ring-1 ring-inset ring-cx-nav-line" aria-hidden="true">
          {logoUrl ? (
            <img src={logoUrl} alt="" className="h-full w-full bg-white object-contain p-1" />
          ) : (
            <Building2 className="h-[18px] w-[18px] text-cx-nav-teal-strong" strokeWidth={1.75} />
          )}
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-[10.5px] font-semibold uppercase tracking-[0.12em] text-cx-nav-muted" aria-hidden="true">
            Clinic
          </p>
          {/* Uppercase in the type only: the clinic's name as stored is unchanged. */}
          <p className="mt-0.5 line-clamp-2 break-words text-[13px] font-bold uppercase leading-[1.3] tracking-[0.05em] text-cx-nav-ink" title={name}>
            <span className="sr-only">Clinic: </span>
            {name}
          </p>
        </div>
      </div>

      {/* The branch being worked, shown only: changing it is the top bar's selector. */}
      {branchName && (
        <div className="mt-3 flex items-center gap-2 rounded-md bg-cx-nav-bg px-2.5 py-2 ring-1 ring-inset ring-cx-nav-line">
          <MapPin className="h-3.5 w-3.5 shrink-0 text-cx-nav-teal-strong" strokeWidth={1.75} aria-hidden="true" />
          <p className="min-w-0 truncate text-[12px] font-medium text-cx-nav-ink" title={branchName}>
            <span className="sr-only">Branch: </span>
            {branchName}
          </p>
        </div>
      )}

      {subscription && (
        <div className="mt-3 border-t border-cx-nav-line pt-2.5">
          <div className="flex items-center justify-between gap-2">
            <p className="min-w-0 truncate text-[12.5px] font-semibold text-cx-nav-ink">
              {subscription.state === 'none' ? 'Plan' : (subscription.plan ?? 'Plan')}
            </p>
            {chip && <span className={cn('shrink-0 rounded px-1.5 py-0.5 text-[11px] font-semibold', chip.tone)}>{chip.label}</span>}
          </div>
          <p className="mt-0.5 text-[12px] leading-snug text-cx-nav-muted">{subscriptionLine(subscription)}</p>
          {needsAttention && supportHref && (
            <Link href={supportHref} className={cn('mt-1.5 inline-block rounded text-[12px] font-semibold text-cx-nav-teal-strong hover:underline', FOCUS)}>
              Ask support about your plan
            </Link>
          )}
        </div>
      )}
    </section>
  );
}
