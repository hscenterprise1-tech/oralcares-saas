import { createContext, useContext, useState, ReactNode, useEffect } from 'react';
import { FeatureFlag, SUBSCRIPTION_PRESETS, ALL_FEATURES } from '@/constants/features';
import { CLINIC_PROFILES, ClinicProfileType } from '@/constants/clinicProfiles';

// Define the simulated state interfaces
export interface PreviewState {
    isEnabled: boolean;
    role: string;
    clinicProfile: ClinicProfileType;
    subscription: string;
    featureFlags: Record<string, boolean>;
    patientVolume: number;
    staffVolume: number;
    branchVolume: number;
    dashboardFocus: string;
}

interface PreviewContextType {
    previewState: PreviewState;
    updatePreviewState: (key: keyof PreviewState, value: any) => void;
    updateFeatureFlag: (feature: FeatureFlag, enabled: boolean) => void;
}

// Helper to convert an array of features into a true/false map
function buildFeatureMap(features: FeatureFlag[]): Record<string, boolean> {
    const map: Record<string, boolean> = {};
    ALL_FEATURES.forEach(f => { map[f] = false; });
    features.forEach(f => { map[f] = true; });
    return map;
}

// Default state when disabled
const defaultState: PreviewState = {
    isEnabled: false,
    role: 'master-admin',
    clinicProfile: 'solo-dentist',
    subscription: 'starter',
    featureFlags: buildFeatureMap(SUBSCRIPTION_PRESETS['starter']),
    patientVolume: 500,
    staffVolume: 2,
    branchVolume: 1,
    dashboardFocus: 'appointments',
};

export const PreviewContext = createContext<PreviewContextType | undefined>(undefined);

/**
 * PreviewProvider wraps the application in development mode
 * allowing QA/Devs to simulate different roles and states.
 */
export function PreviewProvider({ children }: { children: ReactNode }) {
    const [previewState, setPreviewState] = useState<PreviewState>(() => {
        // Try to load from localStorage so it persists across dev reloads
        if (typeof window !== 'undefined') {
            const saved = localStorage.getItem('oc_preview_state');
            if (saved) {
                try {
                    const parsed = JSON.parse(saved);
                    // Ensure new fields exist for backward compat with old cache
                    if (!parsed.featureFlags) parsed.featureFlags = buildFeatureMap(SUBSCRIPTION_PRESETS[parsed.subscription || 'starter'] || []);
                    if (!parsed.clinicProfile) parsed.clinicProfile = 'solo-dentist';
                    if (!parsed.patientVolume) parsed.patientVolume = 500;
                    if (!parsed.staffVolume) parsed.staffVolume = 2;
                    if (!parsed.branchVolume) parsed.branchVolume = 1;
                    if (!parsed.dashboardFocus) parsed.dashboardFocus = 'appointments';
                    return parsed;
                } catch (e) {
                    // Ignore
                }
            }
        }
        return defaultState;
    });

    useEffect(() => {
        if (typeof window !== 'undefined') {
            localStorage.setItem('oc_preview_state', JSON.stringify(previewState));
        }
    }, [previewState]);

    const updatePreviewState = (key: keyof PreviewState, value: any) => {
        setPreviewState(prev => {
            const newState = { ...prev, [key]: value };

            // Cascade 1: If Clinic Profile changes, update everything related to it
            if (key === 'clinicProfile') {
                const profile = CLINIC_PROFILES[value as ClinicProfileType];
                if (profile) {
                    newState.subscription = profile.defaultSubscription;
                    newState.featureFlags = buildFeatureMap(SUBSCRIPTION_PRESETS[profile.defaultSubscription] || []);
                    newState.branchVolume = profile.defaultBranchCount;
                    newState.staffVolume = profile.defaultStaffCount;
                    newState.patientVolume = profile.defaultPatientVolume;
                    newState.dashboardFocus = profile.dashboardFocus;
                    
                    // If current role is not available in new profile, fallback to clinic-owner or master-admin
                    if (!profile.availableRoles.includes(newState.role)) {
                        newState.role = profile.availableRoles.includes('clinic-owner') ? 'clinic-owner' : 'master-admin';
                    }
                }
            }

            // Cascade 2: If Subscription manually changes, auto-update feature flags based on preset
            if (key === 'subscription' && prev.subscription !== value) {
                newState.featureFlags = buildFeatureMap(SUBSCRIPTION_PRESETS[value] || []);
            }
            
            return newState;
        });
    };

    const updateFeatureFlag = (feature: FeatureFlag, enabled: boolean) => {
        setPreviewState(prev => ({
            ...prev,
            featureFlags: {
                ...prev.featureFlags,
                [feature]: enabled
            }
        }));
    };

    return (
        <PreviewContext.Provider value={{ previewState, updatePreviewState, updateFeatureFlag }}>
            {children}
        </PreviewContext.Provider>
    );
}

export function usePreview() {
    const context = useContext(PreviewContext);
    return context ?? { 
        previewState: { ...defaultState, isEnabled: false }, 
        updatePreviewState: () => {},
        updateFeatureFlag: () => {}
    };
}
