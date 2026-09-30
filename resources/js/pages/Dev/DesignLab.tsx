import { Head } from '@inertiajs/react';
import { CreditCard, Zap, Activity, Briefcase, FileText, TrendingUp, AlertCircle, ArrowRight } from 'lucide-react';
import { SignatureCard } from '@/components/ui/signature-card';
import { MetricCard } from '@/components/ui/metric-card';
import { StatusChip } from '@/components/ui/status-chip';
import { CommandButton } from '@/components/ui/command-button';
import { PipelineDistribution } from '@/components/ui/pipeline-distribution';
import { EnterpriseLayout } from '@/layouts/EnterpriseLayout';

export default function DesignLab() {
    const headerContent = (
        <div>
            <h2 className="micro-label mb-2 text-slate-500">Dev</h2>
            <h1 className="text-3xl font-bold tracking-tight text-slate-900">Design Lab</h1>
        </div>
    );

    return (
        <EnterpriseLayout header={headerContent}>
            <Head title="OEDS Design Lab" />

            <div className="max-w-[1600px] mx-auto pb-20 px-4 md:px-8 space-y-16 mt-8">
                <div>
                    <h1 className="text-4xl font-black text-slate-950 tracking-tight mb-2">Enterprise Component Showcase</h1>
                    <p className="text-slate-500">Visual QA and reference for OEDS reusable components.</p>
                </div>

                {/* Section: SignatureCard */}
                <section>
                    <h2 className="text-xl font-bold mb-6 border-b pb-2">1. SignatureCard</h2>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <SignatureCard className="p-8 h-48 flex items-center justify-center">
                            <p className="font-semibold text-slate-700">Default (Hover enabled)</p>
                        </SignatureCard>
                        <SignatureCard noHover className="p-8 h-48 flex items-center justify-center">
                            <p className="font-semibold text-slate-700">noHover = true</p>
                        </SignatureCard>
                    </div>
                </section>

                {/* Section: MetricCard */}
                <section>
                    <h2 className="text-xl font-bold mb-6 border-b pb-2">2. MetricCard</h2>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <MetricCard
                            title="Monthly Recurring Revenue"
                            value="₹124,500"
                            icon={CreditCard}
                            badge={<StatusChip variant="success"><TrendingUp className="w-3 h-3" /> +12.5%</StatusChip>}
                            badgeLabel="vs last month"
                            footer={<p className="text-sm font-medium text-slate-600">Sample footer data.</p>}
                        />
                        <MetricCard
                            title="Active Organizations"
                            value="1,492"
                            icon={Briefcase}
                            badge={<StatusChip variant="neutral">42 Lifetime</StatusChip>}
                            badgeLabel="onboarded"
                        />
                    </div>
                </section>

                {/* Section: StatusChip */}
                <section>
                    <h2 className="text-xl font-bold mb-6 border-b pb-2">3. StatusChip</h2>
                    <div className="flex flex-wrap gap-4 p-8 bg-white border rounded-2xl">
                        <StatusChip variant="neutral">Neutral State</StatusChip>
                        <StatusChip variant="success">Success State</StatusChip>
                        <StatusChip variant="info">Info State</StatusChip>
                        <StatusChip variant="warning">Warning State</StatusChip>
                        <StatusChip variant="destructive">Destructive State</StatusChip>
                        
                        {/* With Icons */}
                        <StatusChip variant="success" icon={TrendingUp}>+24%</StatusChip>
                        <StatusChip variant="info" icon={Zap}>New</StatusChip>
                        <StatusChip variant="destructive" icon={AlertCircle}>Failed</StatusChip>
                    </div>
                </section>

                {/* Section: CommandButton */}
                <section>
                    <h2 className="text-xl font-bold mb-6 border-b pb-2">4. CommandButton</h2>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                        <SignatureCard className="p-6">
                            <div className="space-y-1">
                                <CommandButton icon={Activity} shortcut="N">Log New Lead</CommandButton>
                                <CommandButton icon={Briefcase} shortcut="O">Onboard Organization</CommandButton>
                                <CommandButton icon={FileText} shortcut="Q">Generate Quotation</CommandButton>
                            </div>
                        </SignatureCard>
                    </div>
                </section>

                {/* Section: PipelineDistribution */}
                <section>
                    <h2 className="text-xl font-bold mb-6 border-b pb-2">5. PipelineDistribution</h2>
                    <div className="max-w-3xl">
                        <PipelineDistribution 
                            action={<button className="text-sm font-semibold text-indigo-600 hover:text-indigo-800 transition-colors flex items-center gap-1">View Details <ArrowRight className="w-4 h-4" /></button>}
                            segments={[
                                { label: 'New', value: 45, colorClass: 'text-slate-300', bgClass: 'bg-slate-300' },
                                { label: 'Quotations', value: 23, colorClass: 'text-indigo-400', bgClass: 'bg-indigo-400' },
                                { label: 'Won', value: 12, colorClass: 'text-emerald-500', bgClass: 'bg-emerald-500' },
                                { label: 'Lost', value: 4, colorClass: 'text-rose-400', bgClass: 'bg-rose-400' },
                            ]}
                        />
                    </div>
                </section>

            </div>
        </EnterpriseLayout>
    );
}
