import React from 'react';
import { cn } from '@/lib/utils';
import { SignatureCard } from './signature-card';

export interface MetricCardProps extends React.HTMLAttributes<HTMLDivElement> {
    title: string;
    value: React.ReactNode;
    icon: React.ElementType;
    badge?: React.ReactNode;
    badgeLabel?: string;
    footer?: React.ReactNode;
    seq?: 1 | 2 | 3 | 4 | 5;
    iconColorClass?: string;
}

export function MetricCard({ 
    className, 
    title, 
    value, 
    icon: Icon, 
    badge, 
    badgeLabel, 
    footer, 
    seq,
    iconColorClass = "text-slate-200",
    ...props 
}: MetricCardProps) {
    return (
        <SignatureCard 
            seq={seq} 
            className={cn("p-4 lg:p-5 flex flex-col justify-between relative overflow-hidden", className)}
            {...props}
        >
            <div className="absolute top-0 right-0 p-4 lg:p-5 opacity-0 group-hover:opacity-100 transition-opacity duration-500">
                <Icon className={cn("w-6 h-6", iconColorClass)} aria-hidden="true" />
            </div>
            <div>
                <h2 className="micro-label mb-2">{title}</h2>
                <p className="text-4xl md:text-5xl font-black text-slate-950 tracking-tighter mb-1.5">{value}</p>
                {(badge || badgeLabel) && (
                    <div className="flex items-center gap-2 text-sm">
                        {badge}
                        {badgeLabel && <span className="text-slate-500 font-medium">{badgeLabel}</span>}
                    </div>
                )}
            </div>
            {footer && (
                <div className="mt-5 pt-4 border-t border-slate-100">
                    {footer}
                </div>
            )}
        </SignatureCard>
    );
}
