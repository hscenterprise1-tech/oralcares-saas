import React from 'react';
import { avatarHexForName } from '@/pages/Company/design';

/**
 * The clinic identity slot — logo alone when there is one, the clinic's own
 * name as a styled hero element when there isn't. Never both, and never a
 * generic avatar/monogram box.
 *
 * The boxed-monogram pass before this one gave every logo-less clinic a
 * uniform coloured tile with one letter in it — consistent across 100k
 * clinics, but it read as small and generic, the exact "just a placeholder"
 * feeling a real identity slot shouldn't have. A clinic's actual name, set
 * large and bold, carries more identity than a single initial ever will,
 * and it needs no fallback path of its own because it's just... the name.
 * The logo case is unchanged from the robust height-only treatment from a
 * few passes back: fixed height, free width up to a cap, `object-contain`,
 * so it can't crop, distort, or force this slot to grow.
 */
export function ClinicIdentity({
  name,
  logoUrl,
  /** Logo height in pixels — 56–64 for a hero moment, smaller for a
   * compact header or a settings-page preview. */
  logoHeight = 60,
  /** Hero name font-size in pixels, used only when there's no logo. */
  nameSize = 34,
  multiline = true,
  className,
}: {
  name: string;
  logoUrl?: string | null;
  logoHeight?: number;
  nameSize?: number;
  /** Two-line clamp for a hero moment (the default) vs a single truncated
   * line for a compact horizontal spot like a header. */
  multiline?: boolean;
  className?: string;
}) {
  if (logoUrl) {
    return (
      <img
        src={logoUrl}
        alt={name}
        className={`w-auto shrink-0 rounded-[14px] border border-ovs-crm-border bg-white object-contain ${className ?? ''}`}
        style={{ height: logoHeight, maxWidth: logoHeight * 2.6 }}
      />
    );
  }

  // A dark-to-accent gradient rather than accent-to-white: this slot sits on
  // light backgrounds everywhere it's used (the pre-auth mesh, the portal
  // canvas, the settings card), and a wash that ends in white would fade
  // straight into all three. Ending in the clinic's own hash-derived hue
  // keeps every clinic's name a shade different from the next one's,
  // without a box or an avatar carrying that difference instead.
  const hue = avatarHexForName(name);

  return (
    <p
      title={name}
      className={`${multiline ? 'line-clamp-2' : 'truncate'} font-extrabold leading-[1.08] tracking-[-0.02em] ${className ?? ''}`}
      style={{
        fontSize: nameSize,
        backgroundImage: `linear-gradient(90deg, #0B0B0F 0%, ${hue} 100%)`,
        WebkitBackgroundClip: 'text',
        backgroundClip: 'text',
        color: 'transparent',
      }}
    >
      {name}
    </p>
  );
}
