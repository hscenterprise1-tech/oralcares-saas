import React from 'react';
import { AuthShell } from '@/components/auth/AuthShell';
import { ShieldAlert, Download } from 'lucide-react';

export default function BrowserUnsupported() {
  return (
    <AuthShell
      pageTitle="Browser Unsupported"
      formTitle="Browser Upgrade Required"
      formSubtitle="Your web browser does not support modern WebCrypto / TLS 1.3 security protocols required by OralCares OS."
    >
      <div className="space-y-6 bg-white border border-[#E4E7EE] rounded-2xl p-8 sm:p-10 shadow-sm text-center">
        <div className="w-16 h-16 bg-amber-50 text-amber-600 rounded-full flex items-center justify-center mx-auto border border-amber-200 shadow-2xs">
          <ShieldAlert className="w-8 h-8" />
        </div>

        <p className="text-[13px] text-[#64748B] leading-relaxed">
          Please upgrade to a modern browser (Google Chrome 110+, Microsoft Edge 110+, Mozilla Firefox 110+, or Apple Safari 16+).
        </p>

        <div className="pt-2 space-y-2">
          <a
            href="https://www.google.com/chrome/"
            target="_blank"
            rel="noopener noreferrer"
            className="w-full h-[48px] bg-[#0A2540] hover:bg-[#07192C] text-white text-[13px] font-extrabold rounded-xl flex items-center justify-center gap-2 shadow-sm transition-all"
          >
            <Download className="w-4 h-4 text-emerald-300" /> Download Google Chrome
          </a>
        </div>
      </div>
    </AuthShell>
  );
}
