import { useEffect, useState } from 'react';
import { HeartHandshake } from 'lucide-react';
import { requestJson } from '@/clinic/http';
import { PhysioCareList, type PhysioCare } from '@/clinic/patients/PhysioCare';

/**
 * Repeat Case, in a physiotherapy clinic: the chosen patient's treatments,
 * sessions done, package balances and what they owe — read-only, so the desk
 * knows before checking them in. Checking in never uses a session; the
 * session is confirmed once it has taken place.
 */
export function PhysioCareAtCheckIn({ patientId }: { patientId: number }) {
  const [care, setCare] = useState<PhysioCare | null | undefined>(undefined);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    setCare(undefined);
    setFailed(false);
    requestJson<{ physio: PhysioCare | null }>('GET', route('clinic.reception.physio-summary', patientId), { signal: controller.signal })
      .then((response) => setCare(response.physio))
      .catch(() => {
        if (!controller.signal.aborted) {
          setFailed(true);
        }
      });

    return () => controller.abort();
  }, [patientId]);

  if (care === null) {
    return null;
  }

  return (
    <section aria-label="Physiotherapy" className="rounded-2xl border border-[#E6EBF1] bg-[#F7FBFA] p-3.5">
      <h3 className="mb-2.5 flex items-center gap-2 text-[13.5px] font-semibold text-[#173B60]">
        <HeartHandshake className="h-4 w-4 text-[#0F6B5F]" aria-hidden="true" />
        Physiotherapy
      </h3>
      {failed ? (
        <p className="text-[13px] text-cx-text-2">Their physiotherapy summary could not be loaded.</p>
      ) : care === undefined ? (
        <p className="text-[13px] text-cx-text-2" role="status">
          Loading…
        </p>
      ) : (
        <PhysioCareList care={care} dense />
      )}
    </section>
  );
}
