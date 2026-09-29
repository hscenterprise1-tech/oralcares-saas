import React, { useState, useEffect, useId, useRef } from 'react';
import { cn } from '@/lib/utils';
import {
  MapPin,
  ShieldCheck,
  AlertTriangle,
  Building,
  CheckCircle2,
  Navigation,
} from 'lucide-react';
import {
  useLocationResolver,
  AddressGroupValue,
  GeoPincodeResolution,
  GeoStateOption,
  GeoCityOption,
  GeoDistrictOption,
  GeoAreaOption,
} from './useLocationResolver';
import { OVSPincodeInput } from './OVSPincodeInput';
import { OVSLocationSelect, LocationOption } from './OVSLocationSelect';

export interface OVSAddressFieldGroupProps {
  value?: AddressGroupValue;
  onChange?: (value: AddressGroupValue) => void;
  disabled?: boolean;
  density?: 'compact' | 'default' | 'comfortable';
  mode?: 'full' | 'drawer' | 'dialog';
  showAddressLines?: boolean;
  showLandmark?: boolean;
  showDistrict?: boolean;
  showCountry?: boolean;
  requiredFields?: {
    address_line_1?: boolean;
    pincode?: boolean;
    state?: boolean;
    city?: boolean;
    area?: boolean;
  };
  errors?: Record<string, string>;
  className?: string;
}

export const OVSAddressFieldGroup: React.FC<OVSAddressFieldGroupProps> = ({
  value = {},
  onChange,
  disabled = false,
  density = 'default',
  mode = 'full',
  showAddressLines = true,
  showLandmark = true,
  showDistrict = true,
  showCountry = false,
  requiredFields = {},
  errors = {},
  className,
}) => {
  const {
    fetchStates,
    fetchDistricts,
    fetchCities,
    fetchAreas,
    validateHierarchy,
  } = useLocationResolver();

  // State Options
  const [states, setStates] = useState<GeoStateOption[]>([]);
  const [loadingStates, setLoadingStates] = useState(false);

  // District Options
  const [districts, setDistricts] = useState<GeoDistrictOption[]>([]);
  const [loadingDistricts, setLoadingDistricts] = useState(false);

  // City Options
  const [cities, setCities] = useState<GeoCityOption[]>([]);
  const [loadingCities, setLoadingCities] = useState(false);

  // Area Options
  const [areas, setAreas] = useState<GeoAreaOption[]>([]);
  const [loadingAreas, setLoadingAreas] = useState(false);

  // Validation State
  const [validationMismatch, setValidationMismatch] = useState<string | null>(null);
  const [isValidated, setIsValidated] = useState(false);

  const validateTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Load States once on mount
  useEffect(() => {
    let isMounted = true;
    setLoadingStates(true);
    fetchStates()
      .then((data) => {
        if (isMounted) {
          setStates(data);
          setLoadingStates(false);
        }
      })
      .catch(() => {
        if (isMounted) setLoadingStates(false);
      });
    return () => {
      isMounted = false;
    };
  }, [fetchStates]);

  // Load Districts when state changes
  useEffect(() => {
    if (!value.state_id) {
      setDistricts([]);
      return;
    }
    let isMounted = true;
    setLoadingDistricts(true);
    fetchDistricts(value.state_id)
      .then((data) => {
        if (isMounted) {
          setDistricts(data);
          setLoadingDistricts(false);
        }
      })
      .catch(() => {
        if (isMounted) setLoadingDistricts(false);
      });
    return () => {
      isMounted = false;
    };
  }, [value.state_id, fetchDistricts]);

  // Load Cities when state or district changes
  useEffect(() => {
    if (!value.state_id) {
      setCities([]);
      return;
    }
    let isMounted = true;
    setLoadingCities(true);
    fetchCities(value.state_id, value.district_id)
      .then((data) => {
        if (isMounted) {
          setCities(data);
          setLoadingCities(false);
        }
      })
      .catch(() => {
        if (isMounted) setLoadingCities(false);
      });
    return () => {
      isMounted = false;
    };
  }, [value.state_id, value.district_id, fetchCities]);

  // Load Areas when city changes
  useEffect(() => {
    if (!value.city_id) {
      setAreas([]);
      return;
    }
    let isMounted = true;
    setLoadingAreas(true);
    fetchAreas(value.city_id)
      .then((data) => {
        if (isMounted) {
          setAreas(data);
          setLoadingAreas(false);
        }
      })
      .catch(() => {
        if (isMounted) setLoadingAreas(false);
      });
    return () => {
      isMounted = false;
    };
  }, [value.city_id, fetchAreas]);

  // Relational Hierarchy Validation Trigger
  useEffect(() => {
    if (validateTimerRef.current) {
      clearTimeout(validateTimerRef.current);
    }

    if (!value.city_id && !value.state_id && !value.pincode) {
      setValidationMismatch(null);
      setIsValidated(false);
      return;
    }

    validateTimerRef.current = setTimeout(async () => {
      const res = await validateHierarchy({
        state_id: value.state_id,
        city_id: value.city_id,
        district_id: value.district_id,
        area_id: value.area_id,
        pincode: value.pincode,
      });

      if (!res.valid) {
        setValidationMismatch(res.errors?.[0] || 'Location mismatch detected.');
        setIsValidated(false);
      } else {
        setValidationMismatch(null);
        setIsValidated(true);
      }
    }, 250);

    return () => {
      if (validateTimerRef.current) clearTimeout(validateTimerRef.current);
    };
  }, [value.state_id, value.city_id, value.district_id, value.area_id, value.pincode, validateHierarchy]);

  // Value updater
  const updateValue = (patch: Partial<AddressGroupValue>) => {
    const updated = { ...value, ...patch };
    if (onChange) {
      onChange(updated);
    }
  };

  // Handler: PIN Code Resolved
  const handlePincodeResolved = (resolution: GeoPincodeResolution) => {
    const patch: Partial<AddressGroupValue> = {
      pincode: resolution.pincode,
      country_id: resolution.country.id,
      country_name: resolution.country.name,
      state_id: resolution.state.id,
      state_name: resolution.state.name,
      district_id: resolution.district.id,
      district_name: resolution.district.name,
      city_id: resolution.city.id,
      city_name: resolution.city.name,
      latitude: resolution.latitude,
      longitude: resolution.longitude,
    };

    if (resolution.areas && resolution.areas.length === 1) {
      patch.area_id = resolution.areas[0].id;
      patch.area_name = resolution.areas[0].name;
    } else if (resolution.areas && resolution.areas.length > 1) {
      patch.area_id = undefined;
      patch.area_name = undefined;
    }

    updateValue(patch);
  };

  // Handler: State Changed
  const handleStateChange = (stateId: number | string | null, opt?: LocationOption) => {
    if (!stateId) {
      updateValue({
        state_id: undefined,
        state_name: undefined,
        district_id: undefined,
        district_name: undefined,
        city_id: undefined,
        city_name: undefined,
        area_id: undefined,
        area_name: undefined,
        pincode: '',
      });
      return;
    }

    const state = states.find((s) => s.id === stateId);
    updateValue({
      state_id: Number(stateId),
      state_name: state?.name || opt?.name,
      district_id: undefined,
      district_name: undefined,
      city_id: undefined,
      city_name: undefined,
      area_id: undefined,
      area_name: undefined,
      pincode: '',
    });
  };

  // Handler: District Changed
  const handleDistrictChange = (distId: number | string | null, opt?: LocationOption) => {
    if (!distId) {
      updateValue({
        district_id: undefined,
        district_name: undefined,
        city_id: undefined,
        city_name: undefined,
        area_id: undefined,
        area_name: undefined,
      });
      return;
    }

    const dist = districts.find((d) => d.id === distId);
    updateValue({
      district_id: Number(distId),
      district_name: dist?.name || opt?.name,
      city_id: undefined,
      city_name: undefined,
      area_id: undefined,
      area_name: undefined,
    });
  };

  // Handler: City Changed
  const handleCityChange = (cityId: number | string | null, opt?: LocationOption) => {
    if (!cityId) {
      updateValue({
        city_id: undefined,
        city_name: undefined,
        area_id: undefined,
        area_name: undefined,
        pincode: '',
      });
      return;
    }

    const city = cities.find((c) => c.id === cityId);
    const patch: Partial<AddressGroupValue> = {
      city_id: Number(cityId),
      city_name: city?.name || opt?.name,
      area_id: undefined,
      area_name: undefined,
    };

    if (city && city.state_id && !value.state_id) {
      patch.state_id = city.state_id;
      const st = states.find((s) => s.id === city.state_id);
      if (st) patch.state_name = st.name;
    }

    updateValue(patch);
  };

  // Handler: Area Changed
  const handleAreaChange = (areaId: number | string | null, opt?: LocationOption) => {
    if (!areaId) {
      updateValue({ area_id: undefined, area_name: undefined });
      return;
    }

    const area = areas.find((a) => a.id === areaId);
    updateValue({
      area_id: Number(areaId),
      area_name: area?.name || opt?.name,
      latitude: area?.latitude ?? value.latitude,
      longitude: area?.longitude ?? value.longitude,
    });
  };

  // Options mapping
  const stateOptions: LocationOption[] = states.map((s) => ({
    id: s.id,
    name: s.name,
    subtext: s.gst_code ? `GST: ${s.gst_code}` : undefined,
    badge: s.type === 'union_territory' ? 'UT' : undefined,
  }));

  const districtOptions: LocationOption[] = districts.map((d) => ({
    id: d.id,
    name: d.name,
  }));

  const cityOptions: LocationOption[] = cities.map((c) => ({
    id: c.id,
    name: c.name,
    badge: c.tier ? c.tier.toUpperCase() : undefined,
  }));

  const areaOptions: LocationOption[] = areas.map((a) => ({
    id: a.id,
    name: a.name,
  }));

  const isInvalid = Boolean(validationMismatch);
  const isValid = isValidated && !validationMismatch && Boolean(value.state_name && value.city_name);

  return (
    <div className={cn('w-full space-y-4 font-sans', className)}>
      {/* ─── 1. POSTAL INTELLIGENCE (Pincode & Locality) ────────────────────────── */}
      <div className="space-y-2">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-1.5 text-xs font-semibold text-slate-800">
            <MapPin className="w-3.5 h-3.5 text-teal-600" />
            <span>Postal Intelligence</span>
          </div>
          <span className="text-[10px] text-slate-400 font-medium">
            Auto-resolves State, District & City
          </span>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 items-start">
          <div className="sm:col-span-1">
            <OVSPincodeInput
              id="address-group-pincode"
              value={value.pincode || ''}
              onChange={(pin) => updateValue({ pincode: pin })}
              onResolved={handlePincodeResolved}
              onClear={() => updateValue({ pincode: '' })}
              density={density}
              disabled={disabled}
              required={requiredFields.pincode}
              error={errors.pincode}
            />
          </div>

          <div className="sm:col-span-2">
            <OVSLocationSelect
              id="address-group-area"
              label="Postal Locality / Area"
              value={value.area_id || ''}
              onChange={handleAreaChange}
              options={areaOptions}
              loading={loadingAreas}
              disabled={disabled || areaOptions.length === 0}
              placeholder={
                areaOptions.length > 0
                  ? `${areaOptions.length} postal areas available (Select...)`
                  : value.city_id
                  ? 'No localities found for city'
                  : 'Enter PIN or select city first'
              }
              searchPlaceholder="Search postal areas..."
              density={density}
              required={requiredFields.area}
              error={errors.area_id || errors.area}
            />
          </div>
        </div>
      </div>

      {/* ─── DIVIDER ───────────────────────────────────────────────────────────── */}
      {showAddressLines && <div className="border-t border-slate-100" />}

      {/* ─── 2. STRUCTURED STREET ADDRESS ──────────────────────────────────────── */}
      {showAddressLines && (
        <div className="space-y-2.5">
          <div className="text-xs font-semibold text-slate-800">Street & Premises</div>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div className="sm:col-span-2">
              <label className="text-[11px] font-semibold text-slate-700 tracking-tight flex items-center gap-1 mb-1">
                Address Line 1 (Premises / Street)
                {requiredFields.address_line_1 && <span className="text-red-500 font-bold">*</span>}
              </label>
              <input
                type="text"
                disabled={disabled}
                value={value.address_line_1 || ''}
                onChange={(e) => updateValue({ address_line_1: e.target.value })}
                placeholder="e.g. Unit 402, Titanium City Centre, S.G. Highway"
                className={cn(
                  'flex w-full rounded-lg border bg-white px-3 py-1.5 text-xs text-slate-900 shadow-2xs transition-all duration-150',
                  'placeholder:text-slate-400 font-normal tracking-tight',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/20 focus-visible:border-teal-500',
                  'disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-slate-50',
                  density === 'compact' ? 'h-8' : density === 'comfortable' ? 'h-10 text-sm' : 'h-9',
                  errors.address_line_1 ? 'border-red-400 text-red-900' : 'border-slate-200 hover:border-slate-300'
                )}
              />
              {errors.address_line_1 && (
                <p className="text-[10.5px] text-red-600 font-medium mt-1">{errors.address_line_1}</p>
              )}
            </div>

            <div className="sm:col-span-1">
              <label className="text-[11px] font-semibold text-slate-700 tracking-tight flex items-center gap-1 mb-1">
                Address Line 2 (Optional)
              </label>
              <input
                type="text"
                disabled={disabled}
                value={value.address_line_2 || ''}
                onChange={(e) => updateValue({ address_line_2: e.target.value })}
                placeholder="e.g. 100 Feet Road"
                className={cn(
                  'flex w-full rounded-lg border bg-white px-3 py-1.5 text-xs text-slate-900 shadow-2xs transition-all duration-150',
                  'placeholder:text-slate-400 font-normal tracking-tight',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/20 focus-visible:border-teal-500',
                  'disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-slate-50',
                  density === 'compact' ? 'h-8' : density === 'comfortable' ? 'h-10 text-sm' : 'h-9',
                  'border-slate-200 hover:border-slate-300'
                )}
              />
            </div>

            {showLandmark && (
              <div className="sm:col-span-3">
                <label className="text-[11px] font-semibold text-slate-700 tracking-tight flex items-center gap-1 mb-1">
                  Landmark
                </label>
                <input
                  type="text"
                  disabled={disabled}
                  value={value.landmark || ''}
                  onChange={(e) => updateValue({ landmark: e.target.value })}
                  placeholder="e.g. Opposite Sachin Tower / Near ISKCON"
                  className={cn(
                    'flex w-full rounded-lg border bg-white px-3 py-1.5 text-xs text-slate-900 shadow-2xs transition-all duration-150',
                    'placeholder:text-slate-400 font-normal tracking-tight',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/20 focus-visible:border-teal-500',
                    'disabled:cursor-not-allowed disabled:opacity-50 disabled:bg-slate-50',
                    density === 'compact' ? 'h-8' : density === 'comfortable' ? 'h-10 text-sm' : 'h-9',
                    'border-slate-200 hover:border-slate-300'
                  )}
                />
              </div>
            )}
          </div>
        </div>
      )}

      {/* ─── DIVIDER ───────────────────────────────────────────────────────────── */}
      <div className="border-t border-slate-100" />

      {/* ─── 3. ADMINISTRATIVE JURISDICTION (State, District, City) ─────────────── */}
      <div className="space-y-2.5">
        <div className="text-xs font-semibold text-slate-800">Administrative Jurisdiction</div>
        <div className={cn(
          'grid gap-3',
          showDistrict ? 'grid-cols-1 sm:grid-cols-3' : 'grid-cols-1 sm:grid-cols-2'
        )}>
          {/* State */}
          <div>
            <OVSLocationSelect
              id="address-group-state"
              label="State / Union Territory"
              value={value.state_id || ''}
              onChange={handleStateChange}
              options={stateOptions}
              loading={loadingStates}
              disabled={disabled}
              placeholder="Select state..."
              searchPlaceholder="Search 36 States/UTs..."
              density={density}
              required={requiredFields.state}
              error={errors.state_id || errors.state}
            />
          </div>

          {/* District */}
          {showDistrict && (
            <div>
              <OVSLocationSelect
                id="address-group-district"
                label="District"
                value={value.district_id || ''}
                onChange={handleDistrictChange}
                options={districtOptions}
                loading={loadingDistricts}
                disabled={disabled || (!value.state_id && districtOptions.length === 0)}
                placeholder={
                  value.state_id
                    ? `Select district (${districtOptions.length})...`
                    : 'Select state first...'
                }
                searchPlaceholder="Search districts..."
                density={density}
              />
            </div>
          )}

          {/* City */}
          <div>
            <OVSLocationSelect
              id="address-group-city"
              label="City / Urban Division"
              value={value.city_id || ''}
              onChange={handleCityChange}
              options={cityOptions}
              loading={loadingCities}
              disabled={disabled || (!value.state_id && cityOptions.length === 0)}
              placeholder={
                value.state_id
                  ? `Select city (${cityOptions.length} in ${value.state_name || 'state'})...`
                  : 'Select state first...'
              }
              searchPlaceholder="Search cities..."
              density={density}
              required={requiredFields.city}
              error={errors.city_id || errors.city}
            />
          </div>
        </div>
      </div>

      {/* ─── 4. VERIFICATION / MISMATCH FEEDBACK ───────────────────────────────── */}
      {isInvalid && (
        <div className="flex items-start gap-2.5 p-3 rounded-lg bg-red-50 border border-red-200 text-red-900 text-xs animate-in fade-in-0 duration-150">
          <AlertTriangle className="w-4 h-4 text-red-600 flex-shrink-0 mt-0.5" />
          <div>
            <div className="font-bold text-red-900">Location mismatch</div>
            <div className="text-red-700 text-[11.5px] mt-0.5">{validationMismatch}</div>
          </div>
        </div>
      )}

      {isValid && (
        <div className="flex items-center gap-2 text-[11px] text-slate-500 pt-2 border-t border-slate-100 font-medium">
          <ShieldCheck className="w-3.5 h-3.5 text-teal-600 flex-shrink-0" />
          <span className="text-teal-800 font-semibold">✓ Verified Jurisdiction:</span>
          <span className="text-slate-900 font-medium">
            {value.area_name ? `${value.area_name} · ` : ''}
            {value.city_name}
            {value.district_name && value.district_name !== value.city_name ? ` · ${value.district_name}` : ''}
            {` · ${value.state_name}`}
            {value.pincode ? ` · ${value.pincode}` : ''}
          </span>
          <span className="text-slate-300">·</span>
          <span className="text-slate-500">India</span>
        </div>
      )}
    </div>
  );
};
