import { useCallback, useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { router } from '@inertiajs/react';
import * as Popover from '@radix-ui/react-popover';
import { Check, ChevronDown, MapPin, RefreshCw, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '../http';
import type { BranchPage, BranchSummary, ClinicContextProps, PortalLinks } from '../types';
import { Spinner } from '../ui/Spinner';

const SEARCH_DELAY_MS = 250;

/** The branch's own display name, falling back to its name. */
export function branchLabel(clinic: ClinicContextProps): string {
  const branch = clinic.active_branch;

  return branch ? branch.display_name?.trim() || branch.name : 'Select a branch';
}

/**
 * Which branch this session works in.
 *
 * Opens instantly on the branches shared with the page (at most 50). A clinic
 * with more, or anyone who types, gets bounded pages from the server's
 * branch search instead, so 1 branch and 500 branches cost the same to open.
 * Only branches the server says this member may open are ever listed, and the
 * switch itself is checked again on the server.
 */
export function BranchSwitcher({
  clinic,
  links,
  variant = 'bar',
}: {
  clinic: ClinicContextProps;
  links: PortalLinks;
  variant?: 'bar' | 'block';
}) {
  const [open, setOpen] = useState(false);
  const label = branchLabel(clinic);

  if (clinic.accessible_branch_count <= 1) {
    return (
      <span
        className={cn(
          'inline-flex min-w-0 items-center gap-1.5 text-[12.5px] font-medium text-clinic-secondary',
          variant === 'block' && 'rounded-xl bg-clinic-inset px-3 py-2.5',
        )}
      >
        <MapPin className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span className="truncate">{clinic.active_branch ? label : 'No branch assigned'}</span>
      </span>
    );
  }

  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      <Popover.Trigger
        aria-label={`Branch: ${label}. Change branch`}
        className={cn(
          'group inline-flex min-w-0 items-center gap-1.5 rounded-full text-[12.5px] font-semibold text-clinic-ink',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
          variant === 'bar'
            ? 'h-8 max-w-[240px] border border-clinic-border bg-clinic-surface px-3 hover:border-clinic-border-strong [@media(pointer:coarse)]:h-11'
            : 'h-11 w-full justify-between rounded-xl bg-clinic-inset px-3',
          !clinic.active_branch && 'border-clinic-warning/40 text-clinic-warning',
        )}
      >
        <MapPin className="h-3.5 w-3.5 shrink-0 text-clinic-secondary" aria-hidden="true" />
        <span className="truncate">{label}</span>
        <ChevronDown
          className="h-3.5 w-3.5 shrink-0 text-clinic-secondary motion-safe:transition-transform group-data-[state=open]:rotate-180"
          aria-hidden="true"
        />
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content
          align="start"
          sideOffset={8}
          collisionPadding={16}
          className="z-50 w-[min(340px,calc(100vw-32px))] rounded-2xl border border-clinic-border bg-clinic-surface shadow-[0_16px_40px_-12px_rgba(16,24,40,0.25)] focus:outline-none"
        >
          <BranchList clinic={clinic} links={links} onDone={() => setOpen(false)} />
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}

function BranchList({ clinic, links, onDone }: { clinic: ClinicContextProps; links: PortalLinks; onDone: () => void }) {
  const listId = useId();
  const [query, setQuery] = useState('');
  const [remote, setRemote] = useState<{ items: BranchSummary[]; nextPage: number | null } | null>(null);
  const [loading, setLoading] = useState(false);
  const [failure, setFailure] = useState<ClinicHttpErrorKind | null>(null);
  const [switchingTo, setSwitchingTo] = useState<number | null>(null);
  const [switchError, setSwitchError] = useState<string | null>(null);
  const [activeIndex, setActiveIndex] = useState(0);
  const abortRef = useRef<AbortController | null>(null);

  const sharedIsComplete = clinic.accessible_branches.length >= clinic.accessible_branch_count;
  // Everything is already on the page for most clinics; only a clinic with
  // more branches than were shared asks the server, for searches too.
  const needsServer = !sharedIsComplete;

  const load = useCallback(
    async (search: string, page: number) => {
      abortRef.current?.abort();
      const controller = new AbortController();
      abortRef.current = controller;
      setLoading(true);
      setFailure(null);

      try {
        const result = await requestJson<BranchPage>('GET', links.branches, {
          query: { search, page },
          signal: controller.signal,
        });
        setRemote((current) => ({
          items: page > 1 && current ? [...current.items, ...result.data] : result.data,
          nextPage: result.next_page,
        }));
      } catch (error) {
        const kind = errorKind(error);
        if (kind !== 'aborted') {
          setFailure(kind);
        }
      } finally {
        if (abortRef.current === controller) {
          setLoading(false);
        }
      }
    },
    [links.branches],
  );

  useEffect(() => {
    if (!needsServer) {
      return;
    }
    const timer = window.setTimeout(() => void load(query.trim(), 1), query.trim() === '' ? 0 : SEARCH_DELAY_MS);

    return () => window.clearTimeout(timer);
  }, [needsServer, query, load]);

  useEffect(() => () => abortRef.current?.abort(), []);

  const items = useMemo<BranchSummary[]>(() => {
    if (needsServer) {
      return remote?.items ?? [];
    }
    const needle = query.trim().toLowerCase();

    return needle === ''
      ? clinic.accessible_branches
      : clinic.accessible_branches.filter((branch) => branch.name.toLowerCase().includes(needle));
  }, [needsServer, remote, query, clinic.accessible_branches]);

  useEffect(() => setActiveIndex(0), [query]);

  const choose = (branch: BranchSummary) => {
    if (branch.id === clinic.active_branch?.id) {
      onDone();
      return;
    }
    setSwitchingTo(branch.id);
    setSwitchError(null);
    router.post(
      links.branchSwitch,
      { branch_id: branch.id },
      {
        preserveScroll: false,
        onSuccess: () => onDone(),
        onError: (errors) => setSwitchError(errors.branch_id ?? 'This branch could not be opened. Try again.'),
        onNetworkError: () => {
          setSwitchError(ERROR_COPY.network);
        },
        onFinish: () => setSwitchingTo(null),
      },
    );
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActiveIndex((index) => Math.min(index + 1, Math.max(items.length - 1, 0)));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActiveIndex((index) => Math.max(index - 1, 0));
    } else if (event.key === 'Enter' && items[activeIndex]) {
      event.preventDefault();
      choose(items[activeIndex]);
    }
  };

  const optionId = (index: number) => `${listId}-option-${index}`;

  return (
    <div>
      <div className="border-b border-clinic-border p-3">
        <p className="mb-2 px-1 text-[10px] font-bold uppercase tracking-[0.08em] text-clinic-secondary">
          Branches · {clinic.accessible_branch_count}
        </p>
        <div className="relative">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-clinic-secondary"
            aria-hidden="true"
          />
          <input
            autoFocus
            type="search"
            role="combobox"
            aria-expanded="true"
            aria-controls={listId}
            aria-activedescendant={items[activeIndex] ? optionId(activeIndex) : undefined}
            aria-label="Search branches"
            placeholder="Search branches"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            onKeyDown={onKeyDown}
            className="h-10 w-full rounded-xl border border-clinic-border bg-clinic-inset pl-9 pr-3 text-[13px] text-clinic-ink placeholder:text-clinic-secondary focus:border-clinic-brand-strong focus:bg-clinic-surface focus:outline-none focus:ring-2 focus:ring-clinic-brand/25"
          />
        </div>
      </div>

      {switchError && (
        <p
          role="alert"
          className="mx-3 mt-3 rounded-xl bg-clinic-danger-soft px-3 py-2 text-[12.5px] font-medium text-clinic-danger"
        >
          {switchError}
        </p>
      )}

      <ul
        id={listId}
        role="listbox"
        aria-label="Branches"
        aria-busy={loading || undefined}
        className="max-h-[320px] overflow-y-auto overscroll-contain p-1.5"
      >
        {items.map((branch, index) => {
          const isActive = branch.id === clinic.active_branch?.id;
          const isSwitching = switchingTo === branch.id;

          return (
            <li
              key={branch.id}
              id={optionId(index)}
              role="option"
              aria-selected={isActive}
              aria-disabled={switchingTo !== null || undefined}
              onMouseEnter={() => setActiveIndex(index)}
              onClick={() => switchingTo === null && choose(branch)}
              className={cn(
                'flex min-h-11 cursor-pointer items-center gap-2.5 rounded-xl px-3 py-2 text-[13px] text-clinic-ink',
                index === activeIndex && 'bg-clinic-inset',
                switchingTo !== null && !isSwitching && 'opacity-50',
              )}
            >
              <span className="min-w-0 flex-1">
                <span className={cn('block truncate', isActive && 'font-semibold')}>{branch.name}</span>
                {branch.is_main && <span className="text-[11.5px] text-clinic-secondary">Main branch</span>}
              </span>
              {isSwitching ? (
                <Spinner className="h-4 w-4 text-clinic-secondary" />
              ) : isActive ? (
                <Check className="h-4 w-4 text-clinic-brand-strong" aria-label="Current branch" />
              ) : null}
            </li>
          );
        })}
      </ul>

      <div className="px-3 pb-3" aria-live="polite">
        {loading && (
          <p className="flex items-center gap-2 px-1 py-2 text-[12.5px] text-clinic-secondary">
            <Spinner /> Loading branches…
          </p>
        )}
        {!loading && failure && (
          <div className="flex items-center justify-between gap-2 rounded-xl bg-clinic-danger-soft px-3 py-2 text-[12.5px] text-clinic-danger">
            <span>{ERROR_COPY[failure]}</span>
            <button
              type="button"
              onClick={() => void load(query.trim(), 1)}
              className="inline-flex items-center gap-1 rounded-full px-2 py-1 font-semibold hover:bg-white/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-danger"
            >
              <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" /> Retry
            </button>
          </div>
        )}
        {!loading && !failure && items.length === 0 && (
          <p className="px-1 py-2 text-[12.5px] text-clinic-secondary">No branches match “{query.trim()}”.</p>
        )}
        {!loading && !failure && needsServer && remote?.nextPage && (
          <button
            type="button"
            onClick={() => void load(query.trim(), remote.nextPage ?? 1)}
            className="mt-1 h-9 w-full rounded-full border border-clinic-border text-[12.5px] font-semibold text-clinic-ink hover:bg-clinic-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
          >
            Show more branches
          </button>
        )}
      </div>
    </div>
  );
}
