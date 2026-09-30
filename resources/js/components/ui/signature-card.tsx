import React from 'react';
import { cn } from '@/lib/utils';

export interface SignatureCardProps extends React.HTMLAttributes<HTMLDivElement> {
    noHover?: boolean;
    seq?: 1 | 2 | 3 | 4 | 5;
}

export function SignatureCard({ className, noHover = false, seq, children, ...props }: SignatureCardProps) {
    return (
        <div 
            className={cn(
                "signature-card", 
                noHover && "no-hover",
                seq && `seq-${seq}`,
                className
            )}
            {...props}
        >
            {children}
        </div>
    );
}
