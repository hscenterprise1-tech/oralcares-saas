import { router } from '@inertiajs/react';
import { DoorOpen } from 'lucide-react';
import { CrmSection, toneHex } from '@/pages/Company/design';
import { Switch } from '@/pages/Company/design/CrmFormPrimitives';

export interface PatientFlowSettings {
  rooms_enabled: boolean;
}

/**
 * Settings > Patient Flow. Saves on toggle — a single switch doesn't need a
 * separate Save button. Rooms stay off until a clinic actually uses them.
 */
export function PatientFlowSection({ settings }: { settings: PatientFlowSettings }) {
  const toggleRooms = (next: boolean) => {
    router.put(route('admin.settings.patient-flow'), { rooms_enabled: next }, { preserveScroll: true });
  };

  return (
    <div className="max-w-[640px] pb-12">
      <CrmSection
        title="Patient Flow"
        description="How patients move from reception to the doctor."
        icon={DoorOpen}
        hue={toneHex('blue')}
      >
        <label className="flex min-h-[44px] cursor-pointer items-center justify-between gap-4 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface-inset px-4 py-3">
          <span>
            <span className="block text-[13px] font-semibold text-ovs-crm-text-primary">Use rooms</span>
            <span className="block text-[12px] text-ovs-crm-text-secondary">
              Show rooms or chairs on the live queue, the doctor&apos;s card, check-in and appointment booking. Rooms
              already saved on appointments are kept when this is off.
            </span>
          </span>
          <Switch checked={settings.rooms_enabled} onCheckedChange={toggleRooms} />
        </label>
      </CrmSection>
    </div>
  );
}
