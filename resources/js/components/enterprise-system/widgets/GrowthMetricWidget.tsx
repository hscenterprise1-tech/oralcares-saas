import React from 'react';
import { WidgetProps } from '../manifests/WidgetManifest';
import { ArrowRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';

interface GrowthMetricWidgetProps extends WidgetProps {
    value: string | number;
    trend: number;
    isGood: boolean;
    subtitle?: string;
    attention?: boolean;
}

export function GrowthMetricWidget({ manifest, value, trend, isGood, subtitle, attention }: GrowthMetricWidgetProps) {
    const handleDrillDown = () => {
        if (manifest.drillDownRoute) {
            router.visit(manifest.drillDownRoute);
        }
    };

    return (
        <div 
            onClick={handleDrillDown}
            className="bg-white rounded-[16px] p-5 border border-[#E2E8F0] shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 ease-out group cursor-pointer relative overflow-hidden flex flex-col justify-between h-full min-h-[110px]"
        >
            {attention && <div className="absolute top-0 left-0 w-full h-1 bg-amber-500" />}
            <div className="flex items-center justify-between mb-2">
                <span className="text-[13px] font-semibold text-[#64748B] tracking-wide">{manifest.title}</span>
                <ArrowRight className="w-4 h-4 text-[#CBD5E1] opacity-0 -translate-x-2 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-200" />
            </div>
            <div className="text-[28px] font-bold text-[#0F172A] tracking-tight mb-2 leading-none">{value}</div>
            <div className="flex items-center gap-1.5 mt-auto">
                <Badge 
                    variant="secondary" 
                    className={cn(
                        "px-1.5 py-0 text-[11px] font-bold rounded flex items-center gap-0.5 border shadow-sm",
                        isGood 
                            ? "bg-emerald-50 text-emerald-700 border-emerald-200/50" 
                            : "bg-amber-50 text-amber-700 border-amber-200/50"
                    )}
                >
                    {trend > 0 ? '+' : ''}{trend}%
                </Badge>
                {subtitle && <span className="text-[12px] font-medium text-[#94A3B8] truncate">{subtitle}</span>}
            </div>
        </div>
    );
}
