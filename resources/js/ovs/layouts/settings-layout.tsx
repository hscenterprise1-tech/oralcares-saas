import React, { useState } from 'react';
import { cn } from '@/lib/utils';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/ovs/navigation/tabs';

export interface SettingsSection {
  id: string;
  label: string;
  icon?: React.ElementType;
  description?: string;
  count?: number | string;
  content: React.ReactNode;
}

export interface SettingsLayoutProps {
  sections: SettingsSection[];
  defaultSection?: string;
  title?: string;
  subtitle?: string;
  className?: string;
}

export function SettingsLayout({
  sections,
  defaultSection,
  title = 'Settings',
  subtitle = 'Manage your organization configurations and operational preferences.',
  className,
}: SettingsLayoutProps) {
  const initialValue = defaultSection || sections[0]?.id || '';
  const [activeTab, setActiveTab] = useState(initialValue);

  return (
    <div className={cn('space-y-5 font-sans text-slate-900', className)}>
      {/* ── 1. SETTINGS TOP TITLE STRIP ────────────────────────────────────── */}
      {(title || subtitle) && (
        <div className="pb-3.5 border-b border-slate-200/80">
          <h1 className="text-lg font-bold text-slate-900 tracking-tight">{title}</h1>
          {subtitle && <p className="text-xs text-slate-500 mt-0.5">{subtitle}</p>}
        </div>
      )}

      {/* ── 2. MAIN SETTINGS CONTAINER (TABS) ──────────────────────────────── */}
      <Tabs
        value={activeTab}
        onValueChange={setActiveTab}
        orientation="vertical"
        className="flex flex-col md:flex-row gap-5 items-start w-full"
      >
        {/* Mobile Dropdown View Picker (< 768px) */}
        <div className="w-full md:hidden p-3 bg-slate-50 border border-slate-200/80 rounded-xl space-y-1.5">
          <label className="text-[10px] font-mono uppercase font-bold text-slate-500 block">
            Navigation Section
          </label>
          <select
            value={activeTab}
            onChange={(e) => setActiveTab(e.target.value)}
            className="w-full h-9 px-3 text-xs bg-white border border-slate-200 rounded-lg text-slate-900 font-semibold focus:outline-none focus:border-teal-500"
          >
            {sections.map((sec) => (
              <option key={sec.id} value={sec.id}>
                {sec.label}
              </option>
            ))}
          </select>
        </div>

        {/* Left Vertical Navigation Rail (>= 768px) */}
        <div className="hidden md:block w-60 lg:w-64 flex-shrink-0 bg-white border border-slate-200/90 p-1.5 rounded-xl shadow-2xs space-y-1">
          <div className="px-2.5 py-1.5 text-[10px] font-mono font-bold uppercase tracking-wider text-slate-400">
            Configuration
          </div>

          <div className="space-y-0.5">
            {sections.map((sec) => {
              const Icon = sec.icon;
              const isActive = activeTab === sec.id;

              return (
                <button
                  key={sec.id}
                  type="button"
                  onClick={() => setActiveTab(sec.id)}
                  className={cn(
                    'w-full flex items-start gap-2.5 px-2.5 py-2 rounded-lg text-left transition-all duration-150 cursor-pointer select-none',
                    isActive
                      ? 'bg-slate-50 text-slate-900 font-bold border-l-2 border-teal-700 rounded-l-none'
                      : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50/70'
                  )}
                >
                  {Icon && (
                    <Icon
                      className={cn(
                        'w-4 h-4 flex-shrink-0 mt-0.5 transition-colors',
                        isActive ? 'text-teal-700' : 'text-slate-400'
                      )}
                    />
                  )}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-1">
                      <span className="text-xs tracking-tight truncate block leading-tight">
                        {sec.label}
                      </span>
                      {sec.count !== undefined && (
                        <span
                          className={cn(
                            'text-[9.5px] font-mono font-bold px-1.5 py-0.2 rounded-full shrink-0',
                            isActive ? 'bg-teal-100 text-teal-800' : 'bg-slate-100 text-slate-500'
                          )}
                        >
                          {sec.count}
                        </span>
                      )}
                    </div>
                    {sec.description && (
                      <p className="text-[10.5px] font-normal text-slate-400 truncate mt-0.5">
                        {sec.description}
                      </p>
                    )}
                  </div>
                </button>
              );
            })}
          </div>
        </div>

        {/* Right Active Content Surface */}
        <div className="flex-1 w-full bg-white border border-slate-200/90 rounded-xl p-5 sm:p-6 shadow-2xs">
          {sections.map((sec) => (
            <div
              key={sec.id}
              className={cn(
                'space-y-5 animate-in fade-in-0 duration-150',
                activeTab === sec.id ? 'block' : 'hidden'
              )}
            >
              <div className="pb-3.5 border-b border-slate-100 flex items-center gap-3">
                {sec.icon && (
                  <div className="w-8 h-8 rounded-lg bg-teal-50 border border-teal-200/60 flex items-center justify-center text-teal-700 shrink-0">
                    <sec.icon className="w-4 h-4" />
                  </div>
                )}
                <div>
                  <h2 className="text-sm font-bold text-slate-900 tracking-tight">
                    {sec.label}
                  </h2>
                  {sec.description && (
                    <p className="text-xs text-slate-500 mt-0.5">{sec.description}</p>
                  )}
                </div>
              </div>

              <div className="pt-1">{sec.content}</div>
            </div>
          ))}
        </div>
      </Tabs>
    </div>
  );
}
