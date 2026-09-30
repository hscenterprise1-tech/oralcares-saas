import { WidgetMetadata, WidgetRegistryEntry, WidgetCategory } from './types';

// ─────────────────────────────────────────────────────────────────────────────
// ENTERPRISE WIDGET REGISTRY
// ─────────────────────────────────────────────────────────────────────────────

class EnterpriseWidgetRegistry {
  private registry = new Map<string, WidgetRegistryEntry>();

  /**
   * Registers a new widget into the central registry.
   */
  public registerWidget(entry: WidgetRegistryEntry): void {
    if (!entry.metadata?.id) {
      throw new Error('[WidgetRegistry] Failed to register: Widget ID is required.');
    }
    if (!entry.metadata?.name || !entry.metadata?.category) {
      throw new Error(`[WidgetRegistry] Invalid metadata for widget "${entry.metadata.id}".`);
    }

    if (this.registry.has(entry.metadata.id)) {
      console.warn(`[WidgetRegistry] Overwriting existing widget "${entry.metadata.id}".`);
    }

    this.registry.set(entry.metadata.id, entry);
  }

  /**
   * Retrieves a registered widget by ID.
   */
  public getWidget(id: string): WidgetRegistryEntry | undefined {
    return this.registry.get(id);
  }

  /**
   * Returns all registered widgets.
   */
  public listWidgets(): WidgetRegistryEntry[] {
    return Array.from(this.registry.values());
  }

  /**
   * Filters widgets by category, module, or user permissions.
   */
  public filterWidgets(options?: {
    category?: WidgetCategory;
    module?: string;
    userPermissions?: string[];
  }): WidgetRegistryEntry[] {
    let results = this.listWidgets();

    if (options?.category) {
      results = results.filter((w) => w.metadata.category === options.category);
    }

    if (options?.module) {
      results = results.filter((w) => w.metadata.module === options.module);
    }

    if (options?.userPermissions) {
      const userPerms = new Set(options.userPermissions);
      results = results.filter((w) => {
        if (!w.metadata.permissions || w.metadata.permissions.length === 0) return true;
        return w.metadata.permissions.some((p) => userPerms.has(p));
      });
    }

    return results.sort((a, b) => (b.metadata.priority || 0) - (a.metadata.priority || 0));
  }

  /**
   * Clears all widgets (useful for test environments).
   */
  public clear(): void {
    this.registry.clear();
  }
}

export const WidgetRegistry = new EnterpriseWidgetRegistry();
