/**
 * The CRM's semantic colour map.
 *
 * Every stage, status, priority and activity channel in the CRM resolves its
 * colour here and nowhere else. Components ask this module what something
 * *means*; they never pick a palette shade themselves. That is what makes a
 * stage read identically on the kanban, in a badge, on the timeline and in the
 * lead header — and what lets one token edit restyle the whole product.
 *
 * Colours come from the `ovs-crm-*` token layer in tokens.json.
 */

/**
 * The CRM type scale — eight steps, and nothing between them.
 *
 * An earlier pass accumulated nineteen distinct sizes across the Overview
 * alone. Sizes that close together do not read as hierarchy; they read as
 * noise, because the eye cannot tell 12px from 12.5px but *can* tell that
 * nothing on the page is deliberate.
 *
 * The three number steps matter most. Every figure on a page belongs to
 * exactly one of them:
 *
 *   - `numberHero`  — the single focal figure of a panel, at most one per panel
 *   - `numberMajor` — the figures a section is actually about
 *   - `numberMinor` — figures inside rows, tiles and lists
 *
 * Reach for a step by name rather than typing a pixel value, so the scale
 * cannot drift again.
 */
export const TYPE = {
  display: 'text-[42px] font-bold leading-[0.96] tracking-[-0.042em] sm:text-[48px]',
  numberHero: 'text-[34px] font-bold leading-none tracking-[-0.04em] tabular-nums',
  numberMajor: 'text-[24px] font-bold leading-none tracking-[-0.035em] tabular-nums',
  numberMinor: 'text-[18px] font-bold leading-none tracking-[-0.02em] tabular-nums',
  title: 'text-[17px] font-bold tracking-[-0.022em]',
  body: 'text-[13px]',
  meta: 'text-[11.5px]',
  label: 'text-[10px] font-bold uppercase tracking-[0.12em]',
} as const;

/**
 * Stage tones carry pipeline meaning; accent tones are the decorative palette
 * the reference designs use for tiles and chips. Both resolve through the same
 * class map so a component never needs to know which family it was handed.
 */
export type CrmTone =
  | 'slate'
  | 'blue'
  | 'violet'
  | 'teal'
  | 'amber'
  | 'emerald'
  | 'coral'
  | 'yellow'
  | 'lime'
  | 'green';

export interface ToneClasses {
  /** Tinted surface + readable text + edge — for badges and soft chips. */
  soft: string;
  /** Solid fill of the tone — for bars, dots, accents. */
  solid: string;
  /** The tone as text only. */
  text: string;
  /** Left/top accent border, for stage columns and timeline rails. */
  accent: string;
  /** Faint surface only, no text/border — for row tints. */
  wash: string;
}

export const TONES: Record<CrmTone, ToneClasses> = {
  slate: {
    soft: 'bg-ovs-crm-stage-slate-soft text-ovs-crm-stage-slate-text border-ovs-crm-stage-slate-border',
    solid: 'bg-ovs-crm-stage-slate',
    text: 'text-ovs-crm-stage-slate-text',
    accent: 'border-ovs-crm-stage-slate',
    wash: 'bg-ovs-crm-stage-slate-soft',
  },
  blue: {
    soft: 'bg-ovs-crm-stage-blue-soft text-ovs-crm-stage-blue-text border-ovs-crm-stage-blue-border',
    solid: 'bg-ovs-crm-stage-blue',
    text: 'text-ovs-crm-stage-blue-text',
    accent: 'border-ovs-crm-stage-blue',
    wash: 'bg-ovs-crm-stage-blue-soft',
  },
  violet: {
    soft: 'bg-ovs-crm-stage-violet-soft text-ovs-crm-stage-violet-text border-ovs-crm-stage-violet-border',
    solid: 'bg-ovs-crm-stage-violet',
    text: 'text-ovs-crm-stage-violet-text',
    accent: 'border-ovs-crm-stage-violet',
    wash: 'bg-ovs-crm-stage-violet-soft',
  },
  teal: {
    soft: 'bg-ovs-crm-stage-teal-soft text-ovs-crm-stage-teal-text border-ovs-crm-stage-teal-border',
    solid: 'bg-ovs-crm-stage-teal',
    text: 'text-ovs-crm-stage-teal-text',
    accent: 'border-ovs-crm-stage-teal',
    wash: 'bg-ovs-crm-stage-teal-soft',
  },
  amber: {
    soft: 'bg-ovs-crm-stage-amber-soft text-ovs-crm-stage-amber-text border-ovs-crm-stage-amber-border',
    solid: 'bg-ovs-crm-stage-amber',
    text: 'text-ovs-crm-stage-amber-text',
    accent: 'border-ovs-crm-stage-amber',
    wash: 'bg-ovs-crm-stage-amber-soft',
  },
  emerald: {
    soft: 'bg-ovs-crm-stage-emerald-soft text-ovs-crm-stage-emerald-text border-ovs-crm-stage-emerald-border',
    solid: 'bg-ovs-crm-stage-emerald',
    text: 'text-ovs-crm-stage-emerald-text',
    accent: 'border-ovs-crm-stage-emerald',
    wash: 'bg-ovs-crm-stage-emerald-soft',
  },
  coral: {
    soft: 'bg-ovs-crm-stage-coral-soft text-ovs-crm-stage-coral-text border-ovs-crm-stage-coral-border',
    solid: 'bg-ovs-crm-stage-coral',
    text: 'text-ovs-crm-stage-coral-text',
    accent: 'border-ovs-crm-stage-coral',
    wash: 'bg-ovs-crm-stage-coral-soft',
  },
  yellow: {
    soft: 'bg-ovs-crm-accent-yellow-soft text-ovs-crm-accent-yellow-text border-ovs-crm-accent-yellow-border',
    solid: 'bg-ovs-crm-accent-yellow',
    text: 'text-ovs-crm-accent-yellow-text',
    accent: 'border-ovs-crm-accent-yellow',
    wash: 'bg-ovs-crm-accent-yellow-soft',
  },
  lime: {
    soft: 'bg-ovs-crm-accent-lime-soft text-ovs-crm-accent-lime-text border-ovs-crm-accent-lime-border',
    solid: 'bg-ovs-crm-accent-lime',
    text: 'text-ovs-crm-accent-lime-text',
    accent: 'border-ovs-crm-accent-lime',
    wash: 'bg-ovs-crm-accent-lime-soft',
  },
  green: {
    soft: 'bg-ovs-crm-accent-green-soft text-ovs-crm-accent-green-text border-ovs-crm-accent-green-border',
    solid: 'bg-ovs-crm-accent-green',
    text: 'text-ovs-crm-accent-green-text',
    accent: 'border-ovs-crm-accent-green',
    wash: 'bg-ovs-crm-accent-green-soft',
  },
};

/**
 * Lead stage → tone.
 *
 * The progression walks cool→warm→resolved so a pipeline reads left to right
 * as temperature: prospecting is blue, engagement violet, evaluation teal,
 * commercial amber, closed emerald or coral.
 */
export const STAGE_TONE: Record<string, CrmTone> = {
  new: 'slate',
  assigned: 'slate',
  contacted: 'blue',
  demo_scheduled: 'violet',
  demo_completed: 'violet',
  trial_active: 'teal',
  negotiation: 'amber',
  quotation_sent: 'amber',
  quotation_approved: 'emerald',
  payment_received: 'emerald',
  won: 'emerald',
  lost: 'coral',
};

/** Short labels for dense surfaces where the full stage name will not fit. */
export const STAGE_SHORT: Record<string, string> = {
  new: 'New',
  assigned: 'Assigned',
  contacted: 'Contacted',
  demo_scheduled: 'Demo Set',
  demo_completed: 'Demo Done',
  trial_active: 'Trial',
  negotiation: 'Negotiation',
  quotation_sent: 'Quoted',
  quotation_approved: 'Approved',
  payment_received: 'Paid',
  won: 'Won',
  lost: 'Lost',
};

/** Non-lead statuses: demo, trial, quotation, task and document lifecycles. */
export const STATUS_TONE: Record<string, CrmTone> = {
  // Demo
  requested: 'slate',
  scheduled: 'blue',
  confirmed: 'blue',
  completed: 'emerald',
  no_show: 'coral',
  rescheduled: 'amber',
  cancelled: 'slate',

  // Trial
  preparing: 'slate',
  active: 'teal',
  ending_soon: 'amber',
  extended: 'blue',
  converted: 'emerald',
  expired: 'coral',

  // Quotation / document
  draft: 'slate',
  sent: 'blue',
  under_review: 'amber',
  pending_approval: 'amber',
  approved: 'emerald',
  accepted: 'emerald',
  paid: 'emerald',
  rejected: 'coral',
  archived: 'slate',

  // Task
  pending: 'amber',
  in_progress: 'blue',
  overdue: 'coral',
};

/** Activity + communication channels (section 37). */
export const CHANNEL_TONE: Record<string, CrmTone> = {
  call: 'blue',
  whatsapp: 'emerald',
  email: 'violet',
  meeting: 'blue',
  demo: 'amber',
  trial: 'teal',
  quotation: 'violet',
  agreement: 'violet',
  follow_up: 'blue',
  note: 'slate',
  document: 'slate',
  provisioning: 'emerald',
  system_update: 'slate',
  record_created: 'slate',
  record_updated: 'slate',
  record_deleted: 'coral',
};

/** Priority carries colour only when it is worth interrupting someone for. */
export const PRIORITY_TONE: Record<string, CrmTone> = {
  critical: 'coral',
  high: 'coral',
  hot: 'coral',
  medium: 'amber',
  warm: 'amber',
  low: 'slate',
  cold: 'slate',
};

/**
 * What a practice treats, by `HealthcareSpecialty` value.
 *
 * A specialty pill used to render on `neutral` — the same grey as an empty
 * state — because nothing assigned it a meaning of its own. Eight cases, eight
 * of the ten tones in the palette, chosen so no two adjacent list entries
 * share a colour and the two catch-alls (`multi_specialty`, `other`) read as
 * distinctly lower-commitment than a named specialty.
 */
export const SPECIALTY_TONE: Record<string, CrmTone> = {
  dental: 'teal',
  ophthalmology: 'blue',
  physiotherapy: 'emerald',
  dermatology: 'coral',
  ent: 'amber',
  general_hospital: 'violet',
  multi_specialty: 'lime',
  other: 'yellow',

  /*
   * The granular `HealthcareSpecialtyMaster` catalogue keys a lead's
   * specialties can also carry — seeded rows and anything typed before the
   * eight-case intake enum existed. Grouped by the catalogue's own eight
   * categories rather than given thirty individual hues: a badge for
   * "Gynecology" only has to read as the same family as "Obstetrics", not be
   * distinguishable from it at a glance.
   */
  general_medicine: 'blue',
  family_practice: 'blue',
  diabetology: 'blue',
  pediatrics: 'violet',
  gynecology: 'violet',
  obstetrics: 'violet',
  ivf_fertility: 'violet',
  orthopedics: 'amber',
  general_surgery: 'amber',
  urology: 'amber',
  cardiology: 'coral',
  neurology: 'coral',
  gastroenterology: 'coral',
  oncology: 'coral',
  nephrology: 'coral',
  general_dentistry: 'teal',
  orthodontics: 'teal',
  endodontics: 'teal',
  oral_surgery: 'teal',
  occupational_therapy: 'emerald',
  speech_therapy: 'emerald',
  nutrition: 'emerald',
  homeopathy: 'lime',
  ayurveda: 'lime',
  naturopathy: 'lime',
  unani: 'lime',
  pathology_lab: 'yellow',
  radiology_imaging: 'yellow',
  dialysis_center: 'yellow',
};

export function toneFor(map: Record<string, CrmTone>, key?: string | null): CrmTone {
  if (!key) return 'slate';

  return map[String(key).toLowerCase()] ?? 'slate';
}

export function stageTone(stage?: string | null): CrmTone {
  return toneFor(STAGE_TONE, stage);
}

export function specialtyTone(specialty?: string | null): CrmTone {
  return toneFor(SPECIALTY_TONE, specialty);
}

/**
 * Where a lead came from, by `Lead::sources()` key.
 *
 * `manual_entry` is deliberately `slate` — a rep typing a lead in by hand is
 * not a channel worth badging, and giving it a bright tone the way the other
 * seven get one would make the one honest "we don't really know" source look
 * like a deliberate acquisition strategy.
 */
export const SOURCE_TONE: Record<string, CrmTone> = {
  website: 'blue',
  instagram: 'coral',
  whatsapp: 'emerald',
  referral: 'violet',
  cold_call: 'amber',
  exhibition: 'lime',
  partner: 'teal',
  manual_entry: 'slate',
};

export function sourceTone(source?: string | null): CrmTone {
  return toneFor(SOURCE_TONE, source);
}

export function statusTone(status?: string | null): CrmTone {
  if (!status) return 'slate';
  const key = String(status).toLowerCase();

  return STAGE_TONE[key] ?? STATUS_TONE[key] ?? 'slate';
}

export function channelTone(channel?: string | null): CrmTone {
  return toneFor(CHANNEL_TONE, channel);
}

export function priorityTone(priority?: string | null): CrmTone {
  return toneFor(PRIORITY_TONE, priority);
}

export function toneClasses(tone: CrmTone): ToneClasses {
  return TONES[tone];
}

/** Turn a snake_case backend value into readable text. */
export function humanize(value?: string | null): string {
  if (!value) return '';

  return String(value)
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Compact Indian-format currency, the unit the whole CRM reports in. */
export function inr(value?: number | null, opts: { compact?: boolean } = {}): string {
  const amount = Number(value ?? 0);

  if (opts.compact) {
    if (amount >= 10000000) return `₹${(amount / 10000000).toFixed(2)}Cr`;
    if (amount >= 100000) return `₹${(amount / 100000).toFixed(1)}L`;
    if (amount >= 1000) return `₹${(amount / 1000).toFixed(0)}K`;
  }

  return `₹${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(amount)}`;
}

/**
 * Tone → hex, for the places that need a literal colour rather than a class:
 * an SVG stroke, an inline `backgroundColor`, a canvas fill.
 *
 * These are the values the reference designs were sampled at. They live here,
 * beside the rest of the semantic colour map, because this module is the one
 * place allowed to know what a tone actually looks like — it previously sat in
 * the chart module, which meant deleting the charts would have taken the
 * avatars and the schedule grid down with them.
 */
const TONE_HEX: Record<CrmTone, string> = {
  slate: '#6B7488',
  blue: '#2563EB',
  violet: '#8B7FE8',
  teal: '#4A9DAB',
  amber: '#F5A623',
  emerald: '#3DDC84',
  coral: '#E8593C',
  yellow: '#F5D547',
  lime: '#C3F53C',
  green: '#3DDC84',
};

export function toneHex(tone: CrmTone): string {
  return TONE_HEX[tone] ?? TONE_HEX.slate;
}

/* ────────────────────────── Customer Success semantics ─────────────────────── */

/**
 * Customer Success reads three things a CRM deal never has: an account's health
 * score, its churn risk, and where it sits in its life after signing.
 *
 * They live here for the same reason every other tone map does. Accounts and
 * Account 360 had each grown their own copy — `healthTone` and `churnTone`
 * declared twice, `stageTone` a third time with a different set of stages — so
 * the same customer could read "good" on the list and "warn" on the detail page
 * for one unchanged score. That is the exact drift `CrmDesignInvariantTest`
 * exists to catch, and it caught it.
 *
 * These return `CrmStatusTone` rather than `CrmTone`: Customer Success renders
 * through `CrmStatusPill`, which speaks the semantic vocabulary
 * (good/warn/bad/…), not the palette one.
 */
export type CsStatusTone = 'good' | 'warn' | 'bad' | 'neutral' | 'info';

/**
 * An account's health, banded.
 *
 * The thresholds are `CommercialAccountHealthService`'s own: it labels a score
 * Healthy at 80 and At Risk below 50, and the colour has to agree with the word
 * the server already chose.
 */
export function healthTone(score?: number | null): CsStatusTone {
  const value = Number(score ?? 100);

  if (value >= 80) return 'good';
  if (value >= 50) return 'warn';

  return 'bad';
}

/**
 * Churn risk, which arrives capitalised from some seeders and lower-case from
 * others — hence the fold rather than a direct lookup.
 */
export function churnTone(risk?: string | null): CsStatusTone {
  switch (String(risk ?? '').toLowerCase()) {
    case 'critical':
    case 'high':
      return 'bad';
    case 'medium':
      return 'warn';
    case 'low':
      return 'good';
    default:
      return 'neutral';
  }
}

/**
 * Where an account is in its life, as `AccountLifecycleService::stageFor()`
 * derives it. Any stage that service can return has a tone here; anything else
 * is neutral rather than silently green.
 */
export const CS_STAGE_TONE: Record<string, CsStatusTone> = {
  live: 'good',
  trialling: 'info',
  onboarding: 'info',
  grace: 'warn',
  lapsed: 'bad',
  not_provisioned: 'neutral',
};

export function lifecycleStageTone(stage?: string | null): CsStatusTone {
  return CS_STAGE_TONE[String(stage ?? '').toLowerCase()] ?? 'neutral';
}

/**
 * Utilisation, banded the way the Usage workspace already reasons about it:
 * at or over what was bought is a problem, near it is a conversation.
 */
export function utilizationTone(pct?: number | null): CsStatusTone {
  if (pct === null || pct === undefined) return 'neutral';
  if (pct >= 100) return 'bad';
  if (pct >= 90) return 'warn';

  return 'good';
}

/**
 * A Customer Success tone as a literal colour, for the places a class cannot
 * reach: an SVG stroke, a segmented bar, an inline accent rule.
 *
 * `toneHex` already does this for the palette vocabulary, but Customer Success
 * speaks the semantic one — `good`, `warn`, `bad` — and the two are different
 * alphabets. Without this, every CS surface that needed a hex reached for a
 * literal, which is how the module ended up with forty indigo shades in a
 * product whose brand is teal.
 */
export function csToneHex(tone: CsStatusTone): string {
  const mapped: Record<CsStatusTone, CrmTone> = {
    good: 'emerald',
    warn: 'amber',
    bad: 'coral',
    info: 'blue',
    neutral: 'slate',
  };

  return toneHex(mapped[tone] ?? 'slate');
}
