import { lazy } from 'react';
import { WidgetManifest } from './WidgetManifest';

export const PendingRenewalsManifest: WidgetManifest = {
    id: 'finance.renewals',
    module: 'Finance',
    title: "Pending Renewals",
    description: 'Subscriptions expiring soon.',
    zone: 'operations',
    priority: 3,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: true,
    defaultSpan: 6,
    component: lazy(() => import('../widgets/PendingRenewalsWidget').then(module => ({ default: module.PendingRenewalsWidget })))
};