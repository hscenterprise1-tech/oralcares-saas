import { useState } from 'react';
import { Link } from '@inertiajs/react';
import * as Popover from '@radix-ui/react-popover';
import { AlertTriangle, Building2, CalendarClock, ChevronsUpDown, MapPin } from 'lucide-react';
import { cn } from '@/lib/utils';
import { clinicDisplayName, isCurrent } from '../context';
import { specialtyTone, TONE } from '../ds/tones';
import type { ClinicContextProps, NavDestination, OwnerSubscription, PortalLinks } from '../types';
import { iconFor } from '../ui/visuals';
import { BranchList } from './BranchSwitcher';

const SIDEBAR_SECTIONS = ['main', 'additional'] as const;

/** The server's sidebar entries (`ClinicNavigation`) grouped by section, in order; `admin` belongs to the account menu. */
function bySection(entries: NavDestination[]): { key: string; label: string; items: NavDestination[] }[] {
  return SIDEBAR_SECTIONS.map((key) => {
    const items = entries.filter((entry) => entry.section === key);

    return { key, label: items[0]?.section_label ?? '', items };
  }).filter((section) => section.items.length > 0);
}

const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-blue focus-visible:ring-offset-2 focus-visible:ring-offset-transparent';

/** The Reception dashboard's card: white, a hairline, and its soft navy shadow. */
const CARD = 'bg-white shadow-[0_1px_2px_rgba(15,23,42,0.06),0_8px_20px_-16px_rgba(23,59,96,0.35)] ring-1 ring-inset ring-[#DCE6F3]';

/**
 * The portal's navigation (the approved design's sidebar): the OralCares
 * wordmark (type alone, no symbol), the Main and Additional destinations this
 * user may open, each with a plain line icon, and the clinic they are working
 * in. Neutral for every specialty: no tooth or specialty marks here, those
 * belong inside their own modules. A destination whose new
 * screen is not built yet is listed as coming soon instead of linking to a
 * placeholder. The list is the server's, and every route checks again.
 *
 * Its look follows the Command Center's: a white surface with the faintest
 * cyan wash at the top, the current page as one soft teal pill (its icon and
 * label in teal; no extra bar or weight), and the clinic as a quiet card at
 * the foot. `inDrawer` leaves room in the header for the drawer's close button.
 *
 * The column scrolls as one only when the window is too short to hold it
 * (the list first, then the clinic), without a visible scrollbar; nothing is
 * ever hidden.
 */
export function Sidebar({ clinic, links, currentUrl, onNavigate, inDrawer = false }: { clinic: ClinicContextProps; links: PortalLinks; currentUrl: string; onNavigate?: () => void; inDrawer?: boolean }) {
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
    // The Reception dashboard's greeting wash (blue → lavender → mint), run down the column, so the navigation
    // and the dashboard read as one surface; the current page is one of the dashboard's white cards.
    <div className="flex h-full flex-col overflow-y-auto overscroll-contain border-r border-[#DCE6F3] bg-gradient-to-b from-[#EAF2FF] via-[#F3F1FE] to-[#EAF6F2] text-cx-nav-ink [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
      {/* The wordmark: type alone, no symbol; the header lines up with the top bar's 72px. */}
      <div className={cn('flex h-[73px] shrink-0 flex-col justify-center border-b border-[#DCE6F3] px-6', inDrawer && 'pr-16')}>
        <span className="text-[20px] font-semibold leading-none tracking-[-0.02em] text-cx-nav-ink">OralCares</span>
        <span className="mt-1.5 truncate text-[11px] text-cx-text-3">Smarter Healthcare. Better Care.</span>
      </div>

      <nav aria-label="Main" className="flex-1 px-3 pb-4 pt-3">
        {sections.map((section, index) => (
          <div key={section.key} className={cn(index > 0 && 'mt-5')}>
            <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-cx-nav-muted">{section.label}</p>
            <ul className="space-y-1">
              {section.items.map((item) => (
                <li key={item.key}>
                  <SidebarItem item={item} current={item.available && item.href !== null && isCurrent(item.href, currentUrl)} onNavigate={onNavigate} />
                </li>
              ))}
            </ul>
          </div>
        ))}
      </nav>

      {/* The clinic being worked, from the server's clinic and branch context; the plan only when it needs the owner. */}
      <ClinicIdentity
        clinic={clinic}
        links={links}
        name={name}
        branchName={branchName}
        supportHref={clinic.navigation.find((item) => item.key === 'support' && item.available)?.href ?? null}
      />
    </div>
  );
}

/**
 * One destination, 40px tall, its 18px icon and label on one line. The
 * current page is a soft teal pill with its icon and label in teal; a page
 * not built yet is muted with a small "Soon" tag and is not a link.
 */
function SidebarItem({ item, current, onNavigate }: { item: NavDestination; current: boolean; onNavigate?: () => void }) {
  const Icon = iconFor(item.icon);
  const base = 'flex h-10 w-full items-center gap-3 rounded-md px-3 text-[14px] font-medium';
  const icon = (className: string) => <Icon className={cn('h-[18px] w-[18px] shrink-0', className)} strokeWidth={1.75} aria-hidden="true" />;

  if (!item.available || item.href === null) {
    return (
      <span aria-disabled="true" title={`${item.label}: coming soon`} className={cn(base, 'cursor-default text-cx-nav-muted opacity-75')}>
        {icon('opacity-70')}
        <span className="min-w-0 flex-1 truncate">{item.label}</span>
        <span className="rounded-full bg-white/70 px-2 py-0.5 text-[10.5px] font-medium text-cx-nav-muted ring-1 ring-inset ring-[#DCE6F3]">Soon</span>
      </span>
    );
  }

  return (
    <Link
      href={item.href}
      onClick={onNavigate}
      aria-current={current ? 'page' : undefined}
      className={cn(
        base,
        FOCUS,
        'motion-safe:transition-[background-color,color,box-shadow] motion-safe:duration-150',
        current ? cn(CARD, 'font-semibold text-cx-nav-blue') : 'text-cx-nav-ink hover:bg-white/60',
      )}
    >
      {icon(current ? 'text-cx-nav-blue' : 'text-cx-nav-muted')}
      <span className="min-w-0 flex-1 truncate">{item.label}</span>
    </Link>
  );
}

/** Days left as a person says them. */
function daysLeft(days: number | null): string | null {
  if (days === null) {
    return null;
  }

  return days === 0 ? 'ends today' : days === 1 ? '1 day left' : `${days} days left`;
}

/** The plan's one line: what the state is until when, in the resolver's own terms; no date is invented. */
function subscriptionLine(subscription: OwnerSubscription): string {
  const date = subscription.ends_on ? new Date(`${subscription.ends_on}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : null;
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
 * Whether the plan needs the owner now: a grace period, a lapsed plan, or a
 * term (paid or trial) ending within 14 days. Anything else, including no
 * plan on record, is the Command Center's Plan card to explain, not a warning
 * to repeat on every page.
 */
function planNeedsOwner(subscription: OwnerSubscription | null): boolean {
  return (
    subscription !== null &&
    (subscription.state === 'grace' ||
      subscription.state === 'lapsed' ||
      ((subscription.state === 'active' || subscription.state === 'trial') && subscription.days_remaining !== null && subscription.days_remaining <= 14))
  );
}

/** The plan in one quiet line for the owner when nothing is wrong (a term with days left); null otherwise. */
function planNote(subscription: OwnerSubscription | null): string | null {
  if (subscription === null || planNeedsOwner(subscription) || (subscription.state !== 'active' && subscription.state !== 'trial') || subscription.days_remaining === null) {
    return null;
  }

  return [subscription.plan ?? (subscription.state === 'trial' ? 'Trial' : 'Plan'), daysLeft(subscription.days_remaining)].filter(Boolean).join(' · ');
}

/** What the clinic practises, as words (never a mark): the registry's keys, named for people. */
const SPECIALTY_NAMES: Record<string, string> = { dental: 'Dental', physiotherapy: 'Physiotherapy' };

function specialtyName(key: string): string {
  return SPECIALTY_NAMES[key] ?? key.replace(/[_-]+/g, ' ').replace(/^\w/, (letter) => letter.toUpperCase());
}

/**
 * The foot of the sidebar: the clinic as one of the dashboard's cards — its
 * own logo where it has one, its name, the branch, and what it practises as
 * small pastel words. Where there is more than one branch to work in, the card
 * is also the way to change it (the top bar's branch list, opening upward).
 * Below it, for the owner: one warning in amber or rose with the way to ask
 * support when the plan needs them (`planNeedsOwner`), else the plan's days
 * left in one quiet line. On a short window the card keeps only the essentials.
 */
function ClinicIdentity({
  clinic,
  links,
  name,
  branchName,
  supportHref,
}: {
  clinic: ClinicContextProps;
  links: PortalLinks;
  name: string;
  branchName: string | null;
  supportHref: string | null;
}) {
  const [choosing, setChoosing] = useState(false);
  const subscription = clinic.owner_subscription ?? null;
  const warning = planNeedsOwner(subscription) ? subscription : null;
  const severe = warning !== null && (warning.state === 'lapsed' || warning.state === 'grace');
  const note = planNote(subscription);
  const switchable = clinic.accessible_branch_count > 1;

  const body = (
    <>
      {/* The clinic's own logo where it has one; otherwise a plain building mark on the dashboard's wash, never a medical symbol. */}
      <span
        className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-[#EAF2FF] via-[#F3F1FE] to-[#E6F6F2] ring-1 ring-inset ring-[#DCE6F3] [@media(max-height:700px)]:h-9 [@media(max-height:700px)]:w-9"
        aria-hidden="true"
      >
        {clinic.logo_url ? <img src={clinic.logo_url} alt="" className="h-full w-full bg-white object-contain p-1" /> : <Building2 className="h-5 w-5 text-cx-nav-blue" strokeWidth={1.75} />}
      </span>
      <span className="min-w-0 flex-1 text-left">
        <span className="line-clamp-2 break-words text-[13.5px] font-semibold leading-[1.3] text-cx-nav-ink" title={name}>
          <span className="sr-only">Clinic: </span>
          {name}
        </span>
        {branchName && (
          <span className="mt-0.5 flex min-w-0 items-center gap-1 text-[12px] text-cx-nav-muted" title={branchName}>
            <MapPin className="h-3 w-3 shrink-0" strokeWidth={1.75} aria-hidden="true" />
            <span className="sr-only">Branch: </span>
            <span className="truncate">{branchName}</span>
          </span>
        )}
        {clinic.specialties.length > 0 && (
          <span className="mt-1.5 flex flex-wrap gap-1 [@media(max-height:700px)]:hidden">
            <span className="sr-only">Practises: </span>
            {clinic.specialties.map((key) => (
              <span key={key} className={cn('rounded-full px-2 py-px text-[11px] font-semibold', TONE[specialtyTone(key)].soft, TONE[specialtyTone(key)].ink)}>
                {specialtyName(key)}
              </span>
            ))}
          </span>
        )}
      </span>
    </>
  );

  return (
    <section aria-label="Clinic" className="shrink-0 space-y-2 p-3">
      {warning && (
        <div role="status" className={cn('rounded-lg px-3 py-2.5', severe ? 'bg-cx-rose-bg' : 'bg-cx-amber-bg')}>
          <p className={cn('flex items-center gap-1.5 text-[12.5px] font-semibold', severe ? 'text-cx-rose-ink' : 'text-cx-amber-ink')}>
            <AlertTriangle className="h-3.5 w-3.5 shrink-0" strokeWidth={1.75} aria-hidden="true" />
            <span className="min-w-0 truncate">{warning.plan ?? 'Plan'}</span>
          </p>
          <p className="mt-0.5 text-[12px] leading-snug text-cx-text-2">{subscriptionLine(warning)}</p>
          {supportHref && (
            <Link href={supportHref} className={cn('-mb-2 inline-flex min-h-11 items-center rounded text-[12px] font-semibold text-cx-nav-teal-strong hover:underline', FOCUS)}>
              Ask support about your plan
            </Link>
          )}
        </div>
      )}

      {switchable ? (
        <Popover.Root open={choosing} onOpenChange={setChoosing}>
          <Popover.Trigger
            aria-label={`${name}${branchName ? `, ${branchName}` : ''}. Change branch`}
            className={cn(
              'group flex w-full items-center gap-3 rounded-2xl p-3 motion-safe:transition-shadow motion-safe:duration-150 hover:shadow-[0_1px_2px_rgba(15,23,42,0.06),0_14px_28px_-18px_rgba(23,59,96,0.5)] data-[state=open]:ring-cx-nav-blue/40 [@media(max-height:700px)]:p-2.5',
              CARD,
              FOCUS,
            )}
          >
            {body}
            <ChevronsUpDown className="h-4 w-4 shrink-0 text-cx-nav-muted motion-safe:transition-colors group-hover:text-cx-nav-blue" strokeWidth={1.75} aria-hidden="true" />
          </Popover.Trigger>
          <Popover.Portal>
            <Popover.Content
              side="top"
              align="start"
              sideOffset={8}
              collisionPadding={16}
              className="z-[60] w-[min(320px,calc(100vw-32px))] rounded-2xl border border-cx-nav-line bg-white shadow-cx-raised focus:outline-none motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-1"
            >
              <BranchList clinic={clinic} links={links} onDone={() => setChoosing(false)} />
            </Popover.Content>
          </Popover.Portal>
        </Popover.Root>
      ) : (
        <div className={cn('flex items-center gap-3 rounded-2xl p-3 [@media(max-height:700px)]:p-2.5', CARD)}>{body}</div>
      )}

      {note && (
        <p className="flex items-center gap-1.5 px-1 text-[11.5px] text-cx-nav-muted [@media(max-height:700px)]:hidden">
          <CalendarClock className="h-3.5 w-3.5 shrink-0" strokeWidth={1.75} aria-hidden="true" />
          <span className="sr-only">Plan: </span>
          <span className="truncate">{note}</span>
        </p>
      )}
    </section>
  );
}
