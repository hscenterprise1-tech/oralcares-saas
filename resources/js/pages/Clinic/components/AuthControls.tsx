import { cloneElement, forwardRef, isValidElement, useId, type InputHTMLAttributes, type ReactElement, type ReactNode } from 'react';
import { Button, Input, type ButtonProps } from '@/clinic';
import { cn } from '@/lib/utils';

/*
 * The sign-in screens' controls: the portal's own Button and Input, dressed
 * as the approved pre-auth design (softer labels, rounder fields). Only the
 * pre-auth pages use these; inside the portal, use the kit directly.
 */

const FIELD = 'rounded-[16px] bg-white px-4 hover:border-clinic-border-strong focus:border-clinic-ink/30 focus:ring-clinic-ink/15';

/** A sign-in field. `invalid` adds the approved coral ring. */
export const AuthInput = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }>(function AuthInput(
  { className, invalid, ...rest },
  ref,
) {
  return <Input ref={ref} invalid={invalid} className={cn(FIELD, invalid && 'ring-2 ring-clinic-accent-coral/40', className)} {...rest} />;
});

/** The sign-in screens' button: the kit's, in the approved weight and lift. */
export function AuthButton({ className, ...rest }: ButtonProps) {
  return <Button className={cn('crm-lift font-medium text-[13px]', className)} {...rest} />;
}

/**
 * A label, its control and its error or hint. The control (the one child)
 * receives the id, `aria-labelledby` and `aria-describedby`, so the label
 * focuses it and a screen reader reads the message with it.
 */
export function AuthField({
  label,
  hint,
  error,
  required = false,
  htmlFor,
  children,
  className,
}: {
  label?: string;
  hint?: string;
  error?: string;
  required?: boolean;
  htmlFor?: string;
  children: ReactNode;
  className?: string;
}) {
  const generatedId = useId();
  const controlId = htmlFor ?? generatedId;
  const labelId = `${controlId}-label`;
  const descriptionId = `${controlId}-description`;
  const hasDescription = Boolean(error || hint);
  const only = children;
  const control =
    isValidElement(only) && !(only.props as { id?: string }).id
      ? cloneElement(only as ReactElement<{ id?: string; 'aria-labelledby'?: string; 'aria-describedby'?: string }>, {
          id: controlId,
          'aria-labelledby': label ? labelId : undefined,
          'aria-describedby': hasDescription ? descriptionId : undefined,
        })
      : only;

  return (
    <div className={cn('min-w-0', className)}>
      {label && (
        <label id={labelId} htmlFor={controlId} className="mb-1.5 block text-[11.5px] font-medium text-clinic-secondary">
          {label}
          {required && <span className="ml-0.5 text-clinic-accent-coral">*</span>}
        </label>
      )}
      {control}
      {hasDescription && (
        <p id={descriptionId} className={cn('mt-1.5 text-[11.5px] leading-tight', error ? 'text-clinic-accent-coral-text' : 'text-clinic-muted')}>
          {error ?? hint}
        </p>
      )}
    </div>
  );
}
