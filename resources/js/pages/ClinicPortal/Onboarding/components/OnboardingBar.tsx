import { useState } from 'react';
import { router } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { useClinicShared } from '@/clinic/context';

/**
 * The bar above clinic setup, for the portal shell's `setup` variant: the
 * wordmark, which clinic this is, who is signed in and a way to sign out.
 * There is no navigation: until setup is finished every other page sends the
 * member back here.
 */
export function OnboardingBar({ clinicName, logoutUrl }: { clinicName: string | null; logoutUrl: string | null }) {
  const { auth } = useClinicShared();
  const [signingOut, setSigningOut] = useState(false);

  const signOut = () => {
    if (!logoutUrl) {
      return;
    }
    setSigningOut(true);
    router.post(logoutUrl, {}, { onFinish: () => setSigningOut(false) });
  };

  return (
    <div className="border-b border-cx-nav-line bg-cx-nav-bg">
      <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-3 px-4 sm:px-6">
        <div className="flex min-w-0 items-center gap-3">
          {/* The wordmark: type alone, no symbol. */}
          <span className="shrink-0 text-[19px] font-bold leading-none tracking-[-0.025em] text-cx-nav-ink">OralCares</span>
          {clinicName && (
            <>
              <span className="h-5 w-px shrink-0 bg-cx-nav-line" aria-hidden="true" />
              <span className="truncate text-[13.5px] font-semibold text-cx-nav-muted">{clinicName}</span>
            </>
          )}
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {auth.user?.name && <span className="hidden max-w-[220px] truncate text-[12.5px] text-cx-nav-muted sm:inline">{auth.user.name}</span>}
          {logoutUrl && (
            <button
              type="button"
              onClick={signOut}
              disabled={signingOut}
              className="inline-flex h-9 items-center gap-1.5 rounded-full px-3 text-[12.5px] font-semibold text-cx-nav-muted hover:bg-cx-nav-hover hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal disabled:opacity-50 [@media(pointer:coarse)]:min-h-11"
            >
              <LogOut className="h-4 w-4" aria-hidden="true" />
              {signingOut ? 'Signing out…' : 'Sign out'}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
