import * as React from 'react';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import { cn } from '@/lib/utils';

export type TabsVariant = 'segmented' | 'underline';
export type TabsDensity = 'compact' | 'default' | 'comfortable';

interface TabsContextValue {
  variant: TabsVariant;
  density: TabsDensity;
}

const TabsContext = React.createContext<TabsContextValue>({
  variant: 'underline',
  density: 'default',
});

export interface TabsProps extends React.ComponentPropsWithoutRef<typeof TabsPrimitive.Root> {
  variant?: TabsVariant;
  density?: TabsDensity;
}

const Tabs = React.forwardRef<
  React.ElementRef<typeof TabsPrimitive.Root>,
  TabsProps
>(({ className, variant = 'underline', density = 'default', ...props }, ref) => (
  <TabsContext.Provider value={{ variant, density }}>
    <TabsPrimitive.Root
      ref={ref}
      className={cn('w-full font-sans text-slate-900', className)}
      {...props}
    />
  </TabsContext.Provider>
));
Tabs.displayName = TabsPrimitive.Root.displayName;

export interface TabsListProps
  extends React.ComponentPropsWithoutRef<typeof TabsPrimitive.List> {
  sticky?: boolean;
  overflow?: 'scroll' | 'wrap' | 'none';
}

const TabsList = React.forwardRef<
  React.ElementRef<typeof TabsPrimitive.List>,
  TabsListProps
>(({ className, sticky = false, overflow = 'scroll', ...props }, ref) => {
  const { variant, density } = React.useContext(TabsContext);

  if (variant === 'underline') {
    const densityGap = {
      compact: 'gap-4',
      default: 'gap-6',
      comfortable: 'gap-8',
    }[density];

    return (
      <div
        className={cn(
          'w-full border-b border-slate-200/80 bg-transparent',
          sticky && 'sticky top-0 z-20 bg-white/95 backdrop-blur-xs',
          overflow === 'scroll' && 'overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden'
        )}
      >
        <TabsPrimitive.List
          ref={ref}
          className={cn(
            'inline-flex items-center p-0 text-slate-500 font-sans min-w-full sm:min-w-0',
            densityGap,
            className
          )}
          {...props}
        />
      </div>
    );
  }

  // Segmented Variant
  const densityContainer = {
    compact: 'h-8 p-0.5 gap-0.5',
    default: 'h-9 p-1 gap-1',
    comfortable: 'h-10 p-1 gap-1.5',
  }[density];

  return (
    <TabsPrimitive.List
      ref={ref}
      className={cn(
        'inline-flex items-center justify-center rounded-lg bg-slate-100/90 text-slate-500 border border-slate-200/80 font-sans',
        densityContainer,
        'data-[orientation=vertical]:flex-col data-[orientation=vertical]:h-auto data-[orientation=vertical]:w-full data-[orientation=vertical]:items-stretch data-[orientation=vertical]:space-y-1',
        sticky && 'sticky top-0 z-20 bg-white/95 backdrop-blur-xs',
        className
      )}
      {...props}
    />
  );
});
TabsList.displayName = TabsPrimitive.List.displayName;

export interface TabsTriggerProps
  extends React.ComponentPropsWithoutRef<typeof TabsPrimitive.Trigger> {
  count?: number | string;
  badge?: React.ReactNode;
  icon?: React.ElementType;
}

const TabsTrigger = React.forwardRef<
  React.ElementRef<typeof TabsPrimitive.Trigger>,
  TabsTriggerProps
>(({ className, children, count, badge, icon: Icon, ...props }, ref) => {
  const { variant, density } = React.useContext(TabsContext);

  if (variant === 'underline') {
    const densityPadding = {
      compact: 'pb-2 pt-1 text-[11.5px]',
      default: 'pb-2.5 pt-1.5 text-xs',
      comfortable: 'pb-3 pt-2 text-[13px]',
    }[density];

    return (
      <TabsPrimitive.Trigger
        ref={ref}
        className={cn(
          'group inline-flex items-center justify-center whitespace-nowrap shrink-0 border-b-2 border-transparent px-1 font-medium text-slate-500 tracking-tight transition-all duration-150 cursor-pointer font-sans select-none gap-2',
          densityPadding,
          'hover:text-slate-900',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/30 focus-visible:ring-offset-1',
          'disabled:pointer-events-none disabled:opacity-40',
          'data-[state=active]:border-teal-700 data-[state=active]:text-slate-900 data-[state=active]:font-semibold',
          className
        )}
        {...props}
      >
        {Icon && <Icon className="w-3.5 h-3.5 text-slate-400 group-data-[state=active]:text-teal-700 shrink-0" />}
        <span>{children}</span>
        {count !== undefined && (
          <span
            className={cn(
              'text-[10px] font-mono font-bold px-1.5 py-0.5 rounded-full transition-colors tabular-nums shrink-0',
              'bg-slate-100 text-slate-600',
              'group-data-[state=active]:bg-teal-50 group-data-[state=active]:text-teal-800 group-data-[state=active]:border group-data-[state=active]:border-teal-200/70'
            )}
          >
            {count}
          </span>
        )}
        {badge && <span className="shrink-0">{badge}</span>}
      </TabsPrimitive.Trigger>
    );
  }

  // Segmented Variant
  const densityTrigger = {
    compact: 'px-2.5 py-1 text-[11px]',
    default: 'px-3 py-1 text-xs',
    comfortable: 'px-3.5 py-1.5 text-[12.5px]',
  }[density];

  return (
    <TabsPrimitive.Trigger
      ref={ref}
      className={cn(
        'group inline-flex items-center justify-center whitespace-nowrap shrink-0 rounded-md font-medium tracking-tight transition-all duration-150 cursor-pointer font-sans select-none gap-1.5',
        densityTrigger,
        'data-[orientation=vertical]:justify-start data-[orientation=vertical]:py-2 data-[orientation=vertical]:w-full data-[orientation=vertical]:text-left',
        'hover:text-slate-900',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/30 focus-visible:ring-offset-1',
        'disabled:pointer-events-none disabled:opacity-40',
        'data-[state=active]:bg-white data-[state=active]:text-slate-900 data-[state=active]:shadow-2xs data-[state=active]:font-semibold border border-transparent data-[state=active]:border-slate-200/80',
        className
      )}
      {...props}
    >
      {Icon && <Icon className="w-3.5 h-3.5 text-slate-400 group-data-[state=active]:text-slate-900 shrink-0" />}
      <span>{children}</span>
      {count !== undefined && (
        <span
          className={cn(
            'text-[10px] font-mono font-bold px-1.5 py-0.5 rounded-full transition-colors tabular-nums shrink-0',
            'bg-slate-200/60 text-slate-600 group-data-[state=active]:bg-slate-100 group-data-[state=active]:text-slate-900'
          )}
        >
          {count}
        </span>
      )}
      {badge && <span className="shrink-0">{badge}</span>}
    </TabsPrimitive.Trigger>
  );
});
TabsTrigger.displayName = TabsPrimitive.Trigger.displayName;

const TabsContent = React.forwardRef<
  React.ElementRef<typeof TabsPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof TabsPrimitive.Content>
>(({ className, ...props }, ref) => (
  <TabsPrimitive.Content
    ref={ref}
    className={cn(
      'data-[orientation=horizontal]:mt-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/30 focus-visible:ring-offset-1 font-sans text-slate-900',
      className
    )}
    {...props}
  />
));
TabsContent.displayName = TabsPrimitive.Content.displayName;

export { Tabs, TabsList, TabsTrigger, TabsContent };
