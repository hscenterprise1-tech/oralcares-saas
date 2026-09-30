import React from 'react';

/**
 * lucide-react doesn't ship a brand/logo icon set (Instagram included), so this is a
 * small hand-drawn stand-in matching lucide's stroke conventions (24x24, stroke=2,
 * currentColor) — used anywhere an `Instagram` icon reference was expected.
 */
export const InstagramIcon = React.forwardRef<SVGSVGElement, React.SVGProps<SVGSVGElement>>(
  ({ className, ...props }, ref) => (
    <svg
      ref={ref}
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      {...props}
    >
      <rect x="3" y="3" width="18" height="18" rx="5" ry="5" />
      <circle cx="12" cy="12" r="4" />
      <circle cx="17.5" cy="6.5" r="0.9" fill="currentColor" stroke="none" />
    </svg>
  )
);
InstagramIcon.displayName = 'InstagramIcon';
