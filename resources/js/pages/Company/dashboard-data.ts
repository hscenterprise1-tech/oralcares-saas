// ─────────────────────────────────────────────────────────────────────────────
// PHASE E1 — EXECUTIVE BRIEF SERVICE CONTRACTS & DATA PROVIDER
// ─────────────────────────────────────────────────────────────────────────────

export interface ExecutiveSnapshotIndicator {
  id: string;
  value: string | number;
  label: string;
}

export interface RecommendedFirstAction {
  title: string;
  estimatedContract: string;
  deadline: string;
  businessImpact: string;
  actionUrl: string;
  buttonLabel: string;
}

export interface ExecutiveBriefPayload {
  userName: string;
  timeOfDay: 'Morning' | 'Afternoon' | 'Evening';
  introSentence: string;
  narrativeSentences: string[];
  snapshotIndicators: ExecutiveSnapshotIndicator[];
  recommendedFirstAction: RecommendedFirstAction;
}

// ─────────────────────────────────────────────────────────────────────────────
// DEFAULT MOCK BRIEFING PAYLOAD (Ready for ExecutiveBriefService API binding)
// ─────────────────────────────────────────────────────────────────────────────

export function getDynamicTimeOfDay(): 'Morning' | 'Afternoon' | 'Evening' {
  const hour = new Date().getHours();
  if (hour < 12) return 'Morning';
  if (hour < 17) return 'Afternoon';
  return 'Evening';
}

export const DEFAULT_EXECUTIVE_BRIEF_PAYLOAD: ExecutiveBriefPayload = {
  userName: 'Harsh',
  timeOfDay: getDynamicTimeOfDay(),
  introSentence:
    "I reviewed your company activity since your last session. Here's everything that deserves your attention today.",
  narrativeSentences: [
    '18 qualified leads entered the commercial pipeline overnight.',
    'Three enterprise demonstrations are scheduled today.',
    'Two quotations require executive approval before sales can proceed.',
    'One renewal expires today and should be reviewed before the afternoon.',
    'No critical platform or infrastructure issues were detected.',
  ],
  snapshotIndicators: [
    { id: 'leads',     value: 18,        label: 'Qualified Leads' },
    { id: 'demos',     value: 3,         label: "Today's Demos"   },
    { id: 'approvals', value: 2,         label: 'Pending Approvals' },
    { id: 'renewals',  value: 1,         label: 'Renewals Today' },
    { id: 'health',    value: '99.98%',  label: 'Platform Health' },
  ],
  recommendedFirstAction: {
    title: 'Review Apollo Hospitals quotation.',
    estimatedContract: '₹18.5L',
    deadline: '10:30 AM',
    businessImpact: 'Sales team will be unblocked immediately.',
    actionUrl: '/company/crm/deals',
    buttonLabel: 'Review Quotation',
  },
};
