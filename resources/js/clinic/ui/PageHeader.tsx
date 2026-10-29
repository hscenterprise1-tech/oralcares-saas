import type { ReactNode } from 'react';

/** A portal page's title row: what the page is, a short line about it, and its actions. */
export function PageHeader({ title, description, actions, eyebrow }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; eyebrow?: ReactNode }) {
  return (
    <header className="mb-5 flex flex-wrap items-end justify-between gap-3">
      <div className="min-w-0">
        {eyebrow && <div className="mb-1.5">{eyebrow}</div>}
        <h1 className="break-words text-[24px] font-bold leading-tight tracking-tight text-cx-nav-ink">{title}</h1>
        {description && <p className="mt-1 max-w-2xl text-[14px] leading-relaxed text-cx-nav-muted">{description}</p>}
      </div>
      {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
    </header>
  );
}
