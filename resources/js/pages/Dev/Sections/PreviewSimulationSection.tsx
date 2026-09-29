import { usePermissions } from '@/hooks/usePermissions';
import { usePreview } from '@/contexts/PreviewContext';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Info, ShieldAlert, ShieldCheck } from 'lucide-react';
import { PermissionGuard } from '@/components/PermissionGuard';

export function PreviewSimulationSection() {
    const { previewState } = usePreview();
    const { hasPermission, permissions, roles } = usePermissions();

    if (!previewState.isEnabled) {
        return (
            <Alert>
                <Info className="h-4 w-4" />
                <AlertTitle>Preview Engine Disabled</AlertTitle>
                <AlertDescription>
                    Enable the Preview Engine in the top control panel to see this simulation react to roles.
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <div className="space-y-8">
            <div>
                <h2 className="text-2xl font-bold tracking-tight mb-2">Live Permission Simulation</h2>
                <p className="text-slate-500">Change the role in the top bar to see the UI react instantly.</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                {/* Context Viewer */}
                <Card>
                    <CardHeader>
                        <CardTitle>Current Context</CardTitle>
                        <CardDescription>Live data from `usePermissions()`</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4 font-mono text-sm">
                        <div>
                            <span className="text-slate-500">Active Roles: </span>
                            <span className="font-semibold text-ovs-primary">{roles.join(', ') || 'none'}</span>
                        </div>
                        <div>
                            <span className="text-slate-500">Active Permissions: </span>
                            <div className="flex flex-wrap gap-2 mt-2">
                                {permissions.length > 0 ? permissions.map(p => (
                                    <span key={p} className="bg-slate-100 text-slate-700 px-2 py-1 rounded text-xs">{p}</span>
                                )) : <span className="text-slate-400 italic">None</span>}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Simulated UI Widgets */}
                <div className="space-y-4">
                    <PermissionGuard requires="view-finance" fallback={
                        <Alert variant="destructive">
                            <ShieldAlert className="h-4 w-4" />
                            <AlertTitle>Access Denied</AlertTitle>
                            <AlertDescription>You do not have the `view-finance` permission to see the Revenue Widget.</AlertDescription>
                        </Alert>
                    }>
                        <Card className="border-emerald-200 bg-emerald-50/30">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-emerald-800 flex items-center gap-2">
                                    <ShieldCheck className="h-5 w-5" />
                                    Revenue Widget (Finance Only)
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-3xl font-bold text-emerald-900">₹45,231.89</p>
                            </CardContent>
                        </Card>
                    </PermissionGuard>

                    <PermissionGuard requires="view-patients" fallback={
                        <Alert variant="destructive">
                            <ShieldAlert className="h-4 w-4" />
                            <AlertTitle>Access Denied</AlertTitle>
                            <AlertDescription>You do not have the `view-patients` permission.</AlertDescription>
                        </Alert>
                    }>
                        <Card className="border-blue-200 bg-blue-50/30">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-blue-800 flex items-center gap-2">
                                    <ShieldCheck className="h-5 w-5" />
                                    Patient Database
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm text-blue-900">You are authorized to view and search patient records.</p>
                            </CardContent>
                        </Card>
                    </PermissionGuard>
                </div>
            </div>
        </div>
    );
}
