import * as React from "react";
import { Button as OVSButton, type ButtonProps as OVSButtonProps } from "@/ovs/primitives/button";

export interface ButtonProps extends Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, 'size'> {
  variant?: 'default' | 'primary' | 'brand' | 'secondary' | 'outline' | 'ghost' | 'destructive' | 'danger' | 'link';
  size?: 'default' | 'sm' | 'md' | 'lg' | 'icon';
  asChild?: boolean;
  isLoading?: boolean;
}

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ variant = 'default', size = 'default', className, ...props }, ref) => {
    // Compatibility variant mapping
    const variantMap: Record<string, OVSButtonProps['variant']> = {
      default: 'primary',
      primary: 'primary',
      brand: 'brand',
      secondary: 'secondary',
      outline: 'outline',
      ghost: 'ghost',
      destructive: 'destructive',
      danger: 'destructive',
      link: 'ghost',
    };

    // Compatibility size mapping
    const sizeMap: Record<string, OVSButtonProps['size']> = {
      default: 'md',
      sm: 'sm',
      md: 'md',
      lg: 'lg',
      icon: 'icon',
    };

    return (
      <OVSButton
        ref={ref}
        variant={variantMap[variant] || 'primary'}
        size={sizeMap[size] || 'md'}
        className={className}
        {...props}
      />
    );
  }
);
Button.displayName = "Button";

export { Button };
export const buttonVariants = () => "";
