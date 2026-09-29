import React from 'react';
import { AlertCircle, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface WidgetContainerProps {
  loading?: boolean;
  error?: Error | string | null;
  onRetry?: () => void;
  className?: string;
  children: React.ReactNode;
}

export function WidgetContainer({ loading, error, onRetry, className, children }: WidgetContainerProps) {
  if (loading) {
    return (
      <div className={cn('flex items-center justify-center min-h-[200px] rounded-xl border border-[#E5EAF0] bg-white', className)}>
        <Loader2 className="h-6 w-6 animate-spin text-[#00B4C6]" />
      </div>
    );
  }

  if (error) {
    const message = typeof error === 'string' ? error : error.message;
    return (
      <div className={cn('flex flex-col items-center justify-center gap-3 min-h-[200px] rounded-xl border border-[#E5EAF0] bg-white p-6 text-center', className)}>
        <AlertCircle className="h-6 w-6 text-rose-500" />
        <p className="text-sm font-medium text-[#64748B]">{message || 'Failed to load widget data.'}</p>
        {onRetry && (
          <Button size="sm" variant="outline" onClick={onRetry}>
            Retry
          </Button>
        )}
      </div>
    );
  }

  return <>{children}</>;
}
