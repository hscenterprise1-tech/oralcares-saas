import React from 'react';
import { WidgetProps } from '../manifests/WidgetManifest';
import { EnterpriseWidget } from '../EnterpriseWidget';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';

export function CompanyActivityWidget({ manifest, isRefreshing }: WidgetProps) {
    const handleDrillDown = () => router.visit(manifest.drillDownRoute);

    return (
        <EnterpriseWidget 
            title={manifest.title}
            description={manifest.description}
            isRefreshing={isRefreshing}
            onExpand={handleDrillDown}
            className="h-[400px]" // Match operations height
        >
            <div className="flex-1 search-scroll h-full -mx-2 -mb-2 overflow-y-auto">
                <div className="relative border-l border-[#E2E8F0] ml-3.5 space-y-6 mt-4">
                    <ActivityItem 
                        name="Amit Shah" 
                        dept="Sales" 
                        action="closed Demo with" 
                        target="Smile Dental" 
                        time="10m ago" 
                        type="sales" 
                        initials="AS"
                    />
                    <ActivityItem 
                        name="System" 
                        dept="Billing" 
                        action="flagged Invoice #1024 as" 
                        target="Overdue" 
                        time="1h ago" 
                        type="system"
                        initials="SY" 
                        priority="critical"
                    />
                    <ActivityItem 
                        name="Priya Patel" 
                        dept="Support" 
                        action="resolved High Priority Ticket for" 
                        target="City Dental" 
                        time="2h ago" 
                        type="support" 
                        initials="PP"
                        priority="important"
                    />
                    <ActivityItem 
                        name="Rahul Desai" 
                        dept="Sales" 
                        action="sent Proposal to" 
                        target="Metro Hospitals" 
                        time="3h ago" 
                        type="sales" 
                        initials="RD"
                    />
                    <ActivityItem 
                        name="System" 
                        dept="Provisioning" 
                        action="activated new instance for" 
                        target="Apollo Dental Hub" 
                        time="5h ago" 
                        type="system" 
                        initials="SY"
                    />
                </div>
            </div>
        </EnterpriseWidget>
    );
}

function ActivityItem({ name, dept, action, target, time, type, initials, priority }: any) {
    const typeColor = {
        sales: 'bg-blue-100 text-blue-700 ring-blue-50',
        support: 'bg-amber-100 text-amber-700 ring-amber-50',
        system: 'bg-slate-100 text-slate-700 ring-slate-50',
    }[type as 'sales'|'support'|'system'];

    const dotColor = priority === 'critical' ? 'bg-rose-500 border-rose-200' :
                     priority === 'important' ? 'bg-amber-500 border-amber-200' :
                     'bg-[#CBD5E1] border-white';

    return (
        <div className="relative flex gap-4 pb-2">
            <span className={cn("absolute -left-[27px] top-1 h-3.5 w-3.5 rounded-full border-2", dotColor)} />
            <div className={cn("w-8 h-8 rounded-full flex items-center justify-center text-[10.5px] font-bold shrink-0 mt-0.5", typeColor)}>
                {initials}
            </div>
            <div className="flex-1 min-w-0 pt-1">
                <p className="text-[13px] text-[#334155] leading-relaxed">
                    <span className="font-bold text-[#0F172A]">{name}</span> <span className="text-[#94A3B8]">({dept})</span> {action} <span className="font-bold text-[#0F172A]">{target}</span>
                </p>
                <p className="text-[11.5px] font-medium text-[#94A3B8] mt-1">{time}</p>
            </div>
        </div>
    );
}
