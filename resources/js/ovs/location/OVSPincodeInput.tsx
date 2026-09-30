import React, { useState, useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';
import { MapPin, Loader2, CheckCircle2, AlertCircle, X } from 'lucide-react';
import { useLocationResolver, GeoPincodeResolution } from './useLocationResolver';

export interface OVSPincodeInputProps {
  value?: string;
  onChange?: (pincode: string) => void;
  onResolved?: (resolution: GeoPincodeResolution) => void;
  onClear?: () => void;
  disabled?: boolean;
  error?: string | boolean;
  helperText?: string;
  label?: string;
  required?: boolean;
  autoResolve?: boolean;
  className?: string;
  density?: 'compact' | 'default' | 'comfortable';
  id?: string;
}

export const OVSPincodeInput: React.FC<OVSPincodeInputProps> = ({
  value = '',
  onChange,
  onResolved,
  onClear,
  disabled = false,
  error,
  helperText,
  label = 'PIN Code',
  required = false,
  autoResolve = true,
  className,
  density = 'default',
  id = 'ovs-pincode-input',
}) => {
  const [internalValue, setInternalValue] = useState(value);
  const [resolvedSummary, setResolvedSummary] = useState<{
    stateName: string;
    cityName: string;
    areaCount: number;
  } | null>(null);
  const [lookupStatus, setLookupStatus] = useState<'idle' | 'loading' | 'success' | 'not_found' | 'error'>('idle');
  const [statusMessage, setStatusMessage] = useState<string | null>(null);

  const { resolvePincode, isResolving } = useLocationResolver();
  const lastResolvedPinRef = useRef<string>('');

  useEffect(() => {
    setInternalValue(value);
    if (!value) {
      setResolvedSummary(null);
      setLookupStatus('idle');
      setStatusMessage(null);
      lastResolvedPinRef.current = '';
    }
  }, [value]);

  const handleLookup = async (pinToLookup: string) => {
    const clean = pinToLookup.replace(/\D/g, '').trim();
    if (clean.length !== 6) {
      setLookupStatus('idle');
      setResolvedSummary(null);
      setStatusMessage(null);
      return;
    }

    if (clean === lastResolvedPinRef.current && lookupStatus === 'success') {
      return;
    }

    setLookupStatus('loading');
    setStatusMessage('Resolving postal jurisdiction...');

    const result = await resolvePincode(clean);
    if (result) {
      lastResolvedPinRef.current = clean;
      setLookupStatus('success');
      setResolvedSummary({
        stateName: result.state.name,
        cityName: result.city.name,
        areaCount: result.areas?.length || 0,
      });
      setStatusMessage(null);
      if (onResolved) {
        onResolved(result);
      }
    } else {
      setLookupStatus('not_found');
      setResolvedSummary(null);
      setStatusMessage(`PIN code ${clean} was not found.`);
    }
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const raw = e.target.value;
    const digitsOnly = raw.replace(/\D/g, '').slice(0, 6);
    setInternalValue(digitsOnly);

    if (onChange) {
      onChange(digitsOnly);
    }

    if (digitsOnly.length === 6 && autoResolve) {
      handleLookup(digitsOnly);
    } else if (digitsOnly.length < 6) {
      setLookupStatus('idle');
      setResolvedSummary(null);
      setStatusMessage(null);
    }
  };

  const handleClear = () => {
    setInternalValue('');
    setResolvedSummary(null);
    setLookupStatus('idle');
    setStatusMessage(null);
    lastResolvedPinRef.current = '';
    if (onChange) onChange('');
    if (onClear) onClear();
  };

  const heightClasses = {
    compact: 'h-8 text-xs',
    default: 'h-9 text-xs',
    comfortable: 'h-10 text-sm',
  }[density];

  const hasError = Boolean(error) || lookupStatus === 'not_found' || lookupStatus === 'error';
  const displayError = typeof error === 'string' ? error : statusMessage;

  return (
    <div className={cn('w-full space-y-1.5 font-sans', className)}>
      {label && (
        <div className="flex items-center justify-between">
          <label htmlFor={id} className="text-[11px] font-semibold text-slate-700 tracking-tight flex items-center gap-1">
            {label}
            {required && <span className="text-red-500 font-bold">*</span>}
          </label>
          {lookupStatus === 'success' && resolvedSummary && (
            <span className="inline-flex items-center gap-1 text-[10.5px] font-medium text-teal-700 bg-teal-50 px-2 py-0.5 rounded-full border border-teal-200 animate-fade-in">
              <CheckCircle2 className="w-3 h-3 text-teal-600" />
              <span>
                {resolvedSummary.stateName} · {resolvedSummary.cityName} ({resolvedSummary.areaCount} {resolvedSummary.areaCount === 1 ? 'area' : 'areas'})
              </span>
            </span>
          )}
        </div>
      )}

      <div className="relative flex items-center w-full">
        <div className="absolute left-3 flex items-center pointer-events-none text-slate-400">
          {lookupStatus === 'loading' || isResolving ? (
            <Loader2 className="w-3.5 h-3.5 animate-spin text-teal-600" />
          ) : (
            <MapPin className={cn('w-3.5 h-3.5 transition-colors', lookupStatus === 'success' ? 'text-teal-600' : 'text-slate-400')} />
          )}
        </div>

        <input
          id={id}
          type="text"
          inputMode="numeric"
          pattern="[0-9]*"
          maxLength={6}
          disabled={disabled}
          value={internalValue}
          onChange={handleInputChange}
          onBlur={() => {
            if (internalValue.length === 6) {
              handleLookup(internalValue);
            }
          }}
          placeholder="e.g. 380001"
          aria-invalid={hasError}
          aria-describedby={`${id}-status`}
          className={cn(
            'flex w-full rounded-lg border bg-white pl-9 pr-8 font-mono text-slate-900 shadow-2xs transition-all duration-150',
            'placeholder:text-slate-400 placeholder:font-sans font-normal tracking-wider',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/20 focus-visible:border-teal-500',
            'disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-slate-50',
            heightClasses,
            hasError
              ? 'border-red-400 text-red-900 focus-visible:ring-red-500/20 focus-visible:border-red-500'
              : lookupStatus === 'success'
              ? 'border-teal-400/80 bg-teal-50/20 text-slate-900'
              : 'border-slate-200 hover:border-slate-300'
          )}
        />

        {internalValue && !disabled && (
          <button
            type="button"
            onClick={handleClear}
            className="absolute right-2.5 p-1 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-all cursor-pointer"
            title="Clear PIN code"
            aria-label="Clear PIN code"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        )}
      </div>

      {hasError && displayError && (
        <p id={`${id}-status`} className="text-[11px] font-medium text-red-600 flex items-center gap-1 mt-1">
          <AlertCircle className="w-3 h-3 flex-shrink-0" />
          <span>{displayError}</span>
        </p>
      )}

      {!hasError && helperText && (
        <p id={`${id}-status`} className="text-[10.5px] text-slate-500 font-normal">
          {helperText}
        </p>
      )}
    </div>
  );
};
