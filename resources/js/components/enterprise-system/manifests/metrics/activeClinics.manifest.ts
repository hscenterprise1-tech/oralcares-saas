import { lazy } from 'react';
import { WidgetManifest } from '.././WidgetManifest';

export const ActiveClinicsManifest: WidgetManifest = {
    id: 'network.active_clinics',
    module: 'Network',
    title: "Active Clinics",
    description: '',
    zone: 'growth',
    priority: 2,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: false,
    defaultSpan: 2,
    component: lazy(() => import('../../widgets/ActiveClinicsWidget').then(module => ({ default: module.ActiveClinicsWidget })))
};