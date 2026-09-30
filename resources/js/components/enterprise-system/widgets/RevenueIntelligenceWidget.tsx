import React from 'react';
import { WidgetProps } from '../manifests/WidgetManifest';
import { EnterpriseWidget } from '../EnterpriseWidget';
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, ResponsiveContainer, Tooltip } from 'recharts';
import { formatCurrency, formatCurrencyCompact } from '@/ovs/utils/format';
import { ArrowUpRight } from 'lucide-react';
import { router } from '@inertiajs/react';

export function RevenueIntelligenceWidget({ manifest, isRefreshing }: WidgetProps) {
    const handleDrillDown = () => router.visit(manifest.drillDownRoute);

    const data = [
        { name: 'Jan', value: 850000 }, { name: 'Feb', value: 920000 }, 
        { name: 'Mar', value: 980000 }, { name: 'Apr', value: 1100000 }, 
        { name: 'May', value: 1150000 }, { name: 'Jun', value: 1245000 }
    ];

    const CustomTooltip = ({ active, payload, label }: any) => {
        if (active && payload && payload.length) {
            return (
                <div className="bg-[#0F172A] text-white shadow-xl rounded-[10px] p-3 min-w-[140px] border border-[#334155]">
                    <p className="text-[12px] font-medium text-[#94A3B8] mb-1">{label} 2026</p>
                    <p className="text-[16px] font-bold">
                        {formatCurrency(payload[0].value)}
                    </p>
                </div>
            );
        }
        return null;
    };

    return (
        <EnterpriseWidget 
            title={manifest.title}
            description={manifest.description}
            isRefreshing={isRefreshing}
            onExpand={handleDrillDown}
            className="h-[360px]" // Fixed height from blueprint
        >
            <div className="flex flex-col h-full -m-7 pt-4">
                
                {/* Numbers First Section */}
                <div className="flex justify-between px-7 mb-4">
                    <div>
                        <p className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider mb-1">Total MRR</p>
                        <div className="flex items-end gap-2">
                            <p className="text-[26px] font-bold text-[#0F172A] leading-none">{formatCurrencyCompact(1245000)}</p>
                            <span className="flex items-center text-[12px] font-semibold text-emerald-600 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-100">
                                <ArrowUpRight className="w-3 h-3 mr-0.5" /> 24%
                            </span>
                        </div>
                    </div>
                    <div>
                        <p className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider mb-1">Collected (MTD)</p>
                        <p className="text-[20px] font-bold text-[#0F172A] leading-none">{formatCurrencyCompact(980000)}</p>
                    </div>
                    <div>
                        <p className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider mb-1">Outstanding</p>
                        <p className="text-[20px] font-bold text-amber-600 leading-none">{formatCurrencyCompact(265000)}</p>
                    </div>
                    <div className="hidden xl:block">
                        <p className="text-[11px] font-bold text-[#64748B] uppercase tracking-wider mb-1">Renewal Forecast</p>
                        <p className="text-[20px] font-bold text-[#0F172A] leading-none">{formatCurrencyCompact(450000)}</p>
                    </div>
                </div>

                {/* Supporting Chart */}
                <div className="flex-1 w-full mt-2 relative overflow-hidden">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={data} margin={{ top: 0, right: 0, left: -20, bottom: -10 }}>
                            <defs>
                                <linearGradient id="colorRevenue" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%" stopColor="#0D9488" stopOpacity={0.25}/>
                                    <stop offset="95%" stopColor="#0D9488" stopOpacity={0}/>
                                </linearGradient>
                            </defs>
                            <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#F1F5F9" />
                            <XAxis 
                                dataKey="name" 
                                axisLine={false} 
                                tickLine={false} 
                                tick={{fill: '#94A3B8', fontSize: 11, fontWeight: 500}} 
                                dy={10} 
                            />
                            <YAxis 
                                axisLine={false} 
                                tickLine={false} 
                                tick={{fill: '#94A3B8', fontSize: 11, fontWeight: 500}} 
                                tickFormatter={(val) => formatCurrencyCompact(val)} 
                            />
                            <Tooltip content={<CustomTooltip />} cursor={{ stroke: '#CBD5E1', strokeWidth: 1, strokeDasharray: '4 4' }} />
                            <Area 
                                type="monotone" 
                                dataKey="value" 
                                stroke="#0D9488" 
                                strokeWidth={3} 
                                fillOpacity={1} 
                                fill="url(#colorRevenue)" 
                                activeDot={{ r: 6, fill: '#0D9488', stroke: '#fff', strokeWidth: 2, shadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }}
                            />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            </div>
        </EnterpriseWidget>
    );
}
