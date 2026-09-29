import React from 'react';
import { cn } from '@/lib/utils';
import { Card } from '@/ovs/primitives/card';
import { Badge } from '@/ovs/primitives/badge';
import { SimpleSelect as Select } from '@/ovs/primitives/select';
import { Textarea } from '@/ovs/primitives/textarea';
import { Label } from '@/ovs/primitives/label';
import { ShieldCheck } from 'lucide-react';
import { Tooth, ToothOutline } from 'healthicons-react';
import { ovsTokens } from '@/ovs/tokens';

export type ToothStatus =
  | 'healthy'
  | 'decayed'
  | 'filled'
  | 'crown'
  | 'missing'
  | 'root-canal'
  | 'extraction-planned';

export interface ToothData {
  fdiNumber: number;
  status: ToothStatus;
  notes?: string;
  surfaces?: string[];
}

export interface OdontogramProps {
  teeth: ToothData[];
  onToothClick: (fdiNumber: number) => void;
  selectedTooth?: number;
  onStatusChange?: (fdiNumber: number, status: ToothStatus) => void;
  onNotesChange?: (fdiNumber: number, notes: string) => void;
  readOnly?: boolean;
  className?: string;
}

const DENTAL_COLORS = ovsTokens.colors.dental;

const STATUS_CONFIG: Record<
  ToothStatus,
  {
    label: string;
    badgeBg: string;
    badgeVariant: 'neutral' | 'success' | 'warning' | 'danger' | 'info';
    iconColor: string;
    bgColor: string;
    borderColor: string;
  }
> = {
  healthy: {
    label: 'Healthy',
    badgeBg: 'bg-white',
    badgeVariant: 'neutral',
    iconColor: '#94A3B8',
    bgColor: 'bg-white',
    borderColor: 'border-ovs-border-subtle',
  },
  decayed: {
    label: 'Caries / Decayed',
    badgeBg: 'bg-[#BE123C]',
    badgeVariant: 'danger',
    iconColor: '#BE123C',
    bgColor: 'bg-rose-50',
    borderColor: 'border-rose-300',
  },
  filled: {
    label: 'Restoration / Filled',
    badgeBg: 'bg-[#64748B]',
    badgeVariant: 'neutral',
    iconColor: '#64748B',
    bgColor: 'bg-slate-100',
    borderColor: 'border-slate-300',
  },
  crown: {
    label: 'Prosthetic Crown',
    badgeBg: 'bg-[#D97706]',
    badgeVariant: 'warning',
    iconColor: '#D97706',
    bgColor: 'bg-amber-50',
    borderColor: 'border-amber-300',
  },
  missing: {
    label: 'Missing Tooth',
    badgeBg: 'bg-[#E2E8F0]',
    badgeVariant: 'neutral',
    iconColor: '#CBD5E1',
    bgColor: 'bg-slate-50 opacity-60',
    borderColor: 'border-dashed border-slate-300',
  },
  'root-canal': {
    label: 'Root Canal (RCT)',
    badgeBg: 'bg-[#7C3AED]',
    badgeVariant: 'info',
    iconColor: '#7C3AED',
    bgColor: 'bg-purple-50',
    borderColor: 'border-purple-300',
  },
  'extraction-planned': {
    label: 'Planned Extraction',
    badgeBg: 'bg-[#94A3B8]',
    badgeVariant: 'warning',
    iconColor: '#475569',
    bgColor: 'bg-amber-50/60',
    borderColor: 'border-amber-400',
  },
};

// FDI Quadrant Array definitions (Clinical view: Patient Right on Left of screen)
const UPPER_ARCH_RIGHT = [18, 17, 16, 15, 14, 13, 12, 11]; // Q1
const UPPER_ARCH_LEFT = [21, 22, 23, 24, 25, 26, 27, 28];  // Q2
const LOWER_ARCH_RIGHT = [48, 47, 46, 45, 44, 43, 42, 41]; // Q4
const LOWER_ARCH_LEFT = [31, 32, 33, 34, 35, 36, 37, 38];  // Q3

export function Odontogram({
  teeth,
  onToothClick,
  selectedTooth,
  onStatusChange,
  onNotesChange,
  readOnly = false,
  className,
}: OdontogramProps) {
  const teethMap = new Map<number, ToothData>();
  teeth.forEach((t) => teethMap.set(t.fdiNumber, t));

  const getTooth = (fdi: number): ToothData => {
    return teethMap.get(fdi) || { fdiNumber: fdi, status: 'healthy' };
  };

  const selectedToothData = selectedTooth ? getTooth(selectedTooth) : null;

  const renderToothGlyph = (fdi: number) => {
    const data = getTooth(fdi);
    const isSelected = selectedTooth === fdi;
    const config = STATUS_CONFIG[data.status] || STATUS_CONFIG.healthy;

    return (
      <button
        key={fdi}
        type="button"
        onClick={() => onToothClick(fdi)}
        className={cn(
          'group flex flex-col items-center gap-1.5 p-1.5 rounded-xl transition-all focus:outline-none border',
          config.bgColor,
          config.borderColor,
          isSelected && 'ring-2 ring-ovs-accent bg-ovs-accent/15 border-ovs-accent shadow-ovs-surface'
        )}
        title={`Tooth #${fdi} - ${config.label}`}
      >
        {/* Uniform HealthIcons Tooth Icon Container */}
        <div className="relative w-9 h-9 flex items-center justify-center">
          {data.status === 'healthy' ? (
            <ToothOutline className="w-8 h-8 text-slate-400 group-hover:text-ovs-primary transition-colors" />
          ) : data.status === 'missing' ? (
            <div className="relative w-8 h-8 flex items-center justify-center opacity-40">
              <ToothOutline className="w-8 h-8 text-slate-400" />
              <div className="absolute inset-0 flex items-center justify-center">
                <div className="w-7 h-0.5 bg-slate-500 rotate-45 rounded-full" />
                <div className="absolute w-7 h-0.5 bg-slate-500 -rotate-45 rounded-full" />
              </div>
            </div>
          ) : (
            <Tooth className="w-8 h-8 transition-transform group-hover:scale-105" style={{ color: config.iconColor }} />
          )}

          {/* Procedural Accent Badges for RCT & Extraction */}
          {data.status === 'root-canal' && (
            <span className="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-purple-600 rounded-full border border-white ring-1 ring-purple-300" />
          )}
          {data.status === 'extraction-planned' && (
            <span className="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-amber-600 rounded-full border border-white ring-1 ring-amber-300" />
          )}
        </div>

        {/* FDI Number Label */}
        <span
          className={cn(
            'text-[10px] font-mono font-bold leading-none px-1.5 py-0.5 rounded',
            isSelected
              ? 'bg-ovs-accent text-white'
              : 'bg-white border border-ovs-border-subtle text-ovs-secondary group-hover:text-ovs-primary'
          )}
        >
          {fdi}
        </span>
      </button>
    );
  };

  return (
    <div className={cn('w-full bg-white border border-ovs-border-subtle rounded-2xl p-5 shadow-2xs space-y-6', className)}>
      {/* Header */}
      <div className="flex items-center justify-between border-b border-ovs-border-subtle pb-3">
        <div className="flex items-center gap-2">
          <ShieldCheck className="w-5 h-5 text-ovs-accent" />
          <h3 className="text-sm font-bold text-ovs-primary font-display">
            FDI/ISO Dental Odontogram (Adult 32 Uniform Glyphs)
          </h3>
        </div>
        <span className="text-[11px] font-mono text-ovs-secondary bg-ovs-background px-2.5 py-1 rounded-full border border-ovs-border-subtle">
          Clinical Quadrants 1 - 4
        </span>
      </div>

      {/* Odontogram Grid (Upper & Lower Arches) */}
      <div className="space-y-6 overflow-x-auto pb-2">
        {/* UPPER ARCH (Q1 Right | Q2 Left) */}
        <div className="space-y-2">
          <div className="flex items-center justify-between px-2 text-[10px] font-mono uppercase text-ovs-tertiary font-bold tracking-wider">
            <span>Upper Right (Q1)</span>
            <span className="text-ovs-accent">MAXILLARY ARCH</span>
            <span>Upper Left (Q2)</span>
          </div>
          <div className="flex items-center justify-center gap-1 sm:gap-2 p-3 bg-ovs-background/50 rounded-2xl border border-ovs-border-subtle/80">
            <div className="flex items-center gap-1">{UPPER_ARCH_RIGHT.map(renderToothGlyph)}</div>
            <div className="w-px h-14 bg-ovs-border-strong mx-1" />
            <div className="flex items-center gap-1">{UPPER_ARCH_LEFT.map(renderToothGlyph)}</div>
          </div>
        </div>

        {/* MIDLINE SEPARATOR */}
        <div className="relative flex items-center justify-center my-1">
          <div className="w-full border-t border-dashed border-ovs-border-subtle" />
          <span className="absolute bg-white px-3 text-[10px] font-mono text-ovs-tertiary uppercase tracking-widest font-bold">
            Occlusal Line
          </span>
        </div>

        {/* LOWER ARCH (Q4 Right | Q3 Left) */}
        <div className="space-y-2">
          <div className="flex items-center justify-center gap-1 sm:gap-2 p-3 bg-ovs-background/50 rounded-2xl border border-ovs-border-subtle/80">
            <div className="flex items-center gap-1">{LOWER_ARCH_RIGHT.map(renderToothGlyph)}</div>
            <div className="w-px h-14 bg-ovs-border-strong mx-1" />
            <div className="flex items-center gap-1">{LOWER_ARCH_LEFT.map(renderToothGlyph)}</div>
          </div>
          <div className="flex items-center justify-between px-2 text-[10px] font-mono uppercase text-ovs-tertiary font-bold tracking-wider">
            <span>Lower Right (Q4)</span>
            <span className="text-ovs-accent">MANDIBULAR ARCH</span>
            <span>Lower Left (Q3)</span>
          </div>
        </div>
      </div>

      {/* Legend */}
      <div className="pt-2 border-t border-ovs-border-subtle">
        <span className="text-[11px] font-bold text-ovs-primary block mb-2 font-mono uppercase tracking-wider">
          Diagnostic Status Legend:
        </span>
        <div className="flex flex-wrap items-center gap-2">
          {Object.entries(STATUS_CONFIG).map(([key, config]) => (
            <Badge
              key={key}
              variant={config.badgeVariant}
              className="text-[11px] py-1 px-2.5 flex items-center gap-1.5"
            >
              <span
                className={cn('w-3 h-3 rounded-full border border-black/20 flex-shrink-0', config.badgeBg)}
              />
              {config.label}
            </Badge>
          ))}
        </div>
      </div>

      {/* Selected Tooth Inspector Panel */}
      {selectedToothData && (
        <Card className="p-4 bg-ovs-background border border-ovs-accent/30 rounded-2xl space-y-3 ring-1 ring-ovs-accent/10">
          <div className="flex items-center justify-between border-b border-ovs-border-subtle/80 pb-2">
            <div className="flex items-center gap-2">
              <div className="w-7 h-7 rounded-lg bg-ovs-accent text-white font-mono font-bold text-xs flex items-center justify-center">
                #{selectedToothData.fdiNumber}
              </div>
              <div>
                <h4 className="text-xs font-bold text-ovs-primary leading-tight">
                  Tooth #{selectedToothData.fdiNumber} Details
                </h4>
                <span className="text-[10px] text-ovs-secondary">
                  FDI Dental Notation · Quadrant {Math.floor(selectedToothData.fdiNumber / 10)}
                </span>
              </div>
            </div>
            <Badge variant={STATUS_CONFIG[selectedToothData.status].badgeVariant}>
              {STATUS_CONFIG[selectedToothData.status].label}
            </Badge>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1">
            {/* Status Change Dropdown */}
            <div className="space-y-1">
              <Label className="text-xs">Clinical Status</Label>
              <Select
                value={selectedToothData.status}
                onValueChange={(val) => onStatusChange && onStatusChange(selectedToothData.fdiNumber, val as ToothStatus)}
                options={Object.entries(STATUS_CONFIG).map(([val, conf]) => ({
                  value: val,
                  label: conf.label,
                }))}
                disabled={readOnly}
              />
            </div>

            {/* Notes Textarea */}
            <div className="space-y-1">
              <Label className="text-xs">Tooth Clinical Notes</Label>
              <Textarea
                rows={2}
                placeholder="Enter diagnostic observations, cavity depth, or planned restoration..."
                value={selectedToothData.notes || ''}
                onChange={(e) => onNotesChange && onNotesChange(selectedToothData.fdiNumber, e.target.value)}
                disabled={readOnly}
                className="text-xs"
              />
            </div>
          </div>
        </Card>
      )}
    </div>
  );
}
