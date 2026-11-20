import {
  BadgeCheck,
  Bell,
  Banknote,
  Building2,
  ConciergeBell,
  CalendarDays,
  CalendarClock,
  CalendarOff,
  ChartColumn,
  CircleAlert,
  ClipboardCheck,
  ClipboardPlus,
  Crown,
  DoorOpen,
  FlaskConical,
  HandCoins,
  Hourglass,
  LifeBuoy,
  Tags,
  LayoutDashboard,
  FileSignature,
  MessageCircle,
  Microscope,
  Network,
  NotebookPen,
  Receipt,
  RotateCcw,
  ScanLine,
  Settings,
  Settings2,
  ShieldAlert,
  Stethoscope,
  TrendingUp,
  UserCog,
  UserPlus,
  Users,
  Wallet,
  Workflow,
  Zap,
  type LucideIcon,
} from 'lucide-react';
import type { Tone } from '../types';

/**
 * The portal's icon vocabulary. The server names an icon by key (navigation,
 * actions, widgets) and this is the only place a key becomes a component, so
 * the server never ships component names and an unknown key degrades to a
 * neutral icon instead of breaking the page.
 */
const ICONS: Record<string, LucideIcon> = {
  dashboard: LayoutDashboard,
  'front-desk': ConciergeBell,
  'command-center': LayoutDashboard,
  patients: Users,
  appointments: CalendarDays,
  leave: CalendarOff,
  orders: FlaskConical,
  billing: Receipt,
  reports: ChartColumn,
  administration: Settings2,
  staff: UserCog,
  branches: Building2,
  departments: Network,
  rooms: DoorOpen,
  settings: Settings,
  services: Tags,
  'new-case': UserPlus,
  'repeat-case': RotateCcw,
  assessment: ClipboardCheck,
  payment: Banknote,
  lab: Microscope,
  xray: ScanLine,
  owner: Crown,
  bolt: Zap,
  waiting: Hourglass,
  doctor: Stethoscope,
  flow: Workflow,
  wallet: Wallet,
  pending: HandCoins,
  attention: CircleAlert,
  notes: NotebookPen,
  trend: TrendingUp,
  plan: BadgeCheck,
  shield: ShieldAlert,
  order: ClipboardPlus,
  whatsapp: MessageCircle,
  consent: FileSignature,
  'follow-ups': CalendarClock,
  notifications: Bell,
  support: LifeBuoy,
};

export function iconFor(key: string): LucideIcon {
  return ICONS[key] ?? LayoutDashboard;
}

/**
 * Colour by meaning, not decoration: brand (teal) for primary actions, blue
 * for scheduling, green for done and paid, amber for pending, red for urgent,
 * violet for clinical. Every tone pairs a tinted surface with a text colour
 * that passes WCAG AA on it.
 */
export const TONES: Record<
  Tone,
  { chip: string; soft: string; text: string; fill: string; ring: string; accent: string }
> = {
  brand: {
    chip: 'bg-clinic-brand-soft text-clinic-brand-strong',
    soft: 'bg-clinic-brand-soft/60',
    text: 'text-clinic-brand-strong',
    fill: 'bg-clinic-brand',
    ring: 'ring-clinic-brand/25',
    accent: 'from-clinic-brand/15',
  },
  blue: {
    chip: 'bg-blue-50 text-blue-700',
    soft: 'bg-blue-50/70',
    text: 'text-blue-700',
    fill: 'bg-blue-500',
    ring: 'ring-blue-200',
    accent: 'from-blue-500/10',
  },
  green: {
    chip: 'bg-emerald-50 text-emerald-700',
    soft: 'bg-emerald-50/70',
    text: 'text-emerald-700',
    fill: 'bg-emerald-500',
    ring: 'ring-emerald-200',
    accent: 'from-emerald-500/10',
  },
  amber: {
    chip: 'bg-amber-50 text-amber-800',
    soft: 'bg-amber-50/70',
    text: 'text-amber-800',
    fill: 'bg-amber-500',
    ring: 'ring-amber-200',
    accent: 'from-amber-500/10',
  },
  red: {
    chip: 'bg-rose-50 text-rose-700',
    soft: 'bg-rose-50/70',
    text: 'text-rose-700',
    fill: 'bg-rose-500',
    ring: 'ring-rose-200',
    accent: 'from-rose-500/10',
  },
  violet: {
    chip: 'bg-violet-50 text-violet-700',
    soft: 'bg-violet-50/70',
    text: 'text-violet-700',
    fill: 'bg-violet-500',
    ring: 'ring-violet-200',
    accent: 'from-violet-500/10',
  },
  slate: {
    chip: 'bg-slate-100 text-slate-700',
    soft: 'bg-slate-50',
    text: 'text-slate-700',
    fill: 'bg-slate-400',
    ring: 'ring-slate-200',
    accent: 'from-slate-500/10',
  },
  ink: {
    chip: 'bg-clinic-ink text-white',
    soft: 'bg-slate-50',
    text: 'text-clinic-ink',
    fill: 'bg-clinic-ink',
    ring: 'ring-slate-300',
    accent: 'from-clinic-ink/10',
  },
};

/**
 * The colour of a part of the product, kept separate from state colours
 * (`TONES`/`statusTone`): a badge or accent says which area something
 * belongs to — dental, physiotherapy, clinical work, money, the calendar —
 * and that area keeps one colour everywhere. Each pairs a tinted surface
 * with text that passes WCAG AA on it.
 */
export type ContextKey = 'dental' | 'physiotherapy' | 'clinical' | 'billing' | 'appointments' | 'general';

export const CONTEXTS: Record<ContextKey, { label: string; chip: string; dot: string; text: string; ring: string }> = {
  dental: { label: 'Dental', chip: 'bg-cyan-50 text-cyan-800', dot: 'bg-cyan-500', text: 'text-cyan-800', ring: 'ring-cyan-200' },
  physiotherapy: {
    label: 'Physiotherapy',
    chip: 'bg-indigo-50 text-indigo-700',
    dot: 'bg-indigo-500',
    text: 'text-indigo-700',
    ring: 'ring-indigo-200',
  },
  clinical: {
    label: 'Clinical',
    chip: 'bg-emerald-50 text-emerald-800',
    dot: 'bg-emerald-500',
    text: 'text-emerald-800',
    ring: 'ring-emerald-200',
  },
  billing: { label: 'Billing', chip: 'bg-amber-50 text-amber-800', dot: 'bg-amber-500', text: 'text-amber-800', ring: 'ring-amber-200' },
  appointments: {
    label: 'Appointments',
    chip: 'bg-blue-50 text-blue-700',
    dot: 'bg-blue-500',
    text: 'text-blue-700',
    ring: 'ring-blue-200',
  },
  general: { label: 'General', chip: 'bg-slate-100 text-slate-700', dot: 'bg-slate-400', text: 'text-slate-700', ring: 'ring-slate-200' },
};

/** A specialty registry key (`dental`, `orthodontics`, `physiotherapy`, …) as its product area. */
export function contextForSpecialty(key: string | null | undefined): ContextKey {
  switch (key) {
    case 'dental':
    case 'orthodontics':
      return 'dental';
    case 'physiotherapy':
      return 'physiotherapy';
    case null:
    case undefined:
    case '':
      return 'general';
    default:
      return 'clinical';
  }
}

export function toneFor(tone: string | undefined): (typeof TONES)[Tone] {
  return TONES[(tone as Tone) ?? 'slate'] ?? TONES.slate;
}

/** The tone a status word carries wherever it appears. */
export function statusTone(status: string | null | undefined): Tone {
  switch (status) {
    case 'active':
    case 'completed':
    case 'paid':
    case 'delivered':
    case 'fitted':
    case 'reviewed':
      return 'green';
    case 'merged':
    case 'arrived':
    case 'checked_in':
    case 'in_consultation':
    case 'in_production':
    case 'captured':
      return 'violet';
    case 'scheduled':
    case 'requested':
    case 'accepted':
    case 'info':
      return 'blue';
    case 'archived':
    case 'waiting':
    case 'calling':
    case 'called':
    case 'pending':
    case 'partially_paid':
    case 'held':
    case 'quality_check':
    case 'ready_for_delivery':
      return 'amber';
    case 'overdue':
    case 'alert':
    case 'no_show':
    case 'cancelled':
      return 'red';
    default:
      return 'slate';
  }
}

export function humanize(value: string | null | undefined): string {
  return value ? value.replace(/[_-]+/g, ' ').replace(/^\w/, (first) => first.toUpperCase()) : '';
}

export function formatNumber(value: number): string {
  return new Intl.NumberFormat(undefined).format(value);
}

/** Money in the currency the server named. Whole units: a dashboard figure, not a ledger. */
export function formatMoney(value: number, currency = 'INR'): string {
  try {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);
  } catch {
    return formatNumber(Math.round(value));
  }
}

/**
 * Today as YYYY-MM-DD on this device's calendar, for date inputs and
 * "is it past" checks. `toISOString()` gives the UTC date, a day behind in
 * India until 05:30, which the server then rejects as past.
 */
export function localDateKey(date: Date = new Date()): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/**
 * Accent colours for what is drawn with an inline colour rather than a class
 * (a gradient, a section's icon chip). Decorative only; states use `TONES`.
 */
export const ACCENT_HEX = {
  slate: '#6B7488',
  blue: '#2563EB',
  violet: '#8B7FE8',
  teal: '#4A9DAB',
  amber: '#F5A623',
  green: '#3DDC84',
  coral: '#E8593C',
} as const;

export type AccentHue = keyof typeof ACCENT_HEX;

const NAME_HUES: AccentHue[] = ['blue', 'teal', 'violet', 'green', 'coral', 'slate'];

/** The same colour for the same name, every time (a clinic's or a person's). */
export function hueForName(name: string): string {
  const source = name || '?';
  let hash = 0;
  for (let index = 0; index < source.length; index++) {
    hash = (hash << 5) - hash + source.charCodeAt(index);
    hash |= 0;
  }

  return ACCENT_HEX[NAME_HUES[Math.abs(hash) % NAME_HUES.length]];
}
