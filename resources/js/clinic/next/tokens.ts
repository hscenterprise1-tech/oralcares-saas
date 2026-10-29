/**
 * OralCares Clinic — "Clinical Ledger", the new Clinic Portal's visual identity.
 *
 * The product reads like a well-kept clinical register, not a dashboard of
 * floating cards:
 *
 * - Paper and ink. A warm paper canvas (#F6F4EF), white sheets, deep
 *   green-black ink (#13201B). No gradients, almost no shadows: structure
 *   comes from hairline rules and alignment.
 * - One brand colour, clinical green (#0E6B54), for the primary action and
 *   the current place. Nothing else is green unless it means "done / paid".
 * - Each specialty has its own accent, used only to mark that specialty:
 *   Dental is enamel indigo (#3446C8), Physiotherapy is motion orange
 *   (#C8551E). They never stand in for status colours.
 * - A dark spine (#10211B) carries navigation on large screens; phones get a
 *   bottom bar. Content sits on paper beside it.
 * - Type does the hierarchy: large titles, tabular figures, and small
 *   upper-case labels for field names. Radius is small (6px controls, 10px
 *   sheets) so lists stay dense and aligned.
 *
 * Colours are arbitrary-value classes so the shared Tailwind config stays as it is.
 */

export const INK = {
  text: 'text-[#13201B]',
  muted: 'text-[#4A5752]',
  faint: 'text-[#6B7671]',
} as const;

export const SURFACE = {
  canvas: 'bg-[#F6F4EF]',
  sheet: 'bg-white',
  rule: 'border-[#E3DED3]',
  ruleStrong: 'border-[#CFC8BA]',
} as const;

/** Keyboard focus: a 2px clinical-green ring, offset from the element so it reads on paper and on white. */
export const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0E6B54] focus-visible:ring-offset-2 focus-visible:ring-offset-[#F6F4EF]';

export type Signal = 'brand' | 'ok' | 'warn' | 'danger' | 'info' | 'neutral' | 'dental' | 'physio';

/** Each signal: a soft fill with readable ink (AA on its fill), and a solid mark for dots and bars. */
export const SIGNAL: Record<Signal, { soft: string; ink: string; solid: string }> = {
  brand: { soft: 'bg-[#E3F1EB]', ink: 'text-[#0A5643]', solid: 'bg-[#0E6B54]' },
  ok: { soft: 'bg-[#E3F3EA]', ink: 'text-[#17603D]', solid: 'bg-[#1F7A4D]' },
  warn: { soft: 'bg-[#FBF0DC]', ink: 'text-[#8A4F07]', solid: 'bg-[#C98213]' },
  danger: { soft: 'bg-[#FCE8E6]', ink: 'text-[#A3201A]', solid: 'bg-[#B42318]' },
  info: { soft: 'bg-[#E6EEF6]', ink: 'text-[#1F4E79]', solid: 'bg-[#2E6BA6]' },
  neutral: { soft: 'bg-[#EFECE5]', ink: 'text-[#4A5752]', solid: 'bg-[#8C948F]' },
  dental: { soft: 'bg-[#E8EAFB]', ink: 'text-[#2B3AA8]', solid: 'bg-[#3446C8]' },
  physio: { soft: 'bg-[#FBEADF]', ink: 'text-[#A3431A]', solid: 'bg-[#C8551E]' },
};

/** Which accent a specialty key carries. */
export function specialtySignal(key: string): Signal {
  if (key === 'physiotherapy' || key.startsWith('physio')) {
    return 'physio';
  }
  if (key === 'dental' || key === 'orthodontics' || key.startsWith('dental')) {
    return 'dental';
  }

  return 'neutral';
}

/** Small upper-case field labels. */
export const LABEL = 'text-[11px] font-semibold uppercase tracking-[0.08em] text-[#6B7671]';
