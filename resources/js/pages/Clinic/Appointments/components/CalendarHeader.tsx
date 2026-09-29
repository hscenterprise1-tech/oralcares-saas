import React, { useState } from 'react';
import { format } from 'date-fns';
import { ChevronLeft, ChevronRight, Plus, Search, X } from 'lucide-react';
import { CrmButton, CrmDatePicker, CrmIconButton } from '@/pages/Company/design';
import { CalendarView } from '../types';

/**
 * The calendar's own header bar — distinct from the page-level `CrmPageHead`
 * above it. Structure follows the two reference screenshots (Untitled UI's
 * Month view, CareDoc's Week view): a date-tile + title/subtitle on the
 * left, search + Today-stepper + Day/Week/Month toggle + "+ New
 * Appointment" on the right. Every colour is crmTheme, not the references'.
 */

const VIEW_OPTIONS: { value: CalendarView; label: string }[] = [
  { value: 'day', label: 'Day' },
  { value: 'week', label: 'Week' },
  { value: 'month', label: 'Month' },
];

export function CalendarHeader({
  date,
  view,
  headerTitle,
  headerSubtitle,
  searchQuery,
  onDateChange,
  onViewChange,
  onShift,
  onToday,
  onSearchChange,
  onNewAppointment,
}: {
  date: string;
  view: CalendarView;
  headerTitle: string;
  headerSubtitle: string | null;
  searchQuery: string;
  onDateChange: (date: string) => void;
  onViewChange: (view: CalendarView) => void;
  onShift: (direction: 1 | -1) => void;
  onToday: () => void;
  onSearchChange: (query: string) => void;
  onNewAppointment: () => void;
}) {
  const [searchOpen, setSearchOpen] = useState(false);
  const tileDate = new Date(`${date}T00:00:00`);

  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <CrmDatePicker
        value={date}
        onChange={(e) => onDateChange(e.target.value)}
        trigger={
          <button
            type="button"
            className="flex min-h-11 items-center gap-3 rounded-[18px] px-1.5 py-1 text-left transition-colors hover:bg-ovs-crm-surface-inset"
          >
            <span className="flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-[12px] border border-ovs-crm-border bg-white leading-none">
              <span className="text-[9.5px] font-bold uppercase tracking-[0.06em] text-ovs-crm-accent-violet">
                {format(tileDate, 'MMM')}
              </span>
              <span className="mt-0.5 text-[16px] font-bold text-ovs-crm-ink">{format(tileDate, 'd')}</span>
            </span>
            <span className="min-w-0">
              <span className="block truncate text-[16px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                {headerTitle}
              </span>
              {headerSubtitle && <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">{headerSubtitle}</span>}
            </span>
          </button>
        }
      />

      <div className="flex flex-wrap items-center gap-2">
        {searchOpen ? (
          <div className="relative">
            <Search className="pointer-events-none absolute left-3.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-ovs-crm-text-muted" aria-hidden="true" />
            <input
              autoFocus
              value={searchQuery}
              onChange={(e) => onSearchChange(e.target.value)}
              placeholder="Search this view…"
              className="h-11 w-44 rounded-full border border-ovs-crm-border bg-white pl-9 pr-9 text-[13px] text-ovs-crm-text-primary placeholder:text-ovs-crm-text-muted focus:border-ovs-crm-ink/30 focus:outline-none focus:ring-2 focus:ring-ovs-crm-ink/15 sm:w-56"
            />
            <button
              type="button"
              aria-label="Close search"
              onClick={() => {
                setSearchOpen(false);
                onSearchChange('');
              }}
              className="absolute right-2.5 top-1/2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-full text-ovs-crm-text-muted hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary"
            >
              <X className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </div>
        ) : (
          <CrmIconButton icon={Search} label="Search this view" tone="ghost" onClick={() => setSearchOpen(true)} />
        )}

        <div className="flex items-center rounded-full border border-ovs-crm-border bg-white p-1">
          <CrmIconButton icon={ChevronLeft} label="Previous" tone="ghost" onClick={() => onShift(-1)} />
          <button
            type="button"
            onClick={onToday}
            className="min-h-9 rounded-full px-3 text-[12.5px] font-semibold text-ovs-crm-text-primary transition-colors hover:bg-ovs-crm-surface-inset"
          >
            Today
          </button>
          <CrmIconButton icon={ChevronRight} label="Next" tone="ghost" onClick={() => onShift(1)} />
        </div>

        <div className="flex items-center rounded-full border border-ovs-crm-border bg-white p-1">
          {VIEW_OPTIONS.map((opt) => (
            <button
              key={opt.value}
              type="button"
              onClick={() => onViewChange(opt.value)}
              className={`min-h-9 rounded-full px-3.5 text-[12.5px] font-semibold transition-colors ${
                view === opt.value ? 'bg-ovs-crm-ink text-white' : 'text-ovs-crm-text-secondary hover:bg-ovs-crm-surface-inset'
              }`}
            >
              {opt.label}
            </button>
          ))}
        </div>

        <CrmButton tone="primary" icon={Plus} onClick={onNewAppointment} className="hidden sm:inline-flex">
          New Appointment
        </CrmButton>
        <CrmIconButton icon={Plus} label="New appointment" tone="ink" onClick={onNewAppointment} className="sm:hidden" />
      </div>
    </div>
  );
}

export default CalendarHeader;
