import { lazy } from 'react';
import { WidgetManifest } from '.././WidgetManifest';

export const ConversionsManifest: WidgetManifest = {
    id: 'crm.conversions',
    module: 'CRM',
    title: "Conversions",
    description: '',
    zone: 'growth',
    priority: 2,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: false,
    defaultSpan: 2,
    component: lazy(() => import('../../widgets/ConversionsWidget').then(module => ({ default: module.ConversionsWidget })))
};