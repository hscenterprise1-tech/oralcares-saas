import React, { createContext, useContext, useEffect, useState } from 'react';

type MotionPreference = 'reduce' | 'no-preference' | 'system';

interface MotionProviderProps {
  children: React.ReactNode;
  defaultPreference?: MotionPreference;
}

interface MotionProviderState {
  preference: MotionPreference;
  setPreference: (preference: MotionPreference) => void;
}

const MotionProviderContext = createContext<MotionProviderState | undefined>(undefined);

export function MotionProvider({
  children,
  defaultPreference = 'system',
  ...props
}: MotionProviderProps) {
  const [preference, setPreference] = useState<MotionPreference>(defaultPreference);

  useEffect(() => {
    const root = window.document.documentElement;
    root.classList.remove('motion-reduce', 'motion-safe');

    let activePreference = preference;

    if (preference === 'system') {
      activePreference = window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ? 'reduce'
        : 'no-preference';
    }

    if (activePreference === 'reduce') {
      root.classList.add('motion-reduce');
    } else {
      root.classList.add('motion-safe');
    }
  }, [preference]);

  const value = {
    preference,
    setPreference,
  };

  return (
    <MotionProviderContext.Provider {...props} value={value}>
      {children}
    </MotionProviderContext.Provider>
  );
}

export const useMotion = () => {
  const context = useContext(MotionProviderContext);
  if (context === undefined)
    throw new Error('useMotion must be used within a MotionProvider');
  return context;
};
