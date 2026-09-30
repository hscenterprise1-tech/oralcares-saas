import React from 'react';
import { cn } from '@/lib/utils';

export interface StatusChipProps extends React.HTMLAttributes<HTMLSpanElement> {
    variant?: 'success' | 'neutral' | 'info' | 'warning' | 'destructive';
    icon?: React.ElementType;
}

export function StatusChip({ className, variant = 'neutral', icon: Icon, children, ...props }: StatusChipProps) {
    return (
        <span 
            className={cn(
                "status-chip",
                `status-chip-${variant}`,
                className
            )}
            {...props}
        >
            {Icon && <Icon className="w-3 h-3" aria-hidden="true" />}
            {children}
        </span>
    );
}
