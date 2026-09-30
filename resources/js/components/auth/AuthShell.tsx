import React from 'react';
import { Head } from '@inertiajs/react';
import { OralCaresLogo } from '@/components/branding';
import {
  ShieldCheck, Lock, HardDrive, KeyRound, Server,
  Briefcase, TrendingUp, UserCheck, Layers, Headphones, CreditCard, Building2
} from 'lucide-react';

export interface AuthShellProps {
  pageTitle: string;
  badgeText?: string;
  formTitle: string;
  formSubtitle: string;
  heroHeadline?: string;
  heroSubheadline?: string;
  variant?: 'split' | 'centered' | 'full';
  children: React.ReactNode;
}

export function AuthShell({
  pageTitle,
  badgeText = 'Internal Company Workspace',
  formTitle,
  formSubtitle,
  heroHeadline = 'Build. Sell. Support. Scale.',
  heroSubheadline = 'One Enterprise Operating System.',
  variant = 'split',
  children,
}: AuthShellProps) {
  return (
    <div className="min-h-screen w-full flex bg-[#F8F9FB] font-sans selection:bg-[#6366F1] selection:text-white overflow-x-hidden">
      <Head title={`${pageTitle} — OralCares`} />

      {/* ───────────────────────────────────────────────────────────────────────── */}
      {/* 1. LEFT HERO SECTION — ENTERPRISE OPERATING SYSTEM (SHARED ACROSS ALL)   */}
      {/* ───────────────────────────────────────────────────────────────────────── */}
      {variant === 'split' && (
        <div className="hidden lg:flex lg:w-1/2 xl:w-7/12 bg-[#0A2540] text-white relative flex-col justify-between p-10 xl:p-14 overflow-hidden border-r border-[#1E3A8A]/30">
          {/* Network & Workflow Mesh Background */}
          <div className="absolute inset-0 bg-[radial-gradient(#38bdf8_1px,transparent_1px)] [background-size:32px_32px] opacity-15 pointer-events-none" />
          
          {/* Subtle Ambient Glows */}
          <div className="absolute -top-32 -left-32 w-[500px] h-[500px] bg-[#6366F1]/20 rounded-full blur-[120px] pointer-events-none" />
          <div className="absolute -bottom-32 -right-32 w-[500px] h-[500px] bg-[#00A8B5]/20 rounded-full blur-[120px] pointer-events-none" />

          {/* Minimal Enterprise Pipeline Connecting Lines SVG */}
          <svg className="absolute inset-0 w-full h-full opacity-10 pointer-events-none" xmlns="http://www.w3.org/2000/svg">
            <path d="M 120 180 Q 320 80 520 380 T 880 580" fill="none" stroke="#38BDF8" strokeWidth="1.5" strokeDasharray="8 8" />
            <path d="M 220 580 Q 420 280 720 180" fill="none" stroke="#818CF8" strokeWidth="1.5" strokeDasharray="6 6" />
            <circle cx="320" cy="80" r="3.5" fill="#38BDF8" />
            <circle cx="520" cy="380" r="5" fill="#10B981" />
            <circle cx="720" cy="180" r="4.5" fill="#818CF8" />
          </svg>

          {/* Brand Header */}
          <div className="relative z-10 flex items-center justify-between">
            <OralCaresLogo size="lg" subtitle="Enterprise Operating System" />
            <span className="text-[9px] text-emerald-400 font-mono font-bold tracking-widest uppercase block border border-emerald-500/30 px-2 py-0.5 rounded-full bg-emerald-500/10">{badgeText}</span>
          </div>

          {/* Hero Headline & Internal Company Story */}
          <div className="relative z-10 max-w-[560px] space-y-6 my-auto py-8">
            <div className="space-y-3">
              <h2 className="text-[40px] xl:text-[46px] font-black tracking-tight text-white leading-[1.12]">
                {heroHeadline}
              </h2>
              <p className="text-[18px] font-extrabold text-emerald-300 tracking-tight">
                {heroSubheadline}
              </p>
              <p className="text-[14.5px] text-slate-300 leading-relaxed font-normal pt-1">
                Manage every part of OralCares from one unified operating system.
              </p>
            </div>

            {/* 6 Internal Business Department Modules */}
            <div className="grid grid-cols-2 xl:grid-cols-3 gap-3 pt-3">
              <div className="p-3.5 bg-white/5 backdrop-blur-md rounded-xl border border-white/10 space-y-1.5 hover:bg-white/10 transition-all">
                <div className="flex items-center gap-2 text-cyan-400">
                  <Briefcase className="w-4 h-4" />
                  <span className="text-[12px] font-bold text-white">CRM</span>
                </div>
                <span className="text-[10px] text-slate-300 font-medium block">Lead Pipeline • Orgs • Contacts</span>
              </div>

              <div className="p-3.5 bg-white/5 backdrop-blur-md rounded-xl border border-white/10 space-y-1.5 hover:bg-white/10 transition-all">
                <div className="flex items-center gap-2 text-emerald-400">
                  <TrendingUp className="w-4 h-4" />
                  <span className="text-[12px] font-bold text-white">Sales</span>
                </div>
                <span className="text-[10px] text-slate-300 font-medium block">Demo • Quotation • Deals</span>
              </div>

              <div className="p-3.5 bg-white/5 backdrop-blur-md rounded-xl border border-white/10 space-y-1.5 hover:bg-white/10 transition-all">
                <div className="flex items-center gap-2 text-amber-400">
                  <UserCheck className="w-4 h-4" />
                  <span className="text-[12px] font-bold text-white">Customer Success</span>
                </div>
                <span className="text-[10px] text-slate-300 font-medium block">Trials • Onboarding • Renewals</span>
              </div>

              <div className="p-3.5 bg-white/5 backdrop-blur-md rounded-xl border border-white/10 space-y-1.5 hover:bg-white/10 transition-all">
                <div className="flex items-center gap-2 text-purple-400">
                  <Layers className="w-4 h-4" />
                  <span className="text-[12px] font-bold text-white">Operations</span>
                </div>
                <span className="text-[10px] text-slate-300 font-medium block">Projects • Tasks • Workflows</span>
              </div>

              <div className="p-3.5 bg-white/5 backdrop-blur-md rounded-xl border border-white/10 space-y-1.5 hover:bg-white/10 transition-all">
                <div className="flex items-center gap-2 text-indigo-400">
                  <Headphones className="w-4 h-4" />
                  <span className="text-[12px] font-bold text-white">Support</span>
                </div>
                <span className="text-[10px] text-slate-300 font-medium block">Tickets • Knowledge Base • Help</span>
              </div>

              <div className="p-3.5 bg-white/5 backdrop-blur-md rounded-xl border border-white/10 space-y-1.5 hover:bg-white/10 transition-all">
                <div className="flex items-center gap-2 text-teal-400">
                  <CreditCard className="w-4 h-4" />
                  <span className="text-[12px] font-bold text-white">Finance</span>
                </div>
                <span className="text-[10px] text-slate-300 font-medium block">Invoices • Subscriptions • Collections</span>
              </div>
            </div>
          </div>

          {/* Enterprise Security & Trust Row */}
          <div className="relative z-10 flex flex-wrap items-center justify-between text-[11px] text-slate-400 font-medium border-t border-white/10 pt-5 gap-2">
            <span className="flex items-center gap-1.5"><Lock className="w-3.5 h-3.5 text-emerald-400" /> Enterprise Security</span>
            <span className="flex items-center gap-1.5"><HardDrive className="w-3.5 h-3.5 text-cyan-400" /> Daily Cloud Backup</span>
            <span className="flex items-center gap-1.5"><KeyRound className="w-3.5 h-3.5 text-indigo-400" /> Role Based Permissions</span>
            <span className="flex items-center gap-1.5"><Server className="w-3.5 h-3.5 text-emerald-400" /> 99.9% Uptime</span>
          </div>
        </div>
      )}

      {/* ───────────────────────────────────────────────────────────────────────── */}
      {/* 2. RIGHT FORM / CONTENT PANEL                                            */}
      {/* ───────────────────────────────────────────────────────────────────────── */}
      <div className={`w-full ${variant === 'split' ? 'lg:w-1/2 xl:w-5/12' : 'max-w-[560px] mx-auto'} flex flex-col justify-between p-6 sm:p-12 min-h-screen bg-[#F8F9FB]`}>
        {/* Top Mobile Header */}
        <div className="lg:hidden flex items-center justify-between pb-6 mb-4 border-b border-[#E4E7EE]">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-[#0A2540] flex items-center justify-center text-white font-bold text-sm">
              OC
            </div>
            <div>
              <span className="text-[14px] font-bold text-[#0A2540] block">OralCares</span>
              <span className="text-[10px] text-[#64748B]">Internal Operating System</span>
            </div>
          </div>
          <span className="text-[10px] font-bold bg-emerald-100 text-emerald-800 px-2.5 py-1 rounded-md">
            Company Portal
          </span>
        </div>

        {/* Main Form Container (~480px width) */}
        <div className="my-auto max-w-[480px] w-full mx-auto space-y-6">
          {/* Header Title */}
          <div className="space-y-1.5 text-left">
            <h2 className="text-[32px] font-black text-[#0F172A] tracking-tight">{formTitle}</h2>
            <p className="text-[14px] text-[#64748B]">{formSubtitle}</p>
          </div>

          {/* Render Page Form Content */}
          {children}

          {/* Secure Transmission Pill */}
          <div className="p-3 bg-[#F8F9FB] border border-[#E4E7EE] rounded-xl flex items-center justify-between text-[11px] text-[#64748B]">
            <span className="flex items-center gap-1.5 font-medium">
              <Lock className="w-3.5 h-3.5 text-emerald-600" />
              256-bit Encrypted Session
            </span>
            <span className="font-mono font-bold text-[#0A2540]">Enterprise Cloud</span>
          </div>
        </div>

        {/* Footer Legal & Clickable Domain */}
        <div className="pt-8 text-center text-[12px] text-[#94A3B8] font-medium">
          <p>
            &copy; 2026{' '}
            <a
              href="https://oralcares.in"
              target="_blank"
              rel="noopener noreferrer"
              className="text-[#0A2540] font-bold hover:underline"
            >
              OralCares.in
            </a>
          </p>
        </div>
      </div>
    </div>
  );
}
