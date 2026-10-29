/**
 * The Patient Management design language — directory, registration, the
 * patient profile and editing share these rules so the four pages read as
 * one workflow. They compose the Clinic kit (`@/clinic`); nothing here is a
 * second component library.
 *
 * Surfaces
 * - The page sits on the portal's ice-blue workspace (the shell). Content is
 *   on white `Card`s with the hairline border and soft shadow; one main card
 *   per task, never a mosaic of small cards.
 * - A page opens with an eyebrow, a navy title and one line of purpose, and
 *   at most one primary action at the top right.
 *
 * Actions
 * - Primary: the kit's solid blue `Button tone="primary"` (the approved
 *   intake's blue) — once per view. Never black or navy.
 * - Secondary: outlined `Button`. Quiet: `ghost`. Destructive: `danger`, and
 *   only behind a confirmation.
 * - Teal marks interaction, not decoration: focus rings, active filters,
 *   links and the current selection.
 *
 * Identity
 * - A patient is always shown the same way (`PatientIdentity`): the avatar
 *   colour from the name, the name, then UHID · age · sex. Only fields the
 *   server sent are shown; nothing is padded with placeholders.
 *
 * Status
 * - States use the kit badges (word + dot; colour is never the only signal).
 *   The everyday state (an active patient) is not badged in lists — only
 *   what needs noticing is.
 * - Safety alerts are rose with an icon and a count, never colour alone.
 *
 * States
 * - Loading: skeleton rows in the list's own shape. Empty, no-results, error
 *   and forbidden: the kit's `StatePanel` family, each with its next step.
 *
 * Density and layout
 * - Desktop: a table with a compact 56px row. Below `lg`: stacked rows with
 *   the same information in the same order. Nothing scrolls sideways.
 * - Every target is at least 44px on touch screens (the kit handles buttons).
 *
 * Links
 * - A page that is not built yet is never linked: the server sends `null`
 *   for it (`ClinicFrontend`), and the control is left out, not disabled.
 */

/** The row and card hover/focus treatment for anything that opens a patient. */
export const ROW_INTERACTIVE =
  'motion-safe:transition-colors hover:bg-cx-nav-bg/60 focus-visible:bg-cx-nav-bg/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cx-nav-teal';

/** Small uppercase labels above data (table headers, field captions). */
export const CAPTION = 'text-[11px] font-semibold uppercase tracking-[0.06em] text-cx-text-3';
