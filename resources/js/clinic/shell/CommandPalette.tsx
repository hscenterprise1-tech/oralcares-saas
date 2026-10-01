import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import { Command } from 'cmdk';
import { LogOut, Search } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { NavDestination, NavLink, PortalLinks, QuickAction } from '../types';
import { iconFor, toneFor } from '../ui/visuals';

const ITEM =
  'flex min-h-11 cursor-pointer select-none items-center gap-3 rounded-xl px-3 text-[13.5px] text-clinic-ink outline-none data-[selected=true]:bg-clinic-inset';
const GROUP_HEADING =
  '[&_[cmdk-group-heading]]:px-3 [&_[cmdk-group-heading]]:pb-1.5 [&_[cmdk-group-heading]]:pt-3 [&_[cmdk-group-heading]]:text-[10px] [&_[cmdk-group-heading]]:font-bold [&_[cmdk-group-heading]]:uppercase [&_[cmdk-group-heading]]:tracking-[0.08em] [&_[cmdk-group-heading]]:text-clinic-secondary';

/** Opens the palette on Ctrl+K / ⌘K from anywhere in the portal. */
export function useCommandShortcut(onOpen: () => void): void {
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        onOpen();
      }
    };
    window.addEventListener('keydown', onKeyDown);

    return () => window.removeEventListener('keydown', onKeyDown);
  }, [onOpen]);
}

/**
 * Jump anywhere this user may go, or start what they may start. It lists the
 * navigation and quick actions the server already resolved for this user
 * (the same lists the top bar and the dashboard use), filtered in the
 * browser, so it makes no request, has no list of its own, and cannot search
 * another tenant's data.
 */
export function CommandPalette({
  open,
  onOpenChange,
  destinations,
  actions,
  links,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  destinations: NavDestination[];
  actions: QuickAction[];
  links: PortalLinks;
}) {
  const go = (href: string) => {
    onOpenChange(false);
    router.visit(href);
  };

  // Each domain, then its sub-areas, as places to go.
  const places: NavLink[] = destinations.flatMap((domain) => (domain.children.length > 0 ? domain.children : [domain]));
  const groups: { heading: string; items: (NavLink & { tone?: string })[] }[] = [
    { heading: 'Go to', items: places },
    { heading: 'Actions', items: actions },
  ].filter((group) => group.items.length > 0);

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-50 bg-clinic-ink/30 motion-safe:animate-in motion-safe:fade-in-0" />
        <Dialog.Content
          aria-describedby={undefined}
          className="fixed left-1/2 top-[12vh] z-50 w-[calc(100vw-32px)] max-w-[560px] -translate-x-1/2 overflow-hidden rounded-2xl border border-clinic-border bg-clinic-surface shadow-[0_24px_64px_-16px_rgba(16,24,40,0.35)] focus:outline-none"
        >
          <Dialog.Title className="sr-only">Go to a page</Dialog.Title>
          <Command label="Go to a page" loop>
            <div className="flex items-center gap-2.5 border-b border-clinic-border px-4">
              <Search className="h-4 w-4 shrink-0 text-clinic-secondary" aria-hidden="true" />
              <Command.Input
                autoFocus
                placeholder="Go to…"
                className="h-14 w-full bg-transparent text-[15px] text-clinic-ink placeholder:text-clinic-secondary focus:outline-none"
              />
              <kbd className="hidden rounded-md border border-clinic-border px-1.5 py-0.5 text-[11px] font-semibold text-clinic-secondary sm:inline">
                Esc
              </kbd>
            </div>
            <Command.List className="max-h-[min(420px,60vh)] overflow-y-auto overscroll-contain p-2">
              <Command.Empty className="px-3 py-8 text-center text-[13px] text-clinic-secondary">
                Nothing matches that.
              </Command.Empty>
              {groups.map((group) => (
                <Command.Group key={group.heading} heading={group.heading} className={GROUP_HEADING}>
                  {group.items.map((item) => (
                    <Command.Item
                      key={item.key}
                      value={item.label}
                      keywords={item.keywords}
                      onSelect={() => go(item.href)}
                      className={ITEM}
                    >
                      <PaletteIcon icon={item.icon} tone={item.tone} />
                      {item.label}
                    </Command.Item>
                  ))}
                </Command.Group>
              ))}
              <Command.Group heading="Account" className={GROUP_HEADING}>
                <Command.Item
                  value="Sign out"
                  keywords={['log out', 'logout']}
                  onSelect={() => {
                    onOpenChange(false);
                    router.post(links.signOut);
                  }}
                  className={ITEM}
                >
                  <LogOut className="h-4 w-4 text-clinic-secondary" aria-hidden="true" />
                  Sign out
                </Command.Item>
              </Command.Group>
            </Command.List>
          </Command>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

function PaletteIcon({ icon, tone }: { icon: string; tone?: string }) {
  const Icon = iconFor(icon);

  return (
    <span
      className={cn(
        'flex h-7 w-7 items-center justify-center rounded-lg',
        tone ? toneFor(tone).chip : 'bg-clinic-inset text-clinic-secondary',
      )}
    >
      <Icon className="h-4 w-4" aria-hidden="true" />
    </span>
  );
}
