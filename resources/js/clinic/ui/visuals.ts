import {
  BadgeCheck,
  Banknote,
  Building2,
  CalendarDays,
  CalendarOff,
  ChartColumn,
  ClipboardCheck,
  ClipboardPlus,
  Crown,
  DoorOpen,
  FlaskConical,
  HandCoins,
  Hourglass,
  LayoutDashboard,
  Microscope,
  Network,
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
  trend: TrendingUp,
  plan: BadgeCheck,
  shield: ShieldAlert,
  order: ClipboardPlus,
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

export function toneFor(tone: string | undefined): (typeof TONES)[Tone] {
  return TONES[(tone as Tone) ?? 'slate'] ?? TONES.slate;
}

/** The tone a status word carries wherever it appears. */
export function statusTone(status: string | null | undefined): Tone {
  switch (status) {
    case 'completed':
    case 'paid':
    case 'delivered':
    case 'fitted':
    case 'reviewed':
      return 'green';
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
