import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { MoreHorizontal, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { FOCUS_RING } from './tones';

export interface ActionMenuItem {
  key: string;
  label: string;
  icon?: LucideIcon;
  onSelect: () => void;
  danger?: boolean;
}

/**
 * A row's secondary actions behind one "more" button. The caller lists
 * only what this person may do here; nothing is shown disabled for show.
 */
export function ActionMenu({ label, items }: { label: string; items: ActionMenuItem[] }) {
  if (items.length === 0) {
    return null;
  }

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger aria-label={label} className={cn('flex h-9 w-9 items-center justify-center rounded-[10px] border border-cx-line text-[#64748B] hover:border-cx-line-strong hover:bg-cx-nav-canvas hover:text-cx-text [@media(pointer:coarse)]:h-11 [@media(pointer:coarse)]:w-11', FOCUS_RING)}>
        <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
      </DropdownMenu.Trigger>
      <DropdownMenu.Portal>
        <DropdownMenu.Content
          align="end"
          sideOffset={6}
          className="z-50 min-w-[210px] rounded-xl border border-cx-line bg-cx-surface p-1 shadow-cx-raised motion-safe:animate-in motion-safe:fade-in-0 motion-safe:zoom-in-95 motion-safe:duration-150"
        >
          {items.map(({ key, label: itemLabel, icon: Icon, onSelect, danger }) => (
            <DropdownMenu.Item
              key={key}
              onSelect={onSelect}
              className={cn(
                'flex min-h-10 cursor-pointer select-none items-center gap-2.5 rounded-lg px-3 text-[14px] font-medium outline-none data-[highlighted]:bg-cx-nav-hover',
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
