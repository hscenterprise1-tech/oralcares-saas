import React from 'react';
import { cn } from '@/lib/utils';
import { SignatureCard } from './signature-card';

export interface PipelineSegmentData {
    label: string;
    value: number;
    colorClass: string;
    bgClass: string;
}

export interface PipelineDistributionProps extends React.HTMLAttributes<HTMLDivElement> {
    title?: string;
    subtitle?: string;
    action?: React.ReactNode;
    segments: PipelineSegmentData[];
}

export function PipelineDistribution({ 
    className, 
    title = "Pipeline Distribution", 
    subtitle = "Sales Velocity", 
    action,
    segments,
    ...props 
}: PipelineDistributionProps) {
    const total = segments.reduce((acc, curr) => acc + curr.value, 0);
    const getPercent = (val: number) => total === 0 ? 0 : (val / total) * 100;

    return (
        <SignatureCard className={cn("p-5 lg:p-6", className)} {...props}>
            <div className="flex justify-between items-end mb-6">
                <div>
                    <h2 className="micro-label mb-1">{subtitle}</h2>
                    <h3 className="section-title">{title}</h3>
                </div>
                {action && <div>{action}</div>}
            </div>

            <div className="pipeline-track mb-5" aria-label={`${title} distribution`}>
                {segments.map((segment, idx) => (
                    <div 
                        key={idx}
                        className={cn("pipeline-segment", segment.bgClass)} 
                        style={{ width: `${getPercent(segment.value)}%` }} 
                        title={`${segment.label}: ${segment.value}`} 
                    />
                ))}
            </div>

            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {segments.map((segment, idx) => (
                    <div key={idx}>
                        <div className="flex items-center gap-2 mb-1">
                            <div className={cn("w-2 h-2 rounded-full", segment.bgClass)}></div>
                            <h4 className="text-sm font-medium text-slate-500">{segment.label}</h4>
                        </div>
                        <p className="text-2xl font-bold text-slate-900 tracking-tight">{segment.value}</p>
                    </div>
                ))}
            </div>
        </SignatureCard>
    );
}
