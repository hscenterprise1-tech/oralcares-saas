import React from 'react';
import { WidgetProps } from '../manifests/WidgetManifest';
import { EnterpriseWidget } from '../EnterpriseWidget';
import { Clock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';

export function TodaysDemosWidget({ manifest, isRefreshing }: WidgetProps) {
    const handleDrillDown = () => router.visit(manifest.drillDownRoute);

    return (
        <EnterpriseWidget 
            title={manifest.title}
            description={manifest.description}
            isRefreshing={isRefreshing}
            onExpand={handleDrillDown}
            className="h-[400px]"
        >
            <div className="overflow-x-auto search-scroll -mx-7 -mb-7">
                <table className="w-full text-left border-collapse">
                    <thead>
                        <tr className="border-b border-[#F1F5F9]">
                            <th className="px-6 py-3 text-[11px] font-semibold text-[#64748B] uppercase tracking-wider bg-[#F8FAFC]">Time</th>
                            <th className="px-6 py-3 text-[11px] font-semibold text-[#64748B] uppercase tracking-wider bg-[#F8FAFC]">Lead</th>
                            <th className="px-6 py-3 text-[11px] font-semibold text-[#64748B] uppercase tracking-wider bg-[#F8FAFC]">Representative</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[#F1F5F9]">
                        <DemoRow time="10:00 AM" lead="Metro Hospitals" rep="Amit Shah" initials="AS" color="bg-blue-100 text-blue-700" />
                        <DemoRow time="01:30 PM" lead="Care Dental Group" rep="Priya Patel" initials="PP" color="bg-emerald-100 text-emerald-700" />
                        <DemoRow time="03:00 PM" lead="White Smiles" rep="Rahul Desai" initials="RD" color="bg-amber-100 text-amber-700" />
                        <DemoRow time="04:15 PM" lead="Apollo Hub" rep="Sneha Reddy" initials="SR" color="bg-purple-100 text-purple-700" />
                        <DemoRow time="05:30 PM" lead="City Ortho Center" rep="Vikram Singh" initials="VS" color="bg-teal-100 text-teal-700" />
                    </tbody>
                </table>
            </div>
        </EnterpriseWidget>
    );
}

function DemoRow({ time, lead, rep, initials, color }: any) {
    return (
        <tr className="hover:bg-[#F8FAFC] transition-colors duration-150 cursor-pointer group">
            <td className="px-6 py-3.5 whitespace-nowrap">
                <div className="flex items-center gap-2 text-[13px] font-medium text-[#475569]">
                    <Clock className="w-3.5 h-3.5 text-[#94A3B8]" />
                    {time}
                </div>
            </td>
            <td className="px-6 py-3.5">
                <div className="text-[13.5px] font-medium text-[#0F172A]">{lead}</div>
            </td>
            <td className="px-6 py-3.5">
                <div className="flex items-center gap-2.5">
                    <div className={cn("w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-bold", color)}>
                        {initials}
                    </div>
                    <span className="text-[13px] font-medium text-[#475569]">{rep}</span>
                </div>
            </td>
        </tr>
    );
}
