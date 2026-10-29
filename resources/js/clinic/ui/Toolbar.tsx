import { useState, type ReactNode } from 'react';
import { Search, SlidersHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from './Button';
import { Modal } from './Modal';

/**
 * A list's search and filters. From `md` up the filters sit in the row; on a
 * phone they move into a centred dialog behind one "Filters" button that says
 * how many are on, so the list stays in view.
 *
 * It only collects input: the caller sends it to the server (debounced
 * search, Inertia partial reload) and passes back what the server applied.
 */
export function Toolbar({
  search,
  onSearchChange,
  searchLabel,
  searchPlaceholder,
  filters,
  activeFilters = 0,
  onClearFilters,
  actions,
  className,
}: {
  search: string;
  onSearchChange: (value: string) => void;
  searchLabel: string;
  searchPlaceholder?: string;
  filters?: ReactNode;
  activeFilters?: number;
  onClearFilters?: () => void;
  actions?: ReactNode;
  className?: string;
}) {
  const [open, setOpen] = useState(false);

  return (
    <div className={cn('flex flex-wrap items-center gap-2', className)}>
      <label className="relative min-w-0 flex-1 basis-56">
        <span className="sr-only">{searchLabel}</span>
        <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-cx-text-3" aria-hidden="true" />
        <input
          type="search"
          value={search}
          onChange={(event) => onSearchChange(event.target.value)}
          placeholder={searchPlaceholder}
          className="h-11 w-full rounded-full border border-cx-line bg-cx-surface pl-10 pr-4 text-[13.5px] text-cx-text placeholder:text-cx-text-3 focus:border-cx-cyan focus:outline-none focus:ring-2 focus:ring-cx-cyan/30"
        />
      </label>

      {filters && <div className="hidden flex-wrap items-center gap-2 md:flex">{filters}</div>}

      {filters && (
        <Button className="md:hidden" icon={SlidersHorizontal} onClick={() => setOpen(true)} aria-haspopup="dialog">
          {activeFilters > 0 ? `Filters (${activeFilters})` : 'Filters'}
        </Button>
      )}

      {activeFilters > 0 && onClearFilters && (
        <Button tone="ghost" size="sm" className="hidden md:inline-flex" onClick={onClearFilters}>
          Clear filters
        </Button>
      )}

      {actions && <div className="ml-auto flex items-center gap-2">{actions}</div>}

      {filters && (
        <Modal
          open={open}
          onOpenChange={setOpen}
          title="Filters"
          size="sm"
          footer={
            <>
              {onClearFilters && activeFilters > 0 && (
                <Button tone="ghost" onClick={onClearFilters}>
                  Clear filters
                </Button>
              )}
              <Button tone="primary" onClick={() => setOpen(false)}>
                Done
              </Button>
            </>
          }
        >
          <div className="grid gap-3">{filters}</div>
        </Modal>
      )}
    </div>
  );
}
