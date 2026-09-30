import React, { createContext, useContext, useEffect } from 'react';

interface ThemeProviderProps {
  children: React.ReactNode;
}

interface ThemeProviderState {
  theme: 'light';
}

const ThemeProviderContext = createContext<ThemeProviderState | undefined>(undefined);

/**
 * OVS standardizes on the Light Theme (Executive Navy / Teal Accent) for
 * enterprise compliance and high contrast. Dark mode is not offered.
 */
export function ThemeProvider({ children }: ThemeProviderProps) {
  useEffect(() => {
    const root = window.document.documentElement;
    root.classList.remove('dark');
    root.classList.add('light');
  }, []);

  return (
    <ThemeProviderContext.Provider value={{ theme: 'light' }}>
      {children}
    </ThemeProviderContext.Provider>
  );
}

export const useTheme = () => {
  const context = useContext(ThemeProviderContext);
  if (context === undefined)
    throw new Error('useTheme must be used within a ThemeProvider');
  return context;
};
