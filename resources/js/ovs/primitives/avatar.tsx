import React from 'react';
import { cn } from '@/lib/utils';

export type AvatarSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

export interface AvatarProps extends React.HTMLAttributes<HTMLDivElement> {
  src?: string;
  fallback?: string;
  size?: AvatarSize;
}

export const Avatar = React.forwardRef<HTMLDivElement, AvatarProps>(
  ({ className, src, fallback, size = 'md', ...props }, ref) => {
    const sizes: Record<AvatarSize, string> = {
      xs: 'w-5 h-5 text-[9px]',
      sm: 'w-6 h-6 text-[10px]',
      md: 'w-8 h-8 text-xs',
      lg: 'w-10 h-10 text-sm',
      xl: 'w-12 h-12 text-base',
    };

    return (
      <div
        ref={ref}
        className={cn(
          'relative flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-900 text-white font-sans font-semibold select-none shadow-2xs',
          sizes[size],
          className
        )}
        {...props}
      >
        {src ? (
          <img className="aspect-square h-full w-full object-cover" src={src} alt="Avatar" />
        ) : (
          <span>{fallback || '?'}</span>
        )}
      </div>
    );
  }
);
Avatar.displayName = 'Avatar';

export interface AvatarGroupProps extends React.HTMLAttributes<HTMLDivElement> {
  limit?: number;
  children: React.ReactNode;
}

export const AvatarGroup: React.FC<AvatarGroupProps> = ({
  className,
  limit = 4,
  children,
  ...props
}) => {
  const childArray = React.Children.toArray(children);
  const visibleChildren = childArray.slice(0, limit);
  const remainingCount = childArray.length - limit;

  return (
    <div className={cn('flex items-center -space-x-2', className)} {...props}>
      {visibleChildren.map((child, i) => (
        <div key={i} className="ring-2 ring-white rounded-full">
          {child}
        </div>
      ))}
      {remainingCount > 0 && (
        <div className="flex h-7 w-7 items-center justify-center rounded-full bg-slate-200 text-[10px] font-bold text-slate-700 ring-2 ring-white font-sans">
          +{remainingCount}
        </div>
      )}
    </div>
  );
};
AvatarGroup.displayName = 'AvatarGroup';

export interface UserChipProps extends React.HTMLAttributes<HTMLDivElement> {
  name: string;
  role?: string;
  src?: string;
  fallback?: string;
  size?: 'sm' | 'md' | 'lg';
}

export const UserChip: React.FC<UserChipProps> = ({
  name,
  role,
  src,
  fallback,
  size = 'md',
  className,
  ...props
}) => {
  const avatarSizeMap = {
    sm: 'sm' as const,
    md: 'md' as const,
    lg: 'lg' as const,
  };

  return (
    <div className={cn('inline-flex items-center gap-2 font-sans', className)} {...props}>
      <Avatar
        src={src}
        fallback={fallback || name.slice(0, 2).toUpperCase()}
        size={avatarSizeMap[size]}
      />
      <div className="flex flex-col min-w-0 leading-tight">
        <span className="text-xs font-semibold text-slate-900 truncate">{name}</span>
        {role && <span className="text-[11px] text-slate-500 truncate">{role}</span>}
      </div>
    </div>
  );
};
UserChip.displayName = 'UserChip';
