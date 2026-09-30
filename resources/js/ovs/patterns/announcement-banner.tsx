import React from 'react';
import { Info, CheckCircle, AlertTriangle, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';

export interface AnnouncementBannerProps {
  message: string;
  ctaLabel?: string;
  onCtaClick?: () => void;
  onDismiss?: () => void;
  variant?: 'info' | 'success' | 'warning';
  className?: string;
}

export function AnnouncementBanner({
  message,
  ctaLabel,
  onCtaClick,
  onDismiss,
  variant = 'info',
  className,
}: AnnouncementBannerProps) {
  const getIcon = () => {
    switch (variant) {
      case 'success': return CheckCircle;
      case 'warning': return AlertTriangle;
      default:        return Info;
    }
  };

  const getVariantStyles = () => {
    switch (variant) {
      case 'success':
        return 'bg-ovs-semantic-success-bg border-ovs-semantic-success/20 text-ovs-semantic-success-text';
      case 'warning':
        return 'bg-ovs-semantic-warning-bg border-ovs-semantic-warning/20 text-ovs-semantic-warning-text';
      default:
        return 'bg-ovs-semantic-info-bg border-ovs-semantic-info/20 text-ovs-semantic-info-text';
    }
  };

  const Icon = getIcon();

  return (
    <div
      className={cn(
        'w-full px-4 py-2.5 rounded-xl border flex flex-wrap items-center justify-between gap-3 text-xs font-medium shadow-2xs transition-all',
        getVariantStyles(),
        className
      )}
    >
      <div className="flex items-center gap-2.5 min-w-0 flex-1">
        <Icon className="w-4 h-4 flex-shrink-0" />
        <span className="truncate leading-relaxed">{message}</span>
      </div>

      <div className="flex items-center gap-2 flex-shrink-0">
        {ctaLabel && onCtaClick && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={onCtaClick}
            className="h-7 px-2.5 text-xs font-bold underline hover:bg-black/5"
          >
            {ctaLabel}
          </Button>
        )}

        {onDismiss && (
          <button
            type="button"
            onClick={onDismiss}
            className="p-1 rounded-lg hover:bg-black/10 transition-colors opacity-75 hover:opacity-100"
            aria-label="Dismiss banner"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        )}
      </div>
    </div>
  );
}
