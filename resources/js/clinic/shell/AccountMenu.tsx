import { router } from '@inertiajs/react';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { Building2, KeyRound, LogOut, MapPin, UserRound } from 'lucide-react';
import { cn } from '@/lib/utils';
import { clinicDisplayName, roleLabel } from '../context';
import type { AuthUser, ClinicContextProps, PortalLinks } from '../types';
import { branchLabel } from './BranchSwitcher';
import { initials } from './ClinicMark';

const ITEM =
  'flex min-h-10 cursor-pointer select-none items-center gap-2.5 rounded-xl px-3 text-[13px] text-clinic-ink outline-none data-[highlighted]:bg-clinic-inset data-[disabled]:cursor-default data-[disabled]:text-clinic-secondary data-[disabled]:data-[highlighted]:bg-transparent';

/**
 * Who is signed in, as what, where; and the way out. Nothing here reaches
 * the Company side: a clinic user has no business there.
 */
export function AccountMenu({
  user,
  clinic,
  links,
}: {
  user: AuthUser;
  clinic: ClinicContextProps;
  links: PortalLinks;
}) {
  const signOut = () => router.post(links.signOut);

  return (
    <Menu.Root>
      <Menu.Trigger
        aria-label={`Account: ${user.name}`}
        className="flex h-10 items-center gap-2 rounded-full p-1 [@media(pointer:coarse)]:h-11 hover:bg-clinic-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong data-[state=open]:bg-clinic-inset"
      >
        <span
          aria-hidden="true"
          className="flex h-8 w-8 items-center justify-center rounded-full bg-clinic-ink text-[11.5px] font-bold text-white"
        >
          {initials(user.name)}
        </span>
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Content
          align="end"
          sideOffset={8}
          collisionPadding={16}
          className="z-50 w-[min(300px,calc(100vw-32px))] rounded-2xl border border-clinic-border bg-clinic-surface p-1.5 shadow-[0_16px_40px_-12px_rgba(16,24,40,0.25)]"
        >
          <div className="px-3 pb-3 pt-2.5">
            <p className="truncate text-[14px] font-bold text-clinic-ink">{user.name}</p>
            <p className="truncate text-[12.5px] text-clinic-secondary">{user.email}</p>
            <p className="mt-2 inline-flex rounded-full bg-clinic-brand-soft px-2.5 py-0.5 text-[11.5px] font-semibold text-clinic-brand-strong">
              {roleLabel(clinic)}
            </p>
          </div>
          <div className="space-y-1 border-t border-clinic-border px-3 py-2.5 text-[12.5px] text-clinic-secondary">
            <p className="flex items-center gap-2">
              <Building2 className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
              <span className="truncate font-medium text-clinic-ink">{clinicDisplayName(clinic)}</span>
            </p>
            <p className="flex items-center gap-2">
              <MapPin className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
              <span className="truncate">{branchLabel(clinic)}</span>
            </p>
          </div>
          <Menu.Separator className="my-1 h-px bg-clinic-border" />
          {/* Profile and security pages belong to the Clinic Portal and are not built yet; shown, not linked. */}
          <Menu.Item disabled className={ITEM}>
            <UserRound className="h-4 w-4" aria-hidden="true" /> Profile
            <span className="ml-auto text-[11px] font-semibold uppercase tracking-[0.06em]">Soon</span>
          </Menu.Item>
          <Menu.Item disabled className={ITEM}>
            <KeyRound className="h-4 w-4" aria-hidden="true" /> Security
            <span className="ml-auto text-[11px] font-semibold uppercase tracking-[0.06em]">Soon</span>
          </Menu.Item>
          <Menu.Separator className="my-1 h-px bg-clinic-border" />
          <Menu.Item onSelect={signOut} className={cn(ITEM, 'text-clinic-danger')}>
            <LogOut className="h-4 w-4" aria-hidden="true" /> Sign out
          </Menu.Item>
        </Menu.Content>
      </Menu.Portal>
    </Menu.Root>
  );
}
