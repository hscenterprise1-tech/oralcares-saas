/**
 * The Clinic design system's colour families. Each operational category
 * keeps one family everywhere it appears: patients blue, waiting amber,
 * with the doctor / in session violet, completed mint, scheduling cyan,
 * attention coral. Dental takes clinical blue and Physio teal. Structure
 * stays navy (#173B60) and secondary text slate (#64748B). Arbitrary-value
 * classes keep the palette out of the shared Tailwind config.
 */
export type DsTone = 'blue' | 'navy' | 'teal' | 'amber' | 'rose' | 'green' | 'slate' | 'violet' | 'cyan' | 'coral';

export const TONE: Record<DsTone, { soft: string; ink: string; solid: string; wash: string; ring: string }> = {
  blue: { soft: 'bg-[#E7F0FD]', ink: 'text-[#1D5FB8]', solid: 'bg-[#1769AA]', wash: 'from-[#EEF4FF]', ring: 'ring-[#1769AA]/30' },
  navy: { soft: 'bg-[#E8EEF5]', ink: 'text-[#173B60]', solid: 'bg-[#173B60]', wash: 'from-[#F0F4F9]', ring: 'ring-[#173B60]/25' },
  teal: { soft: 'bg-[#E0F4F0]', ink: 'text-[#0F6B5F]', solid: 'bg-[#14917F]', wash: 'from-[#EBF8F5]', ring: 'ring-[#14917F]/30' },
  amber: { soft: 'bg-[#FFF1DC]', ink: 'text-[#A35A06]', solid: 'bg-[#E08A12]', wash: 'from-[#FFF7EB]', ring: 'ring-[#E08A12]/35' },
  rose: { soft: 'bg-[#FDECEC]', ink: 'text-[#B42318]', solid: 'bg-[#B42318]', wash: 'from-[#FEF4F4]', ring: 'ring-[#B42318]/30' },
  green: { soft: 'bg-[#E2F5EC]', ink: 'text-[#1E6F51]', solid: 'bg-[#23765C]', wash: 'from-[#EEF9F3]', ring: 'ring-[#23765C]/30' },
  slate: { soft: 'bg-[#EEF2F6]', ink: 'text-[#475569]', solid: 'bg-[#94A3B8]', wash: 'from-[#F5F7FA]', ring: 'ring-[#94A3B8]/30' },
  violet: { soft: 'bg-[#EFEAFD]', ink: 'text-[#5B3CC4]', solid: 'bg-[#7356D8]', wash: 'from-[#F6F2FF]', ring: 'ring-[#7356D8]/30' },
  cyan: { soft: 'bg-[#DFF5F8]', ink: 'text-[#0B6F80]', solid: 'bg-[#1497A8]', wash: 'from-[#ECF9FB]', ring: 'ring-[#1497A8]/30' },
  coral: { soft: 'bg-[#FDE9E4]', ink: 'text-[#B13A22]', solid: 'bg-[#E0654A]', wash: 'from-[#FFF3F0]', ring: 'ring-[#E0654A]/30' },
};

/** Specialty accents: Dental is clinical blue, Physio is teal. Anything else stays neutral. */
export function specialtyTone(key: string | null | undefined): DsTone {
  if (!key) {
    return 'slate';
  }
  if (isPhysio(key)) {
    return 'teal';
  }
  if (isDental(key)) {
    return 'blue';
  }

  return 'navy';
}

export function isPhysio(key: string): boolean {
  return key === 'physiotherapy' || key.startsWith('physio');
}

export function isDental(key: string): boolean {
  return key === 'dental' || key === 'orthodontics' || key.startsWith('dental');
}

export const FOCUS_RING = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA] focus-visible:ring-offset-2 focus-visible:ring-offset-white';
