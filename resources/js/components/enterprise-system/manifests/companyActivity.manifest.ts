import { lazy } from 'react';
import { WidgetManifest } from './WidgetManifest';

export const CompanyActivityManifest: WidgetManifest = {
    id: 'audit.activity',
    module: 'Audit Engine',
    title: "Company Activity",
    description: 'Global audit trail.',
    zone: 'activity',
    priority: 4,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: true,
    defaultSpan: 12,
    component: lazy(() => import('../widgets/CompanyActivityWidget').then(module => ({ default: module.CompanyActivityWidget })))
};