import React from 'react';
import * as SliderPrimitive from '@radix-ui/react-slider';
import { cn } from '@/lib/utils';

export interface SliderProps extends React.ComponentPropsWithoutRef<typeof SliderPrimitive.Root> {
  className?: string;
}

export const Slider = React.forwardRef<
  React.ElementRef<typeof SliderPrimitive.Root>,
  SliderProps
>(({ className, ...props }, ref) => (
  <SliderPrimitive.Root
    ref={ref}
    className={cn(
      'relative flex w-full touch-none select-none items-center',
      className
    )}
    {...props}
  >
    <SliderPrimitive.Track className="relative h-1.5 w-full grow overflow-hidden rounded-full bg-ovs-border-subtle">
      <SliderPrimitive.Range className="absolute h-full bg-ovs-accent" />
    </SliderPrimitive.Track>
    <SliderPrimitive.Thumb className="block h-4 w-4 rounded-full border-2 border-ovs-accent bg-white shadow-2xs ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-accent/30 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:scale-110 cursor-grab active:cursor-grabbing" />
  </SliderPrimitive.Root>
));

Slider.displayName = SliderPrimitive.Root.displayName;
