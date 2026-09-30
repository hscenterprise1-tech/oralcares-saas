import React from 'react';
import { WidgetProps } from '../manifests/WidgetManifest';
import { EnterpriseWidget } from '../EnterpriseWidget';
import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { formatCurrencyCompact } from '@/ovs/utils/format';

export function PendingRenewalsWidget({ manifest, isRefreshing }: WidgetProps) {
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
                            <th className="px-6 py-3 text-[11px] font-semibold text-[#64748B] uppercase tracking-wider bg-[#F8FAFC]">Clinic</th>
                            <th className="px-6 py-3 text-[11px] font-semibold text-[#64748B] uppercase tracking-wider bg-[#F8FAFC]">Risk</th>
                            <th className="px-6 py-3 text-[11px] font-semibold text-[#64748B] uppercase tracking-wider bg-[#F8FAFC] text-right">Value</th>
                            <th className="px-6 py-3 text-[11px] font-semibold text-[#64748B] uppercase tracking-wider bg-[#F8FAFC] text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[#F1F5F9]">
                        <RenewalRow name="Smile Dental Care" risk="high" riskLabel="Expires Today" value={145000} />
                        <RenewalRow name="City Dental Clinic" risk="medium" riskLabel="3 Days Left" value={85000} />
                        <RenewalRow name="Elite Ortho" risk="high" riskLabel="Payment Failed" value={220000} />
                        <RenewalRow name="Sunrise Hospital" risk="low" riskLabel="14 Days Left" value={450000} />
                        <RenewalRow name="Dr. Mehta's Hub" risk="low" riskLabel="15 Days Left" value={115000} />
                    </tbody>
                </table>
            </div>
        </EnterpriseWidget>
    );
}

function RenewalRow({ name, risk, riskLabel, value }: any) {
    const riskColor = {
        high: 'text-rose-500 bg-rose-50 border-rose-200',
        medium: 'text-amber-500 bg-amber-50 border-amber-200',
        low: 'text-emerald-500 bg-emerald-50 border-emerald-200'
    }[risk as 'high' | 'medium' | 'low'];

    return (
        <tr className="hover:bg-[#F8FAFC] transition-colors duration-150 group cursor-pointer">
            <td className="px-6 py-3.5 whitespace-nowrap">
                <div className="text-[13.5px] font-medium text-[#0F172A]">{name}</div>
            </td>
            <td className="px-6 py-3.5">
                <div className={cn("inline-flex items-center gap-1.5 px-2 py-0.5 rounded border text-[11px] font-semibold", riskColor)}>
                    <AlertCircle className="w-3 h-3" />
                    {riskLabel}
                </div>
            </td>
            <td className="px-6 py-3.5 text-right">
                <div className="text-[13px] font-bold text-[#475569]">{formatCurrencyCompact(value)}</div>
            </td>
            <td className="px-6 py-3.5 text-center">
                <Button size="sm" variant="ghost" className="h-7 text-[12px] font-semibold text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 px-3 opacity-0 group-hover:opacity-100 transition-opacity">
                    Follow Up
                </Button>
            </td>
        </tr>
    );
}
