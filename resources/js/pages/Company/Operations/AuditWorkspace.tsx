import React from 'react';
import { Head, router } from '@inertiajs/react';
import { Lock, ScrollText, Users } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmDataState,
} from '@/pages/Company/design';
import { AdministrationShell } from '@/pages/Company/design';

/**
 * Operations → Audit
 *
 * Read-only, and there is no write route behind it: a history that can be
 * corrected is not a history.
 *
 * It creates no new audit store. Everything comes from the tables that already
 * record it — `activities` for the CRM and account timeline,
 * `company_employee_activities` for HQ staff events — shown together rather
 * than copied into a fourth place that would start disagreeing with the three
 * that came first.
 */

const HUE = { violet: '#7C6FE0', blue: '#2F6BE4' } as const;

interface Entry {
  id: string;
  source: string;
  subject_type: string | null;
  subject_id: number | string | null;
  type: string | null;
  description: string | null;
  actor: string | null;
  at: string | null;
  at_label: string | null;
}

interface Props {
  entries: Entry[];
  filters: Record<string, string | undefined>;
  facets: { types: string[]; subjects: string[] };
  totals: { activities: number; employee_events: number; showing: number };
}

export default function AuditWorkspace({ entries, filters, facets, totals }: Props) {
  const apply = (changes: Record<string, string | undefined>) =>
    router.get('/company/audit', { ...filters, ...changes }, { preserveState: true, preserveScroll: true, replace: true });

  return (
    <AdministrationShell title="Audit">
      <Head title="Audit | Customer Operations" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Customer Operations
          </p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Audit
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            What happened, who did it, and when — from the records that already hold it
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={ScrollText} value={totals.activities} caption="Account events" />
          <CrmPageStat icon={Users} value={totals.employee_events} caption="Employee events" />
          <CrmPageStat icon={Lock} value={totals.showing} caption="Showing" />
        </div>
      </div>

      <CrmCard className="mb-4 flex items-start gap-3 px-5 py-4">
        <CrmCardIcon icon={Lock} hue={HUE.violet} />
        <div>
          <p className="text-[13px] font-semibold text-ovs-crm-text-primary">This record cannot be edited</p>
          {/* No write route exists behind this page at all. */}
          <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
            There is no route to change or remove an entry. A history that can be corrected is not a history.
          </p>
        </div>
      </CrmCard>

      <CrmCard className="mb-3 flex flex-wrap items-end gap-3 px-5 py-3.5">
        <div className="min-w-[200px] flex-1">
          <CrmFormField label="Search">
            <CrmInput
              type="search"
              placeholder="Description or type"
              defaultValue={filters.search ?? ''}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  apply({ search: (e.target as HTMLInputElement).value || undefined });
                }
              }}
            />
          </CrmFormField>
        </div>

        <div className="min-w-[160px]">
          <CrmFormField label="Source">
            <CrmSelect value={filters.source ?? ''} onChange={(e) => apply({ source: e.target.value || undefined })}>
              <option value="">Both sources</option>
              <option value="activity">Accounts &amp; deals</option>
              <option value="employee">Employees</option>
            </CrmSelect>
          </CrmFormField>
        </div>

        <div className="min-w-[160px]">
          <CrmFormField label="Type">
            <CrmSelect value={filters.type ?? ''} onChange={(e) => apply({ type: e.target.value || undefined })}>
              <option value="">Any type</option>
              {facets.types.map((type) => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </div>

        <div className="min-w-[160px]">
          <CrmFormField label="Subject">
            <CrmSelect
              value={filters.subject_type ?? ''}
              onChange={(e) => apply({ subject_type: e.target.value || undefined })}
            >
              <option value="">Any subject</option>
              {facets.subjects.map((subject) => (
                <option key={subject} value={subject}>
                  {subject}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </div>

        <CrmButton onClick={() => router.get('/company/audit', {}, { preserveState: false })}>Clear</CrmButton>
      </CrmCard>

      {entries.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={ScrollText}
          title="Nothing recorded here yet"
          description="Entries appear as work happens across accounts, deals and the employee directory."
        />
      ) : (
        <div className="space-y-2">
          {entries.map((entry) => (
            <CrmCard key={entry.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
              <span className="min-w-[130px] text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                {entry.at_label ?? '—'}
              </span>

              <CrmStatusPill tone={entry.source === 'employee' ? 'info' : 'neutral'}>
                {entry.type ?? entry.source}
              </CrmStatusPill>

              <span className="min-w-[200px] flex-1 text-[12.5px] text-ovs-crm-text-primary">
                {entry.description ?? '—'}
              </span>

              <span className="min-w-[140px] text-[11.5px] text-ovs-crm-text-muted">
                {entry.subject_type}
                {entry.subject_id ? ` #${entry.subject_id}` : ''}
              </span>

              <span className="min-w-[110px] text-right text-[11.5px] text-ovs-crm-text-secondary">
                {entry.actor ?? <span className="text-ovs-crm-text-muted">system</span>}
              </span>
            </CrmCard>
          ))}
        </div>
      )}
    </AdministrationShell>
  );
}
