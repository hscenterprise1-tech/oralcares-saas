import React from 'react';
import { cn } from '@/lib/utils';
import { CrmCard, CrmCardIcon, CrmOpenButton, CrmValue } from './CrmSurface';
import type { LucideIcon } from 'lucide-react';

/**
 * The standard CRM content container.
 *
 * One padding scale, one title treatment, one place for section actions — and
 * all of it on the reference language: a solid white card with no border, a
 * soft diffuse shadow, and a title that starts exactly on the card's content
 * edge so it lines up with whatever sits beneath it.
 *
 * The API is unchanged from the version this replaces, so every page already
 * composing with `CrmSection` picks up the new surface without an edit.
 */

export interface CrmSectionProps {
  title?: string;
  description?: string;
  /**
   * Leading mark. Rendered on the *right*, beside the section's controls —
   * putting it before the title pushed every heading clear of the content it
   * introduced, which is the one thing this container exists to prevent.
   */
  icon?: LucideIcon;
  /** Subject hue: tints the icon disc, and should match the data below. */
  hue?: string;
  /** Right-aligned controls in the section header. */
  actions?: React.ReactNode;
  /** Renders the black circular arrow at the far right. */
  href?: string;
  openLabel?: string;
  /** Remove inner padding — use when the body is a full-bleed table. */
  flush?: boolean;
  /** Position in the page's entrance sequence. */
  step?: number;
  children: React.ReactNode;
  className?: string;
  bodyClassName?: string;
}

export function CrmSection({
  title,
  description,
  icon,
  hue,
  actions,
  href,
  openLabel,
  flush = false,
  step = 0,
  children,
  className,
  bodyClassName,
}: CrmSectionProps) {
  const hasHeader = Boolean(title || actions || href);

  return (
    <CrmCard step={step} className={cn('overflow-hidden', flush && '!p-0', className)}>
      {hasHeader && (
        <div className={cn('flex flex-wrap items-start gap-3', flush ? 'px-5 pb-4 pt-5' : 'mb-5')}>
          <div className="min-w-0 flex-1">
            {title && (
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">{title}</h2>
            )}
            {description && (
              <p className="mt-0.5 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">{description}</p>
            )}
          </div>

          {/*
            * `shrink-0` here meant a section whose actions carry a search field
            * and two controls laid them out at their natural width and pushed
            * them past a 375px viewport, where they were clipped rather than
            * wrapped. Sections with one small button are unaffected — there is
            * nothing to shrink — while a busy action row now wraps instead of
            * overflowing.
            */}
          {actions && (
            <div className="flex min-w-0 max-w-full flex-wrap items-center justify-end gap-2">
              {actions}
            </div>
          )}
          {icon && hue && <CrmCardIcon icon={icon} hue={hue} />}
          {href && <CrmOpenButton href={href} label={openLabel ?? `Open ${title ?? 'section'}`} />}
        </div>
      )}

      <div className={cn(flush ? 'px-5 pb-5' : '', bodyClassName)}>{children}</div>
    </CrmCard>
  );
}

/* ────────────────────────────────────────────────────────────── page head */

/**
 * The masthead every CRM page opens with.
 *
 * An eyebrow naming the module, the page's own name set bold and large, and the
 * page's headline figures inline to the right — the reference's masthead, and
 * the same shape the Overview uses, so moving between screens never changes
 * where the eye starts.
 *
 * `CrmShell` deliberately renders no title of its own. Pages that composed
 * their own heading each picked a different size and weight, which is what made
 * the product read as separately built screens; this exists so none of them has
 * to make that choice again.
 */
export function CrmPageHead({
  eyebrow,
  title,
  description,
  stats,
  actions,
  className,
}: {
  eyebrow?: string;
  title: string;
  /**
   * One line under the title saying what the page is for.
   *
   * Added because three Customer Success pages were hand-rolling this whole
   * masthead purely to get a description line, and each hand-rolled copy picked
   * its own type size and set the heading `whitespace-nowrap` — which is how a
   * 42px title ends up pushing the page sideways on a tablet.
   */
  description?: string;
  /** Inline figures beside the title, right-aligned. */
  stats?: React.ReactNode;
  /** Controls at the far right — usually the page's one primary action. */
  actions?: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6', className)}>
      {/*
        * A floor and a basis, not a bare `flex-1`.
        *
        * `shrink-0` here used to stop the masthead giving at all; `flex-1`
        * alone let it collapse to its narrowest content the moment the stats
        * and actions wanted room, which set a long account name one letter per
        * line. It starts wide, yields to a point, then wraps.
        */}
      <div className="min-w-[min(100%,240px)] flex-1 basis-[340px]">
        {eyebrow && (
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            {eyebrow}
          </p>
        )}
        {/*
          * `break-words` because a title is a customer's name, not a label we
          * choose. "Hegmann-Konopelski" at 42px is wider than a phone, and
          * without this it was clipped at the viewport edge rather than wrapped.
          */}
        <h1 className="break-words text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
          {title}
        </h1>
        {description && (
          <p className="mt-1.5 max-w-[62ch] text-[13px] text-ovs-crm-text-secondary">{description}</p>
        )}
      </div>

      {(stats || actions) && (
        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          {/*
           * Stats wrap among themselves, and the actions move as one block.
           *
           * They used to be siblings in this row, so when the header ran out of
           * width a single stat would drop onto the next line *beside* the
           * buttons — a figure and its caption sitting in a row of controls,
           * which reads as a layout accident rather than a masthead. Grouping
           * each side means the row still gives, but it gives in whole units.
           */}
          {stats && <div className="flex flex-wrap items-center gap-x-9 gap-y-5">{stats}</div>}
          {/*
           * The actions wrap too: a page passing three controls — a back link,
           * a select and a primary button — laid them out in a single 475px
           * line inside a 375px screen, and the primary action ended up past
           * the right edge where the canvas clips.
           */}
          {actions && <div className="flex flex-wrap items-center justify-end gap-2">{actions}</div>}
        </div>
      )}
    </div>
  );
}

/**
 * One inline figure for the page head: an outline circle icon, the value, an
 * optional tinted chip, and a caption underneath.
 */
export function CrmPageStat({
  icon: Icon,
  value,
  chip,
  chipHue,
  caption,
}: {
  icon: LucideIcon;
  value: React.ReactNode;
  chip?: string;
  chipHue?: string;
  caption: string;
}) {
  return (
    <div className="flex items-start gap-3">
      <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-ovs-crm-border bg-white text-ovs-crm-text-secondary">
        <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
      </span>

      <div className="min-w-0">
        <div className="flex items-center gap-2">
          <CrmValue value={value} size="minor" />
          {chip && (
            <span
              className="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
              style={{ backgroundColor: `${chipHue ?? '#6B7488'}1F`, color: chipHue ?? '#6B7488' }}
            >
              {chip}
            </span>
          )}
        </div>
        <p className="mt-1 text-[11.5px] text-ovs-crm-text-muted">{caption}</p>
      </div>
    </div>
  );
}

/**
 * A labelled figure for the compact metric rows inside a section — distinct
 * from the page-level stat card, which is heavier by design.
 *
 * Sits on the inset surface rather than a bordered box, and sets its number on
 * the shared light figure scale so a metric here reads the same as a metric
 * anywhere else in the product.
 */
export function CrmMetric({
  label,
  value,
  hint,
  tone = 'default',
  hue,
  className,
}: {
  label: string;
  value: React.ReactNode;
  hint?: React.ReactNode;
  tone?: 'default' | 'danger' | 'warning' | 'success';
  /** Overrides `tone` with an explicit subject hue. */
  hue?: string;
  className?: string;
}) {
  const toneHue = hue ?? {
    default: undefined,
    danger: '#EF5B39',
    warning: '#E0B21E',
    success: '#2FA96B',
  }[tone];

  return (
    <div className={cn('rounded-[18px] bg-ovs-crm-surface-inset p-3.5', className)}>
      {toneHue && (
        <span className="mb-2 block h-[3px] w-7 rounded-full" style={{ backgroundColor: toneHue }} aria-hidden="true" />
      )}
      <p className="text-[11.5px] text-ovs-crm-text-muted">{label}</p>
      <CrmValue className="mt-1" value={value} size="minor" />
      {hint && <p className="mt-1.5 text-[11.5px] leading-tight text-ovs-crm-text-muted">{hint}</p>}
    </div>
  );
}
