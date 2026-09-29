import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import {
  Select,
  SelectTrigger,
  SelectContent,
  SelectItem,
  SelectValue,
} from '@/pages/Company/design/CrmSelectPrimitives';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmCard,
  CrmSection,
  CrmButton,
  CrmInput,
  CrmDatePicker,
  CrmTextarea,
  CrmValue,
  CrmAvatar,
} from '@/pages/Company/design';
import { ArrowRight, MapPin, Save, Send, FileSpreadsheet, Tag as TagIcon, Building2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { LIFECYCLE } from '../Contacts/lifecycle';
import type { ImportBatchForDropdown, MarketingTag } from '../Contacts/types';
import { segmentContactsUrl } from './rules';
import type { SegmentPreviewContact, SegmentRules } from './types';

/**
 * The Segment builder — define reusable targeting, not browse a list.
 *
 * This is not the Contacts filter panel reappearing on a second page. It has
 * one job: turn a set of criteria into a name a campaign can reuse. Every
 * field here maps directly onto a Contacts filter (state/city/area, tags,
 * organisation, import batch, added-between, marketing status) so the two
 * screens can never disagree about what "matches" means — the count on this
 * page and the count in Contacts come from the exact same backend query.
 */

interface BuilderProps {
  audience?: {
    id: number;
    name: string;
    description: string | null;
    status: 'active' | 'paused';
    rules: SegmentRules;
  } | null;
  states: string[];
  cities: string[];
  areas: string[];
  tags: MarketingTag[];
  importBatches: ImportBatchForDropdown[];
}

interface PreviewResponse {
  count: number;
  preview: SegmentPreviewContact[];
  cities: string[];
  areas: string[];
}

/** A pill in a group of pills — the builder's one selectable control shape. */
function Pill({
  on,
  hue,
  onClick,
  children,
}: {
  on: boolean;
  hue?: string;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={on}
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12.5px] font-medium transition-colors',
        on
          ? 'bg-ovs-crm-ink text-white'
          : 'bg-ovs-crm-surface-inset text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary'
      )}
    >
      {hue && (
        <span
          className="h-1.5 w-1.5 shrink-0 rounded-full"
          style={{ backgroundColor: on ? 'rgba(255,255,255,0.7)' : hue }}
          aria-hidden="true"
        />
      )}
      {children}
    </button>
  );
}

/** A section of the builder: an icon, a name, its controls on a sunken ground. */
function Group({
  icon: Icon,
  label,
  children,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <section className="mb-5 last:mb-0">
      <div className="mb-2 flex items-center gap-2">
        <Icon className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
        <span className="text-[11px] font-semibold uppercase tracking-[0.1em] text-ovs-crm-text-secondary">
          {label}
        </span>
      </div>
      <div className="rounded-[16px] bg-ovs-crm-surface-inset p-3">{children}</div>
    </section>
  );
}

export default function AudienceBuilder({
  audience,
  states = [],
  cities: initialCities = [],
  areas: initialAreas = [],
  tags = [],
  importBatches = [],
}: BuilderProps) {
  const isEditing = Boolean(audience);

  const form = useForm({
    name: audience?.name || '',
    description: audience?.description || '',
    status: audience?.status || 'active',
    rules: audience?.rules || ({} as SegmentRules),
  });

  const [cities, setCities] = useState(initialCities);
  const [areas, setAreas] = useState(initialAreas);
  const [preview, setPreview] = useState<{ count: number; contacts: SegmentPreviewContact[] }>({
    count: 0,
    contacts: [],
  });
  const [isLoadingPreview, setIsLoadingPreview] = useState(false);

  const rules = form.data.rules;

  const setRule = (patch: Partial<SegmentRules>) => {
    const next = { ...rules, ...patch };

    // Location narrows top-down — a wider choice clears the narrower ones.
    if (patch.state !== undefined) {
      next.city = undefined;
      next.area = undefined;
    }
    if (patch.city !== undefined) {
      next.area = undefined;
    }

    form.setData('rules', next);
  };

  const toggleTag = (name: string) => {
    const current = rules.tags ?? [];
    const next = current.includes(name) ? current.filter((t) => t !== name) : [...current, name];
    setRule({ tags: next });
  };

  /*
   * One debounced request carries the live count, the location cascade, and
   * the small sample — a keystroke in "State" both narrows the city list and
   * updates the count, in the same round trip.
   */
  const fetchPreview = useCallback(async (currentRules: SegmentRules) => {
    setIsLoadingPreview(true);
    try {
      const res = await fetch('/company/marketing/audiences/preview-count', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
        },
        body: JSON.stringify({ rules: currentRules }),
      });

      if (res.ok) {
        const json: PreviewResponse = await res.json();
        setPreview({ count: json.count, contacts: json.preview });
        setCities(json.cities);
        setAreas(json.areas);
      }
    } catch {
      // A failed preview leaves the last known count on screen rather than blanking it.
    } finally {
      setIsLoadingPreview(false);
    }
  }, []);

  const debounceRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  useEffect(() => {
    clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => fetchPreview(rules), 400);

    return () => clearTimeout(debounceRef.current);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(rules)]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (isEditing && audience) {
      form.put(`/company/marketing/audiences/${audience.id}`);
    } else {
      form.post('/company/marketing/audiences');
    }
  };

  const statusRule = rules.status;
  const contactsUrl = segmentContactsUrl(rules);

  return (
    <MarketingShell title={isEditing ? 'Edit Segment' : 'New Segment'}>
      <CrmPageHead eyebrow="Marketing" title={isEditing ? 'Edit Segment' : 'New Segment'} />

      <form onSubmit={handleSubmit} className="space-y-4 pb-12">
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {/* ── Left: identity + targeting rules ────────────────────────── */}
          <div className="space-y-4 lg:col-span-8">
            <CrmCard step={0}>
              <p className="mb-3 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Segment name
              </p>
              <CrmInput
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                placeholder="e.g. Gujarat Untouched Clinics"
                required
                className="mb-3"
              />
              {form.errors.name && <p className="mb-3 text-[11.5px] text-ovs-semantic-danger">{form.errors.name}</p>}

              <CrmTextarea
                value={form.data.description}
                onChange={(e) => form.setData('description', e.target.value)}
                placeholder="Optional — what this Segment is for"
                rows={2}
              />
            </CrmCard>

            <CrmCard step={1}>
              <h3 className="mb-4 text-[13px] font-semibold text-ovs-crm-text-primary">Targeting rules</h3>

              <Group icon={MapPin} label="Location">
                <div className="space-y-2">
                  <Select
                    value={rules.state || 'all'}
                    onValueChange={(v) => setRule({ state: v === 'all' ? undefined : v })}
                  >
                    <SelectTrigger aria-label="State" className="h-10 bg-white">
                      <SelectValue placeholder="Any state" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Any state</SelectItem>
                      {states.map((s) => (
                        <SelectItem key={s} value={s}>{s}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>

                  <Select
                    value={rules.city || 'all'}
                    onValueChange={(v) => setRule({ city: v === 'all' ? undefined : v })}
                  >
                    <SelectTrigger aria-label="City" className="h-10 bg-white">
                      <SelectValue placeholder="Any city" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Any city</SelectItem>
                      {cities.map((c) => (
                        <SelectItem key={c} value={c}>{c}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>

                  <Select
                    value={rules.area || 'all'}
                    onValueChange={(v) => setRule({ area: v === 'all' ? undefined : v })}
                  >
                    <SelectTrigger aria-label="Area" className="h-10 bg-white">
                      <SelectValue placeholder="Any area" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Any area</SelectItem>
                      {areas.map((a) => (
                        <SelectItem key={a} value={a}>{a}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <p className="mt-1.5 text-[11px] leading-relaxed text-ovs-crm-text-muted">
                  Pick any level on its own — a city without its state works fine.
                </p>
              </Group>

              <Group icon={Send} label="Marketing status">
                <div className="flex flex-wrap gap-1.5">
                  {LIFECYCLE.map((stage) => (
                    <Pill
                      key={stage.key}
                      on={statusRule === stage.key}
                      hue={stage.hue}
                      onClick={() => setRule({ status: statusRule === stage.key ? undefined : stage.key })}
                    >
                      {stage.label}
                    </Pill>
                  ))}
                </div>
              </Group>

              {tags.length > 0 && (
                <Group icon={TagIcon} label="Tags">
                  <div className="flex flex-wrap gap-1.5">
                    {tags.map((tag) => (
                      <Pill
                        key={tag.id}
                        on={(rules.tags ?? []).includes(tag.name)}
                        hue={tag.color || '#8C9BB5'}
                        onClick={() => toggleTag(tag.name)}
                      >
                        {tag.name}
                      </Pill>
                    ))}
                  </div>
                  <p className="mt-1.5 text-[11px] leading-relaxed text-ovs-crm-text-muted">
                    Matches a contact carrying any tag picked.
                  </p>
                </Group>
              )}

              <Group icon={FileSpreadsheet} label="Source">
                <div className="space-y-2.5">
                  <Select
                    value={rules.import_batch || 'all'}
                    onValueChange={(v) => setRule({ import_batch: v === 'all' ? undefined : v })}
                  >
                    <SelectTrigger aria-label="Import batch" className="h-10 bg-white">
                      <SelectValue placeholder="Any import batch" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">Any import batch</SelectItem>
                      {importBatches.map((batch) => (
                        <SelectItem key={batch.id} value={String(batch.id)}>
                          {batch.batch_name || batch.original_filename || `Import #${batch.id}`}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>

                  <div>
                    <span className="mb-1 block text-[10.5px] font-medium uppercase tracking-[0.08em] text-ovs-crm-text-muted">
                      Added between
                    </span>
                    <div className="flex items-center gap-2">
                      <CrmDatePicker
                        value={rules.imported_from ?? ''}
                        onChange={(e) => setRule({ imported_from: e.target.value || undefined })}
                        aria-label="Added from"
                        placeholder="From"
                        max={rules.imported_to || undefined}
                        className="min-w-0 flex-1 bg-white"
                      />
                      <span className="shrink-0 text-[11px] text-ovs-crm-text-muted">to</span>
                      <CrmDatePicker
                        value={rules.imported_to ?? ''}
                        onChange={(e) => setRule({ imported_to: e.target.value || undefined })}
                        aria-label="Added until"
                        placeholder="To"
                        min={rules.imported_from || undefined}
                        className="min-w-0 flex-1 bg-white"
                      />
                    </div>
                  </div>
                </div>
              </Group>

              <Group icon={Building2} label="Organisation">
                <CrmInput
                  value={rules.organization ?? ''}
                  onChange={(e) => setRule({ organization: e.target.value || undefined })}
                  placeholder="Part of the name is enough"
                  aria-label="Organisation name"
                  className="bg-white"
                />
              </Group>
            </CrmCard>
          </div>

          {/* ── Right: live count + save ─────────────────────────────────── */}
          <div className="lg:col-span-4">
            <CrmCard step={2} className="sticky top-6">
              <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Contacts currently match
              </p>
              <CrmValue
                value={preview.count.toLocaleString('en-IN')}
                size="hero"
                className={cn('transition-opacity', isLoadingPreview && 'opacity-50')}
              />

              {preview.contacts.length > 0 && (
                <div className="mt-4 space-y-2 border-t border-ovs-crm-border pt-3.5">
                  {preview.contacts.map((contact) => (
                    <div key={contact.id} className="flex items-center gap-2.5">
                      <CrmAvatar name={contact.name} size="sm" />
                      <p className="min-w-0 truncate text-[12px] text-ovs-crm-text-secondary">
                        <span className="font-medium text-ovs-crm-text-primary">{contact.name}</span>
                        {contact.city && ` — ${contact.city}`}
                      </p>
                    </div>
                  ))}

                  <Link
                    href={contactsUrl}
                    className="inline-flex items-center gap-1.5 pt-1 text-[12px] font-semibold text-ovs-crm-ink hover:underline"
                  >
                    View matching contacts
                    <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                  </Link>
                </div>
              )}

              <div className="mt-5 border-t border-ovs-crm-border pt-4">
                <CrmButton
                  type="submit"
                  tone="primary"
                  icon={Save}
                  disabled={form.processing || !form.data.name}
                  className="w-full justify-center"
                >
                  {isEditing ? 'Save changes' : 'Create Segment'}
                </CrmButton>
              </div>
            </CrmCard>
          </div>
        </div>
      </form>
    </MarketingShell>
  );
}
