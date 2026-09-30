import React, { Suspense, useMemo } from 'react';
import { WidgetRegistry } from './WidgetRegistry';
import { WidgetManifest } from '../manifests/WidgetManifest';
import { usePage } from '@inertiajs/react';
import { EnterpriseWidget } from '../EnterpriseWidget'; // Base wrapper with skeleton

interface DashboardZoneProps {
    zone: WidgetManifest['zone'];
    className?: string;
}

export function DashboardZone({ zone, className }: DashboardZoneProps) {
    // 1. Fetch widgets for this zone
    const widgets = useMemo(() => {
        // Initialize registry if needed
        WidgetRegistry.initialize();
        return WidgetRegistry.getByZone(zone);
    }, [zone]);

    // 2. Filter by permissions
    // In a real app, this would check against the authenticated user's permissions
    const { auth } = usePage().props as any;
    const userPermissions = auth?.user?.permissions || [];
    const isCeo = userPermissions.includes('role:ceo');

    const authorizedWidgets = useMemo(() => {
        // Dummy logic for demonstration: assume full access if CEO, else check permissions
        return widgets.filter(widget => {
            if (isCeo) return true;
            if (!widget.permissions || widget.permissions.length === 0) return true;
            // Return true if user has AT LEAST ONE required permission
            return widget.permissions.some(p => userPermissions.includes(p));
        });
    }, [widgets, userPermissions, isCeo]);

    if (authorizedWidgets.length === 0) {
        return null; // The engine collapses empty zones seamlessly
    }

    // Sort by priority (1 loads/shows first)
    const sortedWidgets = [...authorizedWidgets].sort((a, b) => a.priority - b.priority);

    return (
        <div className={`grid grid-cols-1 md:grid-cols-12 gap-6 ${className || ''}`}>
            {sortedWidgets.map(manifest => {
                const WidgetComponent = manifest.component;
                
                return (
                    <div 
                        key={manifest.id} 
                        style={{ gridColumn: `span ${manifest.defaultSpan} / span ${manifest.defaultSpan}` }}
                        className="col-span-12" // Mobile fallback
                    >
                        <Suspense fallback={
                            <EnterpriseWidget 
                                title={manifest.title} 
                                isLoading={true} 
                            >
                                <div />
                            </EnterpriseWidget>
                        }>
                            <WidgetComponent manifest={manifest} isRefreshing={false} isEditingMode={false} />
                        </Suspense>
                    </div>
                );
            })}
        </div>
    );
}
