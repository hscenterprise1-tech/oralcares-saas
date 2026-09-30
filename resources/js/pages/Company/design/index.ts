/**
 * The CRM design system.
 *
 * A small coherent set of primitives that every CRM surface composes from.
 * Anything added here must be genuinely shared by at least two surfaces — a
 * component used in one place belongs next to that page, not in the system.
 *
 * Visual meaning (stage, status, channel, priority → colour) lives in
 * `crmTheme`. Components ask it what something means; they never choose a
 * palette shade themselves.
 */

// ── Semantics ───────────────────────────────────────────────────────────────
export {
  TONES,
  STAGE_TONE,
  STAGE_SHORT,
  STATUS_TONE,
  CHANNEL_TONE,
  PRIORITY_TONE,
  SPECIALTY_TONE,
  SOURCE_TONE,
  stageTone,
  statusTone,
  channelTone,
  priorityTone,
  specialtyTone,
  sourceTone,
  toneClasses,
  humanize,
  inr,
  toneHex,
} from './crmTheme';
export {
  healthTone,
  churnTone,
  lifecycleStageTone,
  utilizationTone,
  csToneHex,
  CS_STAGE_TONE,
} from './crmTheme';
export type { CrmTone, ToneClasses, CsStatusTone } from './crmTheme';

// ── Badges & indicators ─────────────────────────────────────────────────────
export {
  CrmBadge,
  CrmStageBadge,
  CrmStatusBadge,
  CrmPriorityBadge,
  CrmScore,
} from './CrmBadge';
export type { CrmBadgeProps } from './CrmBadge';

// ── Layout ──────────────────────────────────────────────────────────────────
/*
 * `CrmField` is the read-only label-and-value row, a different job from
 * `CrmFormField`, which wraps a control. The rest of CrmLayout is legacy and
 * only these four names are still composed with.
 */
export {
  CrmSplitWorkspace,
  CrmField,
} from './CrmLayout';

// ── Data surfaces ───────────────────────────────────────────────────────────
export { CrmDataState } from './CrmDataState';
export type { CrmDataStateProps, CrmStateKind, CrmSkeletonShape } from './CrmDataState';

export { CrmFilterBar } from './CrmFilterBar';
export type { CrmFilterBarProps, CrmFilterDef, CrmFilterOption, CrmFilterChip } from './CrmFilterBar';

export { CrmSection, CrmMetric, CrmPageHead, CrmPageStat } from './CrmSection';
export type { CrmSectionProps } from './CrmSection';

export { CrmTable, CrmTableEmpty } from './CrmTable';
export type { CrmTableProps, CrmColumn } from './CrmTable';

export { CrmPagination, unwrapPaginated } from './CrmPagination';
export type { CrmPaginationProps, PaginatorLink } from './CrmPagination';

// ── Overlays ────────────────────────────────────────────────────────────────
export { CrmConfirmDialog, useCrmConfirm } from './CrmConfirmDialog';
export type { CrmConfirmDialogProps } from './CrmConfirmDialog';

// Legacy status helpers kept for surfaces not yet migrated to CrmBadge.
export { statusVariant, humanizeStatus } from './CrmStatusBadge';

// ── Shell ───────────────────────────────────────────────────────────────────
export { CompanyShell, CrmShell, CrmIconButton, CrmToolRail } from './CrmShell';
export { AdministrationShell } from './AdministrationShell';
export type { ShellRailTool, ShellCreateItem } from './CrmShell';
export { CrmCanvas, CrmMesh } from './CrmCanvas';
export type { CrmMeshTone } from './CrmCanvas';
export { CrmCommandPalette } from './CrmCommandPalette';

// ── People & deals ──────────────────────────────────────────────────────────
export { CrmAvatar, CrmAvatarStack, avatarHexForName } from './CrmAvatar';
export type { CrmAvatarProps } from './CrmAvatar';


// ── Reference surface language ──────────────────────────────────────────────
export {
  CrmCard,
  CrmCardIcon,
  CrmOpenButton,
  CrmValue,
  CrmHairlineBar,
  CrmSparkline,
  CrmRing,
  CrmStatusPill,
  CrmGradientCard,
  CrmHalftone,
} from './CrmSurface';
export type { CrmStatusTone, CrmGradientTone } from './CrmSurface';

// ── Controls & overlays ─────────────────────────────────────────────────────
export { CrmButton, CrmFormField, CrmInput, CrmTextarea, CrmSelect, CrmTabs } from './CrmControls';
export type { CrmButtonProps } from './CrmControls';
export { CrmDatePicker, CRM_DATE_PICKER_OVS_FIELD } from './CrmDatePicker';
export type { CrmDatePickerProps } from './CrmDatePicker';
export { CrmModal, CrmConfirm } from './CrmOverlay';

// ── Filter panel — Marketing's pattern, now shared ───────────────────────────
export {
  CrmFiltersToggle,
  CrmFilterPanel,
  CrmFilterGroup,
  CrmFilterPill,
} from './CrmFilterPanel';

export { CrmDisclosure } from './CrmDisclosure';
