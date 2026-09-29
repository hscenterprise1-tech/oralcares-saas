import React from 'react';
import { useNavAuthorization } from '@/hooks/useNavAuthorization';
import { router } from '@inertiajs/react';
import { Search, CornerDownLeft, ArrowUp, ArrowDown } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CRM_DESTINATIONS, CRM_CREATE_ACTIONS } from '@/ovs/layouts/sidebar-configs';

/**
 * CRM command palette.
 *
 * Navigation by typing rather than by pointing — the interaction people expect
 * from a tool they use all day. Keyboard is the primary input: arrows move,
 * Enter opens, Escape closes, and the highlighted row is always scrolled into
 * view so the selection never disappears off the list.
 *
 * This navigates to CRM surfaces. It deliberately does not query records over
 * the network — there is no search endpoint to call, and a palette that
 * silently returns nothing for a real lead name would be worse than one that
 * is honest about being navigation.
 */

interface Destination {
  id: string;
  label: string;
  hint: string;
  href: string;
  icon: LucideIcon;
  group: string;
  /** Extra words that should match this entry. */
  keywords?: string;
  /** The canonical CRM permission the route behind this entry requires. */
  permission?: string;
}

/*
 * The palette is CRM_DESTINATIONS, projected — not a longer list beside it.
 *
 * It used to carry five extra entries the menus did not: Demos, Loss triage,
 * Tasks, Contacts and Sales teams. Those were the CRM's second navigation, and
 * a palette that reaches surfaces the menu will not is exactly how a product
 * grows destinations nobody decided on. Each of them now lives where the work
 * happens — demos and tasks in My Work and on the deal, loss triage in the
 * Deals "Lost" view, deal contacts on the Deal Profile — and Sales teams is
 * configuration reached from ADMIN.
 *
 * Nothing here points at a redirect: a redirect entry offers one destination
 * under two names.
 */
const DESTINATIONS: Destination[] = [
  ...CRM_DESTINATIONS.map((destination) => ({
    id: destination.id,
    label: destination.label,
    hint: destination.hint,
    href: destination.href,
    icon: destination.icon as LucideIcon,
    group: 'Go to',
    keywords: destination.keywords,
    permission: destination.permission,
  })),
  ...CRM_CREATE_ACTIONS.map((action) => ({
    id: action.id,
    label: `Create ${action.label}`,
    hint: 'Add a new prospect',
    href: action.href,
    icon: action.icon as LucideIcon,
    group: 'Create',
    keywords: 'new add',
    permission: action.permission,
  })),
];

export function CrmCommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [query, setQuery] = React.useState('');
  const [active, setActive] = React.useState(0);
  const inputRef = React.useRef<HTMLInputElement>(null);
  const listRef = React.useRef<HTMLUListElement>(null);

  /*
   * The shared guard, so the palette and the menus cannot disagree about what
   * a reader is allowed to reach.
   *
   * This used to carry its own copy, which tried to recognise a master admin by
   * role name and otherwise relied on their permission list being empty. Both
   * assumptions were wrong, and the palette quietly dropped every destination
   * outside the CRM for the one user entitled to all of them.
   */
  const mayOpen = useNavAuthorization();

  const results = React.useMemo(() => {
    const allowed = DESTINATIONS.filter((item) => mayOpen(item.permission));
    const term = query.trim().toLowerCase();
    if (!term) return allowed;

    return allowed.filter((item) =>
      `${item.label} ${item.hint} ${item.keywords ?? ''}`.toLowerCase().includes(term)
    );
  }, [query, mayOpen]);

  // Reset each time it opens so it never reopens mid-search.
  React.useEffect(() => {
    if (open) {
      setQuery('');
      setActive(0);
      requestAnimationFrame(() => inputRef.current?.focus());
    }
  }, [open]);

  React.useEffect(() => {
    setActive(0);
  }, [query]);

  // Keep the highlighted row visible while arrowing through a long list.
  React.useEffect(() => {
    listRef.current?.querySelector<HTMLElement>('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
  }, [active]);

  if (!open) return null;

  const go = (item: Destination) => {
    onClose();
    router.visit(item.href);
  };

  const onKeyDown = (event: React.KeyboardEvent) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((i) => (i + 1) % Math.max(1, results.length));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((i) => (i - 1 + results.length) % Math.max(1, results.length));
    } else if (event.key === 'Enter' && results[active]) {
      event.preventDefault();
      go(results[active]);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      onClose();
    }
  };

  let renderedGroup = '';

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center px-4 pt-[12vh]"
      role="dialog"
      aria-modal="true"
      aria-label="Search OralCares"
    >
      <div
        className="absolute inset-0 bg-ovs-crm-ink/50 backdrop-blur-sm"
        onClick={onClose}
        aria-hidden="true"
      />

      <div className="relative w-full max-w-lg overflow-hidden rounded-[24px] bg-ovs-crm-surface shadow-2xl shadow-ovs-crmPanel">
        <div className="flex items-center gap-2.5 border-b border-ovs-crm-border px-4">
          <Search className="h-4 w-4 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
          <input
            ref={inputRef}
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            onKeyDown={onKeyDown}
            placeholder="Search OralCares…"
            aria-label="Search OralCares"
            aria-controls="crm-command-results"
            className="h-12 min-w-0 flex-1 bg-transparent text-[13px] text-ovs-crm-text-primary placeholder:text-ovs-crm-text-muted focus:outline-none"
          />
          <kbd className="shrink-0 rounded border border-ovs-crm-border px-1.5 py-0.5 font-sans text-[10px] font-semibold text-ovs-crm-text-muted">
            ESC
          </kbd>
        </div>

        <ul id="crm-command-results" ref={listRef} className="max-h-[46vh] overflow-y-auto py-1.5" role="listbox">
          {results.length === 0 && (
            <li className="px-4 py-8 text-center text-[13px] text-ovs-crm-text-muted">
              Nothing matches “{query}”.
            </li>
          )}

          {results.map((item, index) => {
            const showGroup = item.group !== renderedGroup;
            renderedGroup = item.group;
            const isActive = index === active;

            return (
              <React.Fragment key={item.id}>
                {showGroup && (
                  <li
                    className="px-4 pb-1 pt-2 text-[10px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted"
                    aria-hidden="true"
                  >
                    {item.group}
                  </li>
                )}
                <li role="option" aria-selected={isActive} data-active={isActive}>
                  <button
                    type="button"
                    onMouseEnter={() => setActive(index)}
                    onClick={() => go(item)}
                    className={cn(
                      'flex w-full items-center gap-3 px-4 py-2.5 text-left transition-colors',
                      isActive ? 'bg-ovs-crm-brand-soft' : 'hover:bg-ovs-crm-surface-inset'
                    )}
                  >
                    <item.icon
                      className={cn(
                        'h-4 w-4 shrink-0',
                        isActive ? 'text-ovs-crm-brand-text' : 'text-ovs-crm-text-muted'
                      )}
                      aria-hidden="true"
                    />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[13px] font-semibold text-ovs-crm-text-primary">
                        {item.label}
                      </span>
                      <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
                        {item.hint}
                      </span>
                    </span>
                    {isActive && (
                      <CornerDownLeft className="h-3.5 w-3.5 shrink-0 text-ovs-crm-brand-text" aria-hidden="true" />
                    )}
                  </button>
                </li>
              </React.Fragment>
            );
          })}
        </ul>

        <div className="flex items-center gap-3 border-t border-ovs-crm-border bg-ovs-crm-surface-inset px-4 py-2 text-[10px] font-medium text-ovs-crm-text-muted">
          <span className="inline-flex items-center gap-1">
            <ArrowUp className="h-3 w-3" aria-hidden="true" />
            <ArrowDown className="h-3 w-3" aria-hidden="true" />
            Navigate
          </span>
          <span className="inline-flex items-center gap-1">
            <CornerDownLeft className="h-3 w-3" aria-hidden="true" />
            Open
          </span>
        </div>
      </div>
    </div>
  );
}
