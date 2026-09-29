import React from 'react';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The reference surface language.
 *
 * Every trait here was taken from the reference screenshots rather than
 * invented, and each one is a deliberate reversal of what the earlier passes
 * did:
 *
 *   - figures are set LIGHT and large, with the unit trailing small and muted.
 *     Bold numbers were the single biggest thing making the old page read as
 *     generic dashboard rather than as these designs.
 *   - cards are solid white with a soft diffuse shadow and no border. The old
 *     translucent-panel-with-hairline treatment muddied every surface.
 *   - the action affordance is a black filled circle with an arrow, top right;
 *     a card's own icon sits top left in an outline circle.
 *   - progress is a hairline underline, not a filled track.
 *   - rings are thin, roughly 4px, never a thick donut.
 */

/* ──────────────────────────────────────────────────────────────── card shell */

export function CrmCard({
  children,
  className,
  /**
   * Position in the page's entrance sequence. Cards rise in reading order over
   * a short window, which makes a dense dashboard feel assembled rather than
   * dumped. The whole sequence is capped so late cards never crawl in.
   */
  step = 0,
  tone = 'white',
  /**
   * Frosted variant: a soft wash of the hue across the card, the CreditKarma
   * "Credit Card Use" treatment. Exactly one card per row should take it —
   * it is emphasis, and emphasis stops working once it is everywhere.
   */
  frostHue,
  /**
   * The element this renders as, and its label.
   *
   * A card is frequently also a landmark — the deal header is a `header`, the
   * stage control is a labelled `section` — and not being able to say so is why
   * several pages built their own container instead of using this component.
   * That is how one page ended up rendering four different card radii.
   *
   * Defaults to `section`, which is what this always rendered.
   */
  as: Element = 'section',
  'aria-label': ariaLabel,
}: {
  children: React.ReactNode;
  className?: string;
  step?: number;
  /** `sunken` is the inset panel used for grouped content inside a card. */
  tone?: 'white' | 'sunken' | 'ink';
  frostHue?: string;
  as?: 'div' | 'section' | 'header' | 'article' | 'aside';
  'aria-label'?: string;
}) {
  return (
    <Element
      aria-label={ariaLabel}
      className={cn(
        'crm-rise crm-lift rounded-[24px] p-5',
        tone === 'white' && !frostHue && 'bg-white shadow-ovs-crmPanel hover:shadow-ovs-crmLift',
        tone === 'white' && frostHue && 'shadow-ovs-crmPanel hover:shadow-ovs-crmLift',
        tone === 'sunken' && 'bg-ovs-crm-surface-inset',
        tone === 'ink' && 'bg-ovs-crm-ink text-white shadow-ovs-crmInk',
        className
      )}
      style={{
        animationDelay: `${Math.min(step, 12) * 45}ms`,
        ...(frostHue
          ? { background: `linear-gradient(150deg, ${frostHue}26 0%, ${frostHue}0F 45%, #FFFFFF 100%)` }
          : null),
      }}
    >
      {children}
    </Element>
  );
}

/**
 * Circle holding a card's own icon — the reference's top-left mark.
 *
 * Given a `hue` it tints: the glyph takes the colour and the disc a wash of it.
 * That is what carries colour across a page of white cards without painting
 * whole surfaces, and it makes each card identifiable at a glance.
 */
export function CrmCardIcon({
  icon: Icon,
  hue,
  onDark = false,
}: {
  icon: LucideIcon;
  hue?: string;
  onDark?: boolean;
}) {
  return (
    <span
      className={cn(
        'flex h-9 w-9 shrink-0 items-center justify-center rounded-full',
        !hue && (onDark ? 'border border-white/25 text-white/80' : 'border border-ovs-crm-border text-ovs-crm-text-secondary')
      )}
      style={hue ? { backgroundColor: `${hue}1A`, color: hue } : undefined}
    >
      <Icon className="h-4 w-4" aria-hidden="true" />
    </span>
  );
}

/** Black filled circle with an arrow — the reference's "open this" affordance. */
export function CrmOpenButton({
  href,
  label,
  onDark = false,
  className,
}: {
  href: string;
  label: string;
  onDark?: boolean;
  className?: string;
}) {
  return (
    <Link
      href={href}
      aria-label={label}
      title={label}
      className={cn(
        'flex h-9 w-9 shrink-0 items-center justify-center rounded-full transition-transform duration-200 hover:-translate-y-0.5',
        onDark ? 'bg-white text-ovs-crm-ink' : 'bg-ovs-crm-ink text-white',
        className
      )}
    >
      <ArrowUpRight className="h-4 w-4" aria-hidden="true" />
    </Link>
  );
}

/* ───────────────────────────────────────────────────────────────── figures */

/**
 * A figure, set the way the references set them: large, light, tight, with the
 * unit trailing at roughly half size in a muted tone.
 *
 * `size` picks one of the three number steps. Nothing else on a page should
 * introduce a fourth.
 */
export function CrmValue({
  value,
  unit,
  size = 'major',
  onDark = false,
  className,
}: {
  value: React.ReactNode;
  unit?: React.ReactNode;
  size?: 'hero' | 'major' | 'minor';
  onDark?: boolean;
  className?: string;
}) {
  const scale = {
    hero: 'text-[48px] leading-[0.9]',
    major: 'text-[34px] leading-[0.92]',
    minor: 'text-[24px] leading-none',
  }[size];

  const unitScale = { hero: 'text-[18px]', major: 'text-[13px]', minor: 'text-[11.5px]' }[size];

  return (
    <span className={cn('inline-flex items-baseline gap-1', className)}>
      <span
        className={cn(
          'font-light tracking-[-0.03em] tabular-nums',
          scale,
          onDark ? 'text-white' : 'text-ovs-crm-text-primary'
        )}
      >
        {value}
      </span>
      {unit && (
        <span className={cn('font-normal tabular-nums', unitScale, onDark ? 'text-white/45' : 'text-ovs-crm-text-muted')}>
          {unit}
        </span>
      )}
    </span>
  );
}

/* ─────────────────────────────────────────────────────────────── hairline bar */

/**
 * Progress as a hairline rule under a figure — the Cost & Revenue treatment.
 * Deliberately not a filled track: at this weight the colour reads as an
 * annotation on the number rather than as a competing block of its own.
 */
export function CrmHairlineBar({
  value,
  max,
  color,
  className,
  tooltip,
  onDark = false,
}: {
  value: number;
  max: number;
  color: string;
  className?: string;
  tooltip?: string;
  /** The track is a light inset by default, which glows on an ink panel. */
  onDark?: boolean;
}) {
  const pct = max > 0 ? Math.min(100, Math.max(value > 0 ? 4 : 0, (value / max) * 100)) : 0;

  return (
    <div title={tooltip} className={cn('h-[3px] w-full rounded-full', onDark ? 'bg-white/12' : 'bg-ovs-crm-surface-inset', className)}>
      <div className="h-full rounded-full transition-[width] duration-700" style={{ width: `${pct}%`, backgroundColor: color }} />
    </div>
  );
}

/* ────────────────────────────────────────────────────────────────── sparkline */

/** Tiny trend mark for the bottom-right of a stat card. */
export function CrmSparkline({
  points,
  color,
  width = 68,
  height = 24,
  variant = 'line',
  className,
}: {
  points: number[];
  color: string;
  width?: number;
  height?: number;
  /** `bars` matches the reference's Payment History card. */
  variant?: 'line' | 'bars';
  className?: string;
}) {
  if (points.length === 0) return null;

  const max = Math.max(1, ...points);
  const step = points.length > 1 ? width / (points.length - 1) : width;

  if (variant === 'bars') {
    const barWidth = Math.max(2, width / (points.length * 1.8));

    return (
      <svg width={width} height={height} className={className} role="img" aria-hidden="true">
        {points.map((point, index) => {
          const barHeight = Math.max(2, (point / max) * height);

          return (
            <rect
              key={index}
              x={index * (width / points.length)}
              y={height - barHeight}
              width={barWidth}
              height={barHeight}
              rx={1}
              fill={color}
            />
          );
        })}
      </svg>
    );
  }

  const path = points
    .map((point, index) => {
      const x = index * step;
      const y = height - (point / max) * (height - 4) - 2;

      return `${index === 0 ? 'M' : 'L'} ${x} ${y}`;
    })
    .join(' ');

  return (
    <svg width={width} height={height} className={className} role="img" aria-hidden="true">
      <path d={path} fill="none" stroke={color} strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

/* ─────────────────────────────────────────────────────────────── thin ring */

/**
 * Thin progress ring with the figure inside.
 *
 * 4px of stroke, not 22 — at donut thickness the ring becomes the subject of
 * the card, and in every reference the number is the subject and the ring is
 * the annotation.
 */
export function CrmRing({
  value,
  max = 100,
  color,
  label,
  caption,
  size = 168,
  thickness = 4,
  segments,
  className,
}: {
  value: number;
  max?: number;
  color: string;
  label?: string;
  caption?: React.ReactNode;
  size?: number;
  thickness?: number;
  /**
   * Draw the ring as a distribution rather than as one arc.
   *
   * A single arc answers "how far along"; a great many of the questions worth
   * putting in the middle of a page are instead "how is this split" — healthy
   * against at-risk, one stage against the rest. Those were being rendered as
   * the share of one band, which is both less informative and, at nought per
   * cent, an empty ring that reads as a rendering fault rather than as news.
   *
   * `value`/`max` still drive the figure in the centre, so the headline and the
   * ring can say different things on purpose.
   */
  segments?: Array<{ key: string; value: number; color: string; label?: string }>;
  className?: string;
}) {
  const radius = (size - thickness) / 2;
  const circumference = 2 * Math.PI * radius;
  const fraction = max > 0 ? Math.min(1, value / max) : 0;

  const segmentTotal = (segments ?? []).reduce((sum, segment) => sum + Math.max(0, segment.value), 0);
  let consumed = 0;

  return (
    <div className={cn('relative shrink-0', className)} style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90" role="img" aria-label={label ?? 'Progress'}>
        <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="#EDEFF3" strokeWidth={thickness} />

        {segments && segmentTotal > 0
          ? segments
              .filter((segment) => segment.value > 0)
              .map((segment) => {
                const length = (segment.value / segmentTotal) * circumference;
                const offset = -consumed;
                consumed += length;

                return (
                  <circle
                    key={segment.key}
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke={segment.color}
                    strokeWidth={thickness}
                    strokeDasharray={`${length} ${circumference}`}
                    strokeDashoffset={offset}
                    className="transition-[stroke-dasharray] duration-700"
                  >
                    {segment.label && <title>{`${segment.label} — ${segment.value}`}</title>}
                  </circle>
                );
              })
          : (
            <circle
              cx={size / 2}
              cy={size / 2}
              r={radius}
              fill="none"
              stroke={color}
              strokeWidth={thickness}
              strokeLinecap="round"
              strokeDasharray={`${fraction * circumference} ${circumference}`}
              className="transition-[stroke-dasharray] duration-700"
            />
          )}
      </svg>

      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
        {label && <span className="mb-1 text-[11.5px] text-ovs-crm-text-muted">{label}</span>}
        {caption}
      </div>
    </div>
  );
}

/* ───────────────────────────────────────────────────────────── status pill */

export type CrmStatusTone =
  | 'good'
  | 'warn'
  | 'bad'
  | 'neutral'
  | 'green'
  | 'blue'
  | 'yellow'
  | 'red'
  | 'info'
  | 'danger';

const STATUS_PILL: Record<string, { pill: string; dot: string }> = {
  good: { pill: 'bg-ovs-crm-accent-green-soft text-ovs-crm-accent-green-text', dot: 'bg-ovs-crm-accent-green' },
  green: { pill: 'bg-ovs-crm-accent-green-soft text-ovs-crm-accent-green-text', dot: 'bg-ovs-crm-accent-green' },
  warn: { pill: 'bg-ovs-crm-accent-yellow-soft text-ovs-crm-accent-yellow-text', dot: 'bg-ovs-crm-accent-yellow' },
  yellow: { pill: 'bg-ovs-crm-accent-yellow-soft text-ovs-crm-accent-yellow-text', dot: 'bg-ovs-crm-accent-yellow' },
  bad: { pill: 'bg-ovs-crm-accent-coral-soft text-ovs-crm-accent-coral-text', dot: 'bg-ovs-crm-accent-coral' },
  red: { pill: 'bg-ovs-crm-accent-coral-soft text-ovs-crm-accent-coral-text', dot: 'bg-ovs-crm-accent-coral' },
  danger: { pill: 'bg-ovs-crm-accent-coral-soft text-ovs-crm-accent-coral-text', dot: 'bg-ovs-crm-accent-coral' },
  blue: { pill: 'bg-blue-50 text-blue-700', dot: 'bg-blue-600' },
  info: { pill: 'bg-blue-50 text-blue-700', dot: 'bg-blue-600' },
  neutral: { pill: 'bg-ovs-crm-surface-inset text-ovs-crm-text-secondary', dot: 'bg-ovs-crm-text-muted' },
};

/** Small dot-and-label pill — the On Track / Delayed / Blocked treatment. */
export function CrmStatusPill({
  tone,
  children,
  className,
}: {
  tone?: CrmStatusTone | string;
  children: React.ReactNode;
  className?: string;
}) {
  const style = (tone && STATUS_PILL[tone]) ? STATUS_PILL[tone] : STATUS_PILL.neutral;

  return (
    <span
      className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11.5px] font-medium', style.pill, className)}
    >
      <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', style.dot)} aria-hidden="true" />
      {children}
    </span>
  );
}

/* ────────────────────────────────────────────────────────── gradient hero */

/**
 * Two states of one card, because the slot they share is the anchor of the
 * dashboard's first row. `urgent` is the yellow-into-lavender hero the
 * references put the single actionable thing on. `calm` is the same card when
 * there is nothing to act on — green leading into the identical lavender tail,
 * so a clear queue reads as a state of this card rather than as a different,
 * emptier one.
 *
 * The all-clear state used to be a plain white card, which left the row
 * without its anchor exactly when a dashboard should look settled rather than
 * unfinished — and made two dashboards showing the same thing look unrelated
 * depending only on whether either happened to have work outstanding.
 */
const GRADIENT_TONE = {
  urgent: 'linear-gradient(150deg, #F5C64B 0%, #EFD9A8 42%, #D9DCF2 78%, #C9CFF0 100%)',
  calm: 'linear-gradient(150deg, #6FCB9B 0%, #BEE3CE 42%, #D9DCF2 78%, #C9CFF0 100%)',
} as const;

export type CrmGradientTone = keyof typeof GRADIENT_TONE;

export function CrmGradientCard({
  eyebrow,
  timestamp,
  headline,
  emphasis,
  href,
  label,
  people,
  tone = 'urgent',
  className,
}: {
  eyebrow: string;
  timestamp?: string;
  /** Plain part of the sentence. */
  headline: React.ReactNode;
  /** The clause that carries the weight — rendered in a heavier cut. */
  emphasis?: React.ReactNode;
  /** Where the arrow goes. Omit both this and `label` for a card with no next step. */
  href?: string;
  label?: string;
  people?: React.ReactNode;
  tone?: CrmGradientTone;
  className?: string;
}) {
  return (
    <section
      className={cn('crm-rise crm-lift relative flex min-h-[240px] flex-col overflow-hidden rounded-[24px] p-5', className)}
      style={{
        background: GRADIENT_TONE[tone],
        animationDelay: '180ms',
      }}
    >
      <div>
        <p className="text-[13px] font-medium text-black/60">{eyebrow}</p>
        {timestamp && <p className="text-[11.5px] text-black/40">{timestamp}</p>}
      </div>

      <div className="mt-auto">
        {people && <div className="mb-3 flex items-center gap-2">{people}</div>}

        <p className="max-w-[22ch] text-[34px] font-light leading-[1.12] tracking-[-0.02em] text-white drop-shadow-[0_1px_10px_rgba(0,0,0,0.14)]">
          {headline}
          {emphasis && <span className="font-semibold"> {emphasis}</span>}
        </p>

        {href && label && (
          <div className="mt-4 flex justify-end">
            <Link
              href={href}
              aria-label={label}
              title={label}
              className="flex h-10 w-10 items-center justify-center rounded-full border border-white/70 text-white transition-colors hover:bg-white/25"
            >
              <ArrowUpRight className="h-4 w-4" aria-hidden="true" />
            </Link>
          </div>
        )}
      </div>
    </section>
  );
}

/* ───────────────────────────────────────────────────────────── halftone mesh */

/**
 * Halftone dot field — the AI Insights card's texture. Rendered as a tiled
 * radial gradient so it costs nothing and scales to any card size.
 */
export function CrmHalftone({ className }: { className?: string }) {
  return (
    <div
      aria-hidden="true"
      className={cn('pointer-events-none absolute inset-x-0 bottom-0 h-24', className)}
      style={{
        backgroundImage: 'radial-gradient(circle, rgba(11,11,15,0.22) 1px, transparent 1.4px)',
        backgroundSize: '9px 9px',
        maskImage: 'linear-gradient(to bottom, transparent, #000 85%)',
        WebkitMaskImage: 'linear-gradient(to bottom, transparent, #000 85%)',
      }}
    />
  );
}
