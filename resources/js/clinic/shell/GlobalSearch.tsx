import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { router } from '@inertiajs/react';
import * as Popover from '@radix-ui/react-popover';
import { CornerDownLeft, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useClinicShared } from '../context';
import { ERROR_COPY, errorKind, requestJson } from '../http';
import { Spinner } from '../ui/Spinner';
import { iconFor } from '../ui/visuals';
import { useIntake } from './IntakeContext';

const SEARCH_DELAY_MS = 220;

/** One result as `ClinicSearch` sends it: a page to open, or an action (a patient's Repeat Case). */
interface SearchItem {
  key: string;
  title: string;
  subtitle: string | null;
  icon: string;
  href: string | null;
  action: { type: 'repeat_case'; label: string; patient: { id: number; uhid: string | null; name: string } } | null;
}

interface SearchGroup {
  key: string;
  label: string;
  items: SearchItem[];
}

const TARGET_WORDS: Record<string, string> = {
  patients: 'patients',
  pages: 'pages',
  appointments: 'appointments',
  support: 'support',
};

/**
 * The Clinic Portal's one search, in the top bar (Ctrl+K or ⌘K to reach it).
 *
 * What it looks in is decided on the server for every request (`ClinicSearch`):
 * pages this user may open, patients (opening their Repeat Case where the
 * user may check patients in), appointments at the branch being worked
 * (opening the Calendar on that day with the appointment selected) and the
 * support requests this user may see. A group the user may not open never
 * arrives. Before anything is typed it lists the pages this user may open,
 * from the server's navigation.
 *
 * Arrow keys move, Enter opens, Escape clears then closes.
 */
export function GlobalSearch({ className, compact = false }: { className?: string; compact?: boolean }) {
  const { clinic, portalLinks } = useClinicShared();
  const intake = useIntake();
  const listId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const [groups, setGroups] = useState<SearchGroup[] | null>(null);
  const [loading, setLoading] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const [active, setActive] = useState(0);
  const abort = useRef<AbortController | null>(null);
  const term = query.trim();
  const url = portalLinks?.search ?? null;

  // Before typing: the pages the server's navigation gives this user.
  const pages = useMemo<SearchGroup[]>(() => {
    const items = (clinic?.navigation ?? [])
      .filter((page) => page.available && page.href !== null)
      .map((page) => ({ key: `page-${page.key}`, title: page.label, subtitle: null, icon: page.icon, href: page.href, action: null }));

    return items.length > 0 ? [{ key: 'pages', label: 'Go to', items }] : [];
  }, [clinic?.navigation]);

  useEffect(() => {
    abort.current?.abort();
    if (term === '' || !url) {
      setGroups(null);
      setLoading(false);
      setFailure(null);

      return;
    }
    setLoading(true);
    const timer = window.setTimeout(async () => {
      const controller = new AbortController();
      abort.current = controller;
      setFailure(null);
      try {
        const result = await requestJson<{ groups: SearchGroup[] }>('GET', url, { query: { q: term }, signal: controller.signal });
        setGroups(result.groups);
        setLoading(false);
      } catch (error) {
        const kind = errorKind(error);
        if (kind !== 'aborted') {
          setFailure(ERROR_COPY[kind]);
          setLoading(false);
        }
      }
    }, SEARCH_DELAY_MS);

    return () => window.clearTimeout(timer);
  }, [term, url]);

  // Ctrl+K / ⌘K reaches the search that is on screen (the bar has one per layout).
  useEffect(() => {
    const onKey = (event: globalThis.KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && inputRef.current?.offsetParent) {
        event.preventDefault();
        inputRef.current.focus();
        setOpen(true);
      }
    };
    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  }, []);

  // A Repeat Case result only where the intake can open it; the server already checks this too.
  const shown = useMemo<SearchGroup[]>(
    () =>
      (term === '' ? pages : (groups ?? []))
        .map((group) => ({ ...group, items: group.items.filter((item) => item.href !== null || (item.action?.type === 'repeat_case' && intake.available)) }))
        .filter((group) => group.items.length > 0),
    [term, pages, groups, intake.available],
  );
  const flat = useMemo(() => shown.flatMap((group) => group.items), [shown]);

  useEffect(() => setActive(0), [term, groups]);

  const choose = (item: SearchItem) => {
    setOpen(false);
    setQuery('');
    inputRef.current?.blur();
    if (item.href) {
      router.visit(item.href);
    } else if (item.action?.type === 'repeat_case') {
      intake.open('repeat', { id: item.action.patient.id, uhid: item.action.patient.uhid, name: item.action.patient.name });
    }
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      if (query) {
        setQuery('');
      } else {
        setOpen(false);
        inputRef.current?.blur();
      }

      return;
    }
    if (!open) {
      setOpen(true);
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((index) => Math.min(index + 1, Math.max(flat.length - 1, 0)));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((index) => Math.max(index - 1, 0));
    } else if (event.key === 'Enter' && flat[active] && !loading) {
      event.preventDefault();
      choose(flat[active]);
    }
  };

  const optionId = (index: number) => `${listId}-option-${index}`;
  // Names only what this user can actually find.
  const targets = (clinic?.searchable ?? ['pages']).map((key) => TARGET_WORDS[key] ?? key);
  const fullPlaceholder = `Search ${targets.length > 1 ? `${targets.slice(0, -1).join(', ')} and ${targets[targets.length - 1]}` : targets[0]}…`;
  // A phone's row is short: the first target and the promise of the rest.
  const placeholder = compact && targets.length > 1 ? `Search ${targets[0]} and more…` : fullPlaceholder;
  const settled = term !== '' && !loading && !failure;
  const shortcut = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘K' : 'Ctrl K';

  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      <Popover.Anchor asChild>
        <div className={cn('relative', className)}>
          <Search className="pointer-events-none absolute left-4 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-cx-nav-muted" strokeWidth={1.75} aria-hidden="true" />
          <input
            ref={inputRef}
            type="search"
            role="combobox"
            aria-expanded={open}
            aria-controls={listId}
            aria-autocomplete="list"
            aria-activedescendant={open && flat[active] ? optionId(active) : undefined}
            aria-label={fullPlaceholder.replace('…', '')}
            aria-keyshortcuts="Control+K Meta+K"
            placeholder={placeholder}
            value={query}
            onChange={(event) => {
              setQuery(event.target.value);
              setOpen(true);
            }}
            onFocus={() => setOpen(true)}
            onKeyDown={onKeyDown}
            className="h-11 w-full rounded-full border border-[#DCE6F3] bg-white pl-11 pr-14 text-[14px] text-cx-nav-ink shadow-[0_1px_2px_rgba(15,23,42,0.06)] transition-[border-color,box-shadow] placeholder:text-cx-text-3 hover:border-cx-nav-blue/40 focus:border-cx-nav-blue focus:outline-none focus:ring-4 focus:ring-cx-nav-blue/15 [&::-webkit-search-cancel-button]:cursor-pointer"
          />
          <kbd className="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded-md border border-cx-nav-line bg-white px-1.5 py-0.5 font-sans text-[11px] text-cx-text-3 lg:block" aria-hidden="true">
            {shortcut}
          </kbd>
        </div>
      </Popover.Anchor>
      <Popover.Portal>
        <Popover.Content
          align="start"
          sideOffset={6}
          collisionPadding={16}
          onOpenAutoFocus={(event) => event.preventDefault()}
          onInteractOutside={(event) => {
            // Clicking back into the field keeps the results open.
            if (event.target instanceof Node && inputRef.current?.contains(event.target)) {
              event.preventDefault();
            }
          }}
          className="z-50 w-[var(--radix-popover-trigger-width)] min-w-[300px] max-w-[calc(100vw-32px)] rounded-2xl border border-cx-nav-line bg-white p-1.5 shadow-cx-raised"
        >
          <div id={listId} role="listbox" aria-label="Search results" aria-busy={loading || undefined} className="max-h-[min(440px,60vh)] overflow-y-auto overscroll-contain">
            {shown.map((group) => (
              <div key={group.key} role="group" aria-label={group.label}>
                <p className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-cx-nav-muted" aria-hidden="true">
                  {group.label}
                </p>
                {group.items.map((item) => {
                  const index = flat.indexOf(item);
                  const Icon = iconFor(item.icon);

                  return (
                    <div
                      key={item.key}
                      id={optionId(index)}
                      role="option"
                      aria-selected={index === active}
                      onMouseEnter={() => setActive(index)}
                      onClick={() => choose(item)}
                      className={cn('flex min-h-11 cursor-pointer items-center gap-3 rounded-md px-3 py-2', index === active && 'bg-cx-nav-bg')}
                    >
                      <Icon className="h-[18px] w-[18px] shrink-0 text-cx-nav-muted" strokeWidth={1.75} aria-hidden="true" />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-[13.5px] font-medium text-cx-nav-ink">{item.title}</span>
                        {item.subtitle && <span className="block truncate text-[12px] text-cx-nav-muted">{item.subtitle}</span>}
                      </span>
                      {item.action ? (
                        <span className="shrink-0 text-[11.5px] font-semibold text-cx-nav-teal-strong">{item.action.label}</span>
                      ) : (
                        index === active && <CornerDownLeft className="h-4 w-4 shrink-0 text-cx-nav-muted" strokeWidth={1.75} aria-hidden="true" />
                      )}
                    </div>
                  );
                })}
              </div>
            ))}

            {loading && (
              <p className="flex items-center gap-2 px-3 py-3 text-[12.5px] text-cx-nav-muted" role="status">
                <Spinner /> Searching…
              </p>
            )}
            {failure && (
              <p className="px-3 py-3 text-[12.5px] text-cx-rose-ink" role="alert">
                {failure}
              </p>
            )}
            {settled && shown.length === 0 && <p className="px-3 py-4 text-[12.5px] text-cx-nav-muted">Nothing matches “{term}”.</p>}
          </div>
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}
