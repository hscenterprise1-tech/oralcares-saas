import { usePreview } from '@/contexts/PreviewContext';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Building2, Users, Activity, BarChart3, Stethoscope, Clock, ShieldCheck, TrendingUp } from 'lucide-react';
import { useDemoData } from '@/hooks/useDemoData';
import { EventBus } from '@/simulator/EventBus';
import { useState, useEffect } from 'react';

export function DashboardPreviewSection() {
    const { previewState } = usePreview();
    const { mockPatients, mockStaff, mockBranches } = useDemoData();
    
    // Live simulation state
    const [livePatients, setLivePatients] = useState(mockPatients.total);
    const [liveRevenue, setLiveRevenue] = useState(0);
    const [liveQueue, setLiveQueue] = useState(0);

    useEffect(() => {
        // Reset when profile changes
        setLivePatients(mockPatients.total);
        setLiveRevenue(0);
        setLiveQueue(0);
    }, [previewState.clinicProfile, mockPatients.total]);

    useEffect(() => {
        const onPatientReg = () => setLivePatients(p => p + 1);
        const onInvoice = (payload: { amount: number }) => setLiveRevenue(r => r + payload.amount);
        const onQueue = (payload: { waitingCount: number }) => setLiveQueue(payload.waitingCount);

        EventBus.on('PatientRegistered', onPatientReg);
        EventBus.on('InvoiceCreated', onInvoice);
        EventBus.on('QueueCreated', onQueue);

        return () => {
            EventBus.off('PatientRegistered', onPatientReg);
            EventBus.off('InvoiceCreated', onInvoice);
            EventBus.off('QueueCreated', onQueue);
        };
    }, []);

    return (
        <div className="space-y-8">
            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-2xl font-bold tracking-tight mb-2 capitalize">
                        {previewState.clinicProfile.replace(/-/g, ' ')} Dashboard
                    </h2>
                    <p className="text-slate-500">
                        Dashboard layout simulated for the <strong>{previewState.role}</strong> role.
                    </p>
                </div>
            </div>

            {/* Global Stats Mock */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Total Patients</CardTitle>
                        <Users className="h-4 w-4 text-ovs-secondary" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{livePatients.toLocaleString()}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Total Staff</CardTitle>
                        <Stethoscope className="h-4 w-4 text-ovs-secondary" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{mockStaff.total.toLocaleString()}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Active Branches</CardTitle>
                        <Building2 className="h-4 w-4 text-ovs-secondary" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{mockBranches.total.toLocaleString()}</div>
                    </CardContent>
                </Card>
            </div>

            {/* Dynamic Dashboard Layout based on Focus */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {previewState.dashboardFocus === 'appointments' && (
                    <Card className="col-span-1 lg:col-span-2 border-blue-200 bg-blue-50/30">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-blue-800">
                                <Clock className="h-5 w-5" /> Today's Queue & Appointments
                            </CardTitle>
                            <CardDescription>Primary focus for Solo Dentists and small practices.</CardDescription>
                        </CardHeader>
                        <CardContent className="h-[200px] flex flex-col items-center justify-center border-t border-blue-100 bg-white">
                            <div className="text-5xl font-bold text-blue-900">{liveQueue}</div>
                            <span className="text-slate-400 mt-2">Patients Waiting in Queue</span>
                        </CardContent>
                    </Card>
                )}

                {previewState.dashboardFocus === 'performance' && (
                    <Card className="col-span-1 lg:col-span-2 border-indigo-200 bg-indigo-50/30">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-indigo-800">
                                <Activity className="h-5 w-5" /> Doctor Performance & Treatment Flow
                            </CardTitle>
                            <CardDescription>Multi-doctor metric tracking.</CardDescription>
                        </CardHeader>
                        <CardContent className="h-[200px] flex items-center justify-center border-t border-indigo-100 bg-white">
                            <span className="text-slate-400">Mock Performance Chart (Phase 3)</span>
                        </CardContent>
                    </Card>
                )}

                {(previewState.dashboardFocus === 'operations' || previewState.dashboardFocus === 'executive') && (
                    <>
                        <Card className="border-emerald-200 bg-emerald-50/30">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-emerald-800">
                                    <BarChart3 className="h-5 w-5" /> Regional Revenue
                                </CardTitle>
                                <CardDescription>Aggregated across {mockBranches.total} branches.</CardDescription>
                            </CardHeader>
                            <CardContent className="h-[200px] flex flex-col items-center justify-center border-t border-emerald-100 bg-white">
                                <div className="text-4xl font-bold text-emerald-600 flex items-center gap-2">
                                    <TrendingUp className="h-6 w-6" /> ${liveRevenue.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                </div>
                                <span className="text-slate-400 mt-2">Live Revenue Tracker</span>
                            </CardContent>
                        </Card>
                        <Card className="border-purple-200 bg-purple-50/30">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-purple-800">
                                    <ShieldCheck className="h-5 w-5" /> Department Operations
                                </CardTitle>
                                <CardDescription>Lab, X-Ray, and Billing flow.</CardDescription>
                            </CardHeader>
                            <CardContent className="h-[200px] flex items-center justify-center border-t border-purple-100 bg-white">
                                <span className="text-slate-400">Mock Department Metrics</span>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </div>
    );
}
