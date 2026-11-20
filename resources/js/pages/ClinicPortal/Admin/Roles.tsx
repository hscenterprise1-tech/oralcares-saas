import { useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Lock, RotateCcw, Search, ShieldCheck } from 'lucide-react';
import { useOwnerModeStepUp } from '@/clinic/ownerModeStepUp';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Input, Switch } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { cn } from '@/lib/utils';

interface Cell {
  granted: boolean;
  default: boolean;
  changed: boolean;
}

interface Item {
  permission: string;
  label: string;
  description: string;
  sensitive: boolean;
  locked: boolean;
  roles: Record<string, Cell>;
}

/** `RolePermissionController::index`: the server is the contract. */
interface Props {
  roles: { name: string; changes: number; seat: 'doctor' | 'staff' }[];
  areas: { key: string; label: string; items: Item[] }[];
  links: { base: string };
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';

export default function Roles(props: Props) {
  return (
    <ClinicPortalShell title="Roles & permissions">
      <RolesPage {...props} />
    </ClinicPortalShell>
  );
}

/** What each role may do at this clinic. The owner decides; the standard is the starting point, and every change is recorded. */
function RolesPage({ roles, areas, links }: Props) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const stepUp = useOwnerModeStepUp();
  const [search, setSearch] = useState('');
  const [resetting, setResetting] = useState<string | null>(null);
  const url = (role: string, tail: string) => `${links.base}/${encodeURIComponent(role)}/${tail}`;
  const totalChanges = roles.reduce((n, r) => n + r.changes, 0);

  const shown = useMemo(
    () =>
      areas
        .map((area) => ({ ...area, items: area.items.filter((i) => search.trim() === '' || `${i.label} ${i.description}`.toLowerCase().includes(search.trim().toLowerCase())) }))
        .filter((area) => area.items.length > 0),
    [areas, search],
  );

  const toggle = (role: string, item: Item, granted: boolean) => stepUp.submit({ method: 'put', url: url(role, 'permissions'), data: { permission: item.permission, granted } });

  return (
    <div className="w-full pb-12">
      <PageHeader
        icon={ShieldCheck}
        title="Roles & permissions"
        description={totalChanges > 0 ? `${totalChanges} ${totalChanges === 1 ? 'change' : 'changes'} from the standard roles at this clinic` : 'Every role follows the standard'}
      />
      <Notice tone="info" className="mb-4">
        You always have every permission as the owner. A change here applies to this clinic only, is recorded, and takes effect the next time the person opens a page. Giving
        or taking away a permission may ask for your password.
      </Notice>
      {errors.permission && <Notice tone="danger" className="mb-4">{errors.permission}</Notice>}

      <div className={cn(SURFACE, 'mb-4 p-4')}>
        <div className="relative max-w-sm">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-cx-text-3" aria-hidden="true" />
          <Input aria-label="Search permissions" placeholder="Search what a role may do" value={search} onChange={(e) => setSearch(e.target.value)} className="pl-9" />
        </div>
      </div>

      <div className={cn(SURFACE, 'overflow-x-auto')}>
        <table className="w-full min-w-[820px] text-left text-[13px]">
          <caption className="sr-only">What each role may do</caption>
          <thead>
            <tr className="border-b border-cx-line/70 align-bottom">
              <th scope="col" className="sticky left-0 z-10 bg-white px-5 py-3 text-[11.5px] font-semibold uppercase tracking-[0.05em] text-cx-text-3">Permission</th>
              {roles.map((role) => (
                <th key={role.name} scope="col" className="px-3 py-3 text-center">
                  <p className="text-[13px] font-semibold normal-case tracking-normal text-cx-nav-ink">{role.name}</p>
                  <p className="text-[11px] font-normal normal-case tracking-normal text-cx-text-3">{role.seat === 'doctor' ? 'Doctor seat' : 'Staff seat'}</p>
                  {role.changes > 0 && (
                    <button type="button" onClick={() => setResetting(role.name)} className="mt-1 inline-flex items-center gap-1 text-[11.5px] font-semibold normal-case tracking-normal text-[#1769AA] hover:underline">
                      <RotateCcw className="h-3 w-3" aria-hidden="true" /> Reset ({role.changes})
                    </button>
                  )}
                </th>
              ))}
            </tr>
          </thead>
          {shown.map((area) => (
            <tbody key={area.key} className="divide-y divide-cx-line/60">
              <tr className="bg-cx-slate-bg/60">
                <th colSpan={roles.length + 1} scope="colgroup" className="sticky left-0 px-5 py-2 text-[12px] font-bold uppercase tracking-[0.06em] text-cx-nav-teal-strong">{area.label}</th>
              </tr>
              {area.items.map((item) => (
                <tr key={item.permission}>
                  <th scope="row" className="sticky left-0 z-10 min-w-[260px] bg-white px-5 py-3 align-top font-normal">
                    <p className="flex flex-wrap items-center gap-2 font-semibold text-cx-nav-ink">
                      {item.label}
                      {item.sensitive && <span className="rounded-full bg-cx-amber-bg px-2 py-0.5 text-[10.5px] font-semibold text-cx-amber-ink">Sensitive</span>}
                      {item.locked && <span className="inline-flex items-center gap-1 rounded-full bg-cx-slate-bg px-2 py-0.5 text-[10.5px] font-semibold text-cx-slate-ink"><Lock className="h-3 w-3" aria-hidden="true" /> Owner only</span>}
                    </p>
                    <p className="text-[12px] text-cx-text-3">{item.description}</p>
                  </th>
                  {roles.map((role) => {
                    const cell = item.roles[role.name];

                    return (
                      <td key={role.name} className="px-3 py-3 text-center align-top">
                        <div className="flex flex-col items-center gap-1">
                          <Switch
                            aria-label={`${item.label} for ${role.name}`}
                            checked={cell.granted}
                            disabled={item.locked || stepUp.busy}
                            onCheckedChange={(on) => toggle(role.name, item, on)}
                          />
                          {cell.changed && <span className="text-[10.5px] font-semibold text-[#B54708]">Standard: {cell.default ? 'on' : 'off'}</span>}
                        </div>
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          ))}
        </table>
        {shown.length === 0 && <p className="px-5 py-8 text-center text-[13px] text-cx-text-2">Nothing matches your search.</p>}
      </div>

      {resetting && (
        <Modal
          open
          onOpenChange={(open) => !open && setResetting(null)}
          size="sm"
          title={`Put ${resetting} back to the standard?`}
          description="Every change you made to this role at your clinic is undone."
          footer={
            <>
              <Button onClick={() => setResetting(null)} disabled={stepUp.busy}>Cancel</Button>
              <Button tone="danger" disabled={stepUp.busy} onClick={() => stepUp.submit({ method: 'post', url: url(resetting, 'reset'), onSuccess: () => setResetting(null) })}>Reset</Button>
            </>
          }
        >
          <p className="text-[13px] text-cx-text-2">People with this role may lose permissions you gave them, or regain ones you took away.</p>
        </Modal>
      )}
      {stepUp.dialog}
    </div>
  );
}
