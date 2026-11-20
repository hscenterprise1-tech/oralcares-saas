/*
 * The Patient Form settings screen's payload (`SettingsController::patientForm`).
 * The server is the contract: these mirror its shapes, they do not add to them.
 */
import type { CatalogueItem, FieldState, IntakeField } from '@/pages/Clinic/Reception/intake/types';

/** One configurable field's full setting (`PatientFieldConfigService::definitions`). */
export interface FieldDefinition {
  state: FieldState;
  order: number;
  new_case: boolean;
  full_page: boolean;
  specialties: string[] | null;
  visible_roles: string[] | null;
  editable_roles: string[] | null;
  label: string | null;
  help: string | null;
}

/** A catalogue item as the settings list shows it (disabled and archived included). */
export interface ListItem extends CatalogueItem {
  position: number;
  enabled: boolean;
  custom: boolean;
  archived: boolean;
}

export type ListKind = 'visit_reason' | 'condition' | 'allergy' | 'medication' | 'surgery' | 'family_history' | 'alert';

export interface PatientFormProps {
  modules: { key: string; label: string }[];
  fields: Record<string, FieldDefinition>;
  customised: string[];
  system_fields: { key: string; label: string; reason: string }[];
  roles: string[];
  templates: { key: string; label: string; description: string }[];
  limits: { label: number; help: number };
  quick_care: { allergy_status: FieldState; alerts: boolean; alert_roles: string[] | null; conditions_from_catalogue: boolean };
  visit_reason: FieldState;
  lists: Record<ListKind, ListItem[]>;
  links: { fields: string; template: string; quick_care: string; check_in: string; catalogue: string; automation?: string; physio?: string | null; self?: string };
  settings_links: import('../SettingsTabs').SettingsLinks;
}

export type Screen = 'new_case' | 'full_page';

/** What the preview is looking at: one module, role and screen. Empty: any module / the owner. */
export interface PreviewScope {
  module: string;
  role: string;
  screen: Screen;
}

/**
 * The settings as one member sees them on one screen for one module — the
 * same rule the server applies (`PatientFieldConfigService::effective`):
 * hidden unless it applies to the module, the role may see it, and it is on
 * that screen (a required field is on every screen).
 */
export function effectiveFields(fields: Record<string, FieldDefinition>, scope: PreviewScope): Record<string, IntakeField> {
  return Object.fromEntries(
    Object.entries(fields).map(([field, definition]) => {
      const applies = scope.module === '' || !definition.specialties || definition.specialties.includes(scope.module);
      const visible = scope.role === '' || !definition.visible_roles || definition.visible_roles.includes(scope.role);
      const onScreen = definition.state === 'required' || (scope.screen === 'new_case' ? definition.new_case : definition.full_page);
      const state: FieldState = !applies || !visible || !onScreen ? 'hidden' : definition.state;
      const editable = state !== 'hidden' && (scope.role === '' || !definition.editable_roles || definition.editable_roles.includes(scope.role));

      return [field, { state, order: definition.order, editable, specialties: definition.specialties, label: definition.label, help: definition.help }];
    }),
  );
}

/** A required field is on every screen: the server holds the two together, and so does the editor. */
export function normalised(definition: FieldDefinition): FieldDefinition {
  return definition.state === 'required' ? { ...definition, new_case: true, full_page: true } : definition;
}
