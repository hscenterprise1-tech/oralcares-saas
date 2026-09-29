import React from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, LogOut, Menu, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CrmCanvas, CrmIconButton, CrmToolRail } from '@/pages/Company/design';
import { useClinicPermission } from '@/hooks/useClinicPermission';
import { ClinicFlashBanner } from './ClinicFlashBanner';
import { ClinicIdentity } from './ClinicIdentity';
import { visibleClinicNav } from './nav';

/**
 * The clinic portal's application shell.
 *
 * Same reference language as `CompanyShell` — the ground, the brand mark, the
 * floating tool rail, the account avatar — reused via `CrmCanvas`/
 * `CrmIconButton`/`CrmToolRail` rather than rebuilt. What's deliberately
 * different is the header's centre: `CompanyShell` renders `CrmModuleNav`,
 * Company HQ's cross-module menu (Operations/Revenue/Acquisition/People/
 * Governance) — that menu, and `CrmCommandPalette` behind it, belong to the
 * Company product a clinic's own staff have no business seeing. This shell
 * shows the clinic's own identity and branch switcher instead, and has no
 * command palette yet.
 *
 * Navigation itself is `CLINIC_NAV_ITEMS` (see `./nav`), filtered by the
 * tenant's plan (`clinic.enabled_modules`) and the user's own permissions
 * (`auth.user.permissions`) — the same combination `useNavAuthorization`
 * applies on the Company side, just against a clinic-shaped registry instead
 * of `EXECUTIVE_NAV_GROUPS`.
 */

interface Branch {
  id: number;
  name: string;
  slug: string;
  is_main: boolean;
  display_name: string | null;
  logo_url: string | null;
}

interface ClinicShellPageProps {
  clinic?: {
    id: number;
    name: string;
    branding_name: string;
    logo_url: string | null;
    slug: string;
    active_branch: Branch | null;
    accessible_branches: Branch[];
    is_owner: boolean;
    has_all_branches_access: boolean;
    enabled_modules: string[];
  } | null;
  auth?: { user?: { name?: string } };
  [key: string]: unknown;
}

export function ClinicShell({ title, children }: { title: string; children: React.ReactNode }) {
  const { props, url } = usePage<ClinicShellPageProps>();
  const hasPermission = useClinicPermission();
  const [accountOpen, setAccountOpen] = React.useState(false);
  const [branchOpen, setBranchOpen] = React.useState(false);
  const [mobileNavOpen, setMobileNavOpen] = React.useState(false);

  const clinic = props.clinic;
  const currentPath = url.split('?')[0];
  const userName = props.auth?.user?.name ?? '';
  const branches = clinic?.accessible_branches ?? [];
  const canSwitchBranch = branches.length > 1;

  const navItems = visibleClinicNav(clinic?.enabled_modules ?? [], hasPermission);
  const rail = navItems.map((item) => ({ icon: item.icon, label: item.label, href: item.href, hue: item.hue }));

  const switchBranch = (branchId: number) => {
    setBranchOpen(false);
    router.post(route('clinic.branches.switch'), { branch_id: branchId }, { preserveScroll: true });
  };

  React.useEffect(() => {
    if (! accountOpen && ! branchOpen) return;
    const close = () => { setAccountOpen(false); setBranchOpen(false); };
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && close();
    window.addEventListener('click', close);
    window.addEventListener('keydown', onKey);

    return () => {
      window.removeEventListener('click', close);
      window.removeEventListener('keydown', onKey);
    };
  }, [accountOpen, branchOpen]);

  const isActive = (href: string) => currentPath === href || currentPath.startsWith(`${href}/`);

  return (
    <CrmCanvas>
      <Head title={`${title} — OralCares Clinic`} />

      <header className="sticky top-0 z-40 border-b border-ovs-crm-border-soft/70 bg-ovs-crm-surface-veil-strong px-4 py-3 backdrop-blur-xl print:hidden sm:px-6">
        <div className="flex items-center gap-3">
          <Link href="/clinic/dashboard" className="flex shrink-0 items-center xl:w-[64px]">
            <ClinicIdentity
              name={clinic?.active_branch?.display_name || clinic?.branding_name || 'OralCares'}
              logoUrl={clinic?.active_branch?.logo_url ?? clinic?.logo_url}
              logoHeight={32}
              nameSize={16}
              multiline={false}
              className="max-w-[140px] xl:max-w-[56px]"
            />
          </Link>

          {clinic && (
            <div className="mx-auto hidden items-center gap-2 rounded-full border border-ovs-crm-border-soft bg-white/70 px-4 py-2 lg:flex">
              <span className="max-w-[220px] truncate text-[13px] font-semibold text-ovs-crm-text-primary">
                {clinic.branding_name}
              </span>

              {canSwitchBranch ? (
                <div className="relative" onClick={(e) => e.stopPropagation()}>
                  <button
                    type="button"
                    onClick={() => setBranchOpen((o) => ! o)}
                    aria-expanded={branchOpen}
                    aria-haspopup="menu"
                    className="flex items-center gap-1 rounded-full bg-ovs-crm-surface-inset px-2.5 py-1 text-[12px] font-medium text-ovs-crm-text-secondary transition-colors hover:text-ovs-crm-text-primary"
                  >
                    {clinic.active_branch?.name ?? 'Select branch'}
                    <ChevronDown className={cn('h-3 w-3 transition-transform', branchOpen && 'rotate-180')} aria-hidden="true" />
                  </button>

                  {branchOpen && (
                    <div
                      role="menu"
                      onClick={(e) => e.stopPropagation()}
                      className="absolute left-0 top-full z-40 mt-2 w-56 overflow-hidden rounded-[20px] bg-ovs-crm-surface p-2 shadow-ovs-crmLift"
                    >
                      {branches.map((branch) => (
                        <button
                          key={branch.id}
                          type="button"
                          role="menuitem"
                          onClick={() => switchBranch(branch.id)}
                          className={cn(
                            'flex w-full items-center justify-between rounded-[16px] px-3 py-2 text-left text-[13px] font-medium transition-colors',
                            branch.id === clinic.active_branch?.id
                              ? 'bg-ovs-crm-ink text-white'
                              : 'text-ovs-crm-text-primary hover:bg-ovs-crm-surface-inset'
                          )}
                        >
                          {branch.name}
                          {branch.is_main && <span className="text-[10px] opacity-70">Main</span>}
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              ) : (
                clinic.active_branch && (
                  <span className="rounded-full bg-ovs-crm-surface-inset px-2.5 py-1 text-[12px] font-medium text-ovs-crm-text-secondary">
                    {clinic.active_branch.name}
                  </span>
                )
              )}
            </div>
          )}

          <div className="ml-auto flex shrink-0 items-center gap-2 lg:ml-0">
            <div className="relative">
              <button
                type="button"
                onClick={(e) => { e.stopPropagation(); setAccountOpen((o) => ! o); }}
                aria-expanded={accountOpen}
                aria-haspopup="menu"
                aria-label={userName ? `Account: ${userName}` : 'Account'}
                title={userName}
                className="flex h-11 w-11 items-center justify-center rounded-full bg-ovs-crm-accent-teal text-[13px] font-bold text-white ring-2 ring-white transition-opacity hover:opacity-90"
              >
                {userName.charAt(0).toUpperCase() || '?'}
              </button>

              {accountOpen && (
                <div
                  role="menu"
                  onClick={(e) => e.stopPropagation()}
                  className="absolute right-0 top-full z-40 mt-2 w-52 overflow-hidden rounded-[24px] bg-ovs-crm-surface p-2 shadow-ovs-crmLift"
                >
                  {userName && (
                    <p className="truncate px-3 pb-1.5 pt-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                      {userName}
                    </p>
                  )}
                  <button
                    type="button"
                    role="menuitem"
                    onClick={() => router.post(route('clinic.logout'))}
                    className="flex w-full items-center gap-3 rounded-[24px] px-3 py-2.5 text-left text-[13px] font-medium text-ovs-crm-text-primary transition-colors hover:bg-ovs-crm-surface-inset"
                  >
                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-ovs-crm-surface-inset">
                      <LogOut className="h-3.5 w-3.5 text-ovs-crm-text-secondary" aria-hidden="true" />
                    </span>
                    Log out
                  </button>
                </div>
              )}
            </div>

            <CrmIconButton
              icon={mobileNavOpen ? X : Menu}
              label={mobileNavOpen ? 'Close menu' : 'Open menu'}
              onClick={() => setMobileNavOpen((o) => ! o)}
              size="lg"
              className="xl:hidden"
            />
          </div>
        </div>

        {mobileNavOpen && (
          <div className="mx-auto mt-3 max-h-[70vh] w-full max-w-[1560px] overflow-y-auto rounded-[24px] border border-ovs-crm-border-soft bg-ovs-crm-surface-veil-strong p-3 shadow-ovs-crmCard backdrop-blur-xl xl:hidden">
            <div className="grid grid-cols-2 gap-1">
              {navItems.map((item) => (
                <Link
                  key={item.id}
                  href={item.href}
                  onClick={() => setMobileNavOpen(false)}
                  className={cn(
                    'truncate rounded-[24px] px-3 py-2.5 text-[13px] font-semibold transition-colors',
                    isActive(item.href) ? 'bg-ovs-crm-ink text-white' : 'text-ovs-crm-text-secondary hover:bg-white/70'
                  )}
                >
                  {item.label}
                </Link>
              ))}
              <Link
                href="/clinic/admin/settings"
                onClick={() => setMobileNavOpen(false)}
                className={cn(
                  'truncate rounded-[24px] px-3 py-2.5 text-[13px] font-semibold transition-colors',
                  isActive('/clinic/admin/settings') ? 'bg-ovs-crm-ink text-white' : 'text-ovs-crm-text-secondary hover:bg-white/70'
                )}
              >
                Settings
              </Link>
            </div>
          </div>
        )}
      </header>

      <div className="flex print:block">
        {/*
          * No command palette to open yet — the search well routes to
          * Patients, the one page in this phase with its own search.
          */}
        <div className="print:hidden">
          <CrmToolRail
            currentPath={currentPath}
            onSearch={() => router.visit('/clinic/patients')}
            rail={rail}
            settingsHref="/clinic/admin/settings"
          />
        </div>

        <main className="min-w-0 flex-1 px-4 pb-12 pt-7 print:w-full print:p-0 sm:px-6 xl:pr-10">
          <div className="print:hidden">
            <ClinicFlashBanner />
          </div>
          {children}
        </main>
      </div>
    </CrmCanvas>
  );
}
