export interface EnterpriseWidgetConfig {
    id: string;                  // e.g., 'crm.sales_pipeline'
    module: string;              // e.g., 'CRM', 'Finance', 'Network'
    title: string;               // e.g., 'Sales Pipeline'
    description?: string;        
    priority: 1 | 2 | 3 | 4;     // Defines loading sequence and layout placement
    permission: string;          // e.g., 'view_crm_analytics'
    drillDownRoute: string;      // Mandatory: e.g., route('company.crm.index')
    refreshInterval?: number;    // e.g., 60000 (ms) for live updates
    lazyLoad: boolean;           // True for Priority 3 and 4
    exportable: boolean;         // True if data can be downloaded
}
