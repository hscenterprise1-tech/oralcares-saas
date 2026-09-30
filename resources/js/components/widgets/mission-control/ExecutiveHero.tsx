import React from 'react';
import { cn } from '@/lib/utils';
import { formatCurrencyCompact } from '@/ovs/utils/format';

export function ExecutiveHero() {
    return (
        <div className="bg-[#0F172A] rounded-2xl h-[100px] flex items-center justify-between px-8 shadow-[0_8px_32px_rgba(15,23,42,0.12)] relative overflow-hidden">
            {/* Ambient Background Glow */}
            <div className="absolute inset-0 bg-gradient-to-r from-emerald-500/10 via-transparent to-teal-500/10 opacity-50 pointer-events-none" />

            {/* Left: System Status & Greeting */}
            <div className="z-10 flex flex-col justify-center h-full gap-1">
                <div className="flex items-center gap-2">
                    <span className="flex h-2 w-2 relative">
                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    <span className="text-[11px] font-bold uppercase tracking-widest text-emerald-400">All Systems Operational</span>
                </div>
                <h2 className="text-xl font-bold text-white tracking-tight">
                    Good morning, OralCares.
                </h2>
            </div>

            {/* Center: Priority Alert */}
            <div className="hidden md:flex flex-col items-center justify-center h-full z-10 px-8 border-x border-[#1E293B]">
                <p className="text-[12px] font-semibold text-[#64748B] uppercase tracking-wider mb-1">Today's Priority</p>
                <div className="flex items-center gap-2">
                    <span className="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                    <span className="text-[14px] font-medium text-white">3 High-Value Demos & 2 Critical Renewals</span>
                </div>
            </div>

            {/* Right: MRR Target */}
            <div className="z-10 flex flex-col justify-center h-full w-[240px]">
                <div className="flex items-end justify-between mb-2">
                    <span className="text-[12px] font-semibold text-[#64748B] uppercase tracking-wider">MRR Target (Aug)</span>
                    <span className="text-[14px] font-bold text-white">{formatCurrencyCompact(1450000)}</span>
                </div>
                <div className="w-full bg-[#1E293B] rounded-full h-2 overflow-hidden">
                    <div className="bg-teal-500 h-full rounded-full" style={{ width: '82%' }} />
                </div>
                <div className="flex justify-between mt-1.5">
                    <span className="text-[11px] font-medium text-teal-400">On Track</span>
                    <span className="text-[11px] font-medium text-[#64748B]">82%</span>
                </div>
            </div>
        </div>
    );
}
