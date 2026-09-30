import { lazy } from 'react';
import { WidgetManifest } from './WidgetManifest';

export const SalesPipelineManifest: WidgetManifest = {
    id: 'crm.pipeline',
    module: 'CRM',
    title: "Sales Pipeline",
    description: 'CRM deal flow.',
    zone: 'revenue',
    priority: 2,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: false,
    defaultSpan: 5,
    component: lazy(() => import('../widgets/SalesPipelineWidget').then(module => ({ default: module.SalesPipelineWidget })))
};