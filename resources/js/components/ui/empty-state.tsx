import React from 'react';
import { LucideIcon, PackageOpen } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';
import { EmptyState as OVSEmptyState } from '@/ovs/feedback/empty-state';

export interface EmptyStateProps {
  icon?: LucideIcon;
  title: string;
  description?: string;
  primaryAction?: {
    label: string;
    onClick: () => void;
  };
  secondaryAction?: {
    label: string;
    onClick: () => void;
  };
  action?: React.ReactNode;
  className?: string;
}

export function EmptyState({
  icon = PackageOpen,
  title,
  description,
  primaryAction,
  secondaryAction,
  action,
  className,
}: EmptyStateProps) {
  if (action) {
    return (
      <OVSEmptyState
        icon={icon}
        title={title}
        description={description}
        action={action}
        className={className}
      />
    );
  }

  const actions = (primaryAction || secondaryAction) ? (
    <div className="flex flex-col sm:flex-row items-center gap-2 mt-2">
      {primaryAction && (
        <Button
          onClick={primaryAction.onClick}
          variant="brand"
          size="sm"
        >
          {primaryAction.label}
        </Button>
      )}
      {secondaryAction && (
        <Button
          variant="secondary"
          size="sm"
          onClick={secondaryAction.onClick}
        >
          {secondaryAction.label}
        </Button>
      )}
    </div>
  ) : undefined;

  return (
    <OVSEmptyState
      icon={icon}
      title={title}
      description={description}
      action={actions}
      className={className}
    />
  );
}
