import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { LifeBuoy, Menu as MenuIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { isCurrent, useNavigation } from '../context';
import type { AuthUser, ClinicContextProps, PortalLinks } from '../types';
import { AccountMenu } from './AccountMenu';
import { BranchSwitcher } from './BranchSwitcher';
import { GlobalSearch } from './GlobalSearch';
import { NotificationsMenu } from './NotificationsMenu';

/**
 * The bar's round controls, as the Reception dashboard draws its secondary actions: a 44px white disc with the
 * dashboard's hairline and soft shadow, its blue on hover and for the focus ring.
 */
export const BAR_BUTTON =
  'flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white text-cx-nav-muted shadow-[0_1px_2px_rgba(15,23,42,0.06)] ring-1 ring-inset ring-[#DCE6F3] motion-safe:transition-[color,box-shadow,background-color] motion-safe:duration-150 hover:text-cx-nav-blue hover:shadow-[0_1px_2px_rgba(15,23,42,0.06),0_8px_18px_-12px_rgba(23,59,96,0.45)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-blue';

/** The dashboard's greeting wash (blue → lavender → mint), softened for a bar that sits over every page. */
const BAR_SURFACE = 'border-b border-[#DCE6F3] bg-gradient-to-r from-[#EEF4FF]/95 via-[#F6F4FF]/95 to-[#EEF8F5]/95 supports-[backdrop-filter]:backdrop-blur-sm';

/**
 * The date and the time, as the reception sees them on the wall; to the
 * minute, so it never flickers. Shown from a laptop width (1024) up, where it
 * fits beside the search without squeezing it.
 */
function LiveClock() {
  const [now, setNow] = useState<Date | null>(null);

  useEffect(() => {
    setNow(new Date());
    const timer = window.setInterval(() => setNow(new Date()), 10_000);

    return () => window.clearInterval(timer);
  }, []);

  if (!now) {
    return null;
  }

  return (
    <div className="hidden shrink-0 text-right lg:block" aria-live="off">
      <p className="text-[12px] leading-tight text-cx-text-3">{now.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}</p>
      <p className="mt-0.5 text-[14px] font-medium leading-tight tabular-nums text-cx-nav-ink">{now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}</p>
    </div>
  );
}

/** The way to OralCares support, beside the bell: the same control, opening the Support page. */
function SupportLink({ href, currentUrl }: { href: string; currentUrl: string }) {
  const current = isCurrent(href, currentUrl);

  return (
    <Link href={href} aria-label="Support" title="Support" aria-current={current ? 'page' : undefined} className={cn(BAR_BUTTON, current && 'text-cx-nav-blue ring-cx-nav-blue/40')}>
      <LifeBuoy className="h-[18px] w-[18px]" strokeWidth={1.75} aria-hidden="true" />
    </Link>
  );
}

const DIVIDER = 'h-8 w-px shrink-0 bg-cx-nav-line/80';

/**
 * The portal's context bar (the approved design's top navigation), left to
 * right: the branch, when there is a choice to make; the one search, which
 * takes the room there is; the time; Support and notifications together;
 * and the account. Where the sidebar is hidden (tablet, phone) the wordmark
 * stands beside the menu button instead.
 *
 * It sits on the same 1600px measure as the page below it, so its search
 * and controls line up with the page's cards. Below md the search takes its
 * own row under the bar; that row is not part of the sticky bar, so on a
 * phone it scrolls away with the page and the bar that stays is 64px tall.
 *
 * A member who works in one branch never sees a branch control: the server
 * keeps their branch as the session's context. The selector appears only
 * when they may open more than one, or as a warning when they have none.
 */
export function TopBar({ clinic, user, links, onOpenMenu, currentUrl }: { clinic: ClinicContextProps; user: AuthUser; links: PortalLinks; onOpenMenu: () => void; currentUrl: string }) {
  const showsBranch = clinic.accessible_branch_count > 1 || !clinic.active_branch;
  const support = useNavigation().find((item) => item.key === 'support' && item.available && item.href !== null);

  return (
    <>
      <header className={cn('sticky top-0 z-30', BAR_SURFACE)}>
        <div className="mx-auto flex h-16 w-full max-w-[1600px] items-center gap-3 px-4 sm:px-6 lg:h-[72px] lg:gap-4 lg:px-8">
          <button type="button" onClick={onOpenMenu} aria-label="Open navigation" aria-haspopup="dialog" className={cn(BAR_BUTTON, 'lg:hidden')}>
            <MenuIcon className="h-[18px] w-[18px]" strokeWidth={1.75} aria-hidden="true" />
          </button>
          {/* Without the sidebar, the bar says where you are; a branch choice takes that place when there is one. */}
          <span className={cn('shrink-0 text-[18px] font-semibold leading-none tracking-[-0.02em] text-cx-nav-ink lg:hidden', showsBranch && 'hidden')} aria-hidden="true">
            OralCares
          </span>
          {showsBranch && (
            <div className="flex min-w-0 shrink md:max-w-[240px] md:shrink-0">
              <BranchSwitcher clinic={clinic} links={links} />
            </div>
          )}
          {/* The short hint ("Search patients and more…") never truncates; the field's label still names every target. */}
          <GlobalSearch compact className="hidden min-w-[200px] max-w-[560px] flex-1 md:block" />
          <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2 lg:gap-3">
            <LiveClock />
            <span className={cn(DIVIDER, 'mx-1 hidden lg:block')} aria-hidden="true" />
            <div className="flex items-center gap-1">
              {support?.href && <SupportLink href={support.href} currentUrl={currentUrl} />}
              <NotificationsMenu links={links} />
            </div>
            <span className={cn(DIVIDER, 'mx-1 hidden md:block')} aria-hidden="true" />
            <AccountMenu user={user} clinic={clinic} links={links} />
          </div>
        </div>
      </header>
      {/* Below md the search takes its own row rather than squeezing the bar; it scrolls with the page. */}
      <div className={cn('px-4 py-2.5 sm:px-6 md:hidden', BAR_SURFACE)}>
        <GlobalSearch compact />
      </div>
    </>
  );
}
