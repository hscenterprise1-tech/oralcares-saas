import { useEffect, useState, useRef } from 'react';
import { useSimulator } from '@/contexts/SimulatorContext';
import { EventBus } from '@/simulator/EventBus';
import { Button } from '@/components/ui/button';
import { Play, Pause, FastForward, SkipForward, RotateCcw } from 'lucide-react';
import { Slider } from '@/components/ui/slider';

interface LogEntry {
    id: string;
    timestamp: Date;
    event: string;
    payload: any;
}

export function SimulatorControlSection() {
    const { isPlaying, speed, play, pause, step, setSpeed, reset } = useSimulator();
    const [logs, setLogs] = useState<LogEntry[]>([]);
    const endOfLogsRef = useRef<HTMLDivElement>(null);

    // Subscribe to ALL events just for the terminal logger
    useEffect(() => {
        const handleEvent = (eventName: string) => (payload: any) => {
            setLogs(prev => [...prev, {
                id: Math.random().toString(),
                timestamp: new Date(),
                event: eventName,
                payload
            }].slice(-100)); // Keep only last 100 logs
        };

        const events = [
            'PatientRegistered', 'PatientUpdated', 'QueueCreated',
            'DoctorStartedConsultation', 'TreatmentCompleted',
            'InvoiceCreated', 'PaymentReceived', 'NotificationSent'
        ] as const;

        const handlers: Record<string, any> = {};

        events.forEach(e => {
            handlers[e] = handleEvent(e);
            EventBus.on(e, handlers[e]);
        });

        return () => {
            events.forEach(e => {
                EventBus.off(e, handlers[e]);
            });
        };
    }, []);

    useEffect(() => {
        if (endOfLogsRef.current) {
            endOfLogsRef.current.scrollIntoView({ behavior: 'smooth' });
        }
    }, [logs]);

    const formatPayload = (payload: any) => {
        const clean = { ...payload };
        return Object.entries(clean).map(([k, v]) => `${k}: ${v}`).join(', ');
    };

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-2xl font-bold tracking-tight mb-2">Workflow Simulator</h2>
                <p className="text-slate-500">
                    Control the live event engine. Events emitted here are consumed by the Dashboard and other reactive components.
                </p>
            </div>

            <div className="flex flex-col lg:flex-row gap-8">
                {/* Controls */}
                <div className="w-full lg:w-1/3 space-y-8 bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <div className="space-y-4">
                        <h3 className="font-semibold text-slate-800">Playback Controls</h3>
                        <div className="flex gap-2">
                            <Button variant={isPlaying ? "outline" : "default"} onClick={play} disabled={isPlaying} className="w-24">
                                <Play className="w-4 h-4 mr-2" /> Play
                            </Button>
                            <Button variant={!isPlaying ? "outline" : "default"} onClick={pause} disabled={!isPlaying} className="w-24">
                                <Pause className="w-4 h-4 mr-2" /> Pause
                            </Button>
                            <Button variant="outline" onClick={step} disabled={isPlaying}>
                                <SkipForward className="w-4 h-4 mr-2" /> Step
                            </Button>
                        </div>
                    </div>

                    <div className="space-y-4 pt-4 border-t border-slate-100">
                        <div className="flex items-center justify-between">
                            <h3 className="font-semibold text-slate-800 flex items-center gap-2">
                                <FastForward className="w-4 h-4" /> Simulation Speed
                            </h3>
                            <span className="text-sm font-mono bg-slate-100 px-2 py-1 rounded">{speed}x</span>
                        </div>
                        <Slider 
                            value={[speed]} 
                            min={1} 
                            max={10} 
                            step={1} 
                            onValueChange={(val) => setSpeed(val[0])} 
                        />
                    </div>

                    <div className="pt-4 border-t border-slate-100">
                        <Button variant="outline" onClick={() => { reset(); setLogs([]); }} className="w-full text-ovs-semantic-danger-text hover:bg-ovs-semantic-danger-bg">
                            <RotateCcw className="w-4 h-4 mr-2" /> Reset Engine
                        </Button>
                    </div>
                </div>

                {/* Event Terminal */}
                <div className="w-full lg:w-2/3 bg-slate-900 rounded-xl p-4 font-mono text-sm overflow-hidden flex flex-col h-[400px]">
                    <div className="text-slate-400 mb-2 pb-2 border-b border-slate-800 flex justify-between">
                        <span>Live Event Stream</span>
                        <span>{logs.length} events</span>
                    </div>
                    <div className="flex-1 overflow-y-auto space-y-1 pr-2 custom-scrollbar">
                        {logs.length === 0 ? (
                            <div className="text-slate-600 text-center mt-20">No events yet. Press Play.</div>
                        ) : (
                            logs.map(log => (
                                <div key={log.id} className="flex flex-col sm:flex-row gap-2 py-1 border-b border-slate-800/50">
                                    <span className="text-slate-500 whitespace-nowrap">
                                        {log.timestamp.toLocaleTimeString([], { hour12: false, fractionalSecondDigits: 1 })}
                                    </span>
                                    <span className="text-emerald-400 font-semibold w-56 flex-shrink-0">
                                        {log.event}
                                    </span>
                                    <span className="text-slate-300 break-words">
                                        {formatPayload(log.payload)}
                                    </span>
                                </div>
                            ))
                        )}
                        <div ref={endOfLogsRef} />
                    </div>
                </div>
            </div>
        </div>
    );
}
