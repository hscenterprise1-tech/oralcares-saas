import React from 'react';

export interface WidgetManifest {
    id: string;                  // e.g., 'finance.revenue_intelligence'
    module: string;              // 'Finance'
    title: string;
    description: string;
    zone: 'executive' | 'growth' | 'revenue' | 'operations' | 'activity';
    priority: 1 | 2 | 3 | 4;
    permissions: string[];       // e.g., ['view_finance', 'is_ceo']
    drillDownRoute: string;      // route('company.finance.index')
    refreshInterval: number;     // e.g., 60000 (ms)
    lazyLoad: boolean;
    defaultSpan: number;         // e.g., 7 (for a 12-col grid)
    component: React.LazyExoticComponent<any> | React.ComponentType<any>; // Lazy loaded React node or direct component for P1
}

export interface WidgetProps {
    manifest: WidgetManifest;
    isRefreshing: boolean;
    isEditingMode: boolean;
}
