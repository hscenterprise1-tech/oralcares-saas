import { lazy } from 'react';
import { WidgetManifest } from '.././WidgetManifest';

export const DemosScheduledManifest: WidgetManifest = {
    id: 'crm.demos_scheduled',
    module: 'CRM',
    title: "Demos Scheduled",
    description: '',
    zone: 'growth',
    priority: 2,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: false,
    defaultSpan: 2,
    component: lazy(() => import('../../widgets/DemosScheduledWidget').then(module => ({ default: module.DemosScheduledWidget })))
};