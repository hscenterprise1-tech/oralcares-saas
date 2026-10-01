import { useCallback, useState, type ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { ChevronDown, Menu as MenuIcon, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { clinicDisplayName, isCurrent, useClinicShared } from '../context';
import type { NavDestination, NavLink } from '../types';
import { toolButton } from '../ui/Button';
import { iconFor } from '../ui/visuals';
import { AccountMenu } from './AccountMenu';
import { BranchSwitcher } from './BranchSwitcher';
import { ClinicMark } from './ClinicMark';
import { CommandPalette, useCommandShortcut } from './CommandPalette';
import { NotificationsMenu } from './NotificationsMenu';
import { PortalErrorBoundary } from './PortalErrorBoundary';
import { FlashNotices, OfflineBanner, PlanBanner } from './StatusBanners';

const FOCUS_RING = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong';

/** The Company CRM's navigation pill, in the clinic palette. */
const PILL =
  'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-3.5 py-2 text-[13px] font-semibold motion-safe:transition-colors';
const PILL_ACTIVE = 'bg-clinic-ink text-white';
const PILL_IDLE = 'text-clinic-secondary hover:bg-white/70 hover:text-clinic-ink';

/**
 * The Clinic Portal's one frame: the clinic's identity, the branch it is
 * working in, the navigation this user may use, and the page.
 *
 * Top navigation only, no sidebar. Everything in it comes from the server's
 * shared props — the clinic, the branch, the resolved navigation — so the
 * frame never decides access on its own and never reads a tenant from the
 * browser.
 */
export function ClinicPortalShell({ title, children }: { title: string; children: ReactNode }) {
  const { clinic, auth, portalLinks, flash, subscriptionWindow } = useClinicShared();
  const { url } = usePage();
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const openPalette = useCallback(() => setPaletteOpen(true), []);
  useCommandShortcut(openPalette);

  if (!clinic || !auth.user || !portalLinks) {
    // Only reachable if a portal page is rendered outside a clinic session;
    // the server's middleware normally prevents it.
    return (
      <>
        <Head title={title} />
        <main id="main" className="min-h-screen bg-clinic-canvas px-4 py-16">
          {children}
        </main>
      </>
    );
  }

  const name = clinicDisplayName(clinic);
  // One resolved list from the server drives the bar, the mobile menu and the palette.
  const domains = clinic.navigation;
  // A branch selector only when there is a choice to make.
  const multiBranch = clinic.accessible_branch_count > 1;

  return (
    <>
      <Head title={`${title} · ${name}`} />
      <div className="relative isolate flex min-h-screen flex-col bg-clinic-canvas text-clinic-ink">
        <AmbientBackground />
        <a
          href="#main"
          className="sr-only z-[60] rounded-full bg-clinic-ink px-4 py-2 text-[13px] font-semibold text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
        >
          Skip to content
        </a>
        <OfflineBanner />
        <PlanBanner window={subscriptionWindow} />

        {/* The Company CRM's header, in the clinic's palette: one row over the canvas, the
            navigation as a pill group, round tool buttons. */}
        <header className="sticky top-0 z-40 bg-white/50 px-4 py-3 backdrop-blur-xl supports-[backdrop-filter]:bg-white/40 sm:px-6 lg:px-8">
          <div className="mx-auto flex max-w-[1440px] items-center gap-2 sm:gap-3">
            <button
              type="button"
              onClick={() => setMobileOpen(true)}
              aria-label="Open menu"
              className={cn(toolButton, 'lg:hidden')}
            >
              <MenuIcon className="h-4 w-4" aria-hidden="true" />
            </button>

            <Link
              href={portalLinks.dashboard}
              className={cn('flex min-w-0 shrink items-center gap-2.5 rounded-[14px] py-1 pr-1', FOCUS_RING)}
              aria-label={`${name}, home`}
            >
              <ClinicMark name={name} logoUrl={clinic.logo_url} size="md" />
              {/* Two lines on a phone rather than "Sunri…": the clinic's name is the page's identity. */}
              <span className="line-clamp-2 text-[15px] font-bold leading-[1.15] tracking-[-0.02em] text-clinic-ink sm:line-clamp-1 lg:max-w-[260px] xl:max-w-[300px]">
                {name}
              </span>
            </Link>

            {multiBranch && (
              <div className="hidden min-w-0 sm:flex">
                <BranchSwitcher clinic={clinic} links={portalLinks} />
              </div>
            )}

            {domains.length > 0 && (
              <nav
                aria-label="Main"
                className="mx-auto hidden rounded-full border border-white/70 bg-white/70 p-1.5 shadow-[0_1px_2px_rgba(16,24,40,0.04)] lg:block"
              >
                <div className="flex items-center gap-0.5">
                  {domains.map((domain) =>
                    domain.children.length > 0 ? (
                      <DomainMenu key={domain.key} domain={domain} currentUrl={url} />
                    ) : (
                      <NavPill key={domain.key} item={domain} current={isCurrent(domain.href, url)} />
                    ),
                  )}
                </div>
              </nav>
            )}

            <div className="ml-auto flex shrink-0 items-center gap-2 lg:ml-0">
              <button
                type="button"
                onClick={openPalette}
                aria-label="Go to a page (Ctrl+K)"
                aria-keyshortcuts="Control+K Meta+K"
                className={toolButton}
              >
                <Search className="h-4 w-4" aria-hidden="true" />
              </button>
              <NotificationsMenu links={portalLinks} />
              <AccountMenu user={auth.user} clinic={clinic} links={portalLinks} />
            </div>
          </div>
        </header>

        <MobileMenu open={mobileOpen} onOpenChange={setMobileOpen} domains={domains} currentUrl={url}>
          {multiBranch ? <BranchSwitcher clinic={clinic} links={portalLinks} variant="block" /> : null}
        </MobileMenu>

        <main
          id="main"
          tabIndex={-1}
          className="mx-auto w-full max-w-[1440px] flex-1 px-4 py-6 focus:outline-none sm:py-8 lg:px-8"
        >
          <FlashNotices flash={flash} />
          <PortalErrorBoundary resetKey={url}>{children}</PortalErrorBoundary>
        </main>
      </div>

      <CommandPalette
        open={paletteOpen}
        onOpenChange={setPaletteOpen}
        destinations={clinic.navigation}
        actions={clinic.actions}
        links={portalLinks}
      />
    </>
  );
}

/**
 * The page's ambient colour: one blended mesh across the whole canvas (see
 * `.clinic-ambient` in app.css). Fixed to the viewport so it never adds
 * scroll width, static, and gentler on a phone.
 */
function AmbientBackground() {
  return <div aria-hidden="true" className="clinic-ambient pointer-events-none fixed inset-0 -z-10 overflow-hidden" />;
}

/** A domain as the CRM shows a pillar: a pill, solid ink when it is the current one. */
function NavPill({ item, current }: { item: NavLink; current: boolean }) {
  return (
    <Link
      href={item.href}
      aria-current={current ? 'page' : undefined}
      className={cn(PILL, current ? PILL_ACTIVE : PILL_IDLE, FOCUS_RING)}
    >
      {item.label}
    </Link>
  );
}

/** A domain with sub-areas (Administration): a pill that opens the CRM's glass menu. */
function DomainMenu({ domain, currentUrl }: { domain: NavDestination; currentUrl: string }) {
  const active = domain.children.some((item) => isCurrent(item.href, currentUrl));

  return (
    <Menu.Root>
      <Menu.Trigger
        className={cn(
          'group',
          PILL,
          active ? PILL_ACTIVE : cn(PILL_IDLE, 'data-[state=open]:bg-clinic-ink data-[state=open]:text-white'),
          FOCUS_RING,
        )}
      >
        {domain.label}
        <ChevronDown
          className="h-3 w-3 motion-safe:transition-transform motion-safe:duration-200 group-data-[state=open]:rotate-180"
          aria-hidden="true"
        />
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Content
          align="center"
          sideOffset={10}
          collisionPadding={16}
          className="z-50 w-[280px] rounded-[26px] border border-white/70 bg-white/[0.86] p-3 shadow-[0_2px_6px_rgba(11,11,15,0.05),0_20px_44px_-20px_rgba(11,11,15,0.20)] backdrop-blur-2xl"
        >
          <p className="px-2 pb-2 pt-1 text-[10px] font-bold uppercase tracking-[0.12em] text-clinic-secondary">
            {domain.label}
          </p>
          {domain.children.map((item) => {
            const current = isCurrent(item.href, currentUrl);
            const ChildIcon = iconFor(item.icon);

            return (
              <Menu.Item key={item.key} asChild>
                <Link
                  href={item.href}
                  aria-current={current ? 'page' : undefined}
                  className={cn(
                    'flex items-center gap-2.5 rounded-[24px] px-2.5 py-2 text-[13px] font-medium outline-none',
                    current ? 'bg-clinic-ink text-white' : 'text-clinic-ink data-[highlighted]:bg-white',
                  )}
                >
                  <span
                    className={cn(
                      'flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                      current ? 'bg-white/15 text-white' : 'bg-clinic-inset text-clinic-secondary',
                    )}
                  >
                    <ChildIcon className="h-3.5 w-3.5" aria-hidden="true" />
                  </span>
                  <span className="min-w-0 flex-1 truncate">{item.label}</span>
                </Link>
              </Menu.Item>
            );
          })}
        </Menu.Content>
      </Menu.Portal>
    </Menu.Root>
  );
}

/**
 * The navigation on phones and tablets: a full-screen menu, not a side
 * drawer. It closes itself when a page is chosen.
 */
function MobileMenu({
  open,
  onOpenChange,
  domains,
  currentUrl,
  children,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  domains: NavDestination[];
  currentUrl: string;
  children: ReactNode;
}) {
  const link = (item: NavLink) => {
    const current = isCurrent(item.href, currentUrl);
    const Icon = iconFor(item.icon);

    return (
      <li key={item.key}>
        <Link
          href={item.href}
          onClick={() => onOpenChange(false)}
          aria-current={current ? 'page' : undefined}
          className={cn(
            'flex min-h-12 items-center gap-3 rounded-xl px-3 text-[15px] font-semibold',
            current ? 'bg-clinic-ink text-white' : 'text-clinic-ink hover:bg-clinic-inset',
            FOCUS_RING,
          )}
        >
          <Icon className={cn('h-5 w-5', current ? 'text-white' : 'text-clinic-secondary')} aria-hidden="true" />
          {item.label}
        </Link>
      </li>
    );
  };

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Content
          aria-describedby={undefined}
          className="fixed inset-0 z-50 flex flex-col bg-clinic-surface focus:outline-none motion-safe:animate-in motion-safe:fade-in-0 lg:hidden"
        >
          <div className="flex h-16 items-center justify-between border-b border-clinic-border px-4">
            <Dialog.Title className="text-[17px] font-bold text-clinic-ink">Menu</Dialog.Title>
            <Dialog.Close
              aria-label="Close menu"
              className={cn(
                '-mr-1.5 flex h-11 w-11 items-center justify-center rounded-full hover:bg-clinic-inset',
                FOCUS_RING,
              )}
            >
              <X className="h-5 w-5" aria-hidden="true" />
            </Dialog.Close>
          </div>
          <nav aria-label="Main" className="flex-1 overflow-y-auto overscroll-contain px-4 py-4">
            {children && (
              <div className="mb-5 sm:hidden">
                <p className="mb-2 px-1 text-[10px] font-bold uppercase tracking-[0.08em] text-clinic-secondary">
                  Branch
                </p>
                {children}
              </div>
            )}
            <ul className="space-y-1">{domains.filter((domain) => domain.children.length === 0).map(link)}</ul>
            {domains
              .filter((domain) => domain.children.length > 0)
              .map((domain) => (
                <div key={domain.key}>
                  <p className="mb-2 mt-6 px-3 text-[10px] font-bold uppercase tracking-[0.08em] text-clinic-secondary">
                    {domain.label}
                  </p>
                  <ul className="space-y-1">{domain.children.map(link)}</ul>
                </div>
              ))}
          </nav>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
