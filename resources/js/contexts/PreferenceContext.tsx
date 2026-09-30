import React, { createContext, useContext, useState, ReactNode } from 'react';

interface PreferenceState {
    theme: 'light' | 'dark' | 'system';
    sidebarCollapsed: boolean;
    language: string;
    tableDensity: 'compact' | 'comfortable';
}

interface PreferenceContextType {
    preferences: PreferenceState;
    updatePreference: <K extends keyof PreferenceState>(key: K, value: PreferenceState[K]) => void;
}

const PreferenceContext = createContext<PreferenceContextType | undefined>(undefined);

export function PreferenceProvider({ children }: { children: ReactNode }) {
    const [preferences, setPreferences] = useState<PreferenceState>({
        theme: 'system',
        sidebarCollapsed: false,
        language: 'en',
        tableDensity: 'comfortable',
    });

    const updatePreference = <K extends keyof PreferenceState>(key: K, value: PreferenceState[K]) => {
        setPreferences(prev => ({ ...prev, [key]: value }));
        // In the future, this will sync with the User API
    };

    return (
        <PreferenceContext.Provider value={{ preferences, updatePreference }}>
            {children}
        </PreferenceContext.Provider>
    );
}

export function usePreferences() {
    const context = useContext(PreferenceContext);
    if (context === undefined) {
        throw new Error('usePreferences must be used within a PreferenceProvider');
    }
    return context;
}
