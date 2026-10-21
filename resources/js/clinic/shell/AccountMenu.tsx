import { useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { ChevronDown, LogOut, ShieldCheck, ShieldOff } from 'lucide-react';
import { cn } from '@/lib/utils';
import { clinicDisplayName, roleLabel } from '../context';
import type { AuthUser, ClinicContextProps, PortalLinks } from '../types';
import { personInitials } from '../ui/PersonAvatar';
import { iconFor } from '../ui/visuals';
import { OwnerModeDialog } from './OwnerModeDialog';

const ITEM =
  'flex min-h-10 cursor-pointer select-none items-center gap-2.5 rounded-md px-3 text-[13px] text-cx-nav-ink outline-none data-[highlighted]:bg-cx-nav-bg [&_svg]:h-[18px] [&_svg]:w-[18px] [&_svg]:shrink-0 [&_svg]:stroke-[1.75]';

/**
 * Who is signed in, as what, at which clinic; the clinic administration
 * pages this user may open (once each is built); Owner Mode for the owner; and
 * the way out. Signing out is the server's: it ends the session and issues a
 * new CSRF token.
 */
export function AccountMenu({ user, clinic, links }: { user: AuthUser; clinic: ClinicContextProps; links: PortalLinks }) {
  const [verifying, setVerifying] = useState(false);
  // Set when "Enter Owner Mode" is chosen; the dialog opens once the menu has closed, so it keeps focus.
  const handingOff = useRef(false);
  const ownerMode = clinic.owner_mode;
  const inOwnerMode = ownerMode.available && ownerMode.active;
  // Only built pages: an administration page still to come is not offered here at all.
  const administration = clinic.navigation.filter((item) => item.section === 'admin' && item.available && item.href !== null);

  return (
    <>
      <Menu.Root>
        <Menu.Trigger
          aria-label={inOwnerMode ? `Account: ${user.name}, Owner Mode on` : `Account: ${user.name}`}
          className="group flex items-center gap-3 rounded-md py-1 pl-1 pr-1.5 text-left hover:bg-cx-nav-bg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
        >
          <span
            aria-hidden="true"
            className={cn(
              'flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-cx-nav-ink text-[14px] font-semibold text-white',
              inOwnerMode && 'ring-2 ring-cx-nav-teal ring-offset-2 ring-offset-white',
            )}
          >
            {personInitials(user.name)}
          </span>
          <span className="hidden min-w-0 xl:block">
            <span className="block max-w-[150px] truncate text-[14px] font-semibold text-cx-nav-ink">{user.name}</span>
            <span className="block max-w-[150px] truncate text-[12.5px] text-cx-nav-muted">{roleLabel(clinic)}</span>
          </span>
          <ChevronDown className="hidden h-4 w-4 text-cx-nav-muted xl:block" strokeWidth={1.75} aria-hidden="true" />
        </Menu.Trigger>
        <Menu.Portal>
          <Menu.Content
            align="end"
            sideOffset={8}
            collisionPadding={16}
            onCloseAutoFocus={(event) => {
              if (handingOff.current) {
                handingOff.current = false;
                event.preventDefault();
                setVerifying(true);
              }
            }}
            className="z-50 w-[min(290px,calc(100vw-32px))] rounded-2xl border border-cx-nav-line bg-white p-1.5 shadow-cx-raised"
          >
            <div className="px-3 pb-3 pt-2.5">
              <p className="truncate text-[14px] font-semibold text-cx-text">{user.name}</p>
              <p className="truncate text-[12.5px] text-cx-text-3">{user.email}</p>
              <p className="mt-2 inline-flex rounded-full bg-cx-cyan-soft px-2.5 py-0.5 text-[11.5px] font-semibold text-cx-cyan-strong">{roleLabel(clinic)}</p>
              <p className="mt-2 truncate text-[12.5px] text-cx-text-2">{clinicDisplayName(clinic)}</p>
              {inOwnerMode && (
                <p className="mt-2 rounded-xl bg-cx-navy px-3 py-2 text-[12px] text-white">
                  <span className="flex items-center gap-1.5 font-semibold">
                    <ShieldCheck className="h-4 w-4" aria-hidden="true" /> Owner Mode is on
                  </span>
                  Turns off after {ownerMode.idle_timeout_minutes} minutes without activity.
                </p>
              )}
            </div>
            <Menu.Separator className="my-1 h-px bg-cx-nav-line" />
            {administration.length > 0 && (
              <>
                <Menu.Label className="px-3 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-cx-text-3">Clinic administration</Menu.Label>
                {administration.map((item) => {
                  const Icon = iconFor(item.icon);

                  return (
                    <Menu.Item key={item.key} asChild className={ITEM}>
                      <Link href={item.href ?? ''}>
                        <Icon className="h-4 w-4" aria-hidden="true" /> {item.label}
                      </Link>
                    </Menu.Item>
                  );
                })}
                <Menu.Separator className="my-1 h-px bg-cx-nav-line" />
              </>
            )}
            {ownerMode.available &&
              (inOwnerMode ? (
                <Menu.Item onSelect={() => router.delete(links.ownerMode, { preserveScroll: true })} className={cn(ITEM, 'font-semibold')}>
                  <ShieldOff className="h-4 w-4" aria-hidden="true" /> Turn off Owner Mode
                </Menu.Item>
              ) : (
                <Menu.Item onSelect={() => (handingOff.current = true)} className={cn(ITEM, 'font-semibold')}>
                  <ShieldCheck className="h-4 w-4" aria-hidden="true" /> Enter Owner Mode
                </Menu.Item>
              ))}
            <Menu.Item onSelect={() => router.post(links.signOut)} className={cn(ITEM, 'text-cx-rose-ink')}>
              <LogOut className="h-4 w-4" aria-hidden="true" /> Sign out
            </Menu.Item>
          </Menu.Content>
        </Menu.Portal>
      </Menu.Root>
      {ownerMode.available && (
        <OwnerModeDialog open={verifying} onOpenChange={setVerifying} action={links.ownerMode} idleMinutes={ownerMode.idle_timeout_minutes} />
      )}
    </>
  );
}
