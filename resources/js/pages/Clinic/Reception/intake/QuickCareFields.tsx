import { AlertTriangle, Flag } from 'lucide-react';
import { cn } from '@/lib/utils';
import { chipClass } from '@/clinic';
import type { CatalogueItem } from './types';

/**
 * Operational and safety alerts from the clinic's list (fall risk, needs
 * assistance): flags the team should see, never a diagnosis. Tap to toggle.
 */
export function AlertPicker({ items, value, onChange }: { items: CatalogueItem[]; value: string[]; onChange: (keys: string[]) => void }) {
  if (items.length === 0) {
    return null;
  }

  return (
    <div>
      <p className="mb-1.5 flex items-center gap-1.5 text-[12px] font-semibold text-clinic-ink">
        <Flag className="h-3.5 w-3.5 text-clinic-secondary" aria-hidden="true" />
        Alerts for the team
      </p>
      <div role="group" aria-label="Alerts for the team" className="flex flex-wrap gap-2">
        {items.map((item) => {
          const on = value.includes(item.key);

          return (
            <button
              key={item.key}
              type="button"
              aria-pressed={on}
              onClick={() => onChange(on ? value.filter((key) => key !== item.key) : [...value, item.key])}
              className={cn(chipClass(false), on && 'border-amber-500 bg-amber-50 text-amber-900')}
            >
              {on && <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" />}
              {item.name}
            </button>
          );
        })}
      </div>
    </div>
  );
}
