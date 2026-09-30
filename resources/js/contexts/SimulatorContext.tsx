import { createContext, useContext, useState, ReactNode, useEffect, useRef } from 'react';
import { WorkflowEngine } from '@/simulator/WorkflowEngine';

interface SimulatorContextType {
    engine: WorkflowEngine;
    isPlaying: boolean;
    speed: number;
    play: () => void;
    pause: () => void;
    step: () => void;
    setSpeed: (speed: number) => void;
    reset: () => void;
}

export const SimulatorContext = createContext<SimulatorContextType | undefined>(undefined);

export function SimulatorProvider({ children }: { children: ReactNode }) {
    const engineRef = useRef(new WorkflowEngine());
    const [isPlaying, setIsPlaying] = useState(false);
    const [speed, setSpeedState] = useState(1);

    // Ensure engine stops when unmounted
    useEffect(() => {
        const eng = engineRef.current;
        return () => { eng.pause(); };
    }, []);

    const play = () => {
        engineRef.current.play();
        setIsPlaying(true);
    };

    const pause = () => {
        engineRef.current.pause();
        setIsPlaying(false);
    };

    const step = () => {
        engineRef.current.step();
    };

    const setSpeed = (newSpeed: number) => {
        engineRef.current.setSpeed(newSpeed);
        setSpeedState(newSpeed);
    };

    const reset = () => {
        engineRef.current.reset();
        setIsPlaying(false);
    };

    return (
        <SimulatorContext.Provider value={{
            engine: engineRef.current,
            isPlaying,
            speed,
            play,
            pause,
            step,
            setSpeed,
            reset
        }}>
            {children}
        </SimulatorContext.Provider>
    );
}

export function useSimulator() {
    const context = useContext(SimulatorContext);
    if (!context) {
        throw new Error('useSimulator must be used within SimulatorProvider');
    }
    return context;
}
