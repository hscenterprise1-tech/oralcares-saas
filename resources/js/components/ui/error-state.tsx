import React from 'react';
import { Button } from './button';
import { AlertTriangle, Lock, WifiOff, XCircle, LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type ErrorType = 'network' | 'permission' | 'validation' | 'unknown';

export interface ErrorStateProps {
  type?: ErrorType;
  icon?: LucideIcon;
  title?: string;
  description?: string;
  onRetry?: () => void;
  className?: string;
}

const defaultContent = {
  network: {
    icon: WifiOff,
    title: 'Connection Error',
    description: 'We are unable to connect to the server. Please check your internet connection and try again.',
  },
  permission: {
    icon: Lock,
    title: 'Access Denied',
    description: 'You do not have the required permissions to view this resource. Contact your administrator.',
  },
  validation: {
    icon: AlertTriangle,
    title: 'Invalid Request',
    description: 'There was a problem with the data you submitted. Please correct any errors and try again.',
  },
  unknown: {
    icon: XCircle,
    title: 'Something went wrong',
    description: 'An unexpected error occurred. Our technical team has been notified.',
  }
};

export function ErrorState({
  type = 'unknown',
  icon,
  title,
  description,
  onRetry,
  className,
}: ErrorStateProps) {
  const content = defaultContent[type];
  const Icon = icon || content.icon;
  
  return (
    <div className={cn("flex flex-col items-center justify-center py-16 px-4 text-center rounded-xl bg-rose-50/50 border border-rose-100", className)}>
      <div className="w-16 h-16 rounded-full bg-rose-100 flex items-center justify-center mb-6">
        <Icon className="w-8 h-8 text-rose-600" strokeWidth={1.5} />
      </div>
      <h3 className="text-xl font-bold tracking-tight text-slate-900 mb-2">
        {title || content.title}
      </h3>
      <p className="text-slate-500 max-w-sm mx-auto mb-8 font-medium">
        {description || content.description}
      </p>
      
      {onRetry && (
        <Button 
          onClick={onRetry} 
          className="bg-slate-900 hover:bg-slate-800 text-white min-w-[120px]"
        >
          Try Again
        </Button>
      )}
    </div>
  );
}
