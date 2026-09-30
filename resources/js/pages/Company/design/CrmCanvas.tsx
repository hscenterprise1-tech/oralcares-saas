import React from 'react';
import { cn } from '@/lib/utils';

/**
 * The application ground.
 *
 * A quiet near-white cool grey, and nothing else.
 *
 * This previously painted four large blurred orbs across every page —
 * lavender, teal, green and violet — on a blue-tinted base. Measured against
 * the reference screenshots that was wrong on three counts: the references use
 * a neutral ground and never a coloured one; they reserve violet, teal and lime
 * for status and never use them as decoration; and where they do use a gradient
 * mesh it sits *behind a cluster of cards* in warm hues, not across the whole
 * viewport.
 *
 * The orbs were justified in this file as making "translucent panels read as
 * glass". That justification had expired: no page in CRM or Marketing uses a
 * translucent card surface, the `glass` variants in CrmCardPrimitives and
 * CrmFormPrimitives have zero call sites, and every measured card renders solid
 * white with no backdrop-filter. The orbs were tinting the gaps between cards
 * and nothing else, which is what made every screen read pale and hazy.
 *
 * Colour now lives where it carries meaning — stage, status, channel, priority,
 * all from `crmTheme` — and, deliberately and sparingly, in `CrmMesh`.
 */
export function CrmCanvas({ children, className }: { children: React.ReactNode; className?: string }) {
  return (
    /*
     * `overflow-x: clip`, never `hidden`.
     *
     * Setting `overflow-x: hidden` makes the other axis compute to `auto`, which
     * turns this element into a scroll container. Anything `sticky` inside then
     * sticks to *this* box rather than to the viewport — and since this box
     * never scrolls, the tool rail simply scrolled away with the page. `clip`
     * trims horizontal overflow exactly the same way without creating a scroll
     * container, so sticky keeps working.
     */
    /*
     * The ground sets the CRM's own default text colour.
     *
     * `body` applies the app-wide legacy `ovs-primary`, so anything inside the
     * CRM that did not name a colour inherited slate-900 from outside the
     * module — the last place the old palette was still reaching in. Owning the
     * default here keeps that inheritance inside the CRM's own token set
     * without touching the global base, which every other module still uses.
     */
    <div className={cn('relative min-h-screen overflow-x-clip bg-ovs-crm-canvas text-ovs-crm-text-primary', className)}>
      {children}
    </div>
  );
}

/**
 * A warm gradient mesh behind one cluster of cards.
 *
 * This is the reference language's mesh, used the way the references use it:
 * opt-in, scoped to a section, and warm. It is deliberately *not* the page
 * ground — a screen should carry at most one of these, behind the cluster that
 * matters most, so it reads as emphasis rather than as wallpaper.
 *
 * `tone` picks which two of the three warm washes are used. All three are
 * near-white already, so the effect is a lift rather than a colour field; the
 * cards on top stay solid white and keep their own shadow.
 */
export type CrmMeshTone = 'coral' | 'amber' | 'ochre';

const MESH_ORBS: Record<CrmMeshTone, [string, string]> = {
  coral: ['bg-ovs-crm-mesh-coral', 'bg-ovs-crm-mesh-amber'],
  amber: ['bg-ovs-crm-mesh-amber', 'bg-ovs-crm-mesh-ochre'],
  ochre: ['bg-ovs-crm-mesh-ochre', 'bg-ovs-crm-mesh-coral'],
};

export function CrmMesh({
  children,
  tone = 'coral',
  className,
}: {
  children: React.ReactNode;
  tone?: CrmMeshTone;
  className?: string;
}) {
  const [a, b] = MESH_ORBS[tone];

  return (
    <div className={cn('relative isolate', className)}>
      <div className="pointer-events-none absolute -inset-x-8 -inset-y-10 -z-10 overflow-hidden" aria-hidden="true">
        <div className={cn('absolute -left-[6%] top-[-30%] h-[460px] w-[460px] rounded-full opacity-70 blur-[90px]', a)} />
        <div className={cn('absolute right-[-4%] bottom-[-34%] h-[420px] w-[420px] rounded-full opacity-60 blur-[90px]', b)} />
      </div>
      {children}
    </div>
  );
}
