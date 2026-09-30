import React from 'react';
import { SearchX, AlertTriangle, RefreshCw, Plus, ShieldAlert } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';
import { Skeleton } from '@/ovs/feedback/skeleton';
import { Alert, AlertTitle, AlertDescription } from '@/ovs/feedback/alert';

export type SmartStateType = 'empty' | 'loading' | 'error' | 'forbidden';

export interface OVSSmartStateProps {
  type: SmartStateType;
  title?: string;
  description?: string;
  icon?: React.ElementType;
  primaryAction?: {
    label: string;
    onClick: () => void;
    icon?: React.ElementType;
  };
  secondaryAction?: {
    label: string;
    onClick: () => void;
  };
  onRetry?: () => void;
  skeletonRows?: number;
  skeletonHeight?: string;
  compact?: boolean;
  className?: string;
}

export const OVSSmartState: React.FC<OVSSmartStateProps> = ({
  type,
  title,
  description,
  icon: CustomIcon,
  primaryAction,
  secondaryAction,
  onRetry,
  skeletonRows = 4,
  skeletonHeight = 'h-14', // 56px default row
  compact = false,
  className,
}) => {
  // ── 1. LOADING SKELETON STATE (ZERO SHIFT) ─────────────────────────────────
  if (type === 'loading') {
    return (
      <div className={cn('w-full space-y-2 p-4 animate-in fade-in duration-150', className)}>
        {Array.from({ length: skeletonRows }).map((_, i) => (
          <div
            key={i}
            className={cn(
              'w-full bg-slate-50 border border-slate-100 rounded-xl flex items-center justify-between px-4 gap-4',
              skeletonHeight
            )}
          >
            <div className="flex items-center gap-3 min-w-[200px] flex-1">
              <Skeleton className="w-8 h-8 rounded-lg shrink-0" />
              <div className="space-y-1 flex-1 max-w-sm">
                <Skeleton className="h-3.5 w-4/5" />
                <Skeleton className="h-2.5 w-2/5" />
              </div>
            </div>
            <Skeleton className="h-4 w-20 rounded-md hidden sm:block" />
            <Skeleton className="h-5 w-24 rounded-full hidden md:block" />
            <Skeleton className="h-4 w-16 rounded hidden lg:block" />
            <Skeleton className="h-7 w-20 rounded-lg shrink-0" />
          </div>
        ))}
      </div>
    );
  }

  // ── 2. ERROR STATE (IN-SURFACE ALERT) ─────────────────────────────────────
  if (type === 'error') {
    return (
      <div className={cn('p-6 sm:p-10 flex items-center justify-center font-sans text-slate-900', className)}>
        <Alert variant="destructive" className="max-w-xl mx-auto shadow-sm">
          <AlertTriangle className="w-4 h-4" />
          <div className="space-y-2">
            <AlertTitle className="font-bold">
              {title || 'Unable to Load Telemetry Records'}
            </AlertTitle>
            <AlertDescription className="text-xs text-rose-700 leading-relaxed">
              {description || 'A temporary connection timeout occurred while querying organization records. Please check tenant access.'}
            </AlertDescription>
            {onRetry && (
              <div className="pt-2">
                <Button size="sm" variant="secondary" onClick={onRetry} className="h-7 text-xs gap-1.5 bg-white">
                  <RefreshCw className="w-3 h-3" />
                  <span>Retry Connection</span>
                </Button>
              </div>
            )}
          </div>
        </Alert>
      </div>
    );
  }

  // ── 3. FORBIDDEN / PERMISSION STATE ───────────────────────────────────────
  if (type === 'forbidden') {
    return (
      <div className={cn('p-8 sm:p-12 text-center font-sans text-slate-900 max-w-md mx-auto space-y-3', className)}>
        <div className="w-12 h-12 rounded-2xl bg-amber-50 border border-amber-200 text-amber-700 flex items-center justify-center mx-auto shadow-2xs">
          <ShieldAlert className="w-6 h-6" />
        </div>
        <h3 className="text-base font-bold text-slate-900">
          {title || 'Restricted Access Role'}
        </h3>
        <p className="text-xs text-slate-500 leading-relaxed">
          {description || 'You do not have administrative permission to view financial telemetry in this workspace.'}
        </p>
      </div>
    );
  }

  // ── 4. CONTEXTUAL EMPTY STATE ─────────────────────────────────────────────
  const Icon = CustomIcon || SearchX;

  return (
    <div
      className={cn(
        'text-center font-sans text-slate-900 flex flex-col items-center justify-center',
        compact ? 'py-6 px-4 space-y-2.5' : 'py-12 sm:py-16 px-6 space-y-4 max-w-md mx-auto',
        className
      )}
    >
      <div className="w-12 h-12 rounded-2xl bg-slate-100 border border-slate-200 text-slate-500 flex items-center justify-center shadow-2xs">
        <Icon className="w-6 h-6" />
      </div>

      <div className="space-y-1">
        <h3 className="text-sm sm:text-base font-bold text-slate-900">
          {title || 'No Records Found'}
        </h3>
        <p className="text-xs text-slate-500 leading-relaxed max-w-sm">
          {description || 'No matching items were found in this lifecycle stage. Create your first record or adjust filter parameters.'}
        </p>
      </div>

      {(primaryAction || secondaryAction) && (
        <div className="flex items-center gap-3 pt-2">
          {secondaryAction && (
            <button
              type="button"
              onClick={secondaryAction.onClick}
              className="text-xs font-semibold text-slate-600 hover:text-slate-900 underline cursor-pointer"
            >
              {secondaryAction.label}
            </button>
          )}
          {primaryAction && (
            <Button
              size="sm"
              variant="brand"
              onClick={primaryAction.onClick}
              className="h-8 text-xs font-semibold gap-1.5"
            >
              {primaryAction.icon && <primaryAction.icon className="w-3.5 h-3.5" />}
              <span>{primaryAction.label}</span>
            </Button>
          )}
        </div>
      )}
    </div>
  );
};
