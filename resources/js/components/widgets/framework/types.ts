import React from 'react';

// ─────────────────────────────────────────────────────────────────────────────
// ENTERPRISE WIDGET FRAMEWORK (EWF) — CONTRACT & TYPES
// ─────────────────────────────────────────────────────────────────────────────

export type WidgetCategory =
  | 'commercial'
  | 'marketing'
  | 'network'
  | 'operations'
  | 'finance'
  | 'platform'
  | 'executive'
  | 'ai';

export type WidgetState =
  | 'idle'
  | 'loading'
  | 'skeleton'
  | 'empty'
  | 'error'
  | 'permission-denied'
  | 'offline'
  | 'refreshing';

export type WidgetSize = 'xs' | 's' | 'm' | 'l' | 'xl' | 'full';

export type ViewSwitchOption = 'grid' | 'list' | 'table' | 'kanban';

export interface WidgetLayoutRules {
  minWidth?: number | string;
  maxWidth?: number | string;
  preferredHeight?: number | string;
  resizable?: boolean;
  collapsible?: boolean;
  movable?: boolean;
  pinned?: boolean;
}

export interface WidgetMetadata {
  id: string;
  name: string;
  category: WidgetCategory;
  owner: string;
  permissions?: string[];
  module: string;
  priority?: number;
  version: string;
  refreshInterval?: number; // ms
  layoutRules?: WidgetLayoutRules;
  defaultSize?: WidgetSize;
}

export interface WidgetRegistryEntry {
  metadata: WidgetMetadata;
  component: React.ComponentType<any>;
}

export interface WidgetSlotRules {
  zoneId: string;
  allowedCategories?: WidgetCategory[];
  allowedWidgetTypes?: string[];
  maxWidgets?: number;
  layoutBehaviour?: 'grid' | 'stack' | 'flex';
  expansionRules?: {
    allowFullWidth?: boolean;
    autoExpandOnFocus?: boolean;
    collapsible?: boolean;
  };
}
