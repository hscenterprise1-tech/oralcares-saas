import { createContext, useContext, ReactNode } from 'react';

// Stub for Global Auth Context
interface AuthContextType {
    user: any | null;
    isAuthenticated: boolean;
}

const AuthContext = createContext<AuthContextType>({ user: null, isAuthenticated: false });

export function AuthProvider({ children }: { children: ReactNode }) {
    return (
        <AuthContext.Provider value={{ user: null, isAuthenticated: false }}>
            {children}
        </AuthContext.Provider>
    );
}

export const useAuth = () => useContext(AuthContext);
