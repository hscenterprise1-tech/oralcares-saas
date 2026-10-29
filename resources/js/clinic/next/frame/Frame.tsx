import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { Bell, LogOut, Menu as MenuIcon, MoreHorizontal, Search, Undo2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { clinicDisplayName, useClinicShared } from '../../context';
import { IntakeProvider } from '../../shell/IntakeContext';
import { PortalErrorBoundary } from '../../shell/PortalErrorBoundary';
import { FlashNotices, OfflineBanner, PlanBanner } from '../../shell/StatusBanners';
import { ToastViewport } from '../../ui/Toast';
import { leaveNextUi } from '../preview';
import { Kbd, monogramOf } from '../primitives';
import { FOCUS } from '../tokens';
import { framePlaces, patientsHref, type FramePlace } from './navigation';

/**
 * The new Clinic Portal frame.
 *
 * Large screens: a dark spine on the left with the daily places (icon over
 * a short name), and a paper context bar on top saying where you are —
 * clinic, branch, the page — with patient search. Phones: the same context
 * bar, and a bottom bar with the first four places plus "More".
 *
 * It reuses only the portal's plumbing (shared props, the intake, flash and
 * plan banners, the error boundary), never the old frame's layout.
 */
export function Frame({ title, children }: { title: string; children: ReactNode }) {
  const { clinic, auth, portalLinks, flash, subscriptionWindow } = useClinicShared();
  const { url } = usePage();
  const [moreOpen, setMoreOpen] = useState(false);

  useEffect(() => setMoreOpen(false), [url]);

  if (!clinic || !auth.user || !portalLinks) {
    return (
      <>
        <Head title={title} />
        <main id="main" className="min-h-screen bg-[#F6F4EF] px-4 py-16">
          {children}
        </main>
      </>
    );
  }

  const places = framePlaces(clinic, url);
  const bottom = places.work.slice(0, 4);
  const overflow = [...places.work.slice(4), ...places.more];
  const branch = clinic.active_branch?.display_name ?? clinic.active_branch?.name ?? null;
  const userName = auth.user.name;

  return (
    <>
      <Head title={`${title} · ${clinicDisplayName(clinic)}`} />
      <a href="#main" className="sr-only z-[60] rounded-[6px] bg-[#13201B] px-4 py-2 text-[13px] font-semibold text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4">
        Skip to content
      </a>
      <IntakeProvider clinic={clinic}>
        <div className="min-h-screen bg-[#F6F4EF] text-[#13201B] lg:pl-[96px]">
          {/* The spine: large screens only. */}
          <nav aria-label="Clinic" className="fixed inset-y-0 left-0 z-30 hidden w-[96px] flex-col bg-[#10211B] lg:flex">
            <div className="flex h-16 items-center justify-center">
              <span className="grid h-10 w-10 place-items-center rounded-[8px] bg-[#5FD3A8] text-[14px] font-bold tracking-tight text-[#10211B]" aria-hidden="true">
                OC
              </span>
              <span className="sr-only">OralCares</span>
            </div>
            <ul className="flex flex-1 flex-col gap-1 overflow-y-auto px-2 py-2 [scrollbar-width:none]">
              {places.work.map((place) => (
                <SpinePlace key={place.key} place={place} />
              ))}
              {places.more.length > 0 && <li aria-hidden="true" className="mx-3 my-2 border-t border-white/10" />}
              {places.more.map((place) => (
                <SpinePlace key={place.key} place={place} />
              ))}
            </ul>
            <div className="flex justify-center pb-4">
              <AccountMenu name={userName} email={auth.user.email} admin={places.admin} signOut={portalLinks.signOut} placement="spine" />
            </div>
          </nav>

          <div className="flex min-h-screen min-w-0 flex-col">
            <OfflineBanner />
            <PlanBanner window={subscriptionWindow} />
            <header className="sticky top-0 z-20 border-b border-[#E3DED3] bg-[#F6F4EF]/95 backdrop-blur supports-[backdrop-filter]:bg-[#F6F4EF]/85">
              <div className="mx-auto flex h-14 w-full max-w-[1440px] items-center gap-3 px-4 sm:px-6 lg:h-16 lg:px-8">
                <span className="grid h-8 w-8 shrink-0 place-items-center rounded-[6px] bg-[#10211B] text-[12px] font-bold text-[#5FD3A8] lg:hidden" aria-hidden="true">
                  OC
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[14px] font-semibold leading-5">{clinicDisplayName(clinic)}</p>
                  {branch && <p className="truncate text-[12px] leading-4 text-[#6B7671]">{branch}</p>}
                </div>
                <FrameSearch target={patientsHref(clinic)} />
                {places.more.find((p) => p.key === 'notifications')?.href && (
                  <Link
                    href={places.more.find((p) => p.key === 'notifications')!.href!}
                    aria-label="Notifications"
                    className={cn('grid h-10 w-10 shrink-0 place-items-center rounded-[6px] text-[#4A5752] hover:bg-[#EFECE5] hover:text-[#13201B]', FOCUS)}
                  >
                    <Bell className="h-[18px] w-[18px]" aria-hidden="true" />
                  </Link>
                )}
                <div className="lg:hidden">
                  <AccountMenu name={userName} email={auth.user.email} admin={places.admin} signOut={portalLinks.signOut} placement="bar" />
                </div>
              </div>
            </header>

            <main id="main" tabIndex={-1} className="mx-auto w-full min-w-0 max-w-[1440px] flex-1 px-4 pb-28 pt-5 focus:outline-none sm:px-6 lg:px-8 lg:pb-12 lg:pt-7">
              <FlashNotices flash={flash} />
              <PortalErrorBoundary resetKey={url}>{children}</PortalErrorBoundary>
            </main>
          </div>

          {/* Phones and tablets: the bottom bar. */}
          <nav aria-label="Clinic" className="fixed inset-x-0 bottom-0 z-30 border-t border-[#E3DED3] bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden">
            <ul className="mx-auto flex max-w-xl">
              {bottom.map((place) => (
                <li key={place.key} className="min-w-0 flex-1">
                  <BarPlace place={place} />
                </li>
              ))}
              {overflow.length > 0 && (
                <li className="min-w-0 flex-1">
                  <Dialog.Root open={moreOpen} onOpenChange={setMoreOpen}>
                    <Dialog.Trigger className={cn('flex h-16 w-full flex-col items-center justify-center gap-1 text-[11.5px] font-semibold text-[#4A5752]', FOCUS)}>
                      <MoreHorizontal className="h-5 w-5" aria-hidden="true" />
                      More
                    </Dialog.Trigger>
                    <Dialog.Portal>
                      <Dialog.Overlay className="fixed inset-0 z-40 bg-[#13201B]/40 lg:hidden" />
                      <Dialog.Content className="fixed inset-x-0 bottom-0 z-50 rounded-t-[14px] bg-white px-4 pb-[calc(env(safe-area-inset-bottom)+16px)] pt-3 focus:outline-none lg:hidden">
                        <div className="mx-auto mb-3 h-1 w-10 rounded-full bg-[#CFC8BA]" aria-hidden="true" />
                        <div className="flex items-center justify-between">
                          <Dialog.Title className="text-[16px] font-semibold">More places</Dialog.Title>
                          <Dialog.Close aria-label="Close" className={cn('grid h-11 w-11 place-items-center rounded-[6px] text-[#4A5752] hover:bg-[#EFECE5]', FOCUS)}>
                            <X className="h-5 w-5" aria-hidden="true" />
                          </Dialog.Close>
                        </div>
                        <Dialog.Description className="sr-only">Every other place in the Clinic Portal you can open.</Dialog.Description>
                        <ul className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
                          {overflow.map((place) => (
                            <li key={place.key}>
                              <SheetPlace place={place} />
                            </li>
                          ))}
                        </ul>
                      </Dialog.Content>
                    </Dialog.Portal>
                  </Dialog.Root>
                </li>
              )}
            </ul>
          </nav>
        </div>
      </IntakeProvider>
      <ToastViewport />
    </>
  );
}

function SpinePlace({ place }: { place: FramePlace }) {
  const Icon = place.icon;
  const body = (
    <>
      {place.current && <span aria-hidden="true" className="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-[#5FD3A8]" />}
      <Icon className="h-5 w-5" strokeWidth={1.8} aria-hidden="true" />
      <span className="w-full px-0.5 text-center text-[10.5px] font-semibold leading-[13px] [overflow-wrap:anywhere]">{place.label}</span>
    </>
  );
  const shape = 'relative flex min-h-[60px] w-full flex-col items-center justify-center gap-1 rounded-[8px] py-2';

  return (
    <li>
      {place.href ? (
        <Link
          href={place.href}
          aria-current={place.current ? 'page' : undefined}
          className={cn(shape, 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5FD3A8]', place.current ? 'bg-[#1E3A30] text-white' : 'text-[#B8C9C1] hover:bg-white/5 hover:text-white')}
        >
          {body}
        </Link>
      ) : (
        <span aria-disabled="true" title={`${place.label} — coming soon`} className={cn(shape, 'cursor-default text-[#B8C9C1]/45')}>
          {body}
          <span className="sr-only">(coming soon)</span>
        </span>
      )}
    </li>
  );
}

function BarPlace({ place }: { place: FramePlace }) {
  const Icon = place.icon;
  const shape = 'relative flex h-16 w-full flex-col items-center justify-center gap-1 px-1 text-[11.5px] font-semibold';

  if (!place.href) {
    return (
      <span aria-disabled="true" className={cn(shape, 'text-[#6B7671]/50')}>
        <Icon className="h-5 w-5" aria-hidden="true" />
        <span className="max-w-full truncate">{place.label}</span>
        <span className="sr-only">(coming soon)</span>
      </span>
    );
  }

  return (
    <Link href={place.href} aria-current={place.current ? 'page' : undefined} className={cn(shape, FOCUS, place.current ? 'text-[#0E6B54]' : 'text-[#4A5752]')}>
      {place.current && <span aria-hidden="true" className="absolute inset-x-5 top-0 h-[3px] rounded-b-full bg-[#0E6B54]" />}
      <Icon className="h-5 w-5" aria-hidden="true" />
      <span className="max-w-full truncate">{place.label}</span>
    </Link>
  );
}

function SheetPlace({ place }: { place: FramePlace }) {
  const Icon = place.icon;
  const shape = 'flex h-20 flex-col items-center justify-center gap-1.5 rounded-[8px] border px-1 text-center text-[12px] font-semibold';

  if (!place.href) {
    return (
      <span aria-disabled="true" className={cn(shape, 'border-dashed border-[#E3DED3] text-[#6B7671]/60')}>
        <Icon className="h-5 w-5" aria-hidden="true" />
        {place.label}
        <span className="text-[10.5px] font-medium">Coming soon</span>
      </span>
    );
  }

  return (
    <Link href={place.href} aria-current={place.current ? 'page' : undefined} className={cn(shape, FOCUS, place.current ? 'border-[#0E6B54] bg-[#E3F1EB] text-[#0A5643]' : 'border-[#E3DED3] text-[#13201B]')}>
      <Icon className="h-5 w-5" aria-hidden="true" />
      {place.label}
    </Link>
  );
}

/**
 * Patient search from anywhere: it opens the patient register filtered by
 * what was typed. "/" puts the cursor here unless you are already typing.
 */
function FrameSearch({ target }: { target: string | null }) {
  const [query, setQuery] = useState('');

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      const typing = event.target instanceof HTMLElement && (event.target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName));
      if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey) {
        const field = document.getElementById('frame-search') ?? document.getElementById('register-search');
        if (field) {
          event.preventDefault();
          (field as HTMLInputElement).focus();
        }
      }
    };
    window.addEventListener('keydown', onKey);

    return () => window.removeEventListener('keydown', onKey);
  }, []);

  if (!target) {
    return null;
  }

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (query.trim() !== '') {
      router.get(target, { q: query.trim(), view: 'all' });
    }
  };

  return (
    <form role="search" onSubmit={submit} className="hidden w-[min(340px,34vw)] md:block">
      <label htmlFor="frame-search" className="sr-only">
        Find a patient
      </label>
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[#6B7671]" aria-hidden="true" />
        <input
          id="frame-search"
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Find a patient"
          autoComplete="off"
          className="h-10 w-full rounded-[6px] border border-[#E3DED3] bg-white pl-9 pr-10 text-[14px] text-[#13201B] placeholder:text-[#6B7671] focus:border-[#0E6B54] focus:outline-none focus:ring-2 focus:ring-[#0E6B54]/25"
        />
        <span className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2">
          <Kbd>/</Kbd>
        </span>
      </div>
    </form>
  );
}

function AccountMenu({ name, email, admin, signOut, placement }: { name: string; email: string; admin: FramePlace[]; signOut: string; placement: 'spine' | 'bar' }) {
  return (
    <Menu.Root modal={false}>
      <Menu.Trigger
        aria-label={`Account: ${name}`}
        className={cn(
          'grid shrink-0 place-items-center rounded-[8px] text-[13px] font-semibold',
          placement === 'spine'
            ? 'h-11 w-11 bg-white/10 text-white hover:bg-white/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5FD3A8]'
            : cn('h-10 w-10 bg-[#13201B] text-[#E9F4EF]', FOCUS),
        )}
      >
        {placement === 'bar' ? <MenuIcon className="h-[18px] w-[18px]" aria-hidden="true" /> : monogramOf(name)}
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Content side={placement === 'spine' ? 'right' : 'bottom'} align="end" sideOffset={8} className="z-50 w-64 rounded-[10px] border border-[#E3DED3] bg-white p-1.5 shadow-[0_18px_40px_-20px_rgba(19,32,27,0.45)]">
          <div className="px-2.5 py-2">
            <p className="truncate text-[14px] font-semibold text-[#13201B]">{name}</p>
            <p className="truncate text-[12.5px] text-[#6B7671]">{email}</p>
          </div>
          {admin.length > 0 && <Menu.Separator className="my-1 h-px bg-[#E3DED3]" />}
          {admin.map((place) => {
            const Icon = place.icon;

            return place.href ? (
              <Menu.Item key={place.key} asChild>
                <Link href={place.href} className={MENU_ITEM}>
                  <Icon className="h-4 w-4 text-[#4A5752]" aria-hidden="true" />
                  {place.label}
                </Link>
              </Menu.Item>
            ) : (
              <Menu.Item key={place.key} disabled className={MENU_ITEM}>
                <Icon className="h-4 w-4" aria-hidden="true" />
                {place.label}
                <span className="ml-auto text-[11px] font-medium">Soon</span>
              </Menu.Item>
            );
          })}
          <Menu.Separator className="my-1 h-px bg-[#E3DED3]" />
          <Menu.Item onSelect={() => leaveNextUi()} className={MENU_ITEM}>
            <Undo2 className="h-4 w-4 text-[#4A5752]" aria-hidden="true" />
            Classic view
          </Menu.Item>
          <Menu.Item onSelect={() => router.post(signOut)} className={cn(MENU_ITEM, 'text-[#A3201A]')}>
            <LogOut className="h-4 w-4" aria-hidden="true" />
            Sign out
          </Menu.Item>
        </Menu.Content>
      </Menu.Portal>
    </Menu.Root>
  );
}

const MENU_ITEM =
  'flex min-h-10 cursor-pointer items-center gap-2.5 rounded-[6px] px-2.5 text-[14px] font-medium text-[#13201B] outline-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50 data-[highlighted]:bg-[#F1EEE7]';
