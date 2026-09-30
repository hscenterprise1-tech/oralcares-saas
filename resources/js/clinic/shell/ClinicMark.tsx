import { useState } from 'react';
import { cn } from '@/lib/utils';

const SIZES = {
  sm: 'h-8 w-8 rounded-lg text-[12px]',
  md: 'h-10 w-10 rounded-xl text-[14px]',
  lg: 'h-16 w-16 rounded-2xl text-[22px]',
} as const;

export function initials(name: string): string {
  const words = name
    .replace(/[^\p{L}\p{N}\s]/gu, ' ')
    .split(/\s+/)
    .filter(Boolean);

  if (words.length === 0) {
    return '·';
  }

  return (words.length === 1 ? words[0].slice(0, 2) : `${words[0][0]}${words[1][0]}`).toUpperCase();
}

/**
 * The clinic's logo, or its initials on the clinic brand colour when it has
 * none (or the logo fails to load). Decorative: the clinic's name is always
 * written beside it.
 */
export function ClinicMark({
  name,
  logoUrl,
  size = 'md',
  className,
}: {
  name: string;
  logoUrl: string | null;
  size?: keyof typeof SIZES;
  className?: string;
}) {
  const [failed, setFailed] = useState(false);

  if (logoUrl && !failed) {
    return (
      <img
        src={logoUrl}
        alt=""
        onError={() => setFailed(true)}
        className={cn(
          'shrink-0 border border-clinic-border bg-clinic-surface object-contain p-1',
          SIZES[size],
          className,
        )}
      />
    );
  }

  return (
    <span
      aria-hidden="true"
      className={cn(
        'flex shrink-0 items-center justify-center bg-clinic-brand-strong font-bold tracking-tight text-white',
        SIZES[size],
        className,
      )}
    >
      {initials(name)}
    </span>
  );
}
