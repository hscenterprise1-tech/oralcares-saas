import React from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeft, MoreVertical, Building2, MapPin, User, Calendar, ExternalLink } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button, IconButton } from '@/ovs/primitives/button';

export interface BreadcrumbItem {
  label: string;
  href?: string;
}

export interface OVSEntityDetailHeaderProps {
  title: string;
  subtitle?: string;
  metadata?: string | React.ReactNode;
  breadcrumbs?: BreadcrumbItem[];
  backHref?: string;
  backLabel?: string;
  statusCluster?: React.ReactNode;
  primaryAction?: React.ReactNode;
  secondaryActions?: React.ReactNode;
  overflowMenu?: React.ReactNode;
  financialHighlight?: {
    primaryValue: string;
    primaryLabel: string;
    secondaryValue?: string;
    secondaryLabel?: string;
  };
  className?: string;
}

export const OVSEntityDetailHeader: React.FC<OVSEntityDetailHeaderProps> = ({
  title,
  subtitle,
  metadata,
  breadcrumbs,
  backHref,
  backLabel = 'Back',
  statusCluster,
  primaryAction,
  secondaryActions,
  overflowMenu,
  financialHighlight,
  className,
}) => {
  return (
    <header className={cn('bg-white border-b border-slate-200 px-6 py-5 font-sans text-slate-900', className)}>
      {/* ── BREADCRUMB / BACK LINK ────────────────────────────────────────── */}
      {(backHref || (breadcrumbs && breadcrumbs.length > 0)) && (
        <div className="flex items-center gap-2 mb-3 text-xs text-slate-500 font-medium select-none">
          {backHref ? (
            <Link
              href={backHref}
              className="inline-flex items-center gap-1 text-slate-500 hover:text-slate-900 transition-colors font-medium"
            >
              <ChevronLeft className="w-4 h-4" />
              <span>{backLabel}</span>
            </Link>
          ) : (
            breadcrumbs?.map((b, idx) => (
              <React.Fragment key={idx}>
                {idx > 0 && <span className="text-slate-300">/</span>}
                {b.href ? (
                  <Link href={b.href} className="hover:text-slate-900 transition-colors">
                    {b.label}
                  </Link>
                ) : (
                  <span className="text-slate-800 font-semibold">{b.label}</span>
                )}
              </React.Fragment>
            ))
          )}
        </div>
      )}

      {/* ── MAIN HEADER BODY ──────────────────────────────────────────────── */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-5">
        {/* Left: Identity, Subtitle, Metadata & Status Cluster */}
        <div className="space-y-2 max-w-3xl">
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight leading-tight">
              {title}
            </h1>
            {statusCluster && (
              <div className="flex items-center gap-2 shrink-0">
                {statusCluster}
              </div>
            )}
          </div>

          {(subtitle || metadata) && (
            <div className="flex items-center gap-2 text-xs text-slate-600 font-normal flex-wrap">
              {subtitle && <span className="font-semibold text-slate-800">{subtitle}</span>}
              {subtitle && metadata && <span className="text-slate-300">·</span>}
              {metadata && typeof metadata === 'string' ? (
                <span className="text-slate-500">{metadata}</span>
              ) : (
                metadata
              )}
            </div>
          )}
        </div>

        {/* Right: Financial Runrate Badge + Action Hub */}
        <div className="flex items-center gap-4 shrink-0 flex-wrap sm:flex-nowrap">
          {financialHighlight && (
            <div className="p-2.5 sm:px-3.5 sm:py-2 bg-slate-50 border border-slate-200/80 rounded-xl text-right shrink-0">
              <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">
                {financialHighlight.primaryLabel}
              </span>
              <div className="text-base sm:text-lg font-bold text-slate-900 tabular-nums">
                {financialHighlight.primaryValue}
              </div>
              {financialHighlight.secondaryValue && (
                <span className="text-[10px] text-slate-500 font-medium block">
                  {financialHighlight.secondaryValue} {financialHighlight.secondaryLabel}
                </span>
              )}
            </div>
          )}

          <div className="flex items-center gap-2 flex-wrap">
            {secondaryActions}
            {primaryAction}
            {overflowMenu}
          </div>
        </div>
      </div>
    </header>
  );
};
