import { lazy } from 'react';
import { WidgetManifest } from './WidgetManifest';

export const RevenueIntelligenceManifest: WidgetManifest = {
    id: 'finance.revenue',
    module: 'Finance',
    title: "Revenue Intelligence",
    description: 'Financial performance and MRR.',
    zone: 'revenue',
    priority: 2,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: false,
    defaultSpan: 7,
    component: lazy(() => import('../widgets/RevenueIntelligenceWidget').then(module => ({ default: module.RevenueIntelligenceWidget })))
};