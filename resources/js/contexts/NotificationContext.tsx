import React, { createContext, useContext, useEffect, ReactNode } from 'react';
import { EventBus } from '@/simulator/EventBus';
import { useToast } from '@/hooks/use-toast';
import { Toaster } from '@/components/ui/toaster';

interface NotificationContextType {
    // Stub for actual notification API methods
}

const NotificationContext = createContext<NotificationContextType | undefined>(undefined);

export function NotificationProvider({ children }: { children: ReactNode }) {
    const { toast } = useToast();

    // The centralized notification framework automatically listens to Global Events
    useEffect(() => {
        const handlePatientReg = (payload: any) => {
            toast({
                title: "Patient Registered",
                description: `New patient ${payload.patientId} has been added to the system.`,
            });
        };

        const handleInvoice = (payload: any) => {
            toast({
                title: "Invoice Generated",
                description: `Invoice ${payload.invoiceId} created for $${payload.amount}.`,
            });
        };

        EventBus.on('PatientRegistered', handlePatientReg);
        EventBus.on('InvoiceCreated', handleInvoice);

        return () => {
            EventBus.off('PatientRegistered', handlePatientReg);
            EventBus.off('InvoiceCreated', handleInvoice);
        };
    }, [toast]);

    return (
        <NotificationContext.Provider value={{}}>
            {children}
            <Toaster />
        </NotificationContext.Provider>
    );
}

export function useNotification() {
    const context = useContext(NotificationContext);
    if (context === undefined) {
        throw new Error('useNotification must be used within a NotificationProvider');
    }
    return context;
}
