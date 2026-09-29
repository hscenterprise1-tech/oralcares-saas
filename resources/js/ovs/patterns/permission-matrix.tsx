import React from 'react';
import { cn } from '@/lib/utils';
import { Switch } from '@/ovs/primitives/switch';

export interface PermissionRole {
  id: string;
  name: string;
}

export interface PermissionItem {
  id: string;
  label: string;
  category?: string;
  description?: string;
}

export interface PermissionMatrixProps {
  roles: PermissionRole[];
  permissions: PermissionItem[];
  grants: Record<string, Record<string, boolean>>; // grants[roleId][permissionId] = boolean
  onToggle: (roleId: string, permissionId: string, value: boolean) => void;
  className?: string;
}

export function PermissionMatrix({
  roles,
  permissions,
  grants,
  onToggle,
  className,
}: PermissionMatrixProps) {
  // Group permissions by category
  const categoriesMap: Record<string, PermissionItem[]> = {};
  permissions.forEach((p) => {
    const cat = p.category || 'General Access';
    if (!categoriesMap[cat]) categoriesMap[cat] = [];
    categoriesMap[cat].push(p);
  });

  return (
    <div className={cn('w-full bg-white border border-ovs-border-subtle rounded-2xl shadow-2xs overflow-hidden', className)}>
      <div className="overflow-x-auto max-h-[500px] overflow-y-auto">
        <table className="w-full text-left border-collapse min-w-[640px]">
          {/* Sticky Header Row (Roles) */}
          <thead className="bg-ovs-background border-b border-ovs-border-subtle sticky top-0 z-20">
            <tr>
              <th className="p-3.5 text-xs font-bold text-ovs-primary sticky left-0 bg-ovs-background z-30 min-w-[220px] shadow-[2px_0_4px_rgba(0,0,0,0.02)]">
                Permissions & Capabilities
              </th>
              {roles.map((role) => (
                <th key={role.id} className="p-3.5 text-xs font-bold text-ovs-primary text-center min-w-[120px]">
                  {role.name}
                </th>
              ))}
            </tr>
          </thead>

          <tbody className="divide-y divide-ovs-border-subtle">
            {Object.entries(categoriesMap).map(([category, items]) => (
              <React.Fragment key={category}>
                {/* Category Header */}
                <tr className="bg-ovs-background/60">
                  <td
                    colSpan={roles.length + 1}
                    className="px-3.5 py-2 text-[10px] font-bold text-ovs-accent uppercase font-mono tracking-wider border-y border-ovs-border-subtle"
                  >
                    {category}
                  </td>
                </tr>

                {/* Category Items */}
                {items.map((perm) => (
                  <tr key={perm.id} className="hover:bg-ovs-background/40 transition-colors">
                    {/* Permission Name Column (Sticky left) */}
                    <td className="p-3.5 text-xs font-medium text-ovs-primary sticky left-0 bg-white z-10 shadow-[2px_0_4px_rgba(0,0,0,0.02)]">
                      <span className="block font-bold">{perm.label}</span>
                      {perm.description && (
                        <span className="block text-[10px] text-ovs-secondary font-normal mt-0.5">{perm.description}</span>
                      )}
                    </td>

                    {/* Role Toggles */}
                    {roles.map((role) => {
                      const isChecked = Boolean(grants[role.id]?.[perm.id]);
                      return (
                        <td key={`${role.id}-${perm.id}`} className="p-3.5 text-center align-middle">
                          <div className="flex justify-center">
                            <Switch
                              checked={isChecked}
                              onCheckedChange={(checked) => onToggle(role.id, perm.id, checked)}
                              aria-label={`Grant ${perm.label} to ${role.name}`}
                            />
                          </div>
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
