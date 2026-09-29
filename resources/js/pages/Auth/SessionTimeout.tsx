import React, { useState, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { AuthShell } from '@/components/auth/AuthShell';
import { AuthButton } from '@/components/auth/AuthInput';
import { Clock, RefreshCw, LogOut } from 'lucide-react';

export default function SessionTimeout({ countdownSeconds = 60 }: { countdownSeconds?: number }) {
  const [seconds, setSeconds] = useState(countdownSeconds);
  const { post, processing } = useForm({});

  useEffect(() => {
    if (seconds <= 0) {
      window.location.href = '/company/login?expired=1';
      return;
    }
    const timer = setInterval(() => setSeconds(s => s - 1), 1000);
    return () => clearInterval(timer);
  }, [seconds]);

  const handleExtend = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/extend-session');
  };

  const handleLogout = (e: React.FormEvent) => {
    e.preventDefault();
    post('/company/logout');
  };

  return (
    <AuthShell
      pageTitle="Session Expiring"
      formTitle="Inactivity Warning"
      formSubtitle="Your corporate session is about to expire due to prolonged inactivity."
    >
      <div className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-20 h-20 bg-amber-50 text-amber-600 rounded-full flex flex-col items-center justify-center mx-auto border border-amber-200 shadow-2xs">
          <Clock className="w-8 h-8 animate-pulse" />
          <span className="font-mono text-[14px] font-black">{seconds}s</span>
        </div>

        <p className="text-[13px] text-[#64748B]">
          Would you like to extend your active session and continue working?
        </p>

        <form onSubmit={handleExtend}>
          <AuthButton type="submit" loading={processing} icon={<RefreshCw className="w-4 h-4 text-emerald-300" />}>
            Extend Active Session
          </AuthButton>
        </form>

        <form onSubmit={handleLogout} className="pt-2">
          <button type="submit" className="inline-flex items-center gap-1.5 text-[12px] font-bold text-[#64748B] hover:text-[#0F172A] hover:underline">
            <LogOut className="w-3.5 h-3.5" /> Sign Out Immediately
          </button>
        </form>
      </div>
    </AuthShell>
  );
}
