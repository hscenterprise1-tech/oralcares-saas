import { colors, typography, shadows, radius, spacing, motion, status, table, zIndex, grid, accessibility, skeleton, emptyState } from '../tokens';

/**
 * Light Theme (Default)
 * 
 * Purpose: Defines the default active theme mapping for the OVS system.
 */
export const lightTheme = {
  colors,
  typography,
  shadows,
  radius,
  spacing,
  motion,
  status,
  table,
  zIndex,
  grid,
  accessibility,
  skeleton,
  emptyState,
} as const;

export default lightTheme;
