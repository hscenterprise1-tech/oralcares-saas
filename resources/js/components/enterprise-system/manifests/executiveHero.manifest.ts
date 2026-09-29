import { lazy } from 'react';
import { WidgetManifest } from './WidgetManifest';

export const ExecutiveHeroManifest: WidgetManifest = {
    id: 'core.executive_overview',
    module: 'Core',
    title: 'Executive Overview',
    description: 'System health and company-wide priorities.',
    zone: 'executive',
    priority: 1,
    permissions: ['view_dashboard'], // Basic permission required
    drillDownRoute: '/company/settings', // Assuming this is the core platform settings
    refreshInterval: 30000, // 30 seconds
    lazyLoad: false,
    defaultSpan: 12,
    // Since Priority 1, we could import directly, but we'll use lazy for consistency, 
    // or direct import if we want to avoid the React Suspense overhead for the very first element.
    // Let's use lazy for now, but prefetch it if needed.
    component: lazy(() => import('../widgets/ExecutiveHeroWidget').then(module => ({ default: module.ExecutiveHeroWidget })))
};
