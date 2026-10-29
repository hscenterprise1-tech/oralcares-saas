import { useCallback, useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { router } from '@inertiajs/react';
import * as Popover from '@radix-ui/react-popover';
import { Building2, Check, ChevronDown, MapPin, RefreshCw, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ERROR_COPY, errorKind, requestJson, type ClinicHttpErrorKind } from '../http';
import type { BranchPage, BranchSummary, ClinicContextProps, PortalLinks } from '../types';
import { Spinner } from '../ui/Spinner';

const SEARCH_DELAY_MS = 250;

/** Only the owner may look across every branch at once (staff roles are held per branch); the server checks it again. */
export function canSeeAllBranches(clinic: ClinicContextProps): boolean {
  return clinic.is_owner && clinic.accessible_branch_count > 1;
}

/** The branch's own display name, falling back to its name; "All branches" for an owner who chose them all. */
export function branchLabel(clinic: ClinicContextProps): string {
  const branch = clinic.active_branch;
  if (!branch && canSeeAllBranches(clinic)) {
    return 'All branches';
  }

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
    // One branch in the top bar: nothing to choose, so nothing shown; the server keeps it as the context.
    if (variant === 'bar' && clinic.active_branch) {
      return null;
    }

    return (
      // One branch: said, never offered as a choice.
      <span
        className={cn(
          'inline-flex min-w-0 items-center gap-2 font-medium',
          variant === 'block' ? 'rounded-xl bg-clinic-inset px-3 py-2.5 text-[12.5px] text-clinic-secondary' : 'h-11 max-w-full px-1 text-[14px] text-cx-nav-ink',
        )}
      >
        {variant === 'bar' ? <Building2 className="h-[18px] w-[18px] shrink-0 text-cx-nav-muted" strokeWidth={1.75} aria-hidden="true" /> : <MapPin className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />}
        <span className="truncate">{clinic.active_branch ? label : 'No branch assigned'}</span>
      </span>
    );
  }

  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      <Popover.Trigger
        aria-label={`Branch: ${label}. Change branch`}
        className={cn(
          'group inline-flex min-w-0 items-center gap-1.5 font-semibold focus-visible:outline-none focus-visible:ring-2',
          variant === 'bar'
            ? 'h-11 max-w-[240px] shrink-0 gap-2.5 rounded-full border border-[#DCE6F3] bg-white px-3.5 text-[14px] text-cx-nav-ink shadow-[0_1px_2px_rgba(15,23,42,0.06)] hover:border-cx-nav-blue/40 focus-visible:ring-cx-nav-blue sm:min-w-[200px] sm:justify-between'
            : 'h-11 w-full justify-between rounded-xl bg-clinic-inset px-3 text-[12.5px] text-clinic-ink focus-visible:ring-clinic-brand-strong',
          !clinic.active_branch && !canSeeAllBranches(clinic) && (variant === 'bar' ? 'border-cx-amber-ink/40 text-cx-amber-ink' : 'border-clinic-warning/40 text-clinic-warning'),
        )}
      >
        {variant === 'bar' ? <Building2 className="h-[18px] w-[18px] shrink-0 text-cx-nav-muted" strokeWidth={1.75} aria-hidden="true" /> : <MapPin className="h-3.5 w-3.5 shrink-0 text-clinic-secondary" aria-hidden="true" />}
        <span className={cn('truncate', variant === 'bar' && 'flex-1 text-left max-[419px]:hidden')}>{label}</span>
        <ChevronDown
          className={cn('h-4 w-4 shrink-0 motion-safe:transition-transform group-data-[state=open]:rotate-180', variant === 'bar' ? 'text-cx-nav-muted' : 'text-clinic-secondary')}
          strokeWidth={variant === 'bar' ? 1.75 : undefined}
          aria-hidden="true"
        />
      </Popover.Trigger>
      <Popover.Portal>
        <Popover.Content
          align="start"
          sideOffset={8}
          collisionPadding={16}
          className="z-50 w-[min(340px,calc(100vw-32px))] rounded-2xl border border-cx-nav-line bg-white shadow-cx-raised focus:outline-none"
        >
          <BranchList clinic={clinic} links={links} onDone={() => setOpen(false)} />
        </Popover.Content>
      </Popover.Portal>
    </Popover.Root>
  );
}

/** The branch choice itself (search, list, switch): the top bar's selector and the sidebar's clinic card both open it. */
export function BranchList({ clinic, links, onDone }: { clinic: ClinicContextProps; links: PortalLinks; onDone: () => void }) {
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

  const allBranches = canSeeAllBranches(clinic);
  // One list for the keyboard: "All branches" (null) first for the owner, then the branches.
  const options = useMemo<(BranchSummary | null)[]>(() => (allBranches && query.trim() === '' ? [null, ...items] : items), [allBranches, query, items]);

  const choose = (branch: BranchSummary | null) => {
    if ((branch?.id ?? null) === (clinic.active_branch?.id ?? null)) {
      onDone();
      return;
    }
    setSwitchingTo(branch?.id ?? 0);
    setSwitchError(null);
    router.post(
      links.branchSwitch,
      { branch_id: branch?.id ?? null },
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
      setActiveIndex((index) => Math.min(index + 1, Math.max(options.length - 1, 0)));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActiveIndex((index) => Math.max(index - 1, 0));
    } else if (event.key === 'Enter' && activeIndex < options.length && switchingTo === null) {
      event.preventDefault();
      choose(options[activeIndex]);
    }
  };

  const optionId = (index: number) => `${listId}-option-${index}`;

  return (
    <div>
      <div className="border-b border-cx-nav-line p-3">
        <p className="mb-2 px-1 text-[10px] font-bold uppercase tracking-[0.08em] text-cx-nav-muted">
          Branches · {clinic.accessible_branch_count}
        </p>
        <div className="relative">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-cx-nav-muted"
            aria-hidden="true"
          />
          <input
            autoFocus
            type="search"
            role="combobox"
            aria-expanded="true"
            aria-controls={listId}
            aria-activedescendant={activeIndex < options.length ? optionId(activeIndex) : undefined}
            aria-label="Search branches"
            placeholder="Search branches"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            onKeyDown={onKeyDown}
            className="h-10 w-full rounded-md border border-cx-nav-line bg-white pl-9 pr-3 text-[13px] text-cx-nav-ink placeholder:text-cx-nav-muted focus:border-cx-nav-teal focus:outline-none focus:ring-2 focus:ring-cx-nav-teal/20"
          />
        </div>
      </div>

      {switchError && (
        <p
          role="alert"
          className="mx-3 mt-3 rounded-md bg-cx-rose-bg px-3 py-2 text-[12.5px] font-medium text-cx-rose-ink"
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
        {options[0] === null && (
          <li
            id={optionId(0)}
            role="option"
            aria-selected={!clinic.active_branch}
            aria-disabled={switchingTo !== null || undefined}
            onMouseEnter={() => setActiveIndex(0)}
            onClick={() => switchingTo === null && choose(null)}
            className={cn('flex min-h-11 cursor-pointer items-center gap-2.5 rounded-md px-3 py-2 text-[13px] text-cx-nav-ink', activeIndex === 0 && 'bg-cx-nav-bg')}
          >
            <span className="min-w-0 flex-1">
              <span className={cn('block truncate', !clinic.active_branch && 'font-semibold')}>All branches</span>
              <span className="text-[11.5px] text-cx-nav-muted">Every branch together</span>
            </span>
            {switchingTo === 0 ? (
              <Spinner className="h-4 w-4 text-cx-nav-muted" />
            ) : !clinic.active_branch ? (
              <Check className="h-4 w-4 text-cx-nav-teal-strong" aria-label="Current view" />
            ) : null}
          </li>
        )}
        {items.map((branch, itemIndex) => {
          const index = options[0] === null ? itemIndex + 1 : itemIndex;
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
                'flex min-h-11 cursor-pointer items-center gap-2.5 rounded-md px-3 py-2 text-[13px] text-cx-nav-ink',
                index === activeIndex && 'bg-cx-nav-bg',
                switchingTo !== null && !isSwitching && 'opacity-50',
              )}
            >
              <span className="min-w-0 flex-1">
                <span className={cn('block truncate', isActive && 'font-semibold')}>{branch.name}</span>
                {branch.is_main && <span className="text-[11.5px] text-cx-nav-muted">Main branch</span>}
              </span>
              {isSwitching ? (
                <Spinner className="h-4 w-4 text-cx-nav-muted" />
              ) : isActive ? (
                <Check className="h-4 w-4 text-cx-nav-teal-strong" aria-label="Current branch" />
              ) : null}
            </li>
          );
        })}
      </ul>

      <div className="px-3 pb-3" aria-live="polite">
        {loading && (
          <p className="flex items-center gap-2 px-1 py-2 text-[12.5px] text-cx-nav-muted">
            <Spinner /> Loading branches…
          </p>
        )}
        {!loading && failure && (
          <div className="flex items-center justify-between gap-2 rounded-md bg-cx-rose-bg px-3 py-2 text-[12.5px] text-cx-rose-ink">
            <span>{ERROR_COPY[failure]}</span>
            <button
              type="button"
              onClick={() => void load(query.trim(), 1)}
              className="inline-flex items-center gap-1 rounded-full px-2 py-1 font-semibold hover:bg-white/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-rose-ink"
            >
              <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" /> Retry
            </button>
          </div>
        )}
        {!loading && !failure && items.length === 0 && (
          <p className="px-1 py-2 text-[12.5px] text-cx-nav-muted">No branches match “{query.trim()}”.</p>
        )}
        {!loading && !failure && needsServer && remote?.nextPage && (
          <button
            type="button"
            onClick={() => void load(query.trim(), remote.nextPage ?? 1)}
            className="mt-1 h-9 w-full rounded-md border border-cx-nav-line text-[12.5px] font-semibold text-cx-nav-ink hover:bg-cx-nav-bg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
          >
            Show more branches
          </button>
        )}
      </div>
    </div>
  );
}
