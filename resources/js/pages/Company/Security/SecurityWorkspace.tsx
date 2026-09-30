import React from 'react';
import { Head, router } from '@inertiajs/react';
import {
  AlertTriangle,
  KeyRound,
  Lock,
  LogIn,
  Monitor,
  ShieldCheck,
  ShieldAlert,
  Users,
} from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmValue,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmHairlineBar,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { AdministrationShell } from '@/pages/Company/design';

/**
 * Company → Security & access
 *
 * Read-only. Roles are changed where they already are, in People; nothing here
 * revokes a session or issues a token, because no existing backend supports
 * either and inventing one would be inventing security surface.
 *
 * The route audit is the part worth looking at: it walks the registered routes
 * and reports which Company routes enforce nothing at all.
 */

const HUE = {
  green: '#2FA96B',
  blue: '#2F6BE4',
  violet: '#7C6FE0',
  orange: '#F59245',
  coral: '#EF5B39',
  yellow: '#E0B21E',
} as const;

/*
 * The rail is Administration's, from COMPANY_MODULES.
 *
 * This file used to declare its own three-item menu — Security, Roles, Audit —
 * while Workflows and Integrations each declared a different three. None of
 * them was the whole module.
 */

interface UnprotectedRoute {
  uri: string;
  methods: string;
  mutating: boolean;
  action: string;
  name: string | null;
  module: string;
}

interface Props {
  routes: {
    gated_by_middleware: number;
    gated_in_controller: number;
    intentionally_public: number;
    unprotected: UnprotectedRoute[];
    unprotected_count: number;
    unprotected_mutating: number;
    total: number;
  };
  roles: Array<{
    id: number;
    name: string;
    guard: string;
    branch_id: number | null;
    permissions_count: number;
    users_count: number;
    is_system: boolean | null;
  }>;
  permissions: {
    total: number;
    held_by_no_role: string[];
    gated_on_by_routes: string[];
    unreachable_gates: string[];
  };
  users: Array<{
    id: number;
    name: string;
    email: string;
    user_type: string | null;
    is_super_admin: boolean;
    roles: string[];
    role_branches: Array<number | null>;
    inactive_assignments: number;
    last_login: string | null;
  }>;
  logins: Array<{
    id: number;
    user: string | null;
    email: string | null;
    device: string | null;
    browser: string | null;
    ip_address: string | null;
    status: string | null;
    login_time: string | null;
    logout_time: string | null;
  }>;
  sessions: {
    supported: boolean;
    total: number;
    signed_in?: number;
    rows: Array<{
      id: string;
      user: string | null;
      email: string | null;
      ip_address: string | null;
      user_agent: string | null;
      last_activity: string | null;
      is_anonymous: boolean;
    }>;
  };
  filters: { search: string | null };
  can: { manageRoles: boolean; viewAudit: boolean };
}

export default function SecurityWorkspace({
  routes,
  roles,
  permissions,
  users,
  logins,
  sessions,
  filters,
  can,
}: Props) {
  const [tab, setTab] = React.useState<'routes' | 'access' | 'activity'>('routes');

  const covered = routes.gated_by_middleware + routes.gated_in_controller;
  const coverage = routes.total > 0 ? Math.round((covered / routes.total) * 100) : 100;

  const byModule = routes.unprotected.reduce<Record<string, number>>((acc, route) => {
    acc[route.module] = (acc[route.module] ?? 0) + 1;

    return acc;
  }, {});

  return (
    <AdministrationShell title="Security">
      <Head title="Security & access | Company" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Company</p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Security &amp; access
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            Who can reach what, and where nothing is checking
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={ShieldCheck} value={`${coverage}%`} caption="Routes enforcing" />
          <CrmPageStat icon={ShieldAlert} value={routes.unprotected_mutating} caption="Unguarded writes" />
          <CrmPageStat icon={Users} value={users.length} caption="Company users" />
          <CrmPageStat icon={Monitor} value={sessions.total} caption="Live sessions" />
        </div>
      </div>

      {routes.unprotected_mutating > 0 && (
        <CrmCard className="mb-4 flex flex-wrap items-start justify-between gap-4 px-5 py-4" frostHue={HUE.coral}>
          <div className="flex items-start gap-3">
            <CrmCardIcon icon={AlertTriangle} hue={HUE.coral} />
            <div className="min-w-0">
              <p className="text-[13px] font-semibold text-ovs-crm-text-primary">
                {routes.unprotected_mutating} route{routes.unprotected_mutating === 1 ? '' : 's'} that change data
                enforce no authorization
              </p>
              {/*
                Reachable by any authenticated company user. Listed below rather
                than counted, because a number nobody can act on is not a finding.
              */}
              <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
                Any signed-in company user can call them. Concentrated in:{' '}
                {Object.entries(byModule)
                  .map(([module, count]) => `${module} (${count})`)
                  .join(', ')}
              </p>
            </div>
          </div>
        </CrmCard>
      )}

      <div
        role="tablist"
        aria-label="Security view"
        className="mb-4 flex flex-wrap items-center gap-1 rounded-full bg-ovs-crm-surface-inset p-1"
      >
        {(
          [
            ['routes', 'Route guards'],
            ['access', 'Users, roles & permissions'],
            ['activity', 'Sign-ins & sessions'],
          ] as const
        ).map(([key, label]) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={
              tab === key
                ? 'rounded-full bg-ovs-crm-ink px-3.5 py-1.5 text-[12px] font-semibold text-white transition-colors'
                : 'rounded-full px-3.5 py-1.5 text-[12px] font-semibold text-ovs-crm-text-secondary transition-colors hover:text-ovs-crm-text-primary'
            }
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'routes' && (
        <>
          <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { label: 'Gated by middleware', value: routes.gated_by_middleware, hue: HUE.green },
              { label: 'Gated in the controller', value: routes.gated_in_controller, hue: HUE.blue },
              { label: 'Open on purpose', value: routes.intentionally_public, hue: HUE.violet },
              { label: 'Enforcing nothing', value: routes.unprotected_count, hue: HUE.coral },
            ].map((cell) => (
              <CrmCard key={cell.label} className="px-5 py-4">
                <span className="block h-1.5 w-9 rounded-full" style={{ background: cell.hue }} aria-hidden />
                <CrmValue value={cell.value} size="minor" className="mt-2" />
                <p className="mt-1 text-[11.5px] text-ovs-crm-text-muted">{cell.label}</p>
              </CrmCard>
            ))}
          </div>

          <CrmCard className="p-6">
            <div className="mb-4 flex items-start gap-3">
              <div className="min-w-0 flex-1">
                <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  Routes enforcing nothing
                </h2>
                <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                  No <code>can:</code> middleware, and no authorization call in the controller action
                </p>
              </div>
              <CrmCardIcon icon={ShieldAlert} hue={HUE.coral} />
            </div>

            {routes.unprotected.length === 0 ? (
              <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">
                Every Company route enforces something.
              </p>
            ) : (
              <ul className="space-y-1.5">
                {routes.unprotected.map((route) => (
                  <li
                    key={`${route.methods}-${route.uri}`}
                    className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[14px] border border-ovs-crm-border px-3.5 py-2"
                  >
                    <CrmStatusPill tone={route.mutating ? 'bad' : 'neutral'}>{route.methods}</CrmStatusPill>
                    <span className="min-w-[180px] flex-1 text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                      {route.uri}
                    </span>
                    <span className="min-w-[200px] truncate text-[11px] text-ovs-crm-text-muted">
                      {route.action.replace('App\\Http\\Controllers\\', '')}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </CrmCard>
        </>
      )}

      {tab === 'access' && (
        <>
          <CrmCard className="mb-4 flex flex-wrap items-end gap-3 px-5 py-3.5">
            <div className="min-w-[240px] flex-1">
              <CrmFormField label="Find a user">
                <CrmInput
                  type="search"
                  placeholder="Name or email"
                  defaultValue={filters.search ?? ''}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      router.get(
                        '/company/security',
                        { search: (e.target as HTMLInputElement).value || undefined },
                        { preserveState: true, replace: true },
                      );
                    }
                  }}
                />
              </CrmFormField>
            </div>
            {can.manageRoles && <CrmButton href="/company/people/roles">Manage roles</CrmButton>}
          </CrmCard>

          <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <CrmCard className="p-6">
              <div className="mb-4 flex items-start gap-3">
                <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  Users
                </h2>
                <CrmCardIcon icon={Users} hue={HUE.blue} />
              </div>

              <ul className="space-y-2">
                {users.map((user) => (
                  <li key={user.id} className="rounded-[14px] border border-ovs-crm-border px-3.5 py-2.5">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-[12.5px] font-medium text-ovs-crm-text-primary">{user.name}</span>
                      {/* The bypass, said out loud — an empty role list is not no access. */}
                      {user.is_super_admin && <CrmStatusPill tone="good">Super admin · bypass</CrmStatusPill>}
                      {user.inactive_assignments > 0 && (
                        <CrmStatusPill tone="warn">{user.inactive_assignments} branch-scoped</CrmStatusPill>
                      )}
                    </div>
                    <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                      {user.email} · {user.user_type}
                      {user.roles.length > 0 && <> · {user.roles.join(', ')}</>}
                      {user.roles.length === 0 && ! user.is_super_admin && <> · no roles</>}
                    </p>
                  </li>
                ))}
              </ul>
            </CrmCard>

            <div className="space-y-4">
              <CrmCard className="p-6">
                <div className="mb-4 flex items-start gap-3">
                  <div className="min-w-0 flex-1">
                    <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">Roles</h2>
                    <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                      {/* No `is_system` column exists, so the distinction is not claimed. */}
                      Nothing distinguishes a system role from a custom one in this database
                    </p>
                  </div>
                  <CrmCardIcon icon={ShieldCheck} hue={HUE.green} />
                </div>

                <ul className="space-y-1.5">
                  {roles.slice(0, 12).map((role) => (
                    <li key={role.id} className="flex flex-wrap items-center gap-x-3 gap-y-1">
                      <span className="min-w-[140px] flex-1 text-[12.5px] text-ovs-crm-text-primary">{role.name}</span>
                      <span className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                        {role.permissions_count} perms · {role.users_count} holders
                      </span>
                      {role.branch_id !== null && role.branch_id !== 0 && (
                        <CrmStatusPill tone="warn">branch {role.branch_id}</CrmStatusPill>
                      )}
                    </li>
                  ))}
                </ul>
              </CrmCard>

              <CrmCard className="p-6">
                <div className="mb-4 flex items-start gap-3">
                  <div className="min-w-0 flex-1">
                    <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                      Permissions
                    </h2>
                    <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                      {permissions.total} defined · {permissions.gated_on_by_routes.length} gated on by a route
                    </p>
                  </div>
                  <CrmCardIcon icon={KeyRound} hue={HUE.yellow} />
                </div>

                {permissions.unreachable_gates.length > 0 && (
                  <div className="mb-3 rounded-[14px] bg-ovs-crm-accent-coral-soft px-3.5 py-2.5">
                    <p className="text-[12px] font-medium text-ovs-crm-accent-coral-text">
                      {/* A route asks for it and no role holds it: refuses everybody. */}
                      Gated on by a route, held by no role
                    </p>
                    <p className="mt-1 text-[11.5px] text-ovs-crm-accent-coral-text/80">
                      {permissions.unreachable_gates.join(', ')}
                    </p>
                  </div>
                )}

                {permissions.held_by_no_role.length > 0 && (
                  <>
                    <p className="mb-1.5 text-[11px] font-bold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                      Held by no role
                    </p>
                    <p className="text-[12px] text-ovs-crm-text-secondary">
                      {permissions.held_by_no_role.join(', ')}
                    </p>
                  </>
                )}
              </CrmCard>
            </div>
          </div>
        </>
      )}

      {tab === 'activity' && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <CrmCard className="p-6">
            <div className="mb-4 flex items-start gap-3">
              <div className="min-w-0 flex-1">
                <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">Sign-ins</h2>
                <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">Most recent first</p>
              </div>
              <CrmCardIcon icon={LogIn} hue={HUE.blue} />
            </div>

            {logins.length === 0 ? (
              <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">No sign-ins recorded.</p>
            ) : (
              <ul className="space-y-1.5">
                {logins.map((entry) => (
                  <li
                    key={entry.id}
                    className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[14px] border border-ovs-crm-border px-3.5 py-2"
                  >
                    <span className="min-w-[130px] flex-1 text-[12.5px] text-ovs-crm-text-primary">
                      {entry.user ?? 'Unknown user'}
                    </span>
                    <span className="min-w-[110px] text-[11.5px] text-ovs-crm-text-muted">
                      {entry.browser ?? entry.device ?? '—'}
                    </span>
                    <span className="min-w-[100px] text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                      {entry.ip_address ?? '—'}
                    </span>
                    <CrmStatusPill tone={entry.status === 'success' ? 'good' : 'bad'}>
                      {entry.status ?? 'unknown'}
                    </CrmStatusPill>
                    <span className="text-[11px] tabular-nums text-ovs-crm-text-muted">{entry.login_time}</span>
                  </li>
                ))}
              </ul>
            )}
          </CrmCard>

          <CrmCard className="p-6">
            <div className="mb-4 flex items-start gap-3">
              <div className="min-w-0 flex-1">
                <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  Live sessions
                </h2>
                <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                  {/* Revoking one is an act with consequences and no backend supports it. */}
                  {sessions.signed_in ?? 0} signed in of {sessions.total} · read-only
                </p>
              </div>
              <CrmCardIcon icon={Monitor} hue={HUE.violet} />
            </div>

            {! sessions.supported || sessions.rows.length === 0 ? (
              <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">No sessions recorded.</p>
            ) : (
              <ul className="space-y-1.5">
                {sessions.rows.slice(0, 12).map((session) => (
                  <li
                    key={session.id}
                    className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[14px] border border-ovs-crm-border px-3.5 py-2"
                  >
                    <span className="min-w-[130px] flex-1 text-[12.5px] text-ovs-crm-text-primary">
                      {session.user ?? <span className="text-ovs-crm-text-muted">not signed in</span>}
                    </span>
                    <span className="min-w-[100px] text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                      {session.ip_address ?? '—'}
                    </span>
                    <span className="text-[11px] tabular-nums text-ovs-crm-text-muted">{session.last_activity}</span>
                  </li>
                ))}
              </ul>
            )}
          </CrmCard>
        </div>
      )}
    </AdministrationShell>
  );
}
