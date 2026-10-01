import { useCallback, useState, type ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { ChevronDown, Menu as MenuIcon, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { clinicDisplayName, isCurrent, useClinicShared } from '../context';
import type { NavDestination, NavLink } from '../types';
import { iconFor } from '../ui/visuals';
import { AccountMenu } from './AccountMenu';
import { BranchSwitcher } from './BranchSwitcher';
import { ClinicMark } from './ClinicMark';
import { CommandPalette, useCommandShortcut } from './CommandPalette';
import { NotificationsMenu } from './NotificationsMenu';
import { PortalErrorBoundary } from './PortalErrorBoundary';
import { FlashNotices, OfflineBanner, PlanBanner } from './StatusBanners';

const FOCUS_RING = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong';

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
      <div className="flex min-h-screen flex-col bg-clinic-canvas text-clinic-ink">
        <a
          href="#main"
          className="sr-only z-[60] rounded-full bg-clinic-ink px-4 py-2 text-[13px] font-semibold text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
        >
          Skip to content
        </a>
        <OfflineBanner />
        <PlanBanner window={subscriptionWindow} />

        <header className="sticky top-0 z-40 border-b border-clinic-border bg-clinic-surface/95 backdrop-blur supports-[backdrop-filter]:bg-clinic-surface/85">
          <div className="mx-auto flex h-16 max-w-[1440px] items-center gap-2 px-4 sm:gap-3 lg:px-8">
            <button
              type="button"
              onClick={() => setMobileOpen(true)}
              aria-label="Open menu"
              className={cn(
                '-ml-1.5 flex h-11 w-11 items-center justify-center rounded-full text-clinic-ink hover:bg-clinic-inset lg:hidden',
                FOCUS_RING,
              )}
            >
              <MenuIcon className="h-5 w-5" aria-hidden="true" />
            </button>

            <Link
              href={portalLinks.dashboard}
              className={cn('flex min-w-0 items-center gap-2.5 rounded-xl py-1 pr-1', FOCUS_RING)}
              aria-label={`${name}, home`}
            >
              <ClinicMark name={name} logoUrl={clinic.logo_url} size="md" />
              {/* Two lines on a phone rather than "Sunri…": the clinic's name is the page's identity. */}
              <span className="line-clamp-2 text-[15px] font-bold leading-[1.15] tracking-tight text-clinic-ink sm:line-clamp-1 sm:text-[17px]">
                {name}
              </span>
            </Link>

            {multiBranch && (
              <div className="hidden min-w-0 items-center gap-3 sm:flex">
                <span aria-hidden="true" className="h-6 w-px bg-clinic-border" />
                <BranchSwitcher clinic={clinic} links={portalLinks} />
              </div>
            )}

            <div className="ml-auto flex items-center gap-1 sm:gap-1.5">
              <button
                type="button"
                onClick={openPalette}
                aria-label="Go to a page (Ctrl+K)"
                aria-keyshortcuts="Control+K Meta+K"
                className={cn(
                  'flex h-10 items-center gap-2 rounded-full text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink',
                  'w-10 justify-center [@media(pointer:coarse)]:h-11 [@media(pointer:coarse)]:w-11 md:w-56 md:[@media(pointer:coarse)]:w-56 md:justify-start md:border md:border-clinic-border md:bg-clinic-canvas md:px-3.5 md:hover:bg-clinic-surface',
                  FOCUS_RING,
                )}
              >
                <Search className="h-4 w-4 shrink-0" aria-hidden="true" />
                <span className="hidden flex-1 text-left text-[13px] md:inline">Go to…</span>
                <kbd className="hidden rounded-md border border-clinic-border bg-clinic-surface px-1.5 text-[11px] font-semibold md:inline">
                  Ctrl K
                </kbd>
              </button>
              <NotificationsMenu links={portalLinks} />
              <AccountMenu user={auth.user} clinic={clinic} links={portalLinks} />
            </div>
          </div>

          {domains.length > 0 && (
            <nav aria-label="Main" className="hidden border-t border-clinic-border lg:block">
              <div className="mx-auto flex max-w-[1440px] items-center gap-0.5 overflow-x-auto px-6 lg:px-8">
                {domains.map((domain) =>
                  domain.children.length > 0 ? (
                    <DomainMenu key={domain.key} domain={domain} currentUrl={url} />
                  ) : (
                    <NavTab key={domain.key} item={domain} current={isCurrent(domain.href, url)} />
                  ),
                )}
              </div>
            </nav>
          )}
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

function NavTab({ item, current }: { item: NavLink; current: boolean }) {
  const Icon = iconFor(item.icon);

  return (
    <Link
      href={item.href}
      aria-current={current ? 'page' : undefined}
      className={cn(
        'relative flex h-12 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-3 text-[13px] font-semibold',
        'after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:rounded-full',
        current ? 'text-clinic-ink after:bg-clinic-brand' : 'text-clinic-secondary hover:text-clinic-ink',
        FOCUS_RING,
      )}
    >
      <Icon className={cn('h-4 w-4', current ? 'text-clinic-brand-strong' : 'text-current')} aria-hidden="true" />
      {item.label}
    </Link>
  );
}

/** A domain with sub-areas (Administration): one tab that opens its children. */
function DomainMenu({ domain, currentUrl }: { domain: NavDestination; currentUrl: string }) {
  const Icon = iconFor(domain.icon);
  const active = domain.children.some((item) => isCurrent(item.href, currentUrl));

  return (
    <Menu.Root>
      <Menu.Trigger
        className={cn(
          'group relative flex h-12 shrink-0 items-center gap-1 whitespace-nowrap rounded-md px-3 text-[13px] font-semibold',
          'after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:rounded-full',
          active
            ? 'text-clinic-ink after:bg-clinic-brand'
            : 'text-clinic-secondary hover:text-clinic-ink data-[state=open]:text-clinic-ink',
          FOCUS_RING,
        )}
      >
        <Icon
          className={cn('mr-0.5 h-4 w-4', active ? 'text-clinic-brand-strong' : 'text-current')}
          aria-hidden="true"
        />
        {domain.label}
        <ChevronDown
          className="h-3.5 w-3.5 motion-safe:transition-transform group-data-[state=open]:rotate-180"
          aria-hidden="true"
        />
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Content
          align="start"
          sideOffset={4}
          collisionPadding={16}
          className="z-50 min-w-[220px] rounded-2xl border border-clinic-border bg-clinic-surface p-1.5 shadow-[0_16px_40px_-12px_rgba(16,24,40,0.25)]"
        >
          {domain.children.map((item) => {
            const current = isCurrent(item.href, currentUrl);
            const ChildIcon = iconFor(item.icon);

            return (
              <Menu.Item key={item.key} asChild>
                <Link
                  href={item.href}
                  aria-current={current ? 'page' : undefined}
                  className={cn(
                    'flex min-h-10 items-center gap-2.5 rounded-xl px-3 text-[13px] text-clinic-ink outline-none data-[highlighted]:bg-clinic-inset',
                    current && 'font-semibold',
                  )}
                >
                  <ChildIcon className="h-4 w-4 text-clinic-secondary" aria-hidden="true" />
                  {item.label}
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
