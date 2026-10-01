import { Crown } from 'lucide-react';
import { DashboardGrid } from '@/clinic/dashboard/DashboardGrid';
import { VisibilityDialog } from '@/clinic/dashboard/VisibilityDialog';
import { clinicDisplayName, useClinic, useClinicShared } from '@/clinic/context';
import { PageHeader, Panel } from '@/clinic/page/PageHeader';
import { BranchSwitcher, branchLabel } from '@/clinic/shell/BranchSwitcher';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import type { DashboardVisibilitySettings, DashboardWidget, WidgetData } from '@/clinic/types';
import { StatePanel } from '@/clinic/ui/StatePanel';

interface HomeProps {
  /** The widgets the server resolved for this user, in order. No figures. */
  widgets: DashboardWidget[];
  /** Each widget's figures, deferred: undefined until they arrive. */
  widgetData?: Record<string, WidgetData>;
  /** The owner's per-role dashboard choices; only sent in Owner Mode. */
  visibility: DashboardVisibilitySettings | null;
}

const HONORIFICS = /^(dr|mr|mrs|ms|miss|prof)\.?$/i;

/** "Dr. Vikram Rao" → "Dr. Vikram"; "Asha Kulkarni" → "Asha". */
function greetingName(fullName: string): string {
  const words = fullName.trim().split(/\s+/).filter(Boolean);

  if (words.length === 0) {
    return '';
  }

  return HONORIFICS.test(words[0]) && words.length > 1 ? `${words[0]} ${words[1]}` : words[0];
}

function greeting(hour: number): string {
  if (hour < 12) {
    return 'Good morning';
  }

  return hour < 17 ? 'Good afternoon' : 'Good evening';
}

/**
 * The dashboard: a greeting, then whatever widgets the server resolved for
 * this user. One page for every role; it holds no widget list and no
 * navigation of its own.
 */
export default function Home({ widgets, widgetData, visibility }: HomeProps) {
  const clinic = useClinic();
  const { auth, portalLinks } = useClinicShared();
  const firstName = greetingName(auth.user?.name ?? '');
  const today = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' }).format(
    new Date(),
  );
  const title = firstName ? `${greeting(new Date().getHours())}, ${firstName}` : greeting(new Date().getHours());

  /*
   * A member placed at several branches, with none chosen yet. Their roles are
   * held per branch, so until they choose one nothing is open to them.
   */
  const mustChooseBranch =
    clinic !== null &&
    clinic.active_branch === null &&
    clinic.accessible_branch_count > 1 &&
    !clinic.is_owner &&
    !clinic.has_all_branches_access;

  if (mustChooseBranch && clinic && portalLinks) {
    return (
      <ClinicPortalShell title="Dashboard">
        <PageHeader eyebrow={today} title={title} />
        <Panel className="mx-auto max-w-lg">
          <StatePanel
            kind="empty"
            title="Choose the branch you are working at"
            description={`You work at ${clinic.accessible_branch_count} branches. Your access depends on the branch, so choose one to see its day.`}
            action={
              <div className="w-72 max-w-full">
                <BranchSwitcher clinic={clinic} links={portalLinks} variant="block" />
              </div>
            }
          />
        </Panel>
      </ClinicPortalShell>
    );
  }

  const ownerMode = clinic?.owner_mode.active ?? false;
  // Name the branch only when there is more than one to tell apart.
  const where = clinic
    ? clinic.accessible_branch_count > 1
      ? clinic.active_branch
        ? branchLabel(clinic)
        : 'all your branches'
      : clinicDisplayName(clinic)
    : null;

  return (
    <ClinicPortalShell title="Dashboard">
      <PageHeader
        eyebrow={today}
        title={title}
        description={where ? `Here is today at ${where}.` : undefined}
        actions={
          ownerMode ? (
            <>
              {visibility && <VisibilityDialog settings={visibility} />}
              <span className="inline-flex items-center gap-1.5 rounded-full bg-clinic-ink px-3.5 py-1.5 text-[12px] font-bold text-white">
                <Crown className="h-3.5 w-3.5" aria-hidden="true" /> Owner view
              </span>
            </>
          ) : undefined
        }
      />

      {widgets.length === 0 ? (
        <Panel>
          <StatePanel
            kind="empty"
            title="Welcome to your clinic"
            description="Nothing on your dashboard yet: your role here does not include any of today's figures. Use the navigation above to open the areas you work in."
          />
        </Panel>
      ) : (
        <DashboardGrid widgets={widgets} data={widgetData} actions={clinic?.actions ?? []} />
      )}
    </ClinicPortalShell>
  );
}
