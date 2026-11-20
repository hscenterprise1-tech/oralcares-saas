import { useEffect, useState, useRef } from 'react';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { requestJson } from '@/clinic/http';

interface AddressData {
  pincode: string;
  locality: string;
  city: string;
  state: string;
  address: string;
}

interface AddressLookupSectionProps {
  data: AddressData;
  onChange: (patch: Partial<AddressData>) => void;
  errors?: Partial<Record<keyof AddressData, string>>;
  disabled?: boolean;
}

interface GeoPincodeResponse {
  data: {
    pincode: string;
    state?: { name: string };
    city?: { name: string };
    district?: { name: string };
    areas?: Array<{ id: number; name: string }>;
  };
}

export function AddressLookupSection({
  data,
  onChange,
  errors = {},
  disabled = false,
}: AddressLookupSectionProps) {
  const [loading, setLoading] = useState(false);
  const [localityOptions, setLocalityOptions] = useState<string[]>([]);
  const [lookupStatus, setLookupStatus] = useState<'idle' | 'found' | 'not_found'>('idle');
  const lastFetchedPin = useRef<string>('');

  useEffect(() => {
    const cleanPin = data.pincode.replace(/\D/g, '');
    if (cleanPin.length !== 6) {
      setLookupStatus('idle');
      setLocalityOptions([]);
      return;
    }

    if (cleanPin === lastFetchedPin.current) {
      return;
    }

    const abortController = new AbortController();
    lastFetchedPin.current = cleanPin;
    setLoading(true);

    const resolvePin = async () => {
      try {
        // 1. Try local GeoLocation API
        const localRes = await requestJson<GeoPincodeResponse>(
          'GET',
          `/api/v1/geo/pincodes/${cleanPin}`,
          { signal: abortController.signal }
        ).catch(() => null);

        if (localRes?.data) {
          const stateName = localRes.data.state?.name || '';
          const cityName = localRes.data.city?.name || localRes.data.district?.name || '';
          const areas = (localRes.data.areas || []).map((a) => a.name).filter(Boolean);

          setLocalityOptions(areas);
          setLookupStatus('found');

          onChange({
            state: stateName || data.state,
            city: cityName || data.city,
            locality: data.locality || (areas.length > 0 ? areas[0] : ''),
          });
          setLoading(false);
          return;
        }

        // 2. Fallback to India Post Public API
        const response = await fetch(`https://api.postalpincode.in/pincode/${cleanPin}`, {
          signal: abortController.signal,
        });
        if (!response.ok) {
          throw new Error('Pincode service unreachable');
        }

        const postalData = await response.json();
        if (
          Array.isArray(postalData) &&
          postalData[0]?.Status === 'Success' &&
          Array.isArray(postalData[0]?.PostOffice) &&
          postalData[0].PostOffice.length > 0
        ) {
          const postOffices = postalData[0].PostOffice;
          const first = postOffices[0];
          const stateName = first.State || '';
          const cityName = first.District || first.Block || '';
          const areas: string[] = Array.from(new Set(postOffices.map((p: { Name: string }) => p.Name)));

          setLocalityOptions(areas);
          setLookupStatus('found');

          onChange({
            state: stateName || data.state,
            city: cityName || data.city,
            locality: data.locality || (areas.length > 0 ? areas[0] : ''),
          });
        } else {
          setLookupStatus('not_found');
          setLocalityOptions([]);
        }
      } catch (err: unknown) {
        if ((err as Error)?.name !== 'AbortError') {
          setLookupStatus('not_found');
          setLocalityOptions([]);
        }
      } finally {
        setLoading(false);
      }
    };

    resolvePin();

    return () => {
      abortController.abort();
    };
  }, [data.pincode, data.city, data.state, data.locality, onChange]);

  const inputClass = (errorKey?: keyof AddressData) =>
    cn(
      'h-[50px] w-full rounded-xl border bg-white px-3.5 text-[13.5px] text-slate-800 transition-colors',
      'placeholder:text-[#64748B] focus:bg-white focus:outline-none focus:ring-2',
      errors[errorKey as keyof AddressData]
        ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/15'
        : 'border-[#DCE6F3] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:ring-[#1769AA]/20'
    );

  return (
    <div className="space-y-3">
      {/* Street Address / House / Landmark - Full Width at Top */}
      <div>
        <label className="block text-[12px] font-semibold text-slate-700 mb-1">
          Full Street Address / House No / Landmark <span className="text-slate-400 font-normal">(Optional)</span>
        </label>
        <input
          type="text"
          disabled={disabled}
          placeholder="e.g. Flat 402, Sunshine Apartments, Near Metro Station"
          value={data.address}
          onChange={(e) => onChange({ address: e.target.value })}
          className={inputClass('address')}
        />
        {errors.address && <p className="mt-1 text-[11px] font-medium text-rose-600">{errors.address}</p>}
      </div>

      {/* Row 1: PIN Code | Area / Locality */}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {/* PIN Code */}
        <div>
          <div className="flex items-center justify-between mb-1">
            <label className="block text-[12px] font-semibold text-slate-700">
              PIN Code <span className="text-rose-500">*</span> <span className="text-slate-400 font-normal text-[11px]">(6 digits)</span>
            </label>
            {lookupStatus === 'found' && (
              <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10.5px] font-semibold text-emerald-700 border border-emerald-200">
                <Check className="h-3 w-3" /> Auto-populated
              </span>
            )}
          </div>
          <div className="relative">
            <input
              type="text"
              maxLength={6}
              disabled={disabled}
              placeholder="e.g. 560001, 110001"
              value={data.pincode}
              onChange={(e) => {
                const val = e.target.value.replace(/\D/g, '').slice(0, 6);
                onChange({ pincode: val });
              }}
              className={inputClass('pincode')}
            />
            {loading && (
              <span className="absolute right-3.5 top-4 flex items-center text-xs text-blue-600">
                <span className="h-4 w-4 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" />
              </span>
            )}
          </div>
          {errors.pincode && <p className="mt-1 text-[11px] font-medium text-rose-600">{errors.pincode}</p>}
        </div>

        {/* Locality with multi-locality selector */}
        <div>
          <div className="flex items-center justify-between mb-1">
            <label className="block text-[12px] font-semibold text-slate-700">Area / Locality</label>
            {localityOptions.length > 1 && (
              <span className="text-[11px] font-medium text-slate-500">
                {localityOptions.length} areas found
              </span>
            )}
          </div>

          {localityOptions.length > 1 ? (
            <div className="space-y-1.5">
              <select
                disabled={disabled}
                value={localityOptions.includes(data.locality) ? data.locality : ''}
                onChange={(e) => onChange({ locality: e.target.value })}
                className={cn(inputClass('locality'), 'bg-white cursor-pointer font-medium')}
              >
                <option value="" disabled>Select Area / Post Office...</option>
                {localityOptions.map((area) => (
                  <option key={area} value={area}>
                    {area}
                  </option>
                ))}
              </select>
              <input
                type="text"
                disabled={disabled}
                placeholder="Or custom locality name..."
                value={data.locality}
                onChange={(e) => onChange({ locality: e.target.value })}
                className={inputClass('locality')}
              />
            </div>
          ) : (
            <input
              type="text"
              disabled={disabled}
              placeholder="e.g. Indiranagar, Connaught Place"
              value={data.locality}
              onChange={(e) => onChange({ locality: e.target.value })}
              className={inputClass('locality')}
            />
          )}
          {errors.locality && <p className="mt-1 text-[11px] font-medium text-rose-600">{errors.locality}</p>}
        </div>
      </div>

      {/* Row 2: City | State */}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {/* City */}
        <div>
          <label className="block text-[12px] font-semibold text-slate-700 mb-1">City / District</label>
          <input
            type="text"
            disabled={disabled}
            placeholder="City"
            value={data.city}
            onChange={(e) => onChange({ city: e.target.value })}
            className={inputClass('city')}
          />
          {errors.city && <p className="mt-1 text-[11px] font-medium text-rose-600">{errors.city}</p>}
        </div>

        {/* State */}
        <div>
          <label className="block text-[12px] font-semibold text-slate-700 mb-1">State</label>
          <input
            type="text"
            disabled={disabled}
            placeholder="State"
            value={data.state}
            onChange={(e) => onChange({ state: e.target.value })}
            className={inputClass('state')}
          />
          {errors.state && <p className="mt-1 text-[11px] font-medium text-rose-600">{errors.state}</p>}
        </div>
      </div>
    </div>
  );
}
