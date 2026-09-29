import * as React from "react";
import { cn } from "@/lib/utils";
import {
  Card,
  CardHeader,
  CardTitle,
  CardContent,
  CardFooter,
  Surface,
  SurfaceHeader,
  SurfaceSection,
} from "@/ovs/primitives/card";

const CardDescription = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, ...props }, ref) => (
  <p
    ref={ref}
    className={cn("text-xs text-slate-500 font-sans leading-normal", className)}
    {...props}
  />
));
CardDescription.displayName = "CardDescription";

export {
  Card,
  CardHeader,
  CardFooter,
  CardTitle,
  CardDescription,
  CardContent,
  Surface,
  SurfaceHeader,
  SurfaceSection,
};
