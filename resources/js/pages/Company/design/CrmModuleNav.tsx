import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { EXECUTIVE_NAV_GROUPS } from '@/ovs/layouts/sidebar-configs';
import { useNavAuthorization } from '@/hooks/useNavAuthorization';
import type { ModuleNavItem, SidebarNavItem, SidebarNavSection } from '@/ovs/layouts/sidebar-configs';

/**
 * Company-wide module navigation for the header.
 *
 * The CRM is one module among several, and a header that only listed CRM
 * destinations made the rest of the product unreachable without going back to
 * the legacy sidebar. This reads the same `EXECUTIVE_NAV_GROUPS` config the
 * sidebar does — one source of truth, so a module added there appears here
 * without a second edit — and renders each section as a hover-revealed panel.
 *
 * Hover opens; focus opens too, so the menu is reachable from the keyboard.
 * A short close delay keeps the panel alive while the pointer travels the gap
 * between the trigger and the panel, which is the usual failure of hover menus.
 */

/**
 * Pillar id → the label the header shows. Titles are shouty for a sidebar.
 *
 * Five entries, matching COMPANY_MODULES. The header has room for five words
 * and not for five phrases, so "Revenue & Subscriptions" is "Revenue" here and
 * keeps its full name as the dropdown's own heading.
 */
const MODULE_LABELS: Record<string, string> = {
  operations: 'Operations',
  revenue: 'Revenue',
  acquisition: 'Acquisition',
  people: 'People',
  governance: 'Governance',
};

interface NavPageProps {
  auth?: { user?: { permissions?: string[]; roles?: string[]; user_type?: string } };
  [key: string]: unknown;
}

function moduleLabel(section: SidebarNavSection): string {
  return MODULE_LABELS[section.id] ?? section.title.replace(/\s*\(.*\)\s*/, '').toLowerCase()
    .replace(/(^|\s)\S/g, (c) => c.toUpperCase());
}

export function CrmModuleNav({ currentPath }: { currentPath: string }) {
  const { props } = usePage<NavPageProps>();
  const isMasterAdmin = props.auth?.user?.user_type === 'master_admin';

  const [openId, setOpenId] = React.useState<string | null>(null);
  const closeTimer = React.useRef<number | null>(null);

  /* The shared guard — the same one the sidebar and the palette ask. */
  const isAuthorized = useNavAuthorization();

  const modules = React.useMemo(
    () =>
      EXECUTIVE_NAV_GROUPS.filter((section) => isAuthorized(section.permission))
        .map((section) => ({
          ...section,
          items: section.items.filter(
            (item) => isAuthorized(item.permission) && !(item.hideForMasterAdmin && isMasterAdmin)
          ),
        }))
        .filter((section) => section.items.length > 0),
    [isAuthorized, isMasterAdmin]
  );

  const cancelClose = () => {
    if (closeTimer.current !== null) {
      window.clearTimeout(closeTimer.current);
      closeTimer.current = null;
    }
  };

  const scheduleClose = () => {
    cancelClose();
    closeTimer.current = window.setTimeout(() => setOpenId(null), 140);
  };

  React.useEffect(() => cancelClose, []);

  React.useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpenId(null);
    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const sectionIsActive = (section: SidebarNavSection) =>
    section.items.some((item) => item.href !== '#' && currentPath.startsWith(item.href));

  const itemIsActive = (item: SidebarNavItem) =>
    currentPath === item.href || (item.href !== '#' && currentPath.startsWith(`${item.href}/`));


  return (
    <nav aria-label="Company pillars" className="relative hidden items-center gap-0.5 lg:flex">
      {/*
       * Platform Overview no longer sits here as a standalone pill.
       *
       * It was the one destination with no stated home, which is why it read as
       * a separate thing from the product rather than the summary of one part
       * of it. It now opens Operations & Monitoring — the pillar it summarises
       * — as that pillar's first entry, so there is one Dashboard and it is
       * findable from the map rather than beside it.
       */}
      {modules.map((section) => {
        const open = openId === section.id;
        const active = sectionIsActive(section);

        return (
          <div
            key={section.id}
            className="relative"
            onMouseEnter={() => { cancelClose(); setOpenId(section.id); }}
            onMouseLeave={scheduleClose}
          >
            <button
              type="button"
              aria-expanded={open}
              aria-haspopup="true"
              onFocus={() => { cancelClose(); setOpenId(section.id); }}
              onClick={() => setOpenId(open ? null : section.id)}
              className={cn(
                'inline-flex items-center gap-1 rounded-full px-3.5 py-2 text-[13px] font-semibold transition-colors',
                active || open
                  ? 'bg-ovs-crm-ink text-white'
                  : 'text-ovs-crm-text-secondary hover:bg-white/70 hover:text-ovs-crm-text-primary'
              )}
            >
              {moduleLabel(section)}

              <ChevronDown
                className={cn('h-3 w-3 transition-transform duration-200', open && 'rotate-180')}
                aria-hidden="true"
              />
            </button>

            {open && (
              <div
                role="menu"
                aria-label={moduleLabel(section)}
                onMouseEnter={cancelClose}
                onMouseLeave={scheduleClose}
                className={cn(
                  'absolute left-1/2 top-full z-50 mt-2 -translate-x-1/2 rounded-[26px] border border-ovs-crm-border-soft',
                  'bg-ovs-crm-surface-veil-strong p-3 shadow-ovs-crmLift backdrop-blur-2xl',
                  section.items.length > 6 ? 'w-[520px]' : 'w-[280px]'
                )}
              >
                <p className="px-2 pb-2 pt-1 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                  {section.title}
                </p>

                <div className={cn('grid gap-0.5', section.items.length > 6 ? 'grid-cols-2' : 'grid-cols-1')}>
                  {section.items.map((item, index) => {
                    const Icon = item.icon as LucideIcon | undefined;
                    const itemActive = itemIsActive(item);
                    /*
                     * A sub-heading, drawn once where the group changes.
                     *
                     * Acquisition holds the CRM's five destinations and
                     * Marketing's ten. Fifteen in a flat list is a wall; the
                     * heading is what tells a rep which half they are reading.
                     * It spans both columns so the two halves never interleave
                     * mid-row.
                     */
                    const group = (item as ModuleNavItem).group;
                    const startsGroup =
                      !! group && group !== (section.items[index - 1] as ModuleNavItem | undefined)?.group;

                    return (
                      <React.Fragment key={`${item.id}-cell`}>
                        {startsGroup && (
                          <p className="col-span-full mt-2 border-t border-ovs-crm-border-soft px-2.5 pb-1 pt-2.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                            {group}
                          </p>
                        )}
                        <Link
                          href={item.href}
                          role="menuitem"
                          onClick={() => setOpenId(null)}
                          aria-current={itemActive ? 'page' : undefined}
                          className={cn(
                            'flex items-center gap-2.5 rounded-[24px] px-2.5 py-2 text-[13px] font-medium transition-colors',
                            itemActive
                              ? 'bg-ovs-crm-ink text-white'
                              : 'text-ovs-crm-text-primary hover:bg-white'
                          )}
                        >
                          {Icon && (
                            <span
                              className={cn(
                                'flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                                itemActive ? 'bg-white/15 text-white' : 'bg-ovs-crm-surface-inset text-ovs-crm-text-secondary'
                              )}
                            >
                              <Icon className="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                          )}
                          <span className="min-w-0 flex-1 truncate">{item.label}</span>
                        </Link>
                      </React.Fragment>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        );
      })}
    </nav>
  );
}

/** Flat module list for the mobile sheet — the same config, no hover affordance. */
export function useCompanyModules() {
  const { props } = usePage<NavPageProps>();
  const isMasterAdmin = props.auth?.user?.user_type === 'master_admin';
  const authorized = useNavAuthorization();

  return React.useMemo(
    () =>
      EXECUTIVE_NAV_GROUPS.filter((s) => authorized(s.permission))
        .map((s) => ({
          ...s,
          items: s.items.filter((i) => authorized(i.permission) && !(i.hideForMasterAdmin && isMasterAdmin)),
        }))
        .filter((s) => s.items.length > 0),
    [authorized, isMasterAdmin]
  );
}
