import React from 'react';
import { Head } from '@inertiajs/react';
import {
  CalendarCheck,
  ClipboardList,
  GitBranch,
  ReceiptIndianRupee,
  RotateCcw,
  ShieldCheck,
  Stethoscope,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The pre-auth shell shared by every clinic page reached before a session
 * exists — login, the invitation-accept form, and the primary-owner
 * password-setup page.
 *
 * Composition: the clinic owns the page. A deep brand field (brand-strong
 * teal into ink, lit by a cyan and a violet wash) carries the clinic's name
 * as the page's headline, and the white sign-in card floats over the same
 * field instead of sitting in a separate column. On a phone the field
 * becomes a band across the top that holds the clinic's identity, and the
 * card rises over its lower edge, so the form is on screen immediately and
 * the name is still the first thing read.
 *
 * What it replaced, and why:
 *
 *   - The platform's logo, name and "Clinic Portal" mark, absolutely
 *     positioned in the top-left corner, plus a `pt-32` reserve elsewhere
 *     to avoid colliding with it, and a "© OralCares" footer. This page
 *     belongs to the clinic, so no platform identity appears on it, and
 *     nothing is reserved where it used to be.
 *   - The clinic name as a 34px gradient above the card, coloured from a
 *     hash of the name (CityCare came out rust). That colour belonged to no
 *     token, and a long name wrapped into a headline that competed with the
 *     form. The name is now white on the brand field, and large on purpose.
 *   - A looping product tour (a made-up patient stepping from booking to
 *     invoice every 3.4s) with "Trusted by clinics across India" pills. It
 *     was aimed at a buyer, never stopped moving, and made a claim the page
 *     could not back up.
 *   - Five saturated colour fields drifting under a dot grid and concentric
 *     rings, directly behind the form.
 *
 * Every colour is a CRM token (`ovs-crm-*`, from tokens.json). No images,
 * no canvas: the field is two gradients and two blurred circles.
 */

/* ────────────────────────────────────────────────────── the brand field */

/**
 * brand-strong → ink, with a cyan wash (brand) and a violet one
 * (accent-violet). Three hues from the token set, one direction of light.
 * That is the whole colour system of the page. The CTA stays the CRM's ink
 * primary, so it reads as the same product as everything after sign-in.
 */
function BrandField({ className, band = false }: { className?: string; band?: boolean }) {
  return (
    <div className={cn('pointer-events-none absolute inset-0 overflow-hidden', className)} aria-hidden="true">
      <div
        className="absolute inset-0"
        style={{
          background: 'linear-gradient(150deg, var(--ovs-crm-brand-strong) 0%, var(--ovs-crm-ink-soft) 78%)',
        }}
      />
      {/*
       * On the phone band the washes are dimmed. Spread across a short strip,
       * the cyan one lifted the whole band to a light blue that the white
       * clinic name barely stood out from.
       */}
      {/*
       * The cyan wash sits above and right of the clinic name, not behind it.
       * Centred top-left, its brightest point landed exactly under the name
       * and took white text down to about 3:1.
       */}
      <div
        className={cn(
          'absolute -top-[38%] left-[18%] h-[70vmax] w-[70vmax] rounded-full bg-ovs-crm-brand blur-[120px]',
          band ? 'opacity-25' : 'opacity-45',
        )}
      />
      <div
        className={cn(
          'absolute -bottom-[35%] right-[8%] h-[60vmax] w-[60vmax] rounded-full bg-ovs-crm-accent-violet blur-[130px]',
          band ? 'opacity-20' : 'opacity-35',
        )}
      />
      {/*
       * A faint ink shade under the left-hand copy on desktop. It cannot be
       * seen as a shape; it only deepens the field where the clinic name and
       * tagline sit, so they hold their contrast whatever the washes do.
       */}
      {!band && (
        <div
          className="absolute inset-0"
          style={{ background: 'radial-gradient(ellipse 55% 45% at 22% 42%, rgba(6, 30, 38, 0.42), transparent 72%)' }}
        />
      )}
      {/* A hairline highlight along the top edge, so the field reads as lit rather than flat. */}
      <div className="absolute inset-x-0 top-0 h-px bg-white/20" />
    </div>
  );
}

/* ─────────────────────────────────────────────────── the clinic identity */

/**
 * The clinic's logo (when it has one) on a white tile, and the clinic's name,
 * white on the brand field and set as the page's headline. With no logo there
 * is no monogram or placeholder: the name carries the identity on its own.
 */
/**
 * Sets the largest font size (between `max` and `min`) at which `text` fits
 * on one line of its own box. Only a name that still overflows at `min`
 * wraps, and then to at most two lines.
 *
 * Measured rather than guessed from the character count, because a name's
 * width depends on its letters ("Illinois" vs "Mumbai") and the box's width
 * changes with the viewport. Re-measured when the box resizes and once the
 * web font has loaded, since the fallback font's metrics differ.
 */
function useOneLineFit(text: string, max: number, min: number) {
  const ref = React.useRef<HTMLHeadingElement>(null);
  const [fit, setFit] = React.useState({ size: max, wraps: false });

  React.useLayoutEffect(() => {
    const el = ref.current;
    if (!el) {
      return;
    }

    const measure = () => {
      el.style.whiteSpace = 'nowrap';
      let size = max;
      el.style.fontSize = `${size}px`;
      while (size > min && el.scrollWidth > el.clientWidth) {
        size -= 1;
        el.style.fontSize = `${size}px`;
      }
      const wraps = el.scrollWidth > el.clientWidth;
      // Leave the element in its measured state. Clearing the inline size
      // here would drop React's own `style` when the state below is
      // unchanged and so triggers no re-render.
      el.style.whiteSpace = wraps ? '' : 'nowrap';
      el.style.fontSize = `${size}px`;
      setFit((current) => (current.size === size && current.wraps === wraps ? current : { size, wraps }));
    };

    measure();
    document.fonts?.ready.then(measure);
    const observer = new ResizeObserver(measure);
    if (el.parentElement) {
      observer.observe(el.parentElement);
    }

    return () => observer.disconnect();
  }, [text, max, min]);

  return { ref, ...fit };
}

function ClinicIdentityBlock({
  name,
  logoUrl,
  size,
  fallback,
}: {
  name?: string | null;
  logoUrl?: string | null;
  size: 'hero' | 'band';
  fallback: string;
}) {
  const hero = size === 'hero';
  const text = name || fallback;
  /*
   * On a phone the name runs 20–24px. One that does not fit a line at 20px
   * takes two centred lines rather than shrinking into a caption. On desktop
   * it stays the largest type in the field (30–60px), above the 24–30px
   * tagline, so the clinic always outranks the copy beside it.
   */
  const heading = useOneLineFit(text, hero ? 60 : 24, hero ? 30 : 20);

  return (
    <div className={cn('min-w-0', !hero && 'text-center')}>
      {logoUrl && (
        <span
          className={cn(
            'mb-5 flex w-fit items-center rounded-[14px] bg-white p-1.5 shadow-sm',
            hero ? 'h-14' : 'mx-auto h-11',
          )}
        >
          <img src={logoUrl} alt="" className="h-full w-auto max-w-[160px] rounded-[10px] object-contain" />
        </span>
      )}

      {/*
       * One line at the largest size that fits (see `useOneLineFit`); two
       * lines only for a name too long to fit even at the minimum size.
       * `line-clamp` clips at the box edge, and at display leading that edge
       * cuts through descenders ("City", "Specialty"), so leading stays at
       * 1.1+ with a sliver of bottom padding to keep every glyph inside.
       */}
      <h1
        ref={heading.ref}
        title={text}
        // Solid white at 800, with a tight shadow that sharpens the glyph edges
        // and a wide soft one that separates the name from the field behind it.
        // Nothing here dims the name: no opacity, no gradient inside the text.
        style={{
          fontSize: heading.size,
          textShadow: '0 1px 1px rgba(6, 30, 38, 0.35), 0 4px 18px rgba(6, 30, 38, 0.45)',
        }}
        className={cn(
          'pb-[0.12em] font-extrabold text-white',
          heading.wraps ? 'line-clamp-2 break-words [text-wrap:balance]' : 'whitespace-nowrap',
          hero ? 'leading-[1.1] tracking-[-0.045em]' : 'leading-[1.15] tracking-[-0.03em]',
        )}
      >
        {text}
      </h1>
    </div>
  );
}

/* ──────────────────────────────────────────── the visit, as one record */

interface VisitStage {
  icon: typeof CalendarCheck;
  label: string;
  detail: string;
}

/**
 * Specialty-neutral on purpose: "Treatment" is a filling in a dental clinic
 * and a session in a physiotherapy one, so one composition serves every
 * clinic without a per-specialty variant.
 */
const VISIT_STAGES: VisitStage[] = [
  { icon: CalendarCheck, label: 'Check-in', detail: 'Booked or walk-in' },
  { icon: Stethoscope, label: 'Consultation', detail: 'Notes and diagnosis' },
  { icon: ClipboardList, label: 'Treatment', detail: 'Procedures and sessions' },
  { icon: ReceiptIndianRupee, label: 'Billing', detail: 'Invoice and payment' },
  { icon: RotateCcw, label: 'Follow-up', detail: 'The next visit' },
];

/**
 * A one-time entrance, never a loop: the rail draws across once and the
 * stages settle in behind it, then the page is still for as long as it stays
 * open. People who have asked their OS for less motion get the finished
 * state straight away.
 */
const RAIL_MOTION = `
  @keyframes clinicRailDraw { from { transform: scaleX(0); } to { transform: scaleX(1); } }
  @keyframes clinicRailStage { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
  .clinic-rail-line { transform-origin: left; animation: clinicRailDraw 1100ms cubic-bezier(.2,.7,.2,1) both; }
  .clinic-rail-stage { animation: clinicRailStage 500ms ease-out both; }
  @media (prefers-reduced-motion: reduce) {
    .clinic-rail-line, .clinic-rail-stage { animation: none; }
  }
`;

/**
 * The line under the clinic name, set as its subtitle: close beneath it, on
 * the same left edge, and one clear step down in size.
 *
 * One plain, declarative line. It is solid white, with the payoff in a
 * single quiet teal accent token instead of a multi-hue gradient, the
 * register of clinical software rather than a marketing page. At 18px on
 * `lg` and 24px from `xl` it fits one line in the field's column, well below
 * the clinic name's 30–60px. It names no specialty, and the rail below shows
 * the stages.
 */
function ClinicTagline() {
  return (
    <p className="mt-3 text-[18px] font-semibold leading-snug tracking-[-0.02em] text-white xl:mt-4 xl:text-[24px]">
      Every patient. Every visit. <span className="font-bold text-ovs-crm-accent-teal-border">One record.</span>
    </p>
  );
}

function VisitRail() {
  return (
    <div className="max-w-[660px]">
      <style>{RAIL_MOTION}</style>

      {/*
       * A frosted strip rather than bare icons on the field: it gives the
       * rail an edge, so the left half reads as a composed object instead of
       * scattered marks. Captions appear from `xl`; below that the columns
       * are too narrow for them.
       */}
      <ol
        className="relative grid grid-cols-5 rounded-[24px] border border-white/15 bg-white/[0.07] px-2 py-6 backdrop-blur-md"
        aria-label="A clinic visit"
      >
        <span
          className="clinic-rail-line absolute left-[10%] right-[10%] top-[47px] h-px bg-white/30"
          aria-hidden="true"
        />
        {VISIT_STAGES.map((stage, index) => (
          <li
            key={stage.label}
            className="clinic-rail-stage relative flex flex-col items-center px-1 text-center"
            style={{ animationDelay: `${250 + index * 110}ms` }}
          >
            <span className="relative z-10 flex h-11 w-11 items-center justify-center rounded-full bg-white text-ovs-crm-brand-strong shadow-[0_8px_20px_-8px_rgba(0,0,0,0.45)]">
              <stage.icon className="h-5 w-5" aria-hidden="true" />
            </span>
            <span className="mt-3 text-[12px] font-bold text-white">{stage.label}</span>
            <span className="mt-0.5 hidden text-[11px] leading-snug text-white/65 xl:block">{stage.detail}</span>
          </li>
        ))}
      </ol>
    </div>
  );
}

/* ─────────────────────────────────────────────────────────────── shell */

export function ClinicPreAuthShell({
  title,
  clinicName,
  clinicLogo,
  /** The form's own heading — generic on purpose, e.g. "Welcome back".
   * The clinic's name is the page headline's job, not this one's. */
  heading,
  subheading,
  /** The page headline when no clinic could be resolved (an unknown login
   * slug, a dead invitation link). Never an invented clinic name. */
  headline = 'Sign in to your clinic',
  children,
}: {
  title: string;
  headline?: string;
  clinicName?: string | null;
  clinicLogo?: string | null;
  heading?: string;
  subheading?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <div className="relative min-h-[100dvh] w-full bg-ovs-crm-canvas lg:overflow-hidden">
      <Head title={title} />

      <BrandField className="hidden lg:block" />

      {/*
       * Phone and tablet: the field shrinks to a band that holds the clinic's
       * identity, and the card rises over its lower edge (`-mt-16`). The form
       * starts high enough that opening the keyboard never covers it.
       */}
      <header className="relative overflow-hidden px-5 pb-24 pt-12 sm:px-8 sm:pb-32 sm:pt-24 lg:hidden">
        <BrandField band />
        <div className="relative mx-auto max-w-[440px]">
          <ClinicIdentityBlock name={clinicName} logoUrl={clinicLogo} size="band" fallback={headline} />
        </div>
      </header>

      <div className="relative lg:grid lg:min-h-[100dvh] lg:grid-cols-[minmax(0,1fr)_minmax(0,500px)] xl:grid-cols-[minmax(0,1fr)_minmax(0,560px)]">
        {/*
         * The clinic's name and the record rail as one statement centred in
         * the field, two plain facts at the foot. Pinned to opposite ends,
         * name and rail left a dead band across the middle of a tall screen.
         */}
        <section className="hidden flex-col px-14 py-12 lg:flex xl:px-20 xl:py-14">
          <div className="flex flex-1 flex-col justify-center py-10">
            <ClinicIdentityBlock name={clinicName} logoUrl={clinicLogo} size="hero" fallback={headline} />
            <ClinicTagline />
            <div className="mt-12">
              <VisitRail />
            </div>
          </div>
          <ul className="flex flex-wrap gap-2.5 text-[12px] font-medium text-white/85">
            <li className="flex items-center gap-2 rounded-full border border-white/15 bg-white/[0.07] px-3.5 py-2 backdrop-blur-md">
              <ShieldCheck className="h-4 w-4 shrink-0 text-white" aria-hidden="true" />
              Each staff member sees what their role allows
            </li>
            <li className="flex items-center gap-2 rounded-full border border-white/15 bg-white/[0.07] px-3.5 py-2 backdrop-blur-md">
              <GitBranch className="h-4 w-4 shrink-0 text-white" aria-hidden="true" />
              Records stay with your clinic and its branches
            </li>
          </ul>
        </section>

        <main className="relative -mt-16 flex flex-col items-center px-4 pb-8 sm:px-6 lg:mt-0 lg:justify-center lg:py-10 lg:pl-0 lg:pr-14 xl:pr-20">
          <div className="w-full max-w-[440px] rounded-[28px] bg-white p-6 shadow-[0_28px_60px_-30px_rgba(11,11,15,0.45)] ring-1 ring-ovs-crm-border sm:p-9 lg:shadow-[0_40px_90px_-30px_rgba(0,0,0,0.6)] lg:ring-white/40">
            {(heading || subheading) && (
              <div className="mb-6">
                {heading && (
                  <h2 className="text-[20px] font-bold tracking-[-0.025em] text-ovs-crm-text-primary">{heading}</h2>
                )}
                {subheading && (
                  <p className="mt-1.5 text-[13px] leading-relaxed text-ovs-crm-text-secondary">{subheading}</p>
                )}
              </div>
            )}

            {children}
          </div>

          {/* The same two facts as the desktop field, set quietly under the card on a phone. */}
          <ul className="mt-6 grid w-full max-w-[440px] gap-2 px-2 text-[12px] font-medium text-ovs-crm-text-secondary lg:hidden">
            <li className="flex items-center gap-2.5">
              <ShieldCheck className="h-4 w-4 shrink-0 text-ovs-crm-brand-strong" aria-hidden="true" />
              Each staff member sees what their role allows
            </li>
            <li className="flex items-center gap-2.5">
              <GitBranch className="h-4 w-4 shrink-0 text-ovs-crm-brand-strong" aria-hidden="true" />
              Records stay with your clinic and its branches
            </li>
          </ul>
        </main>
      </div>
    </div>
  );
}
