/*
 * `EnterpriseWorkspace` is gone.
 *
 * It wrapped `EnterpriseAppShell`, which wrapped `ExecutiveShell` — a second
 * company chrome that no page ever imported. Nothing imported
 * EnterpriseWorkspace either, so only this barrel kept the chain alive.
 */
export * from './WorkspaceCopilot';
export * from './WorkspaceMetrics';
export * from './WorkspaceTimeline';
export * from './EnterpriseSidebar';
