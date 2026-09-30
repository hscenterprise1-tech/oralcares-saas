import React from 'react';
import { cn } from '@/lib/utils';
import { WidgetState, WidgetSize, WidgetLayoutRules } from './types';
import { WidgetHeader, WidgetHeaderProps } from './WidgetHeader';
import { WidgetToolbar, WidgetToolbarProps } from './WidgetToolbar';
import { WidgetStateView } from './WidgetStateView';

export interface WidgetContainerProps {
  /** Optional metadata header configuration */
  header?: WidgetHeaderProps;
  /** Optional toolbar configuration */
  toolbar?: WidgetToolbarProps;
  /** Primary content body */
  children?: React.ReactNode;
  /** Footer node */
  footer?: React.ReactNode;
  /** Widget operational state */
  state?: WidgetState;
  /** Error message override when state === 'error' */
  errorMessage?: string;
  /** Empty state message override when state === 'empty' */
  emptyMessage?: string;
  /** Callback triggered when retry/refresh action is invoked */
  onRetry?: () => void;
  /** Callback triggered when header refresh action is invoked */
  onRefresh?: () => void;
  /** Widget sizing token */
  size?: WidgetSize;
  /** Custom layout rules */
  layoutRules?: WidgetLayoutRules;
  /** Custom skeleton node override */
  customSkeleton?: React.ReactNode;
  /** Additional container styling */
  className?: string;
  /** Body area styling */
  bodyClassName?: string;
}

const SIZE_CLASSES: Record<WidgetSize, string> = {
  xs:   'col-span-1',
  s:    'col-span-1 md:col-span-4',
  m:    'col-span-1 md:col-span-6',
  l:    'col-span-1 lg:col-span-8',
  xl:   'col-span-1 lg:col-span-12',
  full: 'w-full',
};

export function WidgetContainer({
  header,
  toolbar,
  children,
  footer,
  state = 'idle',
  errorMessage,
  emptyMessage,
  onRetry,
  onRefresh,
  size = 'full',
  layoutRules,
  customSkeleton,
  className = '',
  bodyClassName = '',
}: WidgetContainerProps) {
  const isStateActive = state !== 'idle';
  const effectiveRefresh = onRefresh || header?.onRefresh;

  return (
    <div
      className={cn(
        // OVS Foundation surface language
        'bg-white rounded-xl border border-[#E4E7EE] shadow-2xs flex flex-col overflow-hidden font-sans transition-all',
        SIZE_CLASSES[size],
        className,
      )}
      style={{
        minWidth: layoutRules?.minWidth,
        maxWidth: layoutRules?.maxWidth,
        minHeight: layoutRules?.preferredHeight,
      }}
    >
      {/* 1. HEADER */}
      {header && (
        <WidgetHeader
          {...header}
          onRefresh={effectiveRefresh}
          isRefreshing={state === 'refreshing'}
        />
      )}

      {/* 2. TOOLBAR */}
      {toolbar && <WidgetToolbar {...toolbar} />}

      {/* 3. BODY & STATE VIEWS */}
      <div className={cn('flex-1 min-h-0', bodyClassName)}>
        {isStateActive ? (
          <WidgetStateView
            state={state}
            message={state === 'error' ? errorMessage : state === 'empty' ? emptyMessage : undefined}
            onRetry={onRetry}
            customSkeleton={customSkeleton}
          />
        ) : (
          children
        )}
      </div>

      {/* 4. FOOTER */}
      {footer && (
        <div className="px-5 py-3 border-t border-[#E4E7EE] bg-[#F8FAFC] flex items-center justify-between text-[11px] text-[#64748B] font-medium">
          {footer}
        </div>
      )}
    </div>
  );
}
