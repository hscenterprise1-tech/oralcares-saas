import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const alertVariants = cva(
  'relative w-full rounded-xl border p-3.5 sm:p-4 text-xs font-sans shadow-2xs [&>svg+div]:translate-y-[-2px] [&>svg]:absolute [&>svg]:left-3.5 [&>svg]:top-3.5 sm:[&>svg]:left-4 sm:[&>svg]:top-4 [&>svg]:text-slate-900 [&>svg~*]:pl-7',
  {
    variants: {
      variant: {
        default: 'bg-white border-slate-200 text-slate-900',
        info: 'bg-teal-50/80 border-teal-200/80 text-teal-900 [&>svg]:text-teal-600',
        success: 'bg-emerald-50/80 border-emerald-200/80 text-emerald-900 [&>svg]:text-emerald-600',
        warning: 'bg-amber-50/80 border-amber-200/80 text-amber-900 [&>svg]:text-amber-600',
        destructive: 'bg-rose-50/80 border-rose-200/80 text-rose-900 [&>svg]:text-rose-600',
      },
    },
    defaultVariants: {
      variant: 'default',
    },
  }
);

const Alert = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement> & VariantProps<typeof alertVariants>
>(({ className, variant, ...props }, ref) => (
  <div
    ref={ref}
    role="alert"
    className={cn(alertVariants({ variant }), className)}
    {...props}
  />
));
Alert.displayName = 'Alert';

const AlertTitle = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLHeadingElement>
>(({ className, ...props }, ref) => (
  <h5
    ref={ref}
    className={cn('mb-0.5 font-bold leading-snug tracking-tight text-xs font-sans', className)}
    {...props}
  />
));
AlertTitle.displayName = 'AlertTitle';

const AlertDescription = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn('text-xs leading-relaxed opacity-90 font-sans [&_p]:leading-relaxed', className)}
    {...props}
  />
));
AlertDescription.displayName = 'AlertDescription';

export { Alert, AlertTitle, AlertDescription };
