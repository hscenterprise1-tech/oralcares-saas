export type FeatureFlag = 
    // Core
    | 'core.multi_branch'
    | 'core.multi_clinic'
    | 'core.master_admin'
    | 'core.company_crm'
    | 'core.analytics'
    | 'core.audit_logs'
    // Patient
    | 'patient.timeline'
    | 'patient.medical_history'
    | 'patient.family_members'
    | 'patient.attachments'
    | 'patient.tags'
    // Reception
    | 'reception.queue_management'
    | 'reception.walk_in_registration'
    | 'reception.appointment_module'
    | 'reception.waiting_screen'
    | 'reception.token_system'
    // Clinical
    | 'clinical.treatment_planner'
    | 'clinical.tooth_chart'
    | 'clinical.treatment_templates'
    | 'clinical.prescription'
    | 'clinical.clinical_notes'
    | 'clinical.consent_forms'
    | 'clinical.medical_alerts'
    // Diagnostics
    | 'diagnostics.xray'
    | 'diagnostics.imaging'
    | 'diagnostics.lab'
    | 'diagnostics.lab_tracking'
    | 'diagnostics.radiology'
    // Billing
    | 'billing.invoice'
    | 'billing.insurance'
    | 'billing.discount_engine'
    | 'billing.refund'
    | 'billing.partial_payment'
    | 'billing.credit_balance'
    | 'billing.package_billing'
    // Finance
    | 'finance.accounting'
    | 'finance.expense'
    | 'finance.doctor_commission'
    | 'finance.revenue_reports'
    | 'finance.branch_revenue'
    // Inventory
    | 'inventory.stock'
    | 'inventory.purchase'
    | 'inventory.supplier'
    | 'inventory.batch_tracking'
    | 'inventory.expiry_tracking'
    // Communication
    | 'communication.whatsapp'
    | 'communication.sms'
    | 'communication.email'
    | 'communication.push_notifications'
    | 'communication.internal_chat'
    // AI
    | 'ai.assistant'
    | 'ai.clinical_suggestions'
    | 'ai.reports'
    | 'ai.analytics'
    | 'ai.voice_notes'
    // Integrations
    | 'integrations.payment_gateway'
    | 'integrations.api_access'
    | 'integrations.webhooks'
    | 'integrations.google_calendar'
    | 'integrations.outlook_calendar'
    // Security
    | 'security.two_factor_auth'
    | 'security.device_management'
    | 'security.session_monitoring'
    | 'security.login_audit'
    | 'security.ip_restrictions';

export const ALL_FEATURES: FeatureFlag[] = [
    'core.multi_branch', 'core.multi_clinic', 'core.master_admin', 'core.company_crm', 'core.analytics', 'core.audit_logs',
    'patient.timeline', 'patient.medical_history', 'patient.family_members', 'patient.attachments', 'patient.tags',
    'reception.queue_management', 'reception.walk_in_registration', 'reception.appointment_module', 'reception.waiting_screen', 'reception.token_system',
    'clinical.treatment_planner', 'clinical.tooth_chart', 'clinical.treatment_templates', 'clinical.prescription', 'clinical.clinical_notes', 'clinical.consent_forms', 'clinical.medical_alerts',
    'diagnostics.xray', 'diagnostics.imaging', 'diagnostics.lab', 'diagnostics.lab_tracking', 'diagnostics.radiology',
    'billing.invoice', 'billing.insurance', 'billing.discount_engine', 'billing.refund', 'billing.partial_payment', 'billing.credit_balance', 'billing.package_billing',
    'finance.accounting', 'finance.expense', 'finance.doctor_commission', 'finance.revenue_reports', 'finance.branch_revenue',
    'inventory.stock', 'inventory.purchase', 'inventory.supplier', 'inventory.batch_tracking', 'inventory.expiry_tracking',
    'communication.whatsapp', 'communication.sms', 'communication.email', 'communication.push_notifications', 'communication.internal_chat',
    'ai.assistant', 'ai.clinical_suggestions', 'ai.reports', 'ai.analytics', 'ai.voice_notes',
    'integrations.payment_gateway', 'integrations.api_access', 'integrations.webhooks', 'integrations.google_calendar', 'integrations.outlook_calendar',
    'security.two_factor_auth', 'security.device_management', 'security.session_monitoring', 'security.login_audit', 'security.ip_restrictions'
];

export const SUBSCRIPTION_PRESETS: Record<string, FeatureFlag[]> = {
    'starter': [
        'patient.timeline', 'patient.medical_history', 'patient.attachments',
        'reception.appointment_module', 'reception.walk_in_registration',
        'clinical.treatment_planner', 'clinical.prescription', 'clinical.clinical_notes',
        'billing.invoice', 'billing.discount_engine'
    ],
    'professional': [
        'core.multi_branch', 'core.analytics',
        'patient.timeline', 'patient.medical_history', 'patient.family_members', 'patient.attachments', 'patient.tags',
        'reception.queue_management', 'reception.walk_in_registration', 'reception.appointment_module', 'reception.waiting_screen',
        'clinical.treatment_planner', 'clinical.tooth_chart', 'clinical.treatment_templates', 'clinical.prescription', 'clinical.clinical_notes', 'clinical.consent_forms', 'clinical.medical_alerts',
        'diagnostics.xray', 'diagnostics.imaging', 'diagnostics.lab',
        'billing.invoice', 'billing.discount_engine', 'billing.refund', 'billing.partial_payment', 'billing.package_billing',
        'finance.expense', 'finance.revenue_reports', 'finance.doctor_commission',
        'inventory.stock', 'inventory.purchase', 'inventory.supplier',
        'communication.sms', 'communication.email', 'communication.whatsapp',
        'integrations.payment_gateway'
    ],
    'enterprise': ALL_FEATURES,
    'enterprise-plus': ALL_FEATURES,
    'custom': []
};
