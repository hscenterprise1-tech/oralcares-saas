import type { CSSProperties } from 'react';

/**
 * The dashboard's card colours: the same values the Company CRM uses for its
 * areas, accents and statuses, held here so the Clinic Portal looks like the
 * same product without importing anything from the Company Portal or the
 * OVS token layer.
 *
 * Each meaning has three values, as in the CRM: the solid colour (an icon
 * disc, a dot, an edge), its soft fill (a card or pill background) and its
 * text colour on that fill (AA on the soft fill). One colour per meaning,
 * never one per card.
 */
export const TONES: Record<string, { solid: string; soft: string; text: string }> = {
  brand: { solid: '#12A0B5', soft: '#DDF4F8', text: '#0A6473' }, // primary: patients, the main action
  blue: { solid: '#2563EB', soft: '#DEEAFE', text: '#1741A6' }, // scheduling, returning visits
  violet: { solid: '#8B7FE8', soft: '#EAE6FD', text: '#4C3DAF' }, // clinical: with a doctor, billing
  amber: { solid: '#F5A623', soft: '#FEF0DA', text: '#8A5406' }, // waiting, time-limited
  green: { solid: '#2FA96B', soft: '#DDFBEA', text: '#0C7A42' }, // money in, done
  red: { solid: '#E8593C', soft: '#FDE6E0', text: '#A32E17' }, // something is wrong
  slate: { solid: '#6B7488', soft: '#EFF2F8', text: '#333B4D' }, // neutral
  ink: { solid: '#0B0B0F', soft: '#ECEDF1', text: '#0B0B0F' },
};

export function tone(name: string | null | undefined): { solid: string; soft: string; text: string } {
  return TONES[name ?? 'slate'] ?? TONES.slate;
}

/** A card or tile in a meaning's soft fill. */
export function softFill(name: string): CSSProperties {
  return { backgroundColor: tone(name).soft };
}

/** The solid icon disc: a white glyph on the meaning's colour. */
export function solidDisc(name: string): CSSProperties {
  return { backgroundColor: tone(name).solid, color: '#FFFFFF' };
}

/** The quiet icon disc: the glyph in the meaning's text colour on its soft fill. */
export function disc(name: string): CSSProperties {
  return { backgroundColor: tone(name).soft, color: tone(name).text };
}

/**
 * Surfaces and text. Every card is white on the workspace, edged by the CRM's
 * hairline (`line`) with its restrained shadows; colour stays in icon discs,
 * status pills and thin accents. Headings and values are the portal's deep
 * navy, secondary text the CRM's readable grey.
 */
export const SURFACE = {
  card: 'rounded-[24px] border border-[#ECEEF2] bg-white shadow-[0_1px_2px_rgba(11,11,15,0.03),0_10px_30px_-14px_rgba(11,11,15,0.12)]',
  /** A card inside a card (figures, action tiles): white, hairline, the lighter shadow. */
  tile: 'border border-[#E4E7EC] bg-white shadow-[0_1px_2px_rgba(11,11,15,0.04),0_6px_18px_-10px_rgba(11,11,15,0.12)]',
  raised: 'shadow-[0_1px_2px_rgba(11,11,15,0.04),0_6px_18px_-10px_rgba(11,11,15,0.12)]',
  lift: 'hover:shadow-[0_2px_6px_rgba(11,11,15,0.05),0_20px_44px_-20px_rgba(11,11,15,0.20)]',
  inset: 'bg-[#F5F6F9]',
  line: 'border-[#ECEEF2]',
  primary: 'text-[#0B1F33]',
  secondary: 'text-[#565F73]',
  muted: 'text-[#6B7385]',
} as const;

/** Status pills: the CRM's soft fill, dot and text for each meaning. */
export const PILL: Record<'good' | 'warn' | 'bad' | 'info' | 'violet' | 'neutral', { pill: string; dot: string }> = {
  good: { pill: 'bg-[#DDFBEA] text-[#0C7A42]', dot: 'bg-[#2FA96B]' },
  warn: { pill: 'bg-[#FEF0DA] text-[#8A5406]', dot: 'bg-[#F5A623]' },
  bad: { pill: 'bg-[#FDE6E0] text-[#A32E17]', dot: 'bg-[#E8593C]' },
  info: { pill: 'bg-[#DEEAFE] text-[#1741A6]', dot: 'bg-[#2563EB]' },
  violet: { pill: 'bg-[#EAE6FD] text-[#4C3DAF]', dot: 'bg-[#8B7FE8]' },
  neutral: { pill: 'bg-[#EFF2F8] text-[#333B4D]', dot: 'bg-[#6B7488]' },
};
