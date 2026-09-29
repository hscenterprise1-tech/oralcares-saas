import { ReactNode } from 'react';
import { AuthProvider } from './AuthContext';
import { NotificationProvider } from './NotificationContext';
import { I18nProvider } from './I18nContext';
import { PreferenceProvider } from './PreferenceContext';
import { ThemeProvider } from '../providers/ThemeProvider';
import { MotionProvider } from '../providers/MotionProvider';

/**
 * GlobalProvider wraps the entire application with all required enterprise contexts.
 * This prevents massive prop drilling and separates concerns cleanly.
 */
export function GlobalProvider({ children }: { children: ReactNode }) {
    return (
        <ThemeProvider>
            <MotionProvider>
                <AuthProvider>
                    <PreferenceProvider>
                        <I18nProvider>
                            <NotificationProvider>
                                {children}
                            </NotificationProvider>
                        </I18nProvider>
                    </PreferenceProvider>
                </AuthProvider>
            </MotionProvider>
        </ThemeProvider>
    );
}
