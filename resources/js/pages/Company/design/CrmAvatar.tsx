import React from 'react';
import { cn } from '@/lib/utils';
import { toneHex } from './crmTheme';
import type { CrmTone } from './crmTheme';

/**
 * Avatars and avatar stacks.
 *
 * Every reference design puts faces on the data — deal tiles, table rows,
 * schedule cells, journey columns. A CRM without them reads as a spreadsheet;
 * with them it reads as a record of people doing work.
 *
 * There are no uploaded photos in this system, so initials are the honest
 * representation. The colour is derived deterministically from the name, which
 * means the same person keeps the same colour on every screen — that
 * consistency is what makes initials feel intentional rather than like a
 * missing image.
 */

/**
 * Avatar hues.
 *
 * The palette stays vivid, but deliberately excludes amber/orange: behind two
 * initials at small size that hue reads harsh and cheapens an otherwise
 * considered page. Everywhere else — tiles, charts, badges — amber still
 * carries its meaning.
 *
 * Ordered so adjacent names in a stack rarely land on the same hue.
 */
const AVATAR_TONES: CrmTone[] = ['blue', 'teal', 'violet', 'green', 'coral', 'slate'];

/** Stable hash → tone, so a given name always renders the same colour. */
function toneForName(name: string): CrmTone {
  let hash = 0;
  for (let i = 0; i < name.length; i++) {
    hash = (hash << 5) - hash + name.charCodeAt(i);
    hash |= 0;
  }

  return AVATAR_TONES[Math.abs(hash) % AVATAR_TONES.length];
}

/**
 * The same deterministic name → colour this file uses for initials,
 * exposed for the surfaces that need the *colour* but render their own
 * mark instead of `CrmAvatar` itself — the clinic identity mark across the
 * pre-auth pages, `ClinicShell`'s header, and the branding settings
 * preview all have to agree on one clinic's colour, so they share this
 * rather than each hashing the name their own way.
 */
export function avatarHexForName(name: string): string {
  return toneHex(toneForName(name || '?'));
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();

  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

const SIZES = {
  xs: 'h-6 w-6 text-[10px]',
  sm: 'h-7 w-7 text-[10px]',
  md: 'h-9 w-9 text-[11.5px]',
  lg: 'h-11 w-11 text-[13px]',
  xl: 'h-20 w-20 text-[24px]',
} as const;

export interface CrmAvatarProps {
  name: string;
  size?: keyof typeof SIZES;
  /** White ring — used when avatars overlap or sit on colour. */
  ring?: boolean;
  /** Small status dot on the lower right. */
  status?: 'online' | 'busy' | 'none';
  className?: string;
}

export function CrmAvatar({ name, size = 'md', ring = false, status = 'none', className }: CrmAvatarProps) {
  const tone = toneForName(name || '?');

  return (
    <span className={cn('relative inline-flex shrink-0', className)}>
      <span
        className={cn(
          'inline-flex items-center justify-center rounded-full font-bold text-white',
          SIZES[size],
          ring && 'ring-2 ring-white'
        )}
        style={{ backgroundColor: toneHex(tone) }}
        title={name}
        aria-label={name}
      >
        {initials(name || '?')}
      </span>
      {status !== 'none' && (
        <span
          className={cn(
            'absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white',
            status === 'online' ? 'bg-ovs-crm-accent-green' : 'bg-ovs-crm-warning'
          )}
          aria-hidden="true"
        />
      )}
    </span>
  );
}

/**
 * Overlapping avatar stack with a +N overflow chip — the pattern used on the
 * reference deal tiles and journey columns.
 */
export function CrmAvatarStack({
  people,
  max = 4,
  size = 'sm',
  onDark = false,
  className,
}: {
  people: { name: string; role?: string }[];
  max?: number;
  size?: keyof typeof SIZES;
  /** Rings switch to a translucent white when the stack sits on a dark tile. */
  onDark?: boolean;
  className?: string;
}) {
  if (people.length === 0) return null;

  const shown = people.slice(0, max);
  const overflow = people.length - shown.length;

  return (
    <div className={cn('flex items-center', className)}>
      <div className="flex -space-x-2">
        {shown.map((person, index) => (
          <span
            key={`${person.name}-${index}`}
            className={cn('rounded-full', onDark ? 'ring-2 ring-ovs-crm-ink' : 'ring-2 ring-white')}
            style={{ zIndex: shown.length - index }}
          >
            <CrmAvatar name={person.name} size={size} />
          </span>
        ))}
      </div>

      {overflow > 0 && (
        <span
          className={cn(
            'ml-1.5 inline-flex items-center justify-center rounded-full px-1.5 font-bold',
            SIZES[size].replace(/h-\d+ w-\d+/, 'h-7 min-w-7'),
            onDark ? 'bg-white/15 text-white' : 'bg-ovs-crm-surface-muted text-ovs-crm-text-secondary'
          )}
        >
          +{overflow}
        </span>
      )}
    </div>
  );
}
