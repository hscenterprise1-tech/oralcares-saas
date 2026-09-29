import { lazy } from 'react';
import { WidgetManifest } from './WidgetManifest';

export const TodaysDemosManifest: WidgetManifest = {
    id: 'crm.demos_today',
    module: 'CRM',
    title: "Todays Demos",
    description: 'Scheduled demos for today.',
    zone: 'operations',
    priority: 3,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: true,
    defaultSpan: 6,
    component: lazy(() => import('../widgets/TodaysDemosWidget').then(module => ({ default: module.TodaysDemosWidget })))
};