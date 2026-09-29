import { lazy } from 'react';
import { WidgetManifest } from '.././WidgetManifest';

export const NewLeadsManifest: WidgetManifest = {
    id: 'crm.new_leads',
    module: 'CRM',
    title: "New Leads",
    description: '',
    zone: 'growth',
    priority: 2,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: false,
    defaultSpan: 2,
    component: lazy(() => import('../../widgets/NewLeadsWidget').then(module => ({ default: module.NewLeadsWidget })))
};