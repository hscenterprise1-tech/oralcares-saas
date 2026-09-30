import React from 'react';
import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/*
 * Branches, departments and rooms are one admin area under `branch.manage`,
 * so each of the three pages carries the same row of links to the others.
 */

const TABS = [
  { label: 'Branches', route: 'admin.branches.index' },
  { label: 'Departments', route: 'admin.departments.index' },
  { label: 'Rooms', route: 'admin.rooms.index' },
] as const;

export function InfrastructureTabs({ current }: { current: (typeof TABS)[number]['label'] }) {
  return (
    <nav aria-label="Clinic setup" className="mb-5 overflow-x-auto">
      <div className="inline-flex items-center gap-0.5 rounded-full bg-ovs-crm-surface-inset p-1">
        {TABS.map((tab) => (
          <Link
            key={tab.route}
            href={route(tab.route)}
            aria-current={tab.label === current ? 'page' : undefined}
            className={cn(
              'whitespace-nowrap rounded-full px-4 py-1.5 text-[12.5px] font-semibold transition-colors',
              tab.label === current
                ? 'bg-white text-ovs-crm-text-primary shadow-ovs-crmPanel'
                : 'text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary',
            )}
          >
            {tab.label}
          </Link>
        ))}
      </div>
    </nav>
  );
}
