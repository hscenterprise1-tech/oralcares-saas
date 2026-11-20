import { LinkTabs } from '@/clinic/ui/Tabs';

export interface InfraLinks {
  branches: string;
  departments: string;
  rooms: string;
}

/** The three places a clinic's layout is set up, as tabs above each. The hrefs come from the server. */
export function InfraTabs({ active, links }: { active: keyof InfraLinks; links: InfraLinks }) {
  return (
    <LinkTabs
      label="Clinic layout"
      className="mb-4"
      tabs={[
        { key: 'branches', label: 'Branches', href: links.branches, active: active === 'branches' },
        { key: 'departments', label: 'Departments', href: links.departments, active: active === 'departments' },
        { key: 'rooms', label: 'Rooms & chairs', href: links.rooms, active: active === 'rooms' },
      ]}
    />
  );
}

/** A lowercase key made from a name, for the unique `key`/`slug` the server asks for. */
export const keyOf = (name: string) => name.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 50);
