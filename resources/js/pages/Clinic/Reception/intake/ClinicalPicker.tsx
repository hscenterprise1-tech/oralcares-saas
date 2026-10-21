import { useId, useMemo, useState, type KeyboardEvent } from 'react';
import { Check, Plus, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Input } from '@/clinic';
import { chipClass } from '@/clinic';
import type { CatalogueItem } from './types';

/** A chosen entry: a catalogue key, or a typed name the server checks. */
export type ClinicalChoice = { key: string; label: string } | { key?: undefined; label: string };

const SEVERITY_WORD: Record<string, string> = { critical: 'critical', high: 'high' };

/**
 * Pick conditions or allergies by tapping, not typing. The clinic's first
 * few items (its own order) show as chips; "+ More" opens a search over the
 * rest inline, in the same dialog. Typing a name not in the list adds it as
 * a custom entry when `allowCustom` is on. Items come from the server's
 * catalogue for this clinic and specialty; nothing is listed in code.
 */
export function ClinicalPicker({
  label,
  items,
  value,
  onChange,
  allowCustom = true,
  quickCount = 6,
  addLabel,
}: {
  label: string;
  items: CatalogueItem[];
  value: ClinicalChoice[];
  onChange: (value: ClinicalChoice[]) => void;
  allowCustom?: boolean;
  quickCount?: number;
  addLabel: string;
}) {
  const [searching, setSearching] = useState(false);
  const [query, setQuery] = useState('');
  const searchId = useId();
  const chosen = (item: CatalogueItem) => value.some((choice) => choice.key === item.key);
  const toggle = (item: CatalogueItem) =>
    onChange(chosen(item) ? value.filter((choice) => choice.key !== item.key) : [...value, { key: item.key, label: item.name }]);

  // Items the clinic marked required are always among the quick chips, first.
  const ordered = [...items.filter((item) => item.required), ...items.filter((item) => !item.required)];
  const quick = ordered.slice(0, Math.max(quickCount, items.filter((item) => item.required).length));
  // Anything chosen from "More" stays visible as a chip too.
  const shown = [...quick, ...ordered.slice(quick.length).filter(chosen)];
  const custom = value.filter((choice) => !choice.key);

  const matches = useMemo(() => {
    const q = query.trim().toLowerCase();

    return q === '' ? items : items.filter((item) => item.name.toLowerCase().includes(q));
  }, [items, query]);
  const exact = items.some((item) => item.name.toLowerCase() === query.trim().toLowerCase()) || custom.some((choice) => choice.label.toLowerCase() === query.trim().toLowerCase());

  const addCustom = () => {
    const name = query.trim();
    if (!allowCustom || name === '' || exact) {
      return;
    }
    onChange([...value, { label: name }]);
    setQuery('');
  };

  const onSearchKey = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      if (matches.length === 1) {
        toggle(matches[0]);
        setQuery('');
      } else {
        addCustom();
      }
    }
    if (event.key === 'Escape' && query !== '') {
      event.stopPropagation();
      setQuery('');
    }
  };

  return (
    <fieldset className="min-w-0">
      <legend className="mb-2 text-[12px] font-semibold text-clinic-ink">{label}</legend>
      <div className="flex flex-wrap gap-2">
        {shown.map((item) => {
          const selected = chosen(item);
          const severe = item.severity ? SEVERITY_WORD[item.severity] : undefined;

          return (
            <button key={item.key} type="button" aria-pressed={selected} onClick={() => toggle(item)} className={chipClass(selected)}>
              {selected && <Check className="h-3.5 w-3.5" aria-hidden="true" />}
              {item.name}
              {severe && <span className="sr-only"> ({severe} severity)</span>}
            </button>
          );
        })}
        {custom.map((choice) => (
          <span key={choice.label} className={cn(chipClass(true), 'pr-1.5')}>
            {choice.label}
            <button
              type="button"
              onClick={() => onChange(value.filter((entry) => entry !== choice))}
              aria-label={`Remove ${choice.label}`}
              className="flex h-6 w-6 items-center justify-center rounded-full hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
            >
              <X className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </span>
        ))}
        <button type="button" aria-expanded={searching} aria-controls={searchId} onClick={() => setSearching((open) => !open)} className={chipClass(false)}>
          <Plus className="h-3.5 w-3.5" aria-hidden="true" />
          {items.length > quickCount ? 'More' : addLabel}
        </button>
      </div>

      {searching && (
        <div id={searchId} className="mt-3 rounded-2xl border border-clinic-border bg-clinic-inset/50 p-3">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-clinic-secondary" aria-hidden="true" />
            <Input
              autoFocus
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              onKeyDown={onSearchKey}
              aria-label={`Search ${label.toLowerCase()}`}
              placeholder={allowCustom ? 'Search, or type a name and press Enter' : 'Search'}
              className="pl-9"
            />
          </div>
          <div className="mt-2.5 flex max-h-40 flex-wrap gap-2 overflow-y-auto">
            {matches.map((item) => (
              <button key={item.key} type="button" aria-pressed={chosen(item)} onClick={() => toggle(item)} className={chipClass(chosen(item))}>
                {chosen(item) && <Check className="h-3.5 w-3.5" aria-hidden="true" />}
                {item.name}
              </button>
            ))}
            {allowCustom && query.trim() !== '' && !exact && (
              <button type="button" onClick={addCustom} className={chipClass(false)}>
                <Plus className="h-3.5 w-3.5" aria-hidden="true" />
                Add “{query.trim()}”
              </button>
            )}
            {matches.length === 0 && !allowCustom && <p className="text-[12.5px] text-clinic-secondary">Nothing matches.</p>}
          </div>
        </div>
      )}
    </fieldset>
  );
}

/** What the registration request takes for a picker's choices. */
export function toEntries(choices: ClinicalChoice[]): ({ key: string; label: string } | { label: string })[] {
  return choices.map((choice) => (choice.key ? { key: choice.key, label: choice.label } : { label: choice.label }));
}
