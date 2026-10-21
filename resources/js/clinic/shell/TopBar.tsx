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

/** The date and the time, as the reception sees them on the wall; to the minute, so it never flickers. */
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
    <div className="hidden shrink-0 text-right xl:block" aria-live="off">
      <p className="text-[12px] leading-tight text-cx-nav-muted">{now.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}</p>
      <p className="mt-0.5 text-[15px] font-semibold leading-tight tabular-nums text-cx-nav-ink">{now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}</p>
    </div>
  );
}

/** The way to OralCares support, beside the bell: the same control, opening the Support page. */
function SupportLink({ href, currentUrl }: { href: string; currentUrl: string }) {
  const current = isCurrent(href, currentUrl);

  return (
    <Link
      href={href}
      aria-label="Support"
      title="Support"
      aria-current={current ? 'page' : undefined}
      className={cn(
        'flex h-11 w-11 shrink-0 items-center justify-center rounded-full hover:bg-cx-nav-bg hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal',
        current ? 'bg-cx-nav-bg text-cx-nav-teal-strong' : 'text-cx-nav-muted',
      )}
    >
      <LifeBuoy className="h-[18px] w-[18px]" strokeWidth={1.75} aria-hidden="true" />
    </Link>
  );
}

const DIVIDER = 'h-8 w-px shrink-0 bg-cx-nav-line';

/**
 * The portal's context bar (the approved design's top navigation), left to
 * right: the branch, when there is a choice to make; the one search, which
 * takes the room there is; the time; Support and notifications together;
 * and the account. Where the sidebar is hidden (tablet, phone) the wordmark
 * stands beside the menu button instead.
 *
 * A member who works in one branch never sees a branch control: the server
 * keeps their branch as the session's context. The selector appears only
 * when they may open more than one, or as a warning when they have none.
 */
export function TopBar({ clinic, user, links, onOpenMenu, currentUrl }: {
  clinic: ClinicContextProps;
  user: AuthUser;
  links: PortalLinks;
  onOpenMenu: () => void;
  currentUrl: string;
}) {
  const showsBranch = clinic.accessible_branch_count > 1 || !clinic.active_branch;
  const support = useNavigation().find((item) => item.key === 'support' && item.available && item.href !== null);

  return (
    <header className="sticky top-0 z-30 border-b border-cx-nav-line bg-white">
      <div className="flex h-[76px] items-center gap-3 px-4 sm:px-6 lg:gap-4 lg:px-8">
        <button
          type="button"
          onClick={onOpenMenu}
          aria-label="Open navigation"
          aria-haspopup="dialog"
          className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md border border-cx-nav-line text-cx-nav-muted hover:bg-cx-nav-bg hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal lg:hidden"
        >
          <MenuIcon className="h-[18px] w-[18px]" strokeWidth={1.75} aria-hidden="true" />
        </button>
        {/* Without the sidebar, the bar says where you are; a branch choice takes that place when there is one. */}
        <span className={cn('shrink-0 text-[18px] font-bold leading-none tracking-[-0.025em] text-cx-nav-ink lg:hidden', showsBranch && 'hidden')} aria-hidden="true">
          OralCares
        </span>
        {showsBranch && (
          <div className="flex min-w-0 shrink md:max-w-[240px] md:shrink-0">
            <BranchSwitcher clinic={clinic} links={links} />
          </div>
        )}
        <GlobalSearch className="hidden min-w-[220px] max-w-[720px] flex-1 md:block" />
        <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2 lg:gap-3">
          <LiveClock />
          <span className={cn(DIVIDER, 'mx-1 hidden xl:block')} aria-hidden="true" />
          <div className="flex items-center gap-1">
            {support?.href && <SupportLink href={support.href} currentUrl={currentUrl} />}
            <NotificationsMenu links={links} />
          </div>
          <span className={cn(DIVIDER, 'mx-1 hidden md:block')} aria-hidden="true" />
          <AccountMenu user={user} clinic={clinic} links={links} />
        </div>
      </div>
      {/* Below md the search takes its own row rather than squeezing the bar. */}
      <div className="border-t border-cx-nav-line px-4 py-2.5 md:hidden">
        <GlobalSearch compact />
      </div>
    </header>
  );
}
