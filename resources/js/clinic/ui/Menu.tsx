import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface MenuItem {
  key: string;
  label: string;
  icon?: LucideIcon;
  /** A page to visit (from the server's links). */
  href?: string | null;
  onSelect?: () => void;
  danger?: boolean;
  disabled?: boolean;
}

/**
 * A row's or a page's secondary actions behind one button. Items the server
 * did not allow are left out by the caller, not shown disabled: a menu lists
 * what this person can do here.
 */
export function Menu({ trigger, items, label, align = 'end' }: { trigger: ReactNode; items: MenuItem[]; label: string; align?: 'start' | 'end' }) {
  if (items.length === 0) {
    return null;
  }

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger asChild aria-label={label}>
        {trigger}
      </DropdownMenu.Trigger>
      <DropdownMenu.Portal>
        <DropdownMenu.Content
          align={align}
          sideOffset={6}
          className="z-50 min-w-[200px] rounded-xl border border-cx-line bg-cx-surface p-1 shadow-cx-raised motion-safe:animate-in motion-safe:fade-in-0"
        >
          {items.map(({ key, label: itemLabel, icon: Icon, href, onSelect, danger, disabled }) => (
            <DropdownMenu.Item
              key={key}
              disabled={disabled}
              onSelect={() => (href ? router.visit(href) : onSelect?.())}
              className={cn(
                'flex min-h-10 cursor-pointer select-none items-center gap-2.5 rounded-lg px-3 text-[13px] font-medium outline-none',
                'data-[highlighted]:bg-cx-nav-hover data-[disabled]:pointer-events-none data-[disabled]:opacity-45',
                danger ? 'text-cx-rose-ink' : 'text-cx-text',
              )}
            >
              {Icon && <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />}
              {itemLabel}
            </DropdownMenu.Item>
          ))}
        </DropdownMenu.Content>
      </DropdownMenu.Portal>
    </DropdownMenu.Root>
  );
}
