import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Activity, CalendarCheck, CalendarClock, ClipboardList, Download, Eye, FileImage, FileText, FlaskConical, HeartPulse, History, ListChecks, Package, Receipt, Stethoscope, Wallet } from 'lucide-react';
import type { Patient360Props, Patient360Zone, VisitsPage } from '../../types';
import { useEndpoint } from '../store';
import { BTN_SM, Card, Empty, FilterSelect, Facts, Label, Meter, Pending, Pill, Row, Split, ViewAll, day, humanize, money, when, type Paged, type Tone } from '../ui';
import { CATEGORY_LABEL, CATEGORY_TONE } from './TimelineZone';
import { Appointments, FollowUps, visitStatus } from './VisitsZone';

type TimelineRow = { id: number; category: string; title: string; description: string | null; at: string | null; actor: string | null; open_url: string | null };
type BillingSnapshot = { scope?: 'own_fees'; data: { id: number; number: string | null; status: string | null; date: string | null; total: number; outstanding: number | null }[]; payments: { receipt: string | null; amount: number; method: string | null; date: string | null }[] };
type NextItem = { icon: typeof CalendarClock; tone: Tone; title: string; meta: string; url?: string | null };

const CARE_TONE: Record<string, Tone> = { planned: 'slate', active: 'blue', in_progress: 'blue', paused: 'amber', completed: 'mint' };
const CONSULTATION_TONE: Record<string, Tone> = { signed: 'mint', open: 'amber', voided: 'rose' };
/** How many entries a summary card shows before "View all". */
const CARE_SHOWN = 4;
const NEXT_SHOWN = 5;

function More({ count, onClick }: { count: number; onClick?: () => void }) {
  if (count <= 0) {
    return null;
  }

  return onClick ? (
    <button type="button" onClick={onClick} className="mt-3 w-full rounded-xl bg-[#F6F8FC] py-2 text-[12.5px] font-semibold text-cx-primary hover:bg-[#EEF3FA]">
      +{count} more · View all
    </button>
  ) : (
    <p className="mt-3 rounded-xl bg-[#F6F8FC] py-2 text-center text-[12.5px] text-cx-text-2">+{count} more</p>
  );
}

/**
 * Overview (zone spec §1): the patient's situation at a glance, in bands that
 * line up — care under way beside what comes next, the latest visit beside
 * upcoming appointments, recent activity beside follow-ups, then documents
 * and money. Every card is built from the server's own records and opens its
 * zone with "View all"; a card the member may not see is not drawn and its
 * partner takes the full width.
 */
export function OverviewZone({ props, has, go }: { props: Patient360Props; has: (zone: Patient360Zone['key']) => boolean; go: (zone: Patient360Zone['key']) => void }) {
  const { overview, endpoints, zones, practitioners } = props;
  const open = (key: string) => zones.some((zone) => zone.sections.includes(key));
  const visits = useEndpoint<VisitsPage>(open('visits') ? endpoints.visits : null);
  const [activityCategory, setActivityCategory] = useState('all');
  const timelineBase = useEndpoint<Paged<TimelineRow> & { categories: string[] }>(open('timeline') ? endpoints.timeline : null);
  const timeline = useEndpoint<Paged<TimelineRow>>(open('timeline') ? endpoints.timeline : null, activityCategory === 'all' ? undefined : { category: activityCategory });
  const activityCategories = timelineBase.data?.categories ?? [];
  const finance = overview?.summary.finance ?? null;
  const billing = useEndpoint<BillingSnapshot>(finance && open('billing') ? endpoints.billing : null);
  const view = (zone: Patient360Zone['key']) => (has(zone) ? <ViewAll onClick={() => go(zone)} /> : null);
  const care = overview?.current.active_care ?? [];
  const recent = overview?.recent;
  const next = overview?.next;
  const latest = (visits.data?.data ?? []).find((row) => row.kind === 'visit') ?? null;
  const documents = overview?.documents ?? null;
  const physio = overview?.physio_care ?? null;
  const lastPayment = billing.data?.scope ? null : (billing.data?.payments[0] ?? null);
  const latestBill = billing.data?.scope ? null : (billing.data?.data[0] ?? null);

  const nextItems: NextItem[] = !next
    ? []
    : ([
        next.appointment && { icon: CalendarClock, tone: 'blue', title: 'Next appointment', meta: [next.appointment.is_today ? 'Today' : next.appointment.date_label, next.appointment.time_label, next.appointment.practitioner].filter(Boolean).join(' · ') },
        next.follow_up && { icon: CalendarCheck, tone: next.follow_up.overdue ? 'rose' : 'amber', title: next.follow_up.overdue ? 'Follow-up overdue' : 'Next follow-up', meta: [day(next.follow_up.due_on), next.follow_up.reason, next.follow_up.practitioner].filter(Boolean).join(' · ') },
        overview?.current.open_consultation && { icon: Stethoscope, tone: 'violet', title: 'Consultation still open', meta: overview.current.open_consultation.encounter_number ?? 'Waiting to be signed' },
        ...next.investigations.map((item) => ({ icon: FlaskConical, tone: 'teal' as Tone, title: 'Result awaited', meta: [item.name, humanize(item.status)].join(' · '), url: item.url })),
        ...(finance ? next.payments : []).map((item) => ({ icon: Wallet, tone: 'amber' as Tone, title: 'Payment due', meta: `${item.number ?? 'Bill'} · ${money(item.outstanding)}` })),
        ...care.filter((item) => item.next_stage && item.next_stage.name !== item.title).map((item) => ({ icon: ListChecks, tone: 'violet' as Tone, title: item.next_stage!.name, meta: `Next step in ${item.title}` })),
      ].filter(Boolean) as NextItem[]);

  const currentCare = (
    <Card title="Current care" caption={care.length ? `${care.length} treatments under way` : 'Treatments under way'} icon={HeartPulse} tone="violet" action={view('cases')}>
      {!overview ? (
        <Pending error={null} />
      ) : care.length === 0 ? (
        <Empty>No treatment under way.</Empty>
      ) : (
        <>
          <ul className="grid auto-rows-fr gap-3 md:grid-cols-2">
            {care.slice(0, CARE_SHOWN).map((item) => (
              <li key={item.id} className="flex h-full flex-col rounded-2xl border border-[#EDF1F7] bg-gradient-to-br from-white to-[#F7F5FF] p-4">
                <div className="flex min-h-10 items-start justify-between gap-2">
                  <p className="line-clamp-2 text-[14px] font-semibold leading-5 text-cx-nav-ink [overflow-wrap:anywhere]" title={item.title}>
                    {item.title}
                  </p>
                  <Pill tone={CARE_TONE[item.status] ?? 'slate'}>{humanize(item.status)}</Pill>
                </div>
                <div className="mt-3">
                  <Facts items={[['Practitioner', item.practitioner?.name ?? '—'], ['Started', day(item.started_at) ?? '—']]} />
                </div>
                <div className="mt-3">
                  {item.progress.total ? (
                    <Meter done={item.progress.done} total={item.progress.total} label={`${item.progress.done} of ${item.progress.total} ${item.progress.unit}`} />
                  ) : (
                    <p className="text-[11.5px] text-cx-text-3">{item.progress.done} {item.progress.unit} done</p>
                  )}
                </div>
                {has('cases') && (
                  <div className="mt-auto pt-3">
                    <button type="button" onClick={() => go('cases')} className={BTN_SM}>
                      Open details
                    </button>
                  </div>
                )}
              </li>
            ))}
          </ul>
          <More count={care.length - CARE_SHOWN} onClick={has('cases') ? () => go('cases') : undefined} />
        </>
      )}
    </Card>
  );

  const nextInCare = (
    <Card title="Next in care" caption="Saved bookings, follow-ups and pending steps" icon={ClipboardList} tone="amber">
      {!overview ? (
        <Pending error={null} />
      ) : nextItems.length === 0 ? (
        <Empty>Nothing pending.</Empty>
      ) : (
        <>
          <ul className="space-y-2">
            {nextItems.slice(0, NEXT_SHOWN).map((item, index) => (
              <li key={index}>
                <Row icon={item.icon} tone={item.tone} title={item.title} meta={item.meta} aside={item.url ? <Link href={item.url} className={BTN_SM}>Open</Link> : undefined} />
              </li>
            ))}
          </ul>
          <More count={nextItems.length - NEXT_SHOWN} />
        </>
      )}
    </Card>
  );

  const latestVisit = open('visits') ? (
    <Card title="Latest visit" icon={CalendarCheck} tone="teal" action={view('visits')}>
      {!visits.data ? (
        <Pending error={visits.error} onRetry={visits.reload} />
      ) : !latest || latest.kind !== 'visit' ? (
        <Empty>No visits yet.</Empty>
      ) : (
        <div className="flex h-full flex-col gap-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-[16px] font-semibold text-cx-nav-ink">{[day(latest.date), latest.time_label].filter(Boolean).join(' · ')}</p>
            <span className="flex flex-wrap gap-1.5">
              {latest.registration_type && <Pill tone={latest.registration_type === 'new' ? 'blue' : 'violet'}>{latest.registration_type === 'new' ? 'New' : 'Repeat'}</Pill>}
              <Pill tone={visitStatus(latest.status)[1]}>{visitStatus(latest.status)[0]}</Pill>
              {latest.consultation && <Pill tone={CONSULTATION_TONE[latest.consultation.status] ?? 'slate'}>Consultation {latest.consultation.status}</Pill>}
            </span>
          </div>
          <Facts columns={3} items={[['Practitioner', latest.practitioner ?? '—'], ['Branch', latest.branch ?? '—'], ['Visit type', latest.visit_type ?? '—']]} />
          {latest.reason && (
            <p className="rounded-xl bg-cx-teal-bg/40 px-3 py-2 text-[13px] text-cx-text [overflow-wrap:anywhere]">
              <b className="text-cx-teal-ink">Reason:</b> {latest.reason}
            </p>
          )}
          {(recent?.consultation || recent?.procedure) && (
            <div className="grid gap-2 sm:grid-cols-2">
              {recent?.consultation && (
                <div className="rounded-xl bg-[#F6F8FC] px-3 py-2.5 text-[12.5px]">
                  <Label>Latest consultation · {day(recent.consultation.date)}</Label>
                  <p className="font-semibold text-cx-nav-ink">{recent.consultation.diagnosis ?? 'No diagnosis recorded'}</p>
                  {recent.consultation.complaint && <p className="line-clamp-2 text-cx-text-2 [overflow-wrap:anywhere]">{recent.consultation.complaint}</p>}
                </div>
              )}
              {recent?.procedure && (
                <div className="rounded-xl bg-[#F6F8FC] px-3 py-2.5 text-[12.5px]">
                  <Label>Latest procedure · {day(recent.procedure.date)}</Label>
                  <p className="font-semibold text-cx-nav-ink">
                    {recent.procedure.name}
                    {recent.procedure.tooth ? ` · tooth ${recent.procedure.tooth}` : ''}
                  </p>
                </div>
              )}
            </div>
          )}
          <div className="mt-auto flex flex-wrap gap-2 pt-1">
            {has('visits') && (
              <button type="button" className={BTN_SM} onClick={() => go('visits')}>
                View visit
              </button>
            )}
            {(latest.consultation?.url ?? recent?.consultation?.url) && (
              <Link href={(latest.consultation?.url ?? recent?.consultation?.url) as string} className={BTN_SM}>
                Open consultation
              </Link>
            )}
          </div>
        </div>
      )}
    </Card>
  ) : null;

  const activity = open('timeline') ? (
    <Card
      title="Recent activity"
      caption="Latest events on the record"
      icon={History}
      tone="violet"
      action={
        <span className="flex items-center gap-1">
          {activityCategories.length > 1 && (
            <FilterSelect
              label="Show activity"
              value={activityCategory}
              onChange={setActivityCategory}
              options={[{ value: 'all', label: 'All activity' }, ...activityCategories.map((value) => ({ value, label: CATEGORY_LABEL[value] ?? humanize(value), tone: CATEGORY_TONE[value] }))]}
            />
          )}
          {view('timeline')}
        </span>
      }
    >
      {!timeline.data ? (
        <Pending error={timeline.error} onRetry={timeline.reload} />
      ) : timeline.data.data.length === 0 ? (
        <Empty>{activityCategory === 'all' ? 'Nothing recorded yet.' : 'Nothing of this kind yet.'}</Empty>
      ) : (
        <ol aria-busy={timeline.loading} className="relative space-y-1 transition-opacity aria-busy:opacity-60 before:absolute before:bottom-4 before:left-[13px] before:top-4 before:w-px before:bg-[#E3E8F2]">
          {timeline.data.data.slice(0, 12).map((row) => (
            <li key={row.id} className="relative flex items-start gap-3 py-2">
              <span className="relative z-10 mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-white ring-1 ring-[#E3E8F2]" aria-hidden="true">
                <span className="h-2 w-2 rounded-full bg-[#6B48DE]" />
              </span>
              <div className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                <div className="min-w-0">
                  <p className="text-[13.5px] font-semibold text-cx-nav-ink">{row.title}</p>
                  <p className="line-clamp-2 text-[12px] text-cx-text-2 [overflow-wrap:anywhere]">{[row.description, row.actor ? `by ${row.actor}` : null].filter(Boolean).join(' · ')}</p>
                </div>
                <span className="flex shrink-0 items-center gap-2 text-[11.5px] text-cx-text-3 sm:w-[190px] sm:justify-end">
                  {when(row.at)}
                  {row.open_url && (
                    <Link href={row.open_url} className="font-semibold text-cx-primary hover:underline">
                      Open
                    </Link>
                  )}
                </span>
              </div>
            </li>
          ))}
        </ol>
      )}
    </Card>
  ) : null;

  const appointments = open('appointments') && endpoints.appointments ? <Appointments key="appointments" endpoint={endpoints.appointments} compact action={view('visits')} /> : null;
  const followUps = open('follow-ups') && endpoints['follow-ups'] ? <FollowUps key="follow-ups" endpoint={endpoints['follow-ups'] as string} practitioners={practitioners} compact action={view('visits')} /> : null;

  const recentDocuments =
    documents !== null ? (
      <Card key="documents" title="Recent documents" icon={FileText} tone="blue" action={view('docs')}>
        {documents.length === 0 ? (
          <Empty>No documents yet.</Empty>
        ) : (
          <ul className="space-y-2">
            {documents.slice(0, 5).map((doc) => (
              <li key={doc.id}>
                <Row
                  icon={/image|jpg|jpeg|png|webp/i.test(doc.file_type) ? FileImage : FileText}
                  tone="blue"
                  title={
                    <span className="block truncate" title={doc.name}>
                      {doc.name}
                    </span>
                  }
                  meta={[doc.category, day(doc.date), doc.uploaded_by].filter(Boolean).join(' · ')}
                  aside={
                    <>
                      {doc.preview_url && (
                        <a href={doc.preview_url} target="_blank" rel="noopener" className={BTN_SM} aria-label={`Preview ${doc.name}`}>
                          <Eye className="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                      )}
                      {doc.download_url && (
                        <a href={doc.download_url} className={BTN_SM} aria-label={`Download ${doc.name}`}>
                          <Download className="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                      )}
                    </>
                  }
                />
              </li>
            ))}
          </ul>
        )}
      </Card>
    ) : null;

  const billingCard = finance ? (
    <Card key="billing" title="Billing" icon={Wallet} tone="mint" action={view('billing')}>
      <div className="flex h-full flex-col">
        <dl className="grid grid-cols-2 gap-2">
          {(
            [
              ['Billed', money(finance.billed), 'text-cx-nav-ink'],
              ['Paid', money(finance.paid), 'text-cx-green-ink'],
              ['Outstanding', money(finance.outstanding), finance.outstanding > 0 ? 'text-cx-amber-ink' : 'text-cx-nav-ink'],
              ['Bills', String(finance.bills), 'text-cx-nav-ink'],
            ] as const
          ).map(([label, value, ink]) => (
            <div key={label} className="rounded-xl bg-[#F6F8FC] px-3 py-2">
              <dt className="text-[11px] font-medium text-cx-text-3">{label}</dt>
              <dd className={`text-[15px] font-semibold tabular-nums ${ink}`}>{value}</dd>
            </div>
          ))}
        </dl>
        {(lastPayment || latestBill) && (
          <ul className="mt-3 space-y-2">
            {lastPayment && (
              <li>
                <Row icon={Wallet} tone="mint" title={`Last payment ${money(lastPayment.amount)}`} meta={[day(lastPayment.date), lastPayment.method, lastPayment.receipt].filter(Boolean).join(' · ')} />
              </li>
            )}
            {latestBill && (
              <li>
                <Row icon={Receipt} tone="blue" title={`Latest bill ${latestBill.number ?? ''}`} meta={[day(latestBill.date), money(latestBill.total), latestBill.outstanding ? `${money(latestBill.outstanding)} due` : null].filter(Boolean).join(' · ')} />
              </li>
            )}
          </ul>
        )}
        {props.links.bills && (
          <div className="mt-auto pt-3">
            <Link href={props.links.bills} className={BTN_SM}>
              Open Billing
            </Link>
          </div>
        )}
      </div>
    </Card>
  ) : null;

  const physioCard =
    physio && physio.treatments.length > 0 ? (
      <Card title="Physio care" caption="Sessions done and package sessions left — counted apart" icon={Activity} tone="violet" action={view('clinical')}>
        <ul className="grid auto-rows-fr gap-3 md:grid-cols-2 xl:grid-cols-3">
          {physio.treatments.map((treatment) => {
            const pack = treatment.packages[0];

            return (
              <li key={treatment.id} className="flex h-full flex-col rounded-2xl border border-[#EDF1F7] bg-[#FBFCFE] p-4">
                <div className="flex min-h-10 items-start justify-between gap-2">
                  <p className="line-clamp-2 text-[14px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{treatment.title}</p>
                  <Pill tone={CARE_TONE[treatment.status] ?? 'slate'}>{humanize(treatment.status)}</Pill>
                </div>
                <div className="mt-3">
                  {treatment.sessions_planned !== null ? (
                    <Meter done={treatment.sessions_completed} total={treatment.sessions_planned} label={`${treatment.sessions_completed} of ${treatment.sessions_planned} sessions done`} />
                  ) : (
                    <p className="text-[12.5px] text-cx-text-2">{treatment.sessions_completed} sessions done</p>
                  )}
                </div>
                <div className="mt-auto pt-3">
                  {pack ? (
                    <p className="flex items-center gap-1.5 rounded-xl bg-cx-violet-bg/50 px-3 py-2 text-[12.5px] text-cx-violet-ink">
                      <Package className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                      <span className="min-w-0">
                        <b>{pack.name}</b> · {pack.remaining} of {pack.sessions_total} left{pack.valid_until ? ` · until ${day(pack.valid_until)}` : ''}
                      </span>
                    </p>
                  ) : (
                    <p className="text-[12px] text-cx-text-3">No package with sessions left.</p>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      </Card>
    ) : null;

  return (
    <div className="space-y-5">
      <Split main={currentCare} side={nextInCare} />
      <Split main={latestVisit} side={[appointments, followUps]} />
      {physioCard}
      <Split main={activity} side={[recentDocuments, billingCard]} />
    </div>
  );
}
