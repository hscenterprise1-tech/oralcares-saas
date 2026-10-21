/**
 * The rebuilt portal's colour roles, on the `cx` tokens (tailwind.config.js).
 * Each tone means one thing — brand = primary, blue = scheduling, green =
 * done/paid, amber = waiting/due, red = urgent/unsigned, violet = clinical —
 * and every `ink` passes WCAG AA on its `bg`.
 */
export type CxTone = 'brand' | 'blue' | 'green' | 'amber' | 'red' | 'violet' | 'slate' | 'ink';

export const CX_TONES: Record<CxTone, { bg: string; ink: string; rule: string; dot: string }> = {
  brand: { bg: 'bg-cx-teal-bg', ink: 'text-cx-teal-ink', rule: 'bg-cx-cyan', dot: 'bg-cx-cyan' },
  blue: { bg: 'bg-cx-blue-bg', ink: 'text-cx-blue-ink', rule: 'bg-cx-blue-ink', dot: 'bg-cx-blue-ink' },
  green: { bg: 'bg-cx-green-bg', ink: 'text-cx-green-ink', rule: 'bg-cx-green-ink', dot: 'bg-cx-green-ink' },
  amber: { bg: 'bg-cx-amber-bg', ink: 'text-cx-amber-ink', rule: 'bg-cx-amber-ink', dot: 'bg-cx-amber-ink' },
  red: { bg: 'bg-cx-rose-bg', ink: 'text-cx-rose-ink', rule: 'bg-cx-rose-ink', dot: 'bg-cx-rose-ink' },
  violet: { bg: 'bg-cx-violet-bg', ink: 'text-cx-violet-ink', rule: 'bg-cx-violet-ink', dot: 'bg-cx-violet-ink' },
  slate: { bg: 'bg-cx-slate-bg', ink: 'text-cx-slate-ink', rule: 'bg-cx-slate-ink', dot: 'bg-cx-slate-ink' },
  ink: { bg: 'bg-cx-navy-soft', ink: 'text-cx-navy', rule: 'bg-cx-navy', dot: 'bg-cx-navy' },
};

export function cxTone(tone: string | null | undefined): (typeof CX_TONES)[CxTone] {
  return CX_TONES[(tone ?? 'slate') as CxTone] ?? CX_TONES.slate;
}
