import React from 'react';
import { cn } from '@/lib/utils';

export interface CommandButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
    icon?: React.ElementType;
    shortcut?: string;
}

export function CommandButton({ className, icon: Icon, shortcut, children, ...props }: CommandButtonProps) {
    return (
        <button 
            className={cn("command-button group", className)}
            {...props}
        >
            <div className="flex items-center gap-3">
                {Icon && (
                    <div className="text-slate-400 group-hover:text-teal-600 transition-colors">
                        <Icon className="w-4 h-4" aria-hidden="true" />
                    </div>
                )}
                <span className="text-sm font-semibold text-slate-700 group-hover:text-slate-900 transition-colors">
                    {children}
                </span>
            </div>
            {shortcut && (
                <kbd className="hidden md:inline-flex items-center gap-1 font-mono text-[10px] font-medium text-slate-400 bg-white border border-slate-200 px-1.5 py-0.5 rounded shadow-sm">
                    <span aria-hidden="true">⌘</span>{shortcut}
                </kbd>
            )}
        </button>
    );
}
