import React from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search, Plus, Bell, Mail, Menu, X, Settings } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CrmCanvas } from './CrmCanvas';
import { CrmCommandPalette } from './CrmCommandPalette';
import { CrmModuleNav, useCompanyModules } from './CrmModuleNav';
import { useNavAuthorization } from '@/hooks/useNavAuthorization';
import { CRM_DESTINATIONS, CRM_CREATE_ACTIONS } from '@/ovs/layouts/sidebar-configs';

/**
 * The CRM application shell.
 *
 * Two pieces of chrome:
 *
 *   - a translucent top bar carrying brand, the company module menu, and
 *     identity
 *   - a gradient mesh ground behind it
 *
 * The module menu is the whole company, not just the CRM: it reads the same
 * EXECUTIVE_NAV_GROUPS config the sidebar does, and each module reveals its
 * own contents on hover. Items are filtered by the user's permissions, so the
 * menu can never offer a surface the backend would refuse.
 */

/**
 * Board-level jumps that apply anywhere in the CRM — the floating left rail.
 *
 * Each tool carries its own hue, matching the destination's colour elsewhere in
 * the product, so the rail reads as a colour key rather than a row of grey
 * glyphs. The active tool inverts to ink instead.
 */
export interface ShellRailTool {
  icon: LucideIcon;
  label: string;
  href: string;
  hue: string;
}

export interface ShellCreateItem {
  label: string;
  href: string;
  icon: LucideIcon;
  permission: string;
}

/*
 * The rail is CRM_DESTINATIONS, projected — not a second copy of it.
 *
 * It had drifted into a longer, competing menu: Demos, Documents and Teams were
 * reachable here and nowhere else, so the product presented two navigations
 * that disagreed. Deriving both this and the palette from one constant is what
 * makes that class of drift impossible rather than merely fixed once.
 */
const CRM_RAIL: ShellRailTool[] = CRM_DESTINATIONS.map((destination) => ({
  icon: destination.icon as LucideIcon,
  label: destination.label,
  href: destination.href,
  hue: destination.hue,
}));

/**
 * The reader's own things, reached from the avatar rather than from a module.
 *
 * Neither is a business destination: Profile is the account you are signed in
 * with, and the inbox is what the platform has sent you. They sat in the ADMIN
 * dropdown, which is how a personal setting ended up filed beside Packages.
 */
const ACCOUNT_LINKS: { label: string; href: string; icon: LucideIcon }[] = [
  { label: 'Notifications', href: '/notifications', icon: Bell },
  { label: 'Profile', href: '/profile', icon: Settings },
];

const CRM_CREATE: ShellCreateItem[] = CRM_CREATE_ACTIONS.map((action) => ({
  label: action.label,
  href: action.href,
  icon: action.icon as LucideIcon,
  /*
   * A create action with no permission would be offered to everybody and then
   * refused by the route. There is no such entry today; naming the fallback
   * keeps it that way if one is ever added without one.
   */
  permission: action.permission ?? 'create leads',
}));

interface ShellPageProps {
  crm?: {
    is_manager?: boolean;
  };
  auth?: { user?: { name?: string; role?: string; permissions?: string[] } };
  [key: string]: unknown;
}

/** Circular icon control — the reference language's secondary button. */
export function CrmIconButton({
  icon: Icon,
  label,
  onClick,
  href,
  tone = 'default',
  size = 'md',
  badge,
  className,
}: {
  icon: LucideIcon;
  label: string;
  onClick?: () => void;
  href?: string;
  tone?: 'default' | 'ink' | 'ghost';
  size?: 'sm' | 'md' | 'lg';
  badge?: boolean;
  className?: string;
}) {
  const classes = cn(
    'relative inline-flex shrink-0 items-center justify-center rounded-full transition-all',
    size === 'sm' && 'h-8 w-8',
    size === 'md' && 'h-10 w-10',
    size === 'lg' && 'h-11 w-11',
    tone === 'ink' && 'bg-ovs-crm-ink text-white hover:bg-ovs-crm-ink-soft',
    tone === 'default' &&
      'border border-ovs-crm-border-soft bg-ovs-crm-surface-veil-strong text-ovs-crm-text-secondary backdrop-blur hover:bg-ovs-crm-surface hover:text-ovs-crm-text-primary',
    tone === 'ghost' && 'text-ovs-crm-text-secondary hover:bg-ovs-crm-surface hover:text-ovs-crm-text-primary',
    className
  );

  const inner = (
    <>
      <Icon className={size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4'} aria-hidden="true" />
      {badge && (
        <span className="absolute right-2 top-2 h-2 w-2 rounded-full bg-ovs-crm-accent-coral ring-2 ring-white" />
      )}
      <span className="sr-only">{label}</span>
    </>
  );

  return href ? (
    <Link href={href} className={classes} aria-label={label}>{inner}</Link>
  ) : (
    <button type="button" onClick={onClick} className={classes} aria-label={label}>{inner}</button>
  );
}

export function CompanyShell({
  title,
  children,
  rail = CRM_RAIL,
  create = CRM_CREATE,
  settingsHref = '/profile',
}: {
  title: string;
  children: React.ReactNode;
  /** The six destinations on the floating rail. Module-specific. */
  rail?: ShellRailTool[];
  /** What the create button offers, filtered by permission. */
  create?: ShellCreateItem[];
  /** Where the rail’s Settings entry goes. */
  settingsHref?: string;
}) {
  const { props, url } = usePage<ShellPageProps>();
  const [commandOpen, setCommandOpen] = React.useState(false);
  const [createOpen, setCreateOpen] = React.useState(false);
  const [accountOpen, setAccountOpen] = React.useState(false);
  const [mobileNavOpen, setMobileNavOpen] = React.useState(false);

  const isManager = props.crm?.is_manager ?? false;
  const mayCreate = useNavAuthorization();
  const currentPath = url.split('?')[0];
  const userName = props.auth?.user?.name ?? '';

  /*
   * A raw `includes` here meant a master admin — who holds no permission rows
   * at all — was offered no create actions unless they happened to be flagged a
   * manager. `mayCreate` carries the same bypass the routes do.
   */
  const createItems = create.filter((i) => isManager || mayCreate(i.permission));
  const modules = useCompanyModules();

  React.useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setCommandOpen(true);
      }
    };
    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  }, []);

  React.useEffect(() => {
    if (! createOpen && ! accountOpen) return;
    const close = () => { setCreateOpen(false); setAccountOpen(false); };
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && close();
    window.addEventListener('click', close);
    window.addEventListener('keydown', onKey);

    return () => {
      window.removeEventListener('click', close);
      window.removeEventListener('keydown', onKey);
    };
  }, [createOpen, accountOpen]);

  /*
   * A module's home page matches exactly and never by prefix.
   *
   * Overview is the CRM's home; treating it as a prefix would light it up on
   * every page in the module, since nothing else lives under its path anyway.
   */
  const homeHref = CRM_DESTINATIONS[0].href;
  const isActive = (href: string) =>
    currentPath === href || (href !== homeHref && currentPath.startsWith(`${href}/`));

  return (
    <CrmCanvas>
      {/*
       * The only place a CRM page's browser-tab title is set. Pages pass the
       * bare name — "Leads", "Quotations" — and the suffix is added here, so
       * the product name cannot end up written four different ways. It names the
       * product, not the module, so Marketing pages do not read "OralCares CRM".
       */}
      <Head title={`${title} — OralCares`} />

      {/*
       * The header spans the whole screen and the rail sits beneath it, which
       * is how every reference lays this out: brand at the far left over the
       * rail, module nav centred, identity right, and the page content indented
       * past the rail. The brand and the rail together form the left column, so
       * the content's deeper indent reads as structure rather than as drift.
       */}
      {/* ── Top bar ──────────────────────────────────────────────────────── */}
      {/*
       * No bar. The reference header has no fill and no rule under it — brand,
       * nav and identity simply sit on the page ground. Giving it a surface and
       * a border turned it into a separate strip pasted above the app, which is
       * exactly what it should not read as.
       */}
      {/*
        * z-40, above the z-30 that page-level sticky headers use.
        *
        * Both this and the deal page's own sticky header sat at z-30, and a
        * z-index tie is broken by DOM order — the page header comes later, so
        * it won. The navigation's dropdown is `z-50` *inside* this element,
        * which means it can never rise above whatever this header competes at,
        * so the menu slid behind the page's own header card.
        *
        * The application's chrome belongs above page content by definition,
        * and stating that here is what keeps every page from having to.
        */}
      <header className="sticky top-0 z-40 px-4 py-3 sm:px-6">
        <div className="flex items-center gap-3">
          {/*
           * Brand sits in the rail's width, so the mark and the rail below it
           * read as one left column and the content's indent past both looks
           * deliberate.
           */}
          {/* Home is the first destination, whatever that is — not a second copy of its URL. */}
          <Link href={CRM_DESTINATIONS[0].href} className="flex shrink-0 items-center gap-2.5 xl:w-[64px]">
            <span className="flex h-9 w-9 items-center justify-center rounded-[14px] bg-ovs-crm-ink text-[13px] font-black text-white">
              O
            </span>
            <span className="text-[15px] font-bold tracking-[-0.03em] text-ovs-crm-text-primary sm:block xl:hidden">
              oralcares
            </span>
          </Link>

          {/* Company module nav — every module, contents on hover */}
          <div className="mx-auto hidden rounded-full border border-ovs-crm-border-soft bg-white/70 p-1.5 lg:block">
            <CrmModuleNav currentPath={currentPath} />
          </div>

          <div className="ml-auto flex shrink-0 items-center gap-2 lg:ml-0">
            <CrmIconButton icon={Search} label="Search OralCares" onClick={() => setCommandOpen(true)} size="lg" />
            {/*
             * Messages and Notifications step out below `sm`.
             *
             * This cluster does not shrink — five 44px controls plus their gaps
             * need 252px, and a 375px screen leaves 220px once the brand has
             * taken its width, so the menu button was clipped by the canvas's
             * `overflow-x: clip`. That button is how you navigate on a phone, so
             * it is the one thing here that cannot be the casualty.
             *
             * Both remain reachable: the mobile menu lists Notifications under
             * Platform, and Messages under CRM.
             */}
            <CrmIconButton
              icon={Mail}
              label="Conversations"
              href="/company/marketing/conversations"
              size="lg"
              badge
              className="hidden sm:inline-flex"
            />
            {/*
             * `/notifications` is the registered route; `/company/notifications`
             * was never one, so this control 404'd while six scheduled jobs
             * wrote notifications nobody could reach.
             */}
            <CrmIconButton icon={Bell} label="Notifications" onClick={() => router.visit('/notifications')} size="lg" badge className="hidden sm:inline-flex" />

            {createItems.length > 0 && (
              <div className="relative">
                <button
                  type="button"
                  onClick={(e) => { e.stopPropagation(); setCreateOpen((o) => !o); }}
                  aria-expanded={createOpen}
                  aria-haspopup="menu"
                  className="inline-flex h-11 items-center gap-1.5 rounded-full bg-ovs-crm-ink px-4 text-[13px] font-semibold text-white shadow-ovs-crmInk transition-colors hover:bg-ovs-crm-ink-soft"
                >
                  <Plus className="h-4 w-4" aria-hidden="true" />
                  <span className="hidden sm:inline">New</span>
                </button>

                {createOpen && (
                  <div
                    role="menu"
                    onClick={(e) => e.stopPropagation()}
                    className="absolute right-0 top-full z-40 mt-2 w-52 overflow-hidden rounded-[24px] bg-ovs-crm-surface p-2 shadow-ovs-crmLift"
                  >
                    {createItems.map((item) => (
                      <Link
                        key={item.label}
                        href={item.href}
                        role="menuitem"
                        onClick={() => setCreateOpen(false)}
                        className="flex items-center gap-3 rounded-[24px] px-3 py-2.5 text-[13px] font-medium text-ovs-crm-text-primary transition-colors hover:bg-ovs-crm-surface-inset"
                      >
                        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-ovs-crm-surface-inset">
                          <item.icon className="h-3.5 w-3.5 text-ovs-crm-text-secondary" aria-hidden="true" />
                        </span>
                        New {item.label}
                      </Link>
                    ))}
                  </div>
                )}
              </div>
            )}

            {/*
              * The global utilities, on the avatar.
              *
              * Profile and the notification inbox used to be two entries in the
              * ADMIN dropdown, which put a person's own account settings in a
              * business module beside Packages and Workflows. They belong to
              * the reader, not to a module, so they live on the reader's own
              * control — and this is the only route to them once the module
              * menus stop carrying them, on every breakpoint including mobile.
              */}
            <div className="relative">
              <button
                type="button"
                onClick={(e) => { e.stopPropagation(); setAccountOpen((open) => ! open); }}
                aria-expanded={accountOpen}
                aria-haspopup="menu"
                aria-label={userName ? `Account: ${userName}` : 'Account'}
                title={userName}
                className="flex h-11 w-11 items-center justify-center rounded-full bg-ovs-crm-accent-teal text-[13px] font-bold text-white ring-2 ring-white transition-opacity hover:opacity-90"
              >
                {userName.charAt(0).toUpperCase() || '?'}
              </button>

              {accountOpen && (
                <div
                  role="menu"
                  onClick={(e) => e.stopPropagation()}
                  className="absolute right-0 top-full z-40 mt-2 w-52 overflow-hidden rounded-[24px] bg-ovs-crm-surface p-2 shadow-ovs-crmLift"
                >
                  {userName && (
                    <p className="truncate px-3 pb-1.5 pt-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                      {userName}
                    </p>
                  )}
                  {ACCOUNT_LINKS.map((item) => (
                    <Link
                      key={item.href}
                      href={item.href}
                      role="menuitem"
                      onClick={() => setAccountOpen(false)}
                      className="flex items-center gap-3 rounded-[24px] px-3 py-2.5 text-[13px] font-medium text-ovs-crm-text-primary transition-colors hover:bg-ovs-crm-surface-inset"
                    >
                      <span className="flex h-7 w-7 items-center justify-center rounded-full bg-ovs-crm-surface-inset">
                        <item.icon className="h-3.5 w-3.5 text-ovs-crm-text-secondary" aria-hidden="true" />
                      </span>
                      {item.label}
                    </Link>
                  ))}
                </div>
              )}
            </div>

            <CrmIconButton
              icon={mobileNavOpen ? X : Menu}
              label={mobileNavOpen ? 'Close menu' : 'Open menu'}
              onClick={() => setMobileNavOpen((o) => !o)}
              size="lg"
              className="lg:hidden"
            />
          </div>
        </div>

        {mobileNavOpen && (
          <div className="mx-auto mt-3 max-h-[70vh] w-full max-w-[1560px] overflow-y-auto rounded-[24px] border border-ovs-crm-border-soft bg-ovs-crm-surface-veil-strong p-3 shadow-ovs-crmCard backdrop-blur-xl lg:hidden">
            {modules.map((section) => (
              <div key={section.id} className="mb-3 last:mb-0">
                <p className="px-2 pb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                  {section.title}
                </p>
                <div className="grid grid-cols-2 gap-1">
                  {section.items.map((item) => (
                    <Link
                      key={item.id}
                      href={item.href}
                      onClick={() => setMobileNavOpen(false)}
                      className={cn(
                        'truncate rounded-[24px] px-3 py-2.5 text-[13px] font-semibold transition-colors',
                        isActive(item.href) ? 'bg-ovs-crm-ink text-white' : 'text-ovs-crm-text-secondary hover:bg-white/70'
                      )}
                    >
                      {item.label}
                    </Link>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </header>

      <div className="flex">
        <CrmToolRail currentPath={currentPath} onSearch={() => setCommandOpen(true)} rail={rail} settingsHref={settingsHref} />

        <main className="min-w-0 flex-1 px-4 pb-12 pt-7 sm:px-6 xl:pr-10">{children}</main>
      </div>

      <CrmCommandPalette open={commandOpen} onClose={() => setCommandOpen(false)} />
    </CrmCanvas>
  );
}

/**
 * The slim left tool rail.
 *
 * Icon-only and narrow, matching the reference: verbs and board-level jumps
 * that apply anywhere, sitting beside the page rather than floating over it.
 * It is a real column, so the content next to it is never covered.
 */
export function CrmToolRail({
  currentPath,
  onSearch,
  rail,
  settingsHref,
}: {
  currentPath: string;
  onSearch: () => void;
  rail: ShellRailTool[];
  settingsHref: string;
}) {
  const isActive = (href: string) => currentPath === href || currentPath.startsWith(`${href}/`);

  const railItems: {
    icon: LucideIcon;
    label: string;
    href?: string;
    onClick?: () => void;
    hue?: string;
    divider?: boolean;
  }[] = [
    { icon: Search, label: 'Search', onClick: onSearch },
    ...rail,
    { icon: Settings, label: 'Settings', href: settingsHref, divider: true },
  ];

  return (
    /*
     * A reserved column, with the pill centred in the viewport inside it.
     *
     * The column keeps the rail out of the content's way; centring the pill
     * within it is what produces the floating look. Two details do the actual
     * work: real clearance from the window edge, and a lift shadow — pressed
     * against the edge with a hairline shadow it read as a docked strip, not
     * as something floating over the page.
     */
    /*
     * The pill centres on the band below the header, not on the whole window —
     * with a sticky header the window's centre is not the centre of anything
     * the reader can see, and centring on it left the pill visibly low.
     *
     * `--crm-header` is the header's own height, so the two stay in step if the
     * header's padding ever changes.
     *
     * The 84px column puts the pill's axis on 42px, the same axis as the brand
     * mark in the header above, so the two read as one left column.
     */
    <aside
      aria-label="Module tools"
      style={{ '--crm-header': '75px' } as React.CSSProperties}
      className="sticky top-[var(--crm-header)] z-20 hidden h-[calc(100vh-var(--crm-header))] w-[84px] shrink-0 items-center justify-center xl:flex"
    >
      {/*
       * The rail stays narrow. Only the tool under the cursor opens.
       *
       * Hovering an icon does three things at once: that icon swells and slides
       * out of the pill's left edge, a rounded label capsule springs out beside
       * it, and its neighbours above and below swell a little less — the dock
       * magnification curve. Widening the whole rail instead, which is what the
       * previous version did, moved every icon at once and lost the sense that
       * you were pointing at one thing.
       *
       * Nothing here is clipped, so the swell and the capsule can leave the
       * pill; the pill is absolutely positioned in a reserved column, so none of
       * it costs the page any layout.
       */}
      {/*
       * An ordinary flex child, centred by the column's own `items-center`.
       *
       * It needed to be positioned only while the whole rail expanded on hover;
       * now that only the pointed-at tool moves, the pill's own box never
       * changes size, so it can stay in flow. Taking it out of flow was in fact
       * what broke its centring: the column stretches to the document's height
       * in the flex row, and neither `absolute` nor `fixed` resolved against
       * the viewport once an ancestor established a containing block.
       */}
      <div className="crm-pop flex w-[58px] flex-col gap-1 rounded-[29px] border border-white/90 bg-white/92 p-2 shadow-ovs-crmLift backdrop-blur-xl">
        {railItems.map((item, index) => (
          <React.Fragment key={item.label}>
            {item.divider && <span className="my-1 ml-2.5 h-px w-5 shrink-0 bg-ovs-crm-border" aria-hidden="true" />}
            <RailItem
              icon={item.icon}
              label={item.label}
              href={item.href}
              onClick={item.onClick}
              hue={item.hue}
              active={item.href ? isActive(item.href) : false}
              delay={40 + index * 35}
            />
          </React.Fragment>
        ))}
      </div>
    </aside>
  );
}

/**
 * One tool in the rail: a circular well that swells under the cursor, plus a
 * rounded label capsule that springs out beside it.
 *
 * The magnification and the capsule are driven entirely by CSS — see the
 * `.crm-rail-*` rules — so moving the cursor down the rail costs no React
 * renders, and the neighbour falloff comes from sibling selectors rather than
 * from an index diff held in state.
 *
 * The capsule is absolutely positioned and `pointer-events-none`, so it can
 * leave the rail without stealing the hover that produced it.
 */
export function RailItem({
  icon: Icon,
  label,
  href,
  onClick,
  hue,
  active = false,
  delay,
}: {
  icon: LucideIcon;
  label: string;
  href?: string;
  onClick?: () => void;
  hue?: string;
  active?: boolean;
  delay?: number;
}) {
  const inner = (
    <>
      <span
        className={cn(
          'crm-rail-well flex h-10 w-10 items-center justify-center rounded-full',
          active && 'bg-ovs-crm-ink text-white'
        )}
        style={{ color: active ? undefined : hue }}
      >
        <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
      </span>

      <span
        aria-hidden="true"
        className="crm-rail-capsule pointer-events-none absolute left-full top-1/2 z-10 ml-3 whitespace-nowrap rounded-full bg-ovs-crm-ink px-3.5 py-1.5 text-[11.5px] font-medium text-white shadow-ovs-crmInk"
      >
        {label}
      </span>
    </>
  );

  const classes = cn(
    'crm-rail-item relative flex h-10 w-10 shrink-0 items-center justify-center',
    delay !== undefined && 'crm-rise'
  );
  const style = { animationDelay: delay !== undefined ? `${delay}ms` : undefined };

  return href ? (
    <Link href={href} aria-label={label} aria-current={active ? 'page' : undefined} className={classes} style={style}>
      {inner}
    </Link>
  ) : (
    <button type="button" onClick={onClick} aria-label={label} className={classes} style={style}>
      {inner}
    </button>
  );
}

/**
 * The CRM's shell: the company chrome with the CRM's rail and create menu.
 *
 * Kept as a named export so the CRM's thirty-odd pages did not have to change
 * when the shell became module-aware.
 */
export function CrmShell(props: { title: string; children: React.ReactNode }) {
  return <CompanyShell {...props} />;
}
