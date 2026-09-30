import { useState, useCallback, useRef } from 'react';

export interface GeoCountryOption {
  id: number;
  code: string;
  name: string;
  phone_code?: string | null;
  currency_code?: string | null;
}

export interface GeoStateOption {
  id: number;
  country_id: number;
  code: string;
  name: string;
  gst_code?: string | null;
  type: string;
}

export interface GeoDistrictOption {
  id: number;
  state_id: number;
  name: string;
}

export interface GeoCityOption {
  id: number;
  district_id: number;
  state_id: number;
  name: string;
  slug: string;
  tier?: string;
}

export interface GeoAreaOption {
  id: number;
  city_id: number;
  name: string;
  slug: string;
  latitude?: number | null;
  longitude?: number | null;
}

export interface GeoPincodeResolution {
  pincode: string;
  country: GeoCountryOption;
  state: GeoStateOption;
  district: GeoDistrictOption;
  city: GeoCityOption;
  areas: GeoAreaOption[];
  latitude?: number | null;
  longitude?: number | null;
}

export interface ValidationResult {
  valid: boolean;
  errors: string[];
}

export interface AddressGroupValue {
  address_line_1?: string;
  address_line_2?: string;
  landmark?: string;
  country_id?: number;
  country_name?: string;
  state_id?: number;
  state_name?: string;
  district_id?: number;
  district_name?: string;
  city_id?: number;
  city_name?: string;
  area_id?: number;
  area_name?: string;
  pincode?: string;
  latitude?: number | null;
  longitude?: number | null;
}

// In-memory request memoization cache to prevent duplicate network calls across components
const queryCache = new Map<string, { timestamp: number; data: any }>();
const CACHE_TTL_MS = 60 * 1000; // 1 minute client cache

function getCsrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  if (metaToken) return metaToken;
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : null;
}

async function cachedFetch<T>(url: string, options?: RequestInit): Promise<T> {
  const method = options?.method || 'GET';
  const cacheKey = `${method}:${url}:${options?.body ? JSON.stringify(options.body) : ''}`;

  if (method === 'GET') {
    const cached = queryCache.get(cacheKey);
    if (cached && Date.now() - cached.timestamp < CACHE_TTL_MS) {
      return cached.data as T;
    }
  }

  const csrfToken = getCsrfToken();
  const defaultHeaders: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };

  if (csrfToken) {
    defaultHeaders['X-CSRF-TOKEN'] = csrfToken;
    defaultHeaders['X-XSRF-TOKEN'] = csrfToken;
  }

  const response = await fetch(url, {
    ...options,
    headers: {
      ...defaultHeaders,
      ...options?.headers,
    },
  });

  if (!response.ok) {
    const errorBody = await response.json().catch(() => ({}));
    const error = new Error(errorBody.message || errorBody.error || `HTTP ${response.status}: Request failed`);
    (error as any).status = response.status;
    (error as any).response = errorBody;
    throw error;
  }

  const json = await response.json();
  const result = json.data ?? json;

  if (method === 'GET') {
    queryCache.set(cacheKey, { timestamp: Date.now(), data: result });
  }

  return result as T;
}

export function useLocationResolver() {
  const [isResolving, setIsResolving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // 1. Resolve PIN Code
  const resolvePincode = useCallback(async (pincode: string): Promise<GeoPincodeResolution | null> => {
    const cleanPin = pincode.replace(/\D/g, '').trim();
    if (cleanPin.length !== 6) {
      return null;
    }

    setIsResolving(true);
    setError(null);

    try {
      const data = await cachedFetch<GeoPincodeResolution>(`/api/v1/geo/pincodes/${cleanPin}`);
      setIsResolving(false);
      return data;
    } catch (err: any) {
      setIsResolving(false);
      if (err.status === 404) {
        setError(`Pincode '${cleanPin}' not found`);
      } else {
        setError(err.message || 'Failed to resolve pincode');
      }
      return null;
    }
  }, []);

  // 2. Fetch Countries
  const fetchCountries = useCallback(async (search?: string): Promise<GeoCountryOption[]> => {
    const params = new URLSearchParams();
    if (search) params.append('q', search);
    const query = params.toString() ? `?${params.toString()}` : '';
    return cachedFetch<GeoCountryOption[]>(`/api/v1/geo/countries${query}`);
  }, []);

  // 3. Fetch States
  const fetchStates = useCallback(async (countryId?: number, search?: string): Promise<GeoStateOption[]> => {
    const params = new URLSearchParams();
    if (countryId) params.append('country_id', countryId.toString());
    if (search) params.append('q', search);
    const query = params.toString() ? `?${params.toString()}` : '';
    return cachedFetch<GeoStateOption[]>(`/api/v1/geo/states${query}`);
  }, []);

  // 4. Fetch Districts
  const fetchDistricts = useCallback(async (stateId?: number, search?: string): Promise<GeoDistrictOption[]> => {
    const params = new URLSearchParams();
    if (stateId) params.append('state_id', stateId.toString());
    if (search) params.append('q', search);
    const query = params.toString() ? `?${params.toString()}` : '';
    return cachedFetch<GeoDistrictOption[]>(`/api/v1/geo/districts${query}`);
  }, []);

  // 5. Fetch Cities
  const fetchCities = useCallback(async (stateId?: number, districtId?: number, search?: string): Promise<GeoCityOption[]> => {
    const params = new URLSearchParams();
    if (stateId) params.append('state_id', stateId.toString());
    if (districtId) params.append('district_id', districtId.toString());
    if (search) params.append('q', search);
    const query = params.toString() ? `?${params.toString()}` : '';
    return cachedFetch<GeoCityOption[]>(`/api/v1/geo/cities${query}`);
  }, []);

  // 6. Fetch Areas
  const fetchAreas = useCallback(async (cityId: number, search?: string): Promise<GeoAreaOption[]> => {
    if (!cityId) return [];
    const params = new URLSearchParams();
    params.append('city_id', cityId.toString());
    if (search) params.append('q', search);
    return cachedFetch<GeoAreaOption[]>(`/api/v1/geo/areas?${params.toString()}`);
  }, []);

  // 7. Search location multi-tier
  const searchLocation = useCallback(async (query: string, filters?: { state_id?: number; city_id?: number }) => {
    if (!query || query.length < 2) return { cities: [], areas: [], pincodes: [] };
    const params = new URLSearchParams();
    params.append('q', query);
    if (filters?.state_id) params.append('state_id', filters.state_id.toString());
    if (filters?.city_id) params.append('city_id', filters.city_id.toString());
    return cachedFetch<{ cities: GeoCityOption[]; areas: GeoAreaOption[]; pincodes: { pincode: string }[] }>(
      `/api/v1/geo/search?${params.toString()}`
    );
  }, []);

  // 8. Validate Hierarchy
  const validateHierarchy = useCallback(async (location: Partial<AddressGroupValue>): Promise<ValidationResult> => {
    try {
      const res = await cachedFetch<ValidationResult>('/api/v1/geo/validate', {
        method: 'POST',
        body: JSON.stringify(location),
      });
      return res;
    } catch (err: any) {
      if (err.response?.errors) {
        return {
          valid: false,
          errors: Array.isArray(err.response.errors) ? err.response.errors : Object.values(err.response.errors).flat() as string[],
        };
      }
      return { valid: false, errors: [err.message || 'Validation error'] };
    }
  }, []);

  return {
    isResolving,
    error,
    setError,
    resolvePincode,
    fetchCountries,
    fetchStates,
    fetchDistricts,
    fetchCities,
    fetchAreas,
    searchLocation,
    validateHierarchy,
  };
}
