import { EventBus } from './EventBus';

export class WorkflowEngine {
    private isPlaying = false;
    private timerId: ReturnType<typeof setTimeout> | null = null;
    private speedMultiplier = 1;
    
    // Internal mock state
    private state = {
        patientsToday: 0,
        waitingRoom: [] as string[],
        inConsultation: [] as string[],
        inLab: [] as string[],
        inBilling: [] as { pId: string, amount: number }[],
        revenueToday: 0,
        patientIdCounter: 1000,
        invoiceIdCounter: 5000,
    };

    private baseTickInterval = 2000; // 2 seconds in realtime

    public play() {
        if (this.isPlaying) return;
        this.isPlaying = true;
        this.scheduleNextTick();
    }

    public pause() {
        this.isPlaying = false;
        if (this.timerId) clearTimeout(this.timerId);
    }

    public setSpeed(multiplier: number) {
        this.speedMultiplier = multiplier;
        if (this.isPlaying) {
            this.pause();
            this.play();
        }
    }

    public step() {
        this.tick();
    }

    public reset() {
        this.pause();
        this.state = {
            patientsToday: 0,
            waitingRoom: [],
            inConsultation: [],
            inLab: [],
            inBilling: [],
            revenueToday: 0,
            patientIdCounter: 1000,
            invoiceIdCounter: 5000,
        };
    }

    public getIsPlaying() {
        return this.isPlaying;
    }

    private scheduleNextTick() {
        const interval = this.baseTickInterval / this.speedMultiplier;
        this.timerId = setTimeout(() => {
            this.tick();
            if (this.isPlaying) this.scheduleNextTick();
        }, interval);
    }

    private tick() {
        // Pipeline: Reg -> Queue -> Consult -> Lab/XRay -> Billing
        
        // 1. Process Billing (100% chance if someone is in billing)
        if (this.state.inBilling.length > 0) {
            const bill = this.state.inBilling.shift()!;
            this.state.invoiceIdCounter++;
            const iId = `INV-${this.state.invoiceIdCounter}`;
            this.state.revenueToday += bill.amount;
            
            EventBus.emit('InvoiceCreated', { invoiceId: iId, patientId: bill.pId, amount: bill.amount });
            EventBus.emit('PaymentReceived', { invoiceId: iId, amount: bill.amount, method: 'Card' });
            EventBus.emit('NotificationSent', { type: 'success', message: `Receipt sent for ${iId}`, channel: 'WhatsApp' });
            EventBus.emit('AuditRecorded', { action: 'Payment Processed', user: 'Billing Dept' });
        }

        // 2. Process Lab/XRay (50% chance to finish)
        if (this.state.inLab.length > 0 && Math.random() > 0.5) {
            const pId = this.state.inLab.shift()!;
            EventBus.emit('LabCompleted', { patientId: pId, test: 'Blood Work', status: 'Ready' });
            // Send them back to consultation or straight to billing
            this.state.inBilling.push({ pId, amount: 150 });
        }

        // 3. Process Consultation (50% chance to finish)
        if (this.state.inConsultation.length > 0 && Math.random() > 0.5) {
            const pId = this.state.inConsultation.shift()!;
            EventBus.emit('DoctorCompletedConsultation', { patientId: pId, notes: 'Routine checkup completed.' });
            EventBus.emit('TreatmentCompleted', { patientId: pId, treatment: 'Scaling', cost: 100 });
            EventBus.emit('PrescriptionIssued', { patientId: pId, medications: ['Amoxicillin'] });
            
            // 30% chance they need lab
            if (Math.random() < 0.3) {
                this.state.inLab.push(pId);
                EventBus.emit('LabRequested', { patientId: pId, test: 'Blood Work' });
            } else {
                this.state.inBilling.push({ pId, amount: 100 });
            }
        }

        // 4. Process Queue (if queue exists and doctors are free)
        if (this.state.waitingRoom.length > 0 && this.state.inConsultation.length < 3) {
            const pId = this.state.waitingRoom.shift()!;
            EventBus.emit('QueueCalled', { patientId: pId, room: 'Room 1' });
            EventBus.emit('DoctorStartedConsultation', { patientId: pId, doctorId: 'DR-01' });
            this.state.inConsultation.push(pId);
        }

        // 5. New Patient Registration (30% chance per tick)
        if (Math.random() < 0.3) {
            this.state.patientsToday++;
            this.state.patientIdCounter++;
            const pId = `PT-${this.state.patientIdCounter}`;
            const type = Math.random() > 0.5 ? 'Walk-In' : 'Appointment';
            
            if (type === 'Appointment') {
                EventBus.emit('AppointmentBooked', { patientId: pId, date: new Date().toISOString() });
            }
            
            EventBus.emit('PatientRegistered', { patientId: pId, name: `Mock Patient ${this.state.patientsToday}`, type });
            
            this.state.waitingRoom.push(pId);
            EventBus.emit('QueueCreated', { patientId: pId, department: 'General', waitingCount: this.state.waitingRoom.length });
        }
        
        // 6. Occasional background events (Reports)
        if (Math.random() < 0.05) {
            EventBus.emit('ReportGenerated', { reportType: 'Daily Revenue', url: '/reports/123' });
        }
    }
}
