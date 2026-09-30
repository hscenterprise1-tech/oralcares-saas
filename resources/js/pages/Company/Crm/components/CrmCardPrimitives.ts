/**
 * Re-export shim.
 *
 * The design system now lives in `Company/design/`. This file exists so the
 * CRM's existing imports keep working unchanged; new code should import from
 * `@/pages/Company/design` directly.
 */
export * from '../../design/CrmCardPrimitives';
