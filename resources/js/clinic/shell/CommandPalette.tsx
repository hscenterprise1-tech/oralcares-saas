import { useEffect } from 'react';
import { router } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import { Command } from 'cmdk';
import { ArrowRight, LogOut, Search } from 'lucide-react';
import type { NavDestination, PortalLinks } from '../types';

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
 * Jump anywhere this user may go. Navigation only: it lists the destinations
 * the server already resolved for this user and clinic, filtered in the
 * browser, so it makes no request and cannot search another tenant's data.
 */
export function CommandPalette({
  open,
  onOpenChange,
  destinations,
  links,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  destinations: NavDestination[];
  links: PortalLinks;
}) {
  const go = (href: string) => {
    onOpenChange(false);
    router.visit(href);
  };

  const groups: { heading: string; items: NavDestination[] }[] = [
    { heading: 'Go to', items: destinations.filter((item) => item.section === 'primary') },
    { heading: 'Administration', items: destinations.filter((item) => item.section === 'admin') },
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
                No page matches that.
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
                      <ArrowRight className="h-4 w-4 text-clinic-secondary" aria-hidden="true" />
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
