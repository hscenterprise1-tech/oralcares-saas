import React from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, Plus, Tags } from 'lucide-react';
import {
  CrmCard,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmDataState,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * People → Leave types
 *
 * Nothing is seeded. The clinic migration once carried a comment naming
 * "casual, sick, vacation, emergency, custom" — a comment, never an enum, never
 * validated, and never this company's decision. What leave is offered is
 * defined here or not at all.
 *
 * `Paid` is configuration that changes no figure anywhere today. It is the seam
 * payroll will read when somebody decides unpaid leave should affect pay.
 */

interface TypeRow {
  id: number;
  code: string;
  name: string;
  description: string | null;
  is_paid: boolean;
  requires_approval: boolean;
  allows_half_day: boolean;
  is_active: boolean;
  sort_order: number;
  colour: string | null;
  requests_count: number;
}

interface Props {
  types: TypeRow[];
  can: { manage: boolean };
}

const BLANK = {
  open: false,
  id: null as number | null,
  code: '',
  name: '',
  description: '',
  is_paid: '1',
  requires_approval: '1',
  allows_half_day: '1',
  is_active: '1',
  sort_order: '0',
  colour: '#7C6FE0',
};

export default function LeaveTypes({ types, can }: Props) {
  const [form, setForm] = React.useState(BLANK);

  const openCreate = () => setForm({ ...BLANK, open: true });

  const openEdit = (type: TypeRow) =>
    setForm({
      open: true,
      id: type.id,
      code: type.code,
      name: type.name,
      description: type.description ?? '',
      is_paid: type.is_paid ? '1' : '0',
      requires_approval: type.requires_approval ? '1' : '0',
      allows_half_day: type.allows_half_day ? '1' : '0',
      is_active: type.is_active ? '1' : '0',
      sort_order: String(type.sort_order),
      colour: type.colour ?? '#7C6FE0',
    });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();

    const body = {
      code: form.code,
      name: form.name,
      description: form.description || null,
      is_paid: form.is_paid === '1',
      requires_approval: form.requires_approval === '1',
      allows_half_day: form.allows_half_day === '1',
      is_active: form.is_active === '1',
      sort_order: Number(form.sort_order) || 0,
      colour: form.colour || null,
    };

    const done = { preserveScroll: true, onSuccess: () => setForm(BLANK) };

    if (form.id) {
      router.put(`/company/people/leave-types/${form.id}`, body, done);

      return;
    }

    router.post('/company/people/leave-types', body, done);
  };

  return (
    <PeopleShell title="Leave types">
      <Head title="Leave types | People" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            People · Leave
          </p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Leave types
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            What this company offers. Nothing is assumed on your behalf.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={Tags} value={types.length} caption="Types defined" />
          <CrmPageStat icon={Tags} value={types.filter((type) => type.is_active).length} caption="Active" />
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <CrmButton size="sm" icon={ArrowLeft} href="/company/people/leave">
          Leave
        </CrmButton>
        {can.manage && (
          <CrmButton tone="primary" icon={Plus} onClick={openCreate}>
            Add leave type
          </CrmButton>
        )}
      </div>

      {types.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={Tags}
          title="No leave types defined"
          description="Nothing is seeded on purpose — what leave this company offers is yours to decide. Leave cannot be requested until at least one exists."
        />
      ) : (
        <div className="space-y-2">
          {types.map((type) => (
            <CrmCard key={type.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
              <span
                className="h-8 w-1.5 shrink-0 rounded-full"
                style={{ background: type.colour ?? '#7C6FE0' }}
                aria-hidden
              />

              <span className="min-w-[180px] flex-1">
                <span className="flex flex-wrap items-center gap-2">
                  <span className="text-[13.5px] font-medium text-ovs-crm-text-primary">{type.name}</span>
                  <span className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">{type.code}</span>
                  {!type.is_active && <CrmStatusPill tone="neutral">Retired</CrmStatusPill>}
                </span>
                {type.description && (
                  <span className="mt-0.5 block text-[11.5px] text-ovs-crm-text-muted">{type.description}</span>
                )}
              </span>

              <span className="flex flex-wrap gap-1.5">
                <CrmStatusPill tone={type.is_paid ? 'good' : 'neutral'}>{type.is_paid ? 'Paid' : 'Unpaid'}</CrmStatusPill>
                {type.allows_half_day && <CrmStatusPill tone="info">Half days</CrmStatusPill>}
                {type.requires_approval && <CrmStatusPill tone="warn">Needs approval</CrmStatusPill>}
              </span>

              <span className="min-w-[90px] text-right text-[12px] tabular-nums text-ovs-crm-text-secondary">
                {type.requests_count} {type.requests_count === 1 ? 'request' : 'requests'}
              </span>

              {can.manage && (
                <CrmButton size="sm" onClick={() => openEdit(type)}>
                  Edit
                </CrmButton>
              )}
            </CrmCard>
          ))}
        </div>
      )}

      <CrmModal
        open={form.open}
        onClose={() => setForm(BLANK)}
        title={form.id ? 'Edit leave type' : 'Add a leave type'}
        description="Paid or unpaid is recorded here and read by nothing yet — it is the seam payroll will use later."
        footer={
          <>
            <CrmButton onClick={() => setForm(BLANK)}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="leave-type-form">
              {form.id ? 'Save' : 'Add type'}
            </CrmButton>
          </>
        }
      >
        <form id="leave-type-form" onSubmit={submit} className="flex flex-col gap-3.5">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="Name" required>
              <CrmInput required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </CrmFormField>

            <CrmFormField label="Code" required hint="Short and unique.">
              <CrmInput required value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
            </CrmFormField>
          </div>

          <CrmFormField label="Description">
            <CrmInput value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </CrmFormField>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="Paid" required>
              <CrmSelect value={form.is_paid} onChange={(e) => setForm({ ...form, is_paid: e.target.value })}>
                <option value="1">Paid</option>
                <option value="0">Unpaid</option>
              </CrmSelect>
            </CrmFormField>

            <CrmFormField label="Half days" required>
              <CrmSelect value={form.allows_half_day} onChange={(e) => setForm({ ...form, allows_half_day: e.target.value })}>
                <option value="1">Allowed</option>
                <option value="0">Whole days only</option>
              </CrmSelect>
            </CrmFormField>

            <CrmFormField label="Approval" required>
              <CrmSelect value={form.requires_approval} onChange={(e) => setForm({ ...form, requires_approval: e.target.value })}>
                <option value="1">Needs approval</option>
                <option value="0">No approval needed</option>
              </CrmSelect>
            </CrmFormField>

            <CrmFormField label="Status" required>
              <CrmSelect value={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.value })}>
                <option value="1">Active</option>
                <option value="0">Retired</option>
              </CrmSelect>
            </CrmFormField>

            <CrmFormField label="Colour">
              <CrmInput type="color" value={form.colour} onChange={(e) => setForm({ ...form, colour: e.target.value })} />
            </CrmFormField>

            <CrmFormField label="Sort order" hint="Lower shows first.">
              <CrmInput
                type="number"
                min={0}
                value={form.sort_order}
                onChange={(e) => setForm({ ...form, sort_order: e.target.value })}
              />
            </CrmFormField>
          </div>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
