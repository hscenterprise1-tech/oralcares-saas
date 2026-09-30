import { createContext, useContext, ReactNode } from 'react';

// Stub for Internationalization Context
interface I18nContextType {
    locale: string;
    t: (key: string) => string;
}

const I18nContext = createContext<I18nContextType>({ locale: 'en', t: (k) => k });

export function I18nProvider({ children }: { children: ReactNode }) {
    return (
        <I18nContext.Provider value={{ locale: 'en', t: (k) => k }}>
            {children}
        </I18nContext.Provider>
    );
}

export const useI18n = () => useContext(I18nContext);
