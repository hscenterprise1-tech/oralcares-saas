// Strongly typed events that simulate real production behavior

export type SimulatorEventName = 
    | 'PatientRegistered'
    | 'PatientUpdated'
    | 'AppointmentBooked'
    | 'AppointmentCancelled'
    | 'QueueCreated'
    | 'QueueCalled'
    | 'DoctorStartedConsultation'
    | 'DoctorCompletedConsultation'
    | 'TreatmentCreated'
    | 'TreatmentCompleted'
    | 'PrescriptionIssued'
    | 'LabRequested'
    | 'LabCompleted'
    | 'XRayRequested'
    | 'XRayCompleted'
    | 'InvoiceCreated'
    | 'PaymentReceived'
    | 'RefundCreated'
    | 'BranchChanged'
    | 'UserLoggedIn'
    | 'PermissionUpdated'
    | 'FeatureFlagChanged'
    | 'SubscriptionChanged'
    | 'NotificationSent'
    | 'ReportGenerated'
    | 'AuditRecorded';

export interface SimulatorEventPayload {
    PatientRegistered: { patientId: string; name: string; type: 'Walk-In' | 'Appointment' };
    PatientUpdated: { patientId: string };
    AppointmentBooked: { patientId: string; date: string };
    AppointmentCancelled: { patientId: string; reason: string };
    QueueCreated: { patientId: string; department: string; waitingCount: number };
    QueueCalled: { patientId: string; room: string };
    DoctorStartedConsultation: { patientId: string; doctorId: string };
    DoctorCompletedConsultation: { patientId: string; notes: string };
    TreatmentCreated: { patientId: string; planId: string };
    TreatmentCompleted: { patientId: string; treatment: string; cost: number };
    PrescriptionIssued: { patientId: string; medications: string[] };
    LabRequested: { patientId: string; test: string };
    LabCompleted: { patientId: string; test: string; status: 'Ready' | 'Rejected' };
    XRayRequested: { patientId: string; region: string };
    XRayCompleted: { patientId: string; status: 'Uploaded' };
    InvoiceCreated: { invoiceId: string; patientId: string; amount: number };
    PaymentReceived: { invoiceId: string; amount: number; method: string };
    RefundCreated: { invoiceId: string; amount: number };
    BranchChanged: { branchId: string };
    UserLoggedIn: { userId: string; role: string };
    PermissionUpdated: { role: string };
    FeatureFlagChanged: { feature: string; enabled: boolean };
    SubscriptionChanged: { plan: string };
    NotificationSent: { type: 'success' | 'warning' | 'error' | 'info'; message: string; channel: 'In-App' | 'WhatsApp' | 'Email' };
    ReportGenerated: { reportType: string; url: string };
    AuditRecorded: { action: string; user: string };
}

type EventCallback<T extends SimulatorEventName> = (payload: SimulatorEventPayload[T]) => void;

class SimulatorEventBus {
    private listeners: { [K in SimulatorEventName]?: EventCallback<K>[] } = {};

    on<T extends SimulatorEventName>(event: T, callback: EventCallback<T>) {
        if (!this.listeners[event]) {
            this.listeners[event] = [];
        }
        this.listeners[event]!.push(callback);
    }

    off<T extends SimulatorEventName>(event: T, callback: EventCallback<T>) {
        if (!this.listeners[event]) return;
        this.listeners[event] = this.listeners[event]!.filter(cb => cb !== callback) as any;
    }

    emit<T extends SimulatorEventName>(event: T, payload: SimulatorEventPayload[T]) {
        if (!this.listeners[event]) return;
        this.listeners[event]!.forEach(callback => callback(payload));
    }
}

// Export a singleton instance for global use across the dev environment
export const EventBus = new SimulatorEventBus();
