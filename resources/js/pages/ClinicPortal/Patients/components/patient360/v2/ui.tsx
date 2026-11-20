import { Children, useEffect, useRef, useState, type ReactNode } from 'react';
import { Modal } from '@/clinic/ui/Modal';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { ArrowRight, Check, ChevronDown, ChevronLeft, ChevronRight, ListFilter, RotateCw, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ERROR_COPY, type ClinicHttpErrorKind } from '@/clinic/http';

/*
 * Patient 360's own visual kit (spec §1, §5): white cards with a diffuse
 * shadow on the portal's lavender canvas, a gradient icon tile per section,
 * pastel pills whose colour means something, and "View all" links into a
 * zone. Nothing here loads data or decides access.
 */

export type Tone = 'teal' | 'blue' | 'violet' | 'amber' | 'rose' | 'mint' | 'slate';

export const TILE: Record<Tone, string> = {
  teal: 'from-[#2CC6B4] to-[#0E9F87]',
  blue: 'from-[#4F9BF0] to-[#2B6FD8]',
  violet: 'from-[#9B7BF2] to-[#6B48DE]',
  amber: 'from-[#F7B451] to-[#E9821A]',
  rose: 'from-[#F58A94] to-[#E5484D]',
  mint: 'from-[#5FD9A6] to-[#12A877]',
  slate: 'from-[#94A3B8] to-[#64748B]',
};

export const SOFT: Record<Tone, string> = {
  teal: 'bg-cx-teal-bg text-cx-teal-ink',
  blue: 'bg-cx-blue-bg text-cx-blue-ink',
  violet: 'bg-cx-violet-bg text-cx-violet-ink',
  amber: 'bg-cx-amber-bg text-cx-amber-ink',
  rose: 'bg-cx-rose-bg text-cx-rose-ink',
  mint: 'bg-cx-green-bg text-cx-green-ink',
  slate: 'bg-cx-slate-bg text-cx-slate-ink',
};

export const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35 focus-visible:ring-offset-1';
export const BTN_PRIMARY = cn('inline-flex h-10 items-center justify-center gap-2 rounded-full bg-[#1769AA] px-4 text-[13px] font-semibold text-white shadow-[0_1px_2px_rgba(23,59,96,0.18)] transition-colors hover:bg-[#135A93] disabled:opacity-60', FOCUS);
export const BTN = cn('inline-flex h-10 items-center justify-center gap-2 rounded-full border border-[#DCE6F3] bg-white px-4 text-[13px] font-semibold text-cx-nav-ink transition-colors hover:border-[#B9C6D8] hover:bg-[#F8FAFC] disabled:opacity-60', FOCUS);
export const BTN_SM = cn('inline-flex h-8 items-center justify-center gap-1.5 rounded-full border border-[#DCE6F3] bg-white px-3 text-[12.5px] font-semibold text-cx-nav-ink transition-colors hover:border-[#B9C6D8] hover:bg-[#F8FAFC] disabled:opacity-60', FOCUS);

export function IconTile({ icon: Icon, tone, size = 'md' }: { icon: LucideIcon; tone: Tone; size?: 'sm' | 'md' | 'lg' }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'grid shrink-0 place-items-center bg-gradient-to-br text-white shadow-[0_6px_14px_-8px_rgba(30,50,110,0.55)]',
        TILE[tone],
        size === 'lg' ? 'h-11 w-11 rounded-[14px]' : size === 'md' ? 'h-9 w-9 rounded-[11px]' : 'h-7 w-7 rounded-[9px]',
      )}
    >
      <Icon className={size === 'sm' ? 'h-3.5 w-3.5' : 'h-[18px] w-[18px]'} strokeWidth={1.75} />
    </span>
  );
}

export function Pill({ tone = 'slate', children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
  return <span className={cn('inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', SOFT[tone], className)}>{children}</span>;
}

/** A section card: tile, title, an optional caption, a right-side action ("View all"). */
export function Card({
  title,
  caption,
  icon,
  tone = 'teal',
  action,
  children,
  className,
  bodyClassName,
  id,
  cap = CARD_CAP,
}: {
  title: string;
  caption?: ReactNode;
  icon: LucideIcon;
  tone?: Tone;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
  bodyClassName?: string;
  id?: string;
  /** How tall the body may be on the page before "View more" (px). */
  cap?: number;
}) {
  const clip = useRef<HTMLDivElement | null>(null);
  const content = useRef<HTMLDivElement | null>(null);
  const [tall, setTall] = useState(false);
  const [cut, setCut] = useState(false);
  const [expanded, setExpanded] = useState(false);
  /*
   * A card longer than its cap asks the band for no more than the cap, yet
   * fills whatever height the band gives it (so no blank space opens below);
   * whatever still does not fit is behind "View more", which opens the card
   * alone in a large window.
   */
  useEffect(() => {
    const inner = content.current;
    const outer = clip.current;
    if (!inner || !outer || typeof ResizeObserver === 'undefined') {
      return;
    }
    const measure = () => {
      setTall(inner.offsetHeight > cap + 8);
      setCut(inner.offsetHeight > outer.clientHeight + 4);
    };
    measure();
    const observer = new ResizeObserver(measure);
    observer.observe(inner);
    observer.observe(outer);

    return () => observer.disconnect();
  }, [expanded, cap]);
  const heading = (
    <div className="flex min-w-0 items-start gap-3">
      <IconTile icon={icon} tone={tone} />
      <div className="min-w-0">
        <h2 className="truncate pt-0.5 text-[15px] font-semibold leading-5 text-cx-nav-ink">{title}</h2>
        {caption && <p className="mt-0.5 text-[12px] leading-4 text-cx-text-3">{caption}</p>}
      </div>
    </div>
  );

  return (
    <section id={id} aria-label={title} className={cn('flex min-w-0 flex-col rounded-[22px] border border-white/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04),0_10px_28px_-18px_rgba(30,50,110,0.28)]', className)}>
      <header className="flex min-h-[60px] items-start justify-between gap-3 px-5 pt-4">
        {heading}
        {action && !expanded && <div className="shrink-0">{action}</div>}
      </header>
      <div className={cn('relative flex flex-1 flex-col px-5 pb-5 pt-3.5', bodyClassName)}>
        {expanded ? (
          <p className="rounded-2xl bg-[#F6F8FC] px-4 py-5 text-center text-[13px] text-cx-text-2">Open in the larger view.</p>
        ) : (
          <div ref={clip} className={tall ? 'min-h-0 flex-1 basis-0 overflow-hidden' : undefined} style={tall ? { minHeight: cap } : undefined}>
            <div ref={content}>{children}</div>
          </div>
        )}
        {tall && cut && !expanded && (
          <div className="relative -mt-12 flex justify-center bg-gradient-to-t from-white via-white/95 to-white/0 pb-0.5 pt-10">
            <button type="button" onClick={() => setExpanded(true)} className={cn('inline-flex h-9 items-center gap-1.5 rounded-full border border-[#DCE6F3] bg-white px-4 text-[12.5px] font-semibold text-[#1769AA] shadow-[0_4px_12px_-6px_rgba(30,50,110,0.3)] hover:border-[#B9C6D8]', FOCUS)}>
              View more
              <ChevronDown className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </div>
        )}
      </div>
      {expanded && (
        <Modal open onOpenChange={(open) => !open && setExpanded(false)} title={title} description={typeof caption === 'string' ? caption : undefined} size="wide">
          {action && <div className="mb-4 flex justify-end">{action}</div>}
          {children}
        </Modal>
      )}
    </section>
  );
}

/** How tall a card's body may grow on the page before "View more" (px). */
const CARD_CAP = 460;

export function ViewAll({ onClick, label = 'View all' }: { onClick: () => void; label?: string }) {
  return (
    <button type="button" onClick={onClick} className={cn('inline-flex min-h-9 items-center gap-1 rounded-full px-2 text-[12.5px] font-semibold text-cx-primary hover:text-cx-primary-strong', FOCUS)}>
      {label}
      <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
    </button>
  );
}

/** What a card says while its data is still on the way (no skeleton: a quiet line). */
export function Pending({ error, onRetry, label = 'Getting the records…' }: { error: ClinicHttpErrorKind | null; onRetry?: () => void; label?: string }) {
  if (error === 'forbidden') {
    return <p className="text-[13px] text-cx-text-2">You don’t have access to this.</p>;
  }
  if (error) {
    return (
      <p className="flex flex-wrap items-center gap-2 text-[13px] text-cx-rose-ink">
        {ERROR_COPY[error]}
        {onRetry && (
          <button type="button" onClick={onRetry} className={BTN_SM}>
            <RotateCw className="h-3.5 w-3.5" aria-hidden="true" /> Try again
          </button>
        )}
      </p>
    );
  }

  return <p className="text-[13px] text-cx-text-3">{label}</p>;
}

export function Empty({ children }: { children: ReactNode }) {
  return <p className="rounded-2xl bg-cx-slate-bg/60 px-4 py-5 text-center text-[13px] text-cx-text-2">{children}</p>;
}

export function Pager({ page, last, onPage, busy }: { page: number; last: number; onPage: (page: number) => void; busy?: boolean }) {
  if (last <= 1) {
    return null;
  }

  return (
    <nav aria-label="Pages" className="mt-4 flex items-center justify-between gap-3">
      <button type="button" disabled={page <= 1 || busy} onClick={() => onPage(page - 1)} className={BTN_SM}>
        <ChevronLeft className="h-3.5 w-3.5" aria-hidden="true" /> Newer
      </button>
      <span className="text-[12.5px] text-cx-text-2">
        Page {page} of {last}
      </span>
      <button type="button" disabled={page >= last || busy} onClick={() => onPage(page + 1)} className={BTN_SM}>
        Older <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
      </button>
    </nav>
  );
}

/** A soft figure tile for the top of a zone. */
export function Figure({ icon, tone, label, value, detail }: { icon: LucideIcon; tone: Tone; label: string; value: ReactNode; detail?: ReactNode }) {
  return (
    <div className="flex min-w-0 items-center gap-3 rounded-[18px] bg-white p-3.5 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_8px_22px_-16px_rgba(30,50,110,0.25)]">
      <IconTile icon={icon} tone={tone} />
      <div className="min-w-0">
        <p className="text-[12px] font-medium text-cx-text-2">{label}</p>
        <p className="text-[19px] font-semibold leading-6 tracking-tight text-cx-nav-ink tabular-nums">{value}</p>
        {detail && <p className="truncate text-[11.5px] text-cx-text-3">{detail}</p>}
      </div>
    </div>
  );
}

/**
 * One record in a card's list, laid out the same everywhere: a small tile,
 * the title with its facts beneath, and pills or actions on the right that
 * drop under the text on a phone.
 */
export function Row({ icon, tone = 'slate', title, meta, aside, children, onOpen, className }: { icon?: LucideIcon; tone?: Tone; title: ReactNode; meta?: ReactNode; aside?: ReactNode; children?: ReactNode; onOpen?: () => void; className?: string }) {
  const body = (
    <>
      {icon && <IconTile icon={icon} tone={tone} size="sm" />}
      <div className="min-w-0 flex-1">
        <div className="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
          <div className="min-w-0">
            <p className="text-[13.5px] font-semibold leading-5 text-cx-nav-ink [overflow-wrap:anywhere]">{title}</p>
            {meta && <p className="mt-0.5 text-[12px] leading-[18px] text-cx-text-2 [overflow-wrap:anywhere]">{meta}</p>}
          </div>
          {aside && <div className="flex shrink-0 flex-wrap items-center gap-1.5">{aside}</div>}
        </div>
        {children && <div className="mt-2">{children}</div>}
      </div>
    </>
  );
  const look = cn('flex w-full items-start gap-3 rounded-2xl border border-[#EDF1F7] bg-[#FBFCFE] p-3 text-left', className);

  return onOpen ? (
    <button type="button" onClick={onOpen} className={cn(look, 'transition-colors hover:border-[#CFE3F7] hover:bg-white', FOCUS)}>
      {body}
    </button>
  ) : (
    <div className={look}>{body}</div>
  );
}

/** A progress bar (done of total), drawn only when the server sends a total. */
export function Meter({ done, total, tone = 'violet', label }: { done: number; total: number; tone?: 'violet' | 'mint' | 'blue'; label?: ReactNode }) {
  const percent = Math.min(100, Math.round((done / Math.max(total, 1)) * 100));
  const fill = { violet: 'from-[#9B7BF2] to-[#6B48DE]', mint: 'from-[#5FD9A6] to-[#12A877]', blue: 'from-[#4F9BF0] to-[#2B6FD8]' }[tone];

  return (
    <div>
      <span className="block h-1.5 overflow-hidden rounded-full bg-[#EEF2F7]" role="progressbar" aria-valuenow={done} aria-valuemin={0} aria-valuemax={total}>
        <span className={cn('block h-full rounded-full bg-gradient-to-r', fill)} style={{ width: `${percent}%` }} />
      </span>
      <p className="mt-1 flex justify-between text-[11.5px] text-cx-text-3">
        <span>{label ?? `${done} of ${total}`}</span>
        <span className="tabular-nums">{percent}%</span>
      </p>
    </div>
  );
}

/** Filter chips: one pressed at a time. */
export function Chips<T extends string>({ value, options, onChange, label }: { value: T; options: { value: T; label: string; count?: number }[]; onChange: (value: T) => void; label: string }) {
  return (
    <div role="group" aria-label={label} className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1 [scrollbar-width:none]">
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          aria-pressed={value === option.value}
          onClick={() => onChange(option.value)}
          className={cn('inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full px-3 text-[12.5px] font-semibold transition-colors', FOCUS, value === option.value ? 'bg-[#1769AA] text-white' : 'bg-[#F1F5FA] text-cx-text-2 hover:text-cx-nav-ink')}
        >
          {option.label}
          {option.count !== undefined && <span className={cn('tabular-nums', value === option.value ? 'text-white/80' : 'text-cx-text-3')}>{option.count}</span>}
        </button>
      ))}
    </div>
  );
}

/** Dot colours for a filter's options, by tone. */
export const DOT: Record<Tone, string> = { teal: 'bg-[#0E9F87]', blue: 'bg-[#2B6FD8]', violet: 'bg-[#6B48DE]', amber: 'bg-[#E9821A]', rose: 'bg-[#E5484D]', mint: 'bg-[#12A877]', slate: 'bg-[#94A3B8]' };

/**
 * A compact filter for lists with many categories: a pill showing the choice,
 * opening a menu of options each with its colour dot and a tick on the one
 * chosen. Keyboard and screen-reader friendly (Radix menu, radio items).
 */
export function FilterSelect({ value, options, onChange, label }: { value: string; options: { value: string; label: string; tone?: Tone }[]; onChange: (value: string) => void; label: string }) {
  const current = options.find((option) => option.value === value) ?? options[0];
  const filtered = value !== options[0]?.value;

  return (
    <Menu.Root modal={false}>
      <Menu.Trigger
        aria-label={`${label}: ${current?.label ?? ''}`}
        className={cn(
          'inline-flex h-9 max-w-[200px] items-center gap-1.5 rounded-full border px-3 text-[12.5px] font-semibold transition-colors',
          filtered ? 'border-transparent bg-[#EAF2FC] text-[#1769AA]' : 'border-[#DCE6F3] bg-white text-cx-nav-ink hover:border-[#B9C6D8]',
          FOCUS,
        )}
      >
        {filtered && current?.tone ? <span className={cn('h-2 w-2 shrink-0 rounded-full', DOT[current.tone])} aria-hidden="true" /> : <ListFilter className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />}
        <span className="truncate">{current?.label}</span>
        <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Content align="end" sideOffset={6} className="z-50 max-h-[320px] min-w-[210px] overflow-y-auto rounded-2xl border border-[#E6EBF1] bg-white p-1.5 shadow-[0_18px_40px_-18px_rgba(30,50,110,0.35)]">
          <Menu.RadioGroup value={value} onValueChange={onChange}>
            {options.map((option, index) => (
              <Menu.RadioItem
                key={option.value}
                value={option.value}
                className="flex min-h-9 cursor-pointer items-center gap-2.5 rounded-[10px] px-2.5 text-[13px] font-medium text-cx-nav-ink outline-none data-[highlighted]:bg-[#F1F5FA] data-[state=checked]:font-semibold data-[state=checked]:text-[#1769AA]"
              >
                <span className={cn('h-2 w-2 shrink-0 rounded-full', index === 0 ? 'bg-[#1769AA]' : DOT[option.tone ?? 'slate'])} aria-hidden="true" />
                <span className="flex-1 truncate">{option.label}</span>
                <Menu.ItemIndicator>
                  <Check className="h-3.5 w-3.5" aria-hidden="true" />
                </Menu.ItemIndicator>
              </Menu.RadioItem>
            ))}
          </Menu.RadioGroup>
        </Menu.Content>
      </Menu.Portal>
    </Menu.Root>
  );
}

/** Label/value facts in an even grid. A fact with no value is left out. */
export function Facts({ items, columns = 2 }: { items: [string, ReactNode][]; columns?: 2 | 3 | 4 }) {
  const shown = items.filter(([, value]) => value !== null && value !== undefined && value !== '');

  return (
    <dl className={cn('grid gap-2', columns === 2 ? 'grid-cols-2' : columns === 3 ? 'grid-cols-2 sm:grid-cols-3' : 'grid-cols-2 sm:grid-cols-4')}>
      {shown.map(([label, value]) => (
        <div key={label} className="min-w-0 rounded-xl bg-[#F6F8FC] px-3 py-2">
          <dt className="text-[11px] font-medium text-cx-text-3">{label}</dt>
          <dd className="mt-0.5 truncate text-[13px] font-semibold text-cx-nav-ink">{value}</dd>
        </div>
      ))}
    </dl>
  );
}

/**
 * A zone's two-column band (8 + 4 on desktop). Both columns stretch to the
 * band's height and each column's last card grows to fill it, so the two
 * columns always end on one line; on tablet and phone the side stacks below.
 * A column with nothing in it gives the other the full width.
 */
/** One column of a zone band: cards stacked, the last one growing so the band's columns end together. */
export const COLUMN = 'flex min-w-0 flex-col gap-5 [&>*:last-child]:flex-1';

export function Split({ main, side, mainSpan = 8 }: { main: ReactNode; side?: ReactNode; mainSpan?: 6 | 7 | 8 }) {
  const mainItems = Children.toArray(main);
  const sideItems = Children.toArray(side);
  const column = COLUMN;
  if (mainItems.length === 0 && sideItems.length === 0) {
    return null;
  }
  if (mainItems.length === 0 || sideItems.length === 0) {
    return <div className={column}>{mainItems.length ? mainItems : sideItems}</div>;
  }

  return (
    <div className="grid items-stretch gap-5 xl:grid-cols-12">
      <div className={cn(column, mainSpan === 8 ? 'xl:col-span-8' : mainSpan === 7 ? 'xl:col-span-7' : 'xl:col-span-6')}>{mainItems}</div>
      <div className={cn(column, mainSpan === 8 ? 'xl:col-span-4' : mainSpan === 7 ? 'xl:col-span-5' : 'xl:col-span-6')}>{sideItems}</div>
    </div>
  );
}

/** An even grid of cards that share each row's height. */
export function CardGrid({ children, columns = 2 }: { children: ReactNode; columns?: 2 | 3 }) {
  const items = Children.toArray(children);
  if (items.length === 0) {
    return null;
  }

  return <div className={cn('grid items-stretch gap-5', items.length > 1 && (columns === 3 ? 'md:grid-cols-2 xl:grid-cols-3' : 'lg:grid-cols-2'))}>{items}</div>;
}

/** A small uppercase heading inside a card. */
export function Label({ children }: { children: ReactNode }) {
  return <p className="mb-2 text-[11px] font-semibold uppercase tracking-[0.06em] text-cx-text-3">{children}</p>;
}

/** Date and time, for events that carry one. */
export function when(iso: string | null | undefined): string | null {
  if (!iso) {
    return null;
  }
  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

export function money(value: number | null | undefined): string {
  return `₹${Number(value ?? 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;
}

export function day(iso: string | null | undefined): string | null {
  if (!iso) {
    return null;
  }
  const date = new Date(iso.length === 10 ? `${iso}T00:00:00` : iso);

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function humanize(value: string | null | undefined): string {
  return (value ?? '').replace(/_/g, ' ').replace(/^\w/, (letter) => letter.toUpperCase());
}

export type Paged<T> = { data: T[]; meta: { page: number; last_page: number; total: number } };
