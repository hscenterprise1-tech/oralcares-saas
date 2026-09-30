import { lazy } from 'react';
import { WidgetManifest } from '.././WidgetManifest';

export const RenewalsDueMetricManifest: WidgetManifest = {
    id: 'finance.renewals_due',
    module: 'Finance',
    title: "Renewals Due",
    description: '',
    zone: 'growth',
    priority: 2,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: false,
    defaultSpan: 2,
    component: lazy(() => import('../../widgets/RenewalsDueMetricWidget').then(module => ({ default: module.RenewalsDueMetricWidget })))
};