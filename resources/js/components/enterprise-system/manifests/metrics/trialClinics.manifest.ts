import { lazy } from 'react';
import { WidgetManifest } from '.././WidgetManifest';

export const TrialClinicsManifest: WidgetManifest = {
    id: 'network.trial_clinics',
    module: 'Network',
    title: "Trial Clinics",
    description: '',
    zone: 'growth',
    priority: 2,
    permissions: [],
    drillDownRoute: '/',
    refreshInterval: 60000,
    lazyLoad: false,
    defaultSpan: 2,
    component: lazy(() => import('../../widgets/TrialClinicsWidget').then(module => ({ default: module.TrialClinicsWidget })))
};