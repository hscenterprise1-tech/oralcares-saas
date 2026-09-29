import { createContext, useContext, useState, ReactNode } from 'react';

interface AppShellContextType {
    isMobileSidebarOpen: boolean;
    setMobileSidebarOpen: (open: boolean) => void;
    toggleMobileSidebar: () => void;
}

const AppShellContext = createContext<AppShellContextType | undefined>(undefined);

export function AppShellProvider({ children }: { children: ReactNode }) {
    const [isMobileSidebarOpen, setMobileSidebarOpen] = useState(false);

    const toggleMobileSidebar = () => setMobileSidebarOpen((prev) => !prev);

    return (
        <AppShellContext.Provider value={{ isMobileSidebarOpen, setMobileSidebarOpen, toggleMobileSidebar }}>
            {children}
        </AppShellContext.Provider>
    );
}

export function useAppShell() {
    const context = useContext(AppShellContext);
    if (context === undefined) {
        throw new Error('useAppShell must be used within an AppShellProvider');
    }
    return context;
}
