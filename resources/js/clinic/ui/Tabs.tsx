import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import { cn } from '@/lib/utils';

const LIST = 'flex max-w-full gap-1 overflow-x-auto rounded-full border border-cx-line bg-cx-surface p-1 [scrollbar-width:none]';
const TAB = cn(
  'inline-flex min-h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 text-[13px] font-semibold text-cx-text-2',
  'hover:bg-cx-nav-hover hover:text-cx-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan',
  '[@media(pointer:coarse)]:min-h-11',
);
const ACTIVE = 'bg-cx-nav-active text-cx-nav-ink';

function Count({ value }: { value?: number | null }) {
  if (value === undefined || value === null) {
    return null;
  }

  return <span className="rounded-full bg-cx-slate-bg px-1.5 text-[11.5px] font-semibold tabular-nums text-cx-slate-ink">{value}</span>;
}

/**
 * In-page tabs over content already on the page (Radix: arrow keys move
 * between tabs, the panel is labelled by its tab).
 */
export function Tabs({
  value,
  onValueChange,
  tabs,
  label,
  className,
}: {
  value: string;
  onValueChange: (value: string) => void;
  tabs: { value: string; label: string; count?: number | null; content: ReactNode }[];
  label: string;
  className?: string;
}) {
  return (
    <TabsPrimitive.Root value={value} onValueChange={onValueChange} className={className}>
      <TabsPrimitive.List aria-label={label} className={LIST}>
        {tabs.map((tab) => (
          <TabsPrimitive.Trigger key={tab.value} value={tab.value} className={cn(TAB, 'data-[state=active]:bg-cx-nav-active data-[state=active]:text-cx-nav-ink')}>
            {tab.label}
            <Count value={tab.count} />
          </TabsPrimitive.Trigger>
        ))}
      </TabsPrimitive.List>
      {tabs.map((tab) => (
        <TabsPrimitive.Content key={tab.value} value={tab.value} className="mt-4 focus-visible:outline-none">
          {tab.content}
        </TabsPrimitive.Content>
      ))}
    </TabsPrimitive.Root>
  );
}

/**
 * Tabs that are views of a server list (`?view=mine`): each is a link the
 * server answers, so the URL, the back button and a shared link all agree.
 * The `href`s come from the server or `route()`, never a hand-built path.
 */
export function LinkTabs({ tabs, label, className }: { tabs: { key: string; label: string; href: string; active: boolean; count?: number | null }[]; label: string; className?: string }) {
  return (
    <nav aria-label={label} className={cn(LIST, 'w-fit', className)}>
      {tabs.map((tab) => (
        <Link key={tab.key} href={tab.href} preserveScroll aria-current={tab.active ? 'page' : undefined} className={cn(TAB, tab.active && ACTIVE)}>
          {tab.label}
          <Count value={tab.count} />
        </Link>
      ))}
    </nav>
  );
}

/**
 * The same pill tabs for choices made on the page rather than by URL (a list's filter, a view switch):
 * buttons that report the choice, with the chosen one marked for screen readers.
 */
export function PillTabs<T extends string>({ options, value, onChange, label, className }: { options: { key: T; label: string; count?: number | null }[]; value: T; onChange: (key: T) => void; label: string; className?: string }) {
  return (
    <div role="group" aria-label={label} className={cn(LIST, 'w-fit', className)}>
      {options.map((option) => (
        <button key={option.key} type="button" aria-pressed={value === option.key} onClick={() => onChange(option.key)} className={cn(TAB, value === option.key && ACTIVE)}>
          {option.label}
          <Count value={option.count} />
        </button>
      ))}
    </div>
  );
}
