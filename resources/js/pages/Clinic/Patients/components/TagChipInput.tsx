import React, { useState } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * A free-text, multi-value chip input — Allergies and Alerts are both
 * open-ended lists a receptionist types out, not a fixed vocabulary a
 * `CrmSelect` could offer, so this is a local field rather than a shared
 * design-system control (nothing else in the CRM needs it yet).
 *
 * Enter or a comma commits the current text as a chip; Backspace on an
 * empty field removes the last one, matching the pattern most tag inputs
 * already use elsewhere on the web.
 */

const TONE_CLASSES: Record<'coral' | 'amber', string> = {
  coral: 'bg-ovs-crm-stage-coral-soft text-ovs-crm-stage-coral-text',
  amber: 'bg-ovs-crm-stage-amber-soft text-ovs-crm-stage-amber-text',
};

export function TagChipInput({
  value,
  onChange,
  placeholder,
  tone = 'coral',
}: {
  value: string[];
  onChange: (next: string[]) => void;
  placeholder?: string;
  tone?: 'coral' | 'amber';
}) {
  const [draft, setDraft] = useState('');

  /**
   * Splits on every comma in `raw` and commits each non-empty, not-yet-
   * present segment as its own chip.
   *
   * Driven by the input's resulting *value* rather than a single keydown —
   * a pasted "Penicillin, Latex, Sulfa" arrives as one `onChange` carrying
   * two commas at once, and a fast/programmatic fill can insert a comma
   * without ever dispatching a discrete keydown for it. Splitting whatever
   * text actually landed catches both, where a keydown-only comma check
   * would silently swallow everything after the first into one chip.
   */
  const commitMany = (raw: string) => {
    const parts = raw
      .split(',')
      .map((part) => part.trim())
      .filter((part) => part !== '' && !value.includes(part));

    if (parts.length > 0) {
      onChange([...value, ...parts]);
    }
  };

  const remove = (text: string) => onChange(value.filter((item) => item !== text));

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const next = e.target.value;
    if (!next.includes(',')) {
      setDraft(next);
      return;
    }

    const segments = next.split(',');
    const remainder = segments.pop() ?? '';
    commitMany(segments.join(','));
    setDraft(remainder);
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      commitMany(draft);
      setDraft('');
    } else if (e.key === 'Backspace' && draft === '' && value.length > 0) {
      remove(value[value.length - 1]);
    }
  };

  const handleBlur = () => {
    commitMany(draft);
    setDraft('');
  };

  return (
    <div className="flex min-h-11 w-full flex-wrap items-center gap-1.5 rounded-[16px] border border-ovs-crm-border bg-white px-3 py-2 transition-[box-shadow,border-color] focus-within:border-ovs-crm-ink/30 focus-within:ring-2 focus-within:ring-ovs-crm-ink/15 hover:border-ovs-crm-border-strong">
      {value.map((item) => (
        <span
          key={item}
          className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[12px] font-medium', TONE_CLASSES[tone])}
        >
          {item}
          <button
            type="button"
            onClick={() => remove(item)}
            className="rounded-full opacity-70 hover:opacity-100"
            aria-label={`Remove ${item}`}
          >
            <X className="h-3 w-3" aria-hidden="true" />
          </button>
        </span>
      ))}
      <input
        value={draft}
        onChange={handleInputChange}
        onKeyDown={handleKeyDown}
        onBlur={handleBlur}
        placeholder={value.length === 0 ? placeholder : undefined}
        className="min-w-[120px] flex-1 border-0 bg-transparent text-[13px] text-ovs-crm-text-primary placeholder:text-ovs-crm-text-muted focus:outline-none"
      />
    </div>
  );
}
