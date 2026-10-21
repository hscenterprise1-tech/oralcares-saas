import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { PersonAvatar } from '@/clinic/ui/PersonAvatar';
import { ageSex } from './format';

/**
 * A patient, the one way every patient page shows them: avatar, name, then
 * UHID · age · sex. Only what the server sent is shown.
 */
export function PatientIdentity({
  name,
  uhid,
  age,
  gender,
  size = 'md',
  trailing,
  className,
}: {
  name: string;
  uhid: string | null;
  age?: number | null;
  gender?: string | null;
  size?: 'sm' | 'md' | 'lg';
  /** Badges after the name (a status that needs noticing, alerts). */
  trailing?: ReactNode;
  className?: string;
}) {
  const facts = [uhid, ageSex(age, gender)].filter(Boolean) as string[];

  return (
    <div className={cn('flex min-w-0 items-center gap-3', className)}>
      <PersonAvatar name={name} size={size} />
      <div className="min-w-0">
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
          <span className={cn('truncate font-semibold text-cx-nav-ink', size === 'lg' ? 'text-[20px]' : 'text-[14px]')}>{name}</span>
          {trailing}
        </div>
        {facts.length > 0 && (
          <p className="mt-0.5 truncate text-[12.5px] text-cx-text-2">
            {facts.map((fact, index) => (
              <span key={fact}>
                {index > 0 && <span aria-hidden="true"> · </span>}
                <span className={index === 0 && uhid ? 'font-mono text-[12px] tracking-tight' : undefined}>{fact}</span>
              </span>
            ))}
          </p>
        )}
      </div>
    </div>
  );
}
