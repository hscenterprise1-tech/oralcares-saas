import { useEffect, useState, type ReactNode } from 'react';
import { Head, usePage } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { clinicDisplayName, useClinicShared } from '../context';
import { ToastViewport } from '../ui/Toast';
import { PortalErrorBoundary } from './PortalErrorBoundary';
import { Sidebar } from './Sidebar';
import { IntakeProvider } from './IntakeContext';
import { FlashNotices, OfflineBanner, PlanBanner } from './StatusBanners';
import { TopBar } from './TopBar';

/**
 * The Clinic Portal's application frame: a fixed text-only sidebar on
 * desktop, a drawer on smaller screens, a context bar on top, and the page. Every signed-in Clinic page
 * renders inside it.
 *
 * Everything in it comes from the server's shared props — the clinic, the
 * branch, the resolved navigation — so the frame never decides access on its
 * own and never reads a tenant or branch from the browser.
 *
 * The `setup` variant is the same frame without navigation, for clinic setup:
 * until setup is finished every other page sends the member back to it, so a
 * sidebar would only offer dead ends. The caller supplies its own `bar`.
 */
export function ClinicPortalShell({
  title,
  children,
  variant = 'portal',
  bar,
}: {
  title: string;
  children: ReactNode;
  variant?: 'portal' | 'setup';
  bar?: ReactNode;
}) {
  const { clinic, auth, portalLinks, flash, subscriptionWindow } = useClinicShared();
  const { url } = usePage();
  const [drawerOpen, setDrawerOpen] = useState(false);

  // A visit closes the drawer.
  useEffect(() => setDrawerOpen(false), [url]);

  if (variant === 'setup') {
    return (
      <>
        <Head title={clinic ? `${title} · ${clinicDisplayName(clinic)}` : title} />
        <a
          href="#main"
          className="sr-only z-[60] rounded-full bg-cx-navy px-4 py-2 text-[13px] font-semibold text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
        >
          Skip to content
        </a>
        <div className="min-h-screen bg-cx-nav-canvas text-cx-nav-ink">
          <OfflineBanner />
          {bar}
          <main id="main" tabIndex={-1} className="mx-auto w-full min-w-0 max-w-6xl px-4 py-6 focus:outline-none sm:px-6 sm:py-8">
            <FlashNotices flash={flash} />
            <PortalErrorBoundary resetKey={url}>{children}</PortalErrorBoundary>
          </main>
        </div>
        <ToastViewport />
      </>
    );
  }

  if (!clinic || !auth.user || !portalLinks) {
    // Only reachable if a portal page is rendered outside a clinic session;
    // the server's middleware normally prevents it.
    return (
      <>
        <Head title={title} />
        <main id="main" className="min-h-screen bg-cx-nav-canvas px-4 py-16">
          {children}
        </main>
      </>
    );
  }

  return (
    <>
      <Head title={`${title} · ${clinicDisplayName(clinic)}`} />
      <a
        href="#main"
        className="sr-only z-[60] rounded-full bg-cx-navy px-4 py-2 text-[13px] font-semibold text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      >
        Skip to content
      </a>
      <IntakeProvider clinic={clinic}>
      <div className="flex min-h-screen bg-cx-nav-canvas text-cx-text">
        <aside
          className="sticky top-0 hidden h-screen w-[240px] shrink-0 lg:block"
        >
          <Sidebar clinic={clinic} links={portalLinks} currentUrl={url} />
        </aside>

        <Dialog.Root open={drawerOpen} onOpenChange={setDrawerOpen}>
          <Dialog.Portal>
            <Dialog.Overlay className="fixed inset-0 z-50 bg-cx-nav-ink/30 lg:hidden" />
            <Dialog.Content
              className="fixed inset-y-0 left-0 z-50 w-[min(280px,86vw)] shadow-cx-raised focus:outline-none lg:hidden"
              // The menu button lives in the top bar, not as this dialog's own trigger, so focus is handed back to it here.
              onCloseAutoFocus={(event) => {
                event.preventDefault();
                document.querySelector<HTMLButtonElement>('button[aria-haspopup="dialog"][aria-label="Open navigation"]')?.focus();
              }}
            >
              <Dialog.Title className="sr-only">Navigation</Dialog.Title>
              <Dialog.Description className="sr-only">The places in the Clinic Portal you can open.</Dialog.Description>
              <Sidebar clinic={clinic} links={portalLinks} currentUrl={url} onNavigate={() => setDrawerOpen(false)} inDrawer />
              {/* A 44px target, centred in the drawer's 73px header beside the wordmark (which leaves it room). */}
              <Dialog.Close
                aria-label="Close navigation"
                className="absolute right-3 top-[14px] flex h-11 w-11 items-center justify-center rounded-md text-cx-nav-muted transition-colors hover:bg-cx-nav-canvas hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
              >
                <X className="h-[18px] w-[18px]" strokeWidth={1.75} aria-hidden="true" />
              </Dialog.Close>
            </Dialog.Content>
          </Dialog.Portal>
        </Dialog.Root>

        <div className="flex min-w-0 flex-1 flex-col">
          <OfflineBanner />
          <PlanBanner window={subscriptionWindow} />
          <TopBar clinic={clinic} user={auth.user} links={portalLinks} currentUrl={url} onOpenMenu={() => setDrawerOpen(true)} />
          <main id="main" tabIndex={-1} className="mx-auto w-full min-w-0 max-w-[1600px] flex-1 px-4 py-5 focus:outline-none sm:px-6 sm:py-6 lg:px-8">
            <FlashNotices flash={flash} />
            <PortalErrorBoundary resetKey={url}>{children}</PortalErrorBoundary>
          </main>
        </div>
      </div>
      </IntakeProvider>
      <ToastViewport />
    </>
  );
}

/** The canonical name new pages use. */
export const PortalShell = ClinicPortalShell;
