import React from 'react';
import { cn } from '@/lib/utils';

interface PageContainerProps extends React.HTMLAttributes<HTMLDivElement> {
  children: React.ReactNode;
}

export function PageContainer({ children, className, ...props }: PageContainerProps) {
  return (
    <div 
      className={cn("mx-auto max-w-[1600px] w-full min-h-full pb-8", className)}
      {...props}
    >
      <div className="grid gap-[24px] animate-in fade-in slide-in-from-bottom-4 duration-500 ease-out fill-mode-both">
        {children}
      </div>
    </div>
  );
}
