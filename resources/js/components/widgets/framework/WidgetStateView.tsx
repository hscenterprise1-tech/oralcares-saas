import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetState } from './types';
import {
  Loader2,
  FolderX,
  AlertTriangle,
  ShieldOff,
  WifiOff,
  RefreshCw,
} from 'lucide-react';

export interface WidgetStateViewProps {
  state: WidgetState;
  title?: string;
  message?: string;
  onRetry?: () => void;
  className?: string;
  customSkeleton?: React.ReactNode;
}

export function WidgetStateView({
  state,
  title,
  message,
  onRetry,
  className = '',
  customSkeleton,
}: WidgetStateViewProps) {
  if (state === 'idle') return null;

  if (state === 'skeleton') {
    if (customSkeleton) return <>{customSkeleton}</>;
    return (
      <div className={cn('p-6 space-y-4 animate-pulse', className)}>
        <div className="h-4 bg-[#F1F5F9] rounded-md w-1/3" />
        <div className="space-y-2">
          <div className="h-3 bg-[#F8FAFC] rounded-md w-full" />
          <div className="h-3 bg-[#F8FAFC] rounded-md w-5/6" />
          <div className="h-3 bg-[#F8FAFC] rounded-md w-4/6" />
        </div>
      </div>
    );
  }

  if (state === 'loading' || state === 'refreshing') {
    return (
      <div className={cn('py-12 px-6 flex flex-col items-center justify-center text-center space-y-3', className)}>
        <Loader2 className="w-6 h-6 text-[#4F46E5] animate-spin" />
        <p className="text-[13px] font-bold text-[#0F172A]">
          {title || (state === 'refreshing' ? 'Updating Widget Data...' : 'Loading...')}
        </p>
        {message && <p className="text-[12px] text-[#64748B] max-w-xs font-medium">{message}</p>}
      </div>
    );
  }

  if (state === 'empty') {
    return (
      <div className={cn('py-12 px-6 flex flex-col items-center justify-center text-center space-y-3', className)}>
        <div className="w-10 h-10 rounded-xl bg-[#F8FAFC] flex items-center justify-center text-[#94A3B8] border border-[#E4E7EE]">
          <FolderX className="w-5 h-5" strokeWidth={1.75} />
        </div>
        <div>
          <p className="text-[13px] font-bold text-[#0F172A]">{title || 'No Records Found'}</p>
          <p className="text-[12px] text-[#64748B] max-w-xs mt-0.5 font-medium">
            {message || 'There is currently no data available for this widget.'}
          </p>
        </div>
      </div>
    );
  }

  if (state === 'error') {
    return (
      <div className={cn('py-12 px-6 flex flex-col items-center justify-center text-center space-y-3', className)}>
        <div className="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center border border-rose-200">
          <AlertTriangle className="w-5 h-5" strokeWidth={1.75} />
        </div>
        <div>
          <p className="text-[13px] font-bold text-rose-900">{title || 'Widget Data Error'}</p>
          <p className="text-[12px] text-rose-700 max-w-xs mt-0.5 font-medium">
            {message || 'Unable to retrieve data payload for this widget.'}
          </p>
        </div>
        {onRetry && (
          <button
            type="button"
            onClick={onRetry}
            className="px-3 py-1.5 bg-[#0A2540] hover:bg-[#0d2d4f] text-white text-[11.5px] font-bold rounded-lg flex items-center gap-1.5 shadow-2xs transition-all"
          >
            <RefreshCw className="w-3.5 h-3.5" /> Retry
          </button>
        )}
      </div>
    );
  }

  if (state === 'permission-denied') {
    return (
      <div className={cn('py-12 px-6 flex flex-col items-center justify-center text-center space-y-3', className)}>
        <div className="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center border border-amber-200">
          <ShieldOff className="w-5 h-5" strokeWidth={1.75} />
        </div>
        <div>
          <p className="text-[13px] font-bold text-amber-900">{title || 'Access Restricted'}</p>
          <p className="text-[12px] text-amber-700 max-w-xs mt-0.5 font-medium">
            {message || 'You do not have the necessary permission to view this widget.'}
          </p>
        </div>
      </div>
    );
  }

  if (state === 'offline') {
    return (
      <div className={cn('py-12 px-6 flex flex-col items-center justify-center text-center space-y-3', className)}>
        <div className="w-10 h-10 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center border border-slate-200">
          <WifiOff className="w-5 h-5" strokeWidth={1.75} />
        </div>
        <div>
          <p className="text-[13px] font-bold text-slate-900">{title || 'Network Disconnected'}</p>
          <p className="text-[12px] text-slate-600 max-w-xs mt-0.5 font-medium">
            {message || 'Re-establish connection to sync live widget updates.'}
          </p>
        </div>
      </div>
    );
  }

  return null;
}
