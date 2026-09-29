import React from 'react';
import { WidgetProps } from '../manifests/WidgetManifest';
import { EnterpriseWidget } from '../EnterpriseWidget';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';

export function SalesPipelineWidget({ manifest, isRefreshing }: WidgetProps) {
    const handleDrillDown = () => router.visit(manifest.drillDownRoute);

    return (
        <EnterpriseWidget 
            title={manifest.title}
            description={manifest.description}
            isRefreshing={isRefreshing}
            onExpand={handleDrillDown}
            className="h-[360px]" // Fixed height to match Revenue
        >
            <div className="flex flex-col gap-2.5 mt-2 h-full">
                <FunnelStep name="New Leads" value={124} max={124} color="bg-slate-800" />
                <FunnelStep name="Qualified" value={95} max={124} color="bg-slate-700" drop="23% drop" />
                <FunnelStep name="Demo Scheduled" value={62} max={124} color="bg-slate-600" drop="35% drop" />
                <FunnelStep name="Proposal Sent" value={38} max={124} color="bg-teal-700" drop="38% drop" />
                <FunnelStep name="Closed Won" value={18} max={124} color="bg-teal-500" drop="52% drop" />
            </div>
        </EnterpriseWidget>
    );
}

function FunnelStep({ name, value, max, color, drop }: any) {
    const width = Math.max((value / max) * 100, 15) + '%';
    return (
        <div className="group relative flex-1 flex flex-col justify-center">
            <div className="flex items-center justify-between text-[12.5px] font-semibold text-[#475569] mb-1.5 px-1">
                <span>{name}</span>
                <span className="text-[#0F172A]">{value}</span>
            </div>
            <div className="w-full bg-[#F1F5F9] rounded-full h-8 overflow-hidden relative">
                <div 
                    className={cn("h-full rounded-full transition-all duration-500 ease-out flex items-center justify-end pr-3", color)} 
                    style={{ width }}
                >
                    {drop && <span className="text-[11px] font-bold text-white/80 opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap">{drop}</span>}
                </div>
            </div>
        </div>
    );
}
